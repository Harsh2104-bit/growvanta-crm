<?php
declare(strict_types=1);

/**
 * /api/auth/* — ported from src/routes/auth.js. Included from api/index.php
 * with $method and $rest (path segments after "auth") already in scope.
 *
 * Session tokens are opaque + DB-backed here (see lib/auth.php) rather than
 * signed JWTs, so there is no invalidateScopeCache() call to port: resolve_scope()'s
 * cache is a `static` local that dies with the request, which in PHP's
 * process-per-request model is already exactly one request's worth of cache.
 */

function auth_public_user(array $user, ?array $role): array
{
    // The browser must be told the EFFECTIVE permission set, not the role's
    // raw grant. If the UI worked from the role alone, a user with a special
    // grant would be shown no button for a thing the server would happily let
    // them do - and a user with a REVOKE would be shown a button that 403s.
    $eff = $role
        ? effective_permissions(
            safe_json($role['permissions'], []),
            $role['data_scope'],
            $role['scope_overrides'] ?? null,
            user_permission_overrides($user['id'])
        )
        : ['permissions' => [], 'inherited' => [], 'special' => [], 'scopes' => []];

    $actor = actor();
    $isSelf = $actor && $actor['id'] === $user['id'];

    return [
        'id' => $user['id'],
        // For the signed-in user this is the ACTIVE tenant, which differs from
        // their home organisation while a platform admin is inside a client.
        'organizationId' => $isSelf ? $actor['organizationId'] : $user['organization_id'],
        'homeOrganizationId' => $user['organization_id'],
        'isPlatformAdmin' => gv_bool($user['is_platform_admin'] ?? 0),
        'isImpersonating' => $isSelf ? (bool) ($actor['isImpersonating'] ?? false) : false,
        'firstName' => $user['first_name'], 'lastName' => $user['last_name'], 'email' => $user['email'],
        'phone' => $user['phone'], 'avatarUrl' => $user['avatar_url'], 'jobTitle' => $user['job_title'],
        'designation' => $user['designation'] ?? null,
        'region' => $user['region'], 'managerId' => $user['manager_id'],
        'twoFactorEnabled' => gv_bool($user['two_factor_enabled']), 'lastLoginAt' => $user['last_login_at'],
        'role' => $role ? [
            'id' => $role['id'], 'key' => $role['key'], 'name' => $role['name'],
            'level' => (int) $role['level'], 'dataScope' => $role['data_scope'],
        ] : null,
        'permissions' => $eff['permissions'],
        'inheritedPermissions' => $eff['inherited'],
        'specialPermissions' => $eff['special'],
        'permissionScopes' => $eff['scopes'],
    ];
}

function auth_actor_stub(array $user): array
{
    return ['id' => $user['id'], 'firstName' => $user['first_name'], 'lastName' => $user['last_name'] ?? '', 'email' => $user['email']];
}

$body = request_body();
$sub = $rest[0] ?? '';
$subId = $rest[1] ?? null;

