<?php
declare(strict_types=1);

/**
 * Session auth. PHP has no long-lived process to hold a JWT verifier in
 * memory, so sessions are simpler here than the Node version: an opaque
 * random token in an httpOnly cookie, hashed and looked up against the
 * `sessions` table on every request. Revoking a session (logout, "sign out
 * everywhere", deactivation) takes effect immediately because the lookup is
 * the source of truth, not a signature.
 */

function auth_read_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'bearer ') === 0) {
        return trim(substr($header, 7));
    }
    $cookieName = gv_config('auth.cookie_name');
    return $_COOKIE[$cookieName] ?? null;
}

/**
 * Loads the session actor into $GLOBALS['GV_ACTOR'] / $GLOBALS['GV_SESSION'].
 * Mirrors the Node middleware: token alone is not enough, the session row
 * must exist, be unrevoked, unexpired, and belong to an active user in an
 * active organisation.
 */
function auth_load_actor(): void
{
    $token = auth_read_token();
    if (!$token) {
        return;
    }
    $session = db_one(
        'SELECT * FROM sessions WHERE token_hash = ?',
        [sha256($token)]
    );
    if (!$session || $session['revoked_at'] || strtotime($session['expires_at']) < time()) {
        return;
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
    if (!$user || !gv_bool($user['is_active'])) {
        return;
    }
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$user['organization_id']]);
    // Blocking access has to end sessions that are already open, otherwise a
    // company that stopped paying keeps working until everyone happens to log
    // out. Platform admins are exempt: they are the ones who unblock it.
    if (!$org || (org_access_denial($org) !== null && !gv_bool($user['is_platform_admin'] ?? 0))) {
        return;
    }
    $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
    if (!$role) {
        return;
    }

    // Platform admins act inside whichever tenant their session points at;
    // everyone else is pinned to their own. This one resolution is what makes
    // every downstream `WHERE organization_id = ?` tenant-correct.
    $isPlatformAdmin = gv_bool($user['is_platform_admin'] ?? 0);
    $activeOrg = $org;
    if ($isPlatformAdmin && !empty($session['active_organization_id'])) {
        $candidate = db_one('SELECT * FROM organizations WHERE id = ?', [$session['active_organization_id']]);
        // A tenant that was deleted or suspended mid-session drops the admin
        // back to the platform rather than leaving them in a dead context.
        // A platform admin may open a blocked client - that is precisely when
        // they need to get in and fix the billing.
        if ($candidate) {
            $activeOrg = $candidate;
        }
    }

    $overrides = user_permission_overrides($user['id']);
    $eff = effective_permissions(
        safe_json($role['permissions'], []),
        $role['data_scope'],
        $role['scope_overrides'] ?? null,
        $overrides
    );

    $GLOBALS['GV_SESSION'] = $session;
    $GLOBALS['GV_ACTOR'] = [
        'id' => $user['id'],
        // The tenant this request operates on (see lib/tenancy.php).
        'organizationId' => $activeOrg['id'],
        // The tenant this user actually belongs to. Never use this for data
        // scoping - only for "who am I" style lookups.
        'homeOrganizationId' => $user['organization_id'],
        'isPlatformAdmin' => $isPlatformAdmin,
        'isImpersonating' => $isPlatformAdmin && $activeOrg['id'] !== $user['organization_id'],
        'organization' => [
            'id' => $activeOrg['id'], 'name' => $activeOrg['name'], 'slug' => $activeOrg['slug'],
            'currency' => $activeOrg['currency'], 'timezone' => $activeOrg['timezone'],
            'companyType' => $activeOrg['company_type'] ?? 'DEVELOPER',
            'isPlatform' => gv_bool($activeOrg['is_platform'] ?? 0),
            'branding' => org_branding($activeOrg),
        ],
        'firstName' => $user['first_name'],
        'lastName' => $user['last_name'],
        'email' => $user['email'],
        'avatarUrl' => $user['avatar_url'],
        'jobTitle' => $user['job_title'],
        'designation' => $user['designation'] ?? null,
        'region' => $user['region'],
        'managerId' => $user['manager_id'],
        'twoFactorEnabled' => gv_bool($user['two_factor_enabled']),
        'lastLoginAt' => $user['last_login_at'],
        'role' => [
            'id' => $role['id'], 'key' => $role['key'], 'name' => $role['name'],
            'level' => (int) $role['level'], 'dataScope' => $role['data_scope'], 'isSystem' => gv_bool($role['is_system']),
        ],
        'permissions' => $eff['permissions'],
        'inheritedPermissions' => $eff['inherited'],
        'specialPermissions' => $eff['special'],
        'permissionScopes' => $eff['scopes'],
    ];

    // Cheap liveness ping, throttled like the Node version (avoid a write per request).
    if (time() - strtotime($session['last_seen_at']) > 60) {
        db_exec('UPDATE sessions SET last_seen_at = NOW() WHERE id = ?', [$session['id']]);
    }
}