switch (true) {
    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'register':
        rate_limit_auth();
        $v = validate($body);
        $v->required('organizationName', 'Organisation name')->maxLength('organizationName', 120);
        $v->required('firstName', 'First name')->maxLength('firstName', 60);
        $v->required('email', 'Email')->email('email');
        $v->required('password', 'Password');
        $v->validate();

        $strength = check_password_strength((string) $body['password']);
        if (!$strength['ok']) {
            throw bad_request($strength['message']);
        }
        $email = strtolower(trim((string) $body['email']));
        if (db_one('SELECT id FROM users WHERE email = ?', [$email])) {
            throw bad_request('That email address is already registered.');
        }

        $result = db_transaction(function () use ($body, $email) {
            $prov = provision_organization(trim((string) $body['organizationName']));
            $userId = gv_id();
            db_exec(
                'INSERT INTO users (id, organization_id, role_id, first_name, last_name, email, password_hash, email_verified_at, job_title, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,NOW(),?,NOW(),NOW())',
                [$userId, $prov['organizationId'], $prov['roleIds']['super_admin'], trim((string) $body['firstName']), trim((string) ($body['lastName'] ?? '')), $email, hash_password((string) $body['password']), 'Founder']
            );
            $org = db_one('SELECT * FROM organizations WHERE id = ?', [$prov['organizationId']]);
            $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
            return ['org' => $org, 'user' => $user];
        });

        issue_session($result['user'], false);
        audit_record($result['org']['id'], auth_actor_stub($result['user']), 'organization.created', [
            'entityType' => 'ORGANIZATION', 'entityId' => $result['org']['id'], 'entityLabel' => $result['org']['name'],
        ]);
        $role = db_one('SELECT * FROM roles WHERE id = ?', [$result['user']['role_id']]);
        json_created(['user' => auth_public_user($result['user'], $role), 'organization' => ['id' => $result['org']['id'], 'name' => $result['org']['name'], 'slug' => $result['org']['slug']]]);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'login':
        rate_limit_auth();
        $v = validate($body);
        $v->required('email', 'Email')->email('email');
        $v->required('password', 'Password');
        $v->validate();

        $email = strtolower(trim((string) $body['email']));
        $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);

        if (!$user) {
            verify_password((string) $body['password'], '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinva');
            throw unauthenticated('Email or password is incorrect');
        }
        if (!verify_password((string) $body['password'], $user['password_hash'])) {
            audit_record($user['organization_id'], null, 'auth.login_failed', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email']]);
            throw unauthenticated('Email or password is incorrect');
        }
        if (!gv_bool($user['is_active'])) {
            throw forbidden('This account has been deactivated. Contact your administrator.');
        }
        $org = db_one('SELECT * FROM organizations WHERE id = ?', [$user['organization_id']]);
        $denial = $org ? org_access_denial($org) : 'This organisation no longer exists.';
        if ($denial !== null) {
            // Logged so a Super Admin can see that people are being turned away,
            // which is usually the first sign an invoice was missed.
            audit_record($org['id'] ?? '', null, 'auth.blocked', [
                'entityType' => 'USER', 'entityId' => $user['id'],
                'entityLabel' => $user['email'], 'changes' => ['reason' => $denial],
            ]);
            throw forbidden($denial);
        }

        if (gv_bool($user['two_factor_enabled'])) {
            $code = $body['twoFactorCode'] ?? null;
            if (!$code) {
                json_ok(['twoFactorRequired' => true]);
                break;
            }
            if (!totp_verify($user['two_factor_secret'], (string) $code)) {
                throw unauthenticated('That verification code is not valid.');
            }
        }

        issue_session($user, bool_val($body['rememberMe'] ?? false));
        db_exec('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [client_ip(), $user['id']]);
        audit_record($user['organization_id'], auth_actor_stub($user), 'auth.login', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email']]);

        $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
        json_ok(['user' => auth_public_user($user, $role)]);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'logout':
        $session = $GLOBALS['GV_SESSION'] ?? null;
        $a = actor();
        if ($session) {
            db_exec('UPDATE sessions SET revoked_at = NOW() WHERE id = ?', [$session['id']]);
            audit_record($a['organizationId'], $a, 'auth.logout', ['entityType' => 'USER', 'entityId' => $a['id'], 'entityLabel' => $a['email']]);
        }
        clear_session_cookie();
        json_ok(['loggedOut' => true]);
        break;

    case $method === 'POST' && $sub === 'logout-all':
        $a = require_auth();
        $count = db_exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$a['id']]);
        clear_session_cookie();
        audit_record($a['organizationId'], $a, 'auth.logout_all', ['entityType' => 'USER', 'entityId' => $a['id'], 'changes' => ['sessionsRevoked' => $count]]);
        json_ok(['sessionsRevoked' => $count]);
        break;

    // -------------------------------------------------------------------
    case $method === 'GET' && $sub === 'me':
        $a = require_auth();
        $user = db_one('SELECT * FROM users WHERE id = ?', [$a['id']]);
        if (!$user) {
            throw not_found('User not found');
        }
        $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
        $teams = db_all(
            'SELECT t.id, t.name, tm.team_role FROM team_members tm JOIN teams t ON t.id = tm.team_id WHERE tm.user_id = ?',
            [$a['id']]
        );
        $orgRow = db_one('SELECT * FROM organizations WHERE id = ?', [$a['organizationId']]);
        json_ok([
            'user' => auth_public_user($user, $role),
            'organization' => [
                'id' => $orgRow['id'], 'name' => $orgRow['name'], 'slug' => $orgRow['slug'],
                'currency' => $orgRow['currency'], 'timezone' => $orgRow['timezone'],
                'companyType' => $orgRow['company_type'],
                'isPlatform' => gv_bool($orgRow['is_platform']),
                'usesGlobalProjects' => org_uses_global_projects((string) $orgRow['company_type']),
                'logoUrl' => $orgRow['logo_url'],
                // Drives the per-tenant white-label repaint in the browser.
                'branding' => org_branding($orgRow),
                'settings' => safe_json($orgRow['settings'], []),
            ],
            'teams' => array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 'role' => $t['team_role']], $teams),
            'scope' => scope_info(),
            // Only populated for a platform admin; the shell uses it to render
            // the tenant switcher without a second round trip.
            'platform' => is_platform_admin($a) ? [
                'homeOrganizationId' => $a['homeOrganizationId'],
                'activeOrganizationId' => $a['organizationId'],
                'isImpersonating' => (bool) $a['isImpersonating'],
                'clients' => camelize(db_all(
                    'SELECT id, name, company_type, status, logo_url FROM organizations
                      WHERE is_platform = 0 ORDER BY name ASC LIMIT 200'
                )),
            ] : null,
        ]);
        break;

    // -------------------------------------------------------------------
    case $method === 'GET' && $sub === 'sessions':
        $a = require_auth();
        $sessions = db_all(
            'SELECT id, user_agent, ip, remember_me, created_at, last_seen_at, expires_at FROM sessions
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY last_seen_at DESC',
            [$a['id']]
        );
        $currentId = $GLOBALS['GV_SESSION']['id'] ?? null;
        json_ok(array_map(fn($s) => [
            'id' => $s['id'], 'userAgent' => $s['user_agent'], 'ip' => $s['ip'], 'rememberMe' => gv_bool($s['remember_me']),
            'createdAt' => $s['created_at'], 'lastSeenAt' => $s['last_seen_at'], 'expiresAt' => $s['expires_at'],
            'current' => $s['id'] === $currentId,
        ], $sessions));
        break;

    case $method === 'DELETE' && $sub === 'sessions' && $subId:
        $a = require_auth();
        $session = db_one('SELECT * FROM sessions WHERE id = ? AND user_id = ?', [$subId, $a['id']]);
        if (!$session) {
            throw not_found('Session not found');
        }
        db_exec('UPDATE sessions SET revoked_at = NOW() WHERE id = ?', [$session['id']]);
        json_ok(['revoked' => true]);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'forgot-password':
        rate_limit_auth();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $user = $email ? db_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]) : null;
        $response = ['message' => 'If that account exists, a reset link has been sent.'];
        if ($user) {
            $token = random_token(32);
            db_exec(
                'INSERT INTO auth_tokens (id, user_id, type, token_hash, expires_at, created_at) VALUES (?,?,?,?,?,NOW())',
                [gv_id(), $user['id'], 'PASSWORD_RESET', sha256($token), date('Y-m-d H:i:s', time() + 3600)]
            );
            audit_record($user['organization_id'], auth_actor_stub($user), 'auth.password_reset_requested', ['entityType' => 'USER', 'entityId' => $user['id']]);
            $link = gv_config('app_url') . '/login.html#/reset?token=' . $token;
            if (!gv_config('mail.host')) {
                error_log("[auth] SMTP not configured. Reset link for $email: $link");
            }
            if (gv_config('env') !== 'production') {
                $response['resetUrl'] = $link;
            }
        }
        json_ok($response);
        break;

    case $method === 'POST' && $sub === 'reset-password':
        rate_limit_auth();
        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        if (!$token || !$password) {
            throw bad_request('Token and new password are required');
        }
        $strength = check_password_strength((string) $password);
        if (!$strength['ok']) {
            throw bad_request($strength['message']);
        }
        $row = db_one('SELECT * FROM auth_tokens WHERE token_hash = ?', [sha256((string) $token)]);
        if (!$row || $row['type'] !== 'PASSWORD_RESET' || $row['used_at'] || strtotime($row['expires_at']) < time()) {
            throw bad_request('That reset link is invalid or has expired.');
        }
        $user = db_one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        db_transaction(function () use ($password, $row, $user) {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password((string) $password), $row['user_id']]);
            db_exec('UPDATE auth_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
            db_exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$row['user_id']]);
        });
        audit_record($user['organization_id'], auth_actor_stub($user), 'auth.password_reset', ['entityType' => 'USER', 'entityId' => $row['user_id']]);
        json_ok(['message' => 'Password updated. Please sign in.']);
        break;

    case $method === 'POST' && $sub === 'change-password':
        $a = require_auth();
        $current = $body['currentPassword'] ?? null;
        $new = $body['newPassword'] ?? null;
        if (!$current || !$new) {
            throw bad_request('Current and new password are required');
        }
        $strength = check_password_strength((string) $new);
        if (!$strength['ok']) {
            throw bad_request($strength['message']);
        }
        $user = db_one('SELECT * FROM users WHERE id = ?', [$a['id']]);
        if (!verify_password((string) $current, $user['password_hash'])) {
            throw bad_request('Your current password is not correct.');
        }
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password((string) $new), $user['id']]);
        $currentSessionId = $GLOBALS['GV_SESSION']['id'] ?? null;
        db_exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND id != ?', [$user['id'], $currentSessionId ?? '']);
        audit_record($user['organization_id'], $a, 'auth.password_changed', ['entityType' => 'USER', 'entityId' => $user['id']]);
        json_ok(['message' => 'Password updated. Other sessions were signed out.']);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'send-verification':
        $a = require_auth();
        $token = random_token(24);
        db_exec(
            'INSERT INTO auth_tokens (id, user_id, type, token_hash, expires_at, created_at) VALUES (?,?,?,?,?,NOW())',
            [gv_id(), $a['id'], 'EMAIL_VERIFY', sha256($token), date('Y-m-d H:i:s', time() + 86400)]
        );
        $link = gv_config('app_url') . '/login.html#/verify?token=' . $token;
        if (!gv_config('mail.host')) {
            error_log("[auth] SMTP not configured. Verification link: $link");
        }
        $response = ['message' => 'Verification email queued.'];
        if (gv_config('env') !== 'production') {
            $response['verifyUrl'] = $link;
        }
        json_ok($response);
        break;

    case $method === 'POST' && $sub === 'verify-email':
        $token = $body['token'] ?? '';
        $row = $token ? db_one('SELECT * FROM auth_tokens WHERE token_hash = ?', [sha256((string) $token)]) : null;
        if (!$row || $row['type'] !== 'EMAIL_VERIFY' || $row['used_at'] || strtotime($row['expires_at']) < time()) {
            throw bad_request('That verification link is invalid or has expired.');
        }
        db_transaction(function () use ($row) {
            db_exec('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$row['user_id']]);
            db_exec('UPDATE auth_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
        });
        json_ok(['verified' => true]);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === '2fa' && $subId === 'setup':
        $a = require_auth();
        $secret = totp_generate_secret();
        db_exec('UPDATE users SET two_factor_secret = ? WHERE id = ?', [$secret, $a['id']]);
        json_ok(['secret' => $secret, 'otpauthUri' => totp_otpauth_uri($secret, $a['email'], "Growvanta CRM ({$a['organization']['name']})")]);
        break;

    case $method === 'POST' && $sub === '2fa' && $subId === 'enable':
        $a = require_auth();
        $user = db_one('SELECT * FROM users WHERE id = ?', [$a['id']]);
        if (!$user['two_factor_secret']) {
            throw bad_request('Start the setup step first.');
        }
        if (!totp_verify($user['two_factor_secret'], (string) ($body['code'] ?? ''))) {
            throw bad_request('That code is not valid. Try the next one.');
        }
        db_exec('UPDATE users SET two_factor_enabled = 1 WHERE id = ?', [$user['id']]);
        audit_record($user['organization_id'], $a, 'auth.2fa_enabled', ['entityType' => 'USER', 'entityId' => $user['id']]);
        json_ok(['twoFactorEnabled' => true]);
        break;

    case $method === 'POST' && $sub === '2fa' && $subId === 'disable':
        $a = require_auth();
        $user = db_one('SELECT * FROM users WHERE id = ?', [$a['id']]);
        if (!verify_password((string) ($body['password'] ?? ''), $user['password_hash'])) {
            throw bad_request('Confirm your password to turn off two-factor authentication.');
        }
        db_exec('UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL WHERE id = ?', [$user['id']]);
        audit_record($user['organization_id'], $a, 'auth.2fa_disabled', ['entityType' => 'USER', 'entityId' => $user['id']]);
        json_ok(['twoFactorEnabled' => false]);
        break;

    // -------------------------------------------------------------------
    case $method === 'POST' && $sub === 'accept-invite':
        rate_limit_auth();
        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        if (!$token || !$password) {
            throw bad_request('Token and password are required');
        }
        $strength = check_password_strength((string) $password);
        if (!$strength['ok']) {
            throw bad_request($strength['message']);
        }
        $row = db_one('SELECT * FROM auth_tokens WHERE token_hash = ?', [sha256((string) $token)]);
        if (!$row || $row['type'] !== 'INVITE' || $row['used_at'] || strtotime($row['expires_at']) < time()) {
            throw bad_request('That invitation is invalid or has expired.');
        }
        $existing = db_one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        db_exec(
            'UPDATE users SET password_hash = ?, first_name = ?, last_name = ?, email_verified_at = NOW(), is_active = 1 WHERE id = ?',
            [
                hash_password((string) $password),
                trim((string) ($body['firstName'] ?? $existing['first_name'])),
                array_key_exists('lastName', $body) ? trim((string) $body['lastName']) : $existing['last_name'],
                $row['user_id'],
            ]
        );
        db_exec('UPDATE auth_tokens SET used_at = NOW() WHERE id = ?', [$row['id']]);
        $user = db_one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);

        issue_session($user, false);
        audit_record($user['organization_id'], auth_actor_stub($user), 'auth.invite_accepted', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email']]);
        $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
        json_ok(['user' => auth_public_user($user, $role)]);
        break;

    default:
        throw not_found('Unknown auth route');
}