function actor(): ?array
{
    return $GLOBALS['GV_ACTOR'] ?? null;
}

function require_auth(): array
{
    $a = actor();
    if (!$a) {
        throw unauthenticated();
    }
    return $a;
}

/** Server-side permission gate — the one that actually matters. */
function require_permission(string $module, string $action): array
{
    $a = require_auth();
    $granted = $a['permissions'][$module] ?? [];
    if (!in_array($action, $granted, true)) {
        throw forbidden("Missing permission: $module:$action");
    }
    return $a;
}

function require_any_permission(array $pairs): array
{
    $a = require_auth();
    foreach ($pairs as [$module, $action]) {
        if (in_array($action, $a['permissions'][$module] ?? [], true)) {
            return $a;
        }
    }
    throw forbidden('Missing permission');
}

function has_permission(array $actor, string $module, string $action): bool
{
    return in_array($action, $actor['permissions'][$module] ?? [], true);
}

/** Lazily resolved per-request; every guarded route needs it for query scoping. */
function scope_info(): array
{
    static $info = null;
    if ($info === null) {
        $info = resolve_scope(require_auth());
    }
    return $info;
}

function client_ip(): ?string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if ($fwd) {
        return substr(trim(explode(',', $fwd)[0]), 0, 60);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 60) : null;
}

function user_agent(): ?string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    return $ua ? substr($ua, 0, 250) : null;
}

// ---------------------------------------------------------------------------
// Session issuing / cookies
// ---------------------------------------------------------------------------
function issue_session(array $user, bool $rememberMe): array
{
    $ttlSeconds = $rememberMe
        ? (int) gv_config('auth.remember_me_ttl_days') * 86400
        : (int) gv_config('auth.session_ttl_hours') * 3600;
    $token = random_token(32);
    $sessionId = gv_id();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

    db_exec(
        'INSERT INTO sessions (id, user_id, token_hash, user_agent, ip, remember_me, expires_at) VALUES (?,?,?,?,?,?,?)',
        [$sessionId, $user['id'], sha256($token), user_agent(), client_ip(), $rememberMe ? 1 : 0, $expiresAt]
    );

    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    setcookie(gv_config('auth.cookie_name'), $token, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return ['token' => $token, 'sessionId' => $sessionId, 'expiresAt' => $expiresAt];
}

function clear_session_cookie(): void
{
    setcookie(gv_config('auth.cookie_name'), '', ['expires' => time() - 3600, 'path' => '/']);
}

// ---------------------------------------------------------------------------
// CSRF (double-submit cookie, same scheme as the Node middleware)
// ---------------------------------------------------------------------------
function issue_csrf_cookie(): void
{
    $name = gv_config('auth.csrf_cookie');
    if (!isset($_COOKIE[$name])) {
        $token = random_token(24);
        setcookie($name, $token, ['expires' => 0, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax']);
        $_COOKIE[$name] = $token;
    }
}

function csrf_protect(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (isset($GLOBALS['GV_API_KEY'])) {
        return; // API-key callers are exempt, same as the Node middleware
    }
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($authHeader, 'bearer ') === 0) {
        return;
    }
    $cookieName = gv_config('auth.cookie_name');
    if (!isset($_COOKIE[$cookieName])) {
        return; // unauthenticated: nothing to forge
    }
    $csrfName = gv_config('auth.csrf_cookie');
    $cookieToken = $_COOKIE[$csrfName] ?? '';
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($cookieToken === '' || $headerToken === '' || !hash_equals($cookieToken, $headerToken)) {
        throw forbidden('Invalid or missing CSRF token. Reload the page and try again.');
    }
}

/**
 * The data scope that applies to one specific permission, after role overrides
 * and per-user special access. Falls back to the role's blanket scope.
 */
function permission_scope(array $actor, string $module, string $action): string
{
    return $actor['permissionScopes'][$module][$action] ?? ($actor['role']['dataScope'] ?? 'OWN');
}

/**
 * Visibility for one permission. Use this instead of scope_info() wherever the
 * spec wants a different reach per action - reading team leads but exporting
 * only your own, say. scope_info() remains the role-wide default.
 */
function scope_for(string $module, string $action): array
{
    $a = require_auth();
    return resolve_scope($a, permission_scope($a, $module, $action));
}
