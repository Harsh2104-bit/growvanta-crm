<?php
declare(strict_types=1);

/**
 * Permission catalogue — single source of truth for what a role may be
 * granted. Identical module/action shape to the Node version's
 * src/lib/permissions.js, so roles.php can serve /api/roles/catalogue with the
 * exact same payload the existing frontend already renders.
 */

const GV_MODULES = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'group' => 'CRM', 'actions' => ['read']],
    // Leads carry the finest-grained action list in the product because real
    // estate teams police attribution: who may move a lead between projects or
    // rewrite its source decides whose numbers a campaign report credits.
    // `assign` places an unowned lead; `reassign` takes one off somebody.
    ['key' => 'leads', 'label' => 'Leads', 'group' => 'CRM', 'actions' => [
        'read', 'create', 'update', 'delete', 'export', 'assign', 'reassign',
        'import', 'merge', 'change_status', 'change_project', 'change_source',
    ]],
    ['key' => 'projects', 'label' => 'Projects', 'group' => 'Real Estate', 'actions' => ['read', 'create', 'update', 'delete', 'manage', 'export']],
    ['key' => 'activities', 'label' => 'Activities', 'group' => 'CRM', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'documents', 'label' => 'Documents', 'group' => 'CRM', 'actions' => ['read', 'create', 'delete']],
    ['key' => 'forms', 'label' => 'Web Forms', 'group' => 'Marketing', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'reports', 'label' => 'Reports & Analytics', 'group' => 'Insights', 'actions' => ['read', 'export']],
    ['key' => 'targets', 'label' => 'Targets & Performance', 'group' => 'Insights', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'imports', 'label' => 'Import / Export', 'group' => 'Data', 'actions' => ['read', 'create', 'export']],
    ['key' => 'users', 'label' => 'Users', 'group' => 'Administration', 'actions' => ['read', 'create', 'update', 'delete', 'manage']],
    ['key' => 'teams', 'label' => 'Teams', 'group' => 'Administration', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'roles', 'label' => 'Roles & Permissions', 'group' => 'Administration', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'settings', 'label' => 'Organisation Settings', 'group' => 'Administration', 'actions' => ['read', 'update']],
    ['key' => 'automation', 'label' => 'Automation & Rules', 'group' => 'Administration', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'integrations', 'label' => 'Integrations', 'group' => 'Administration', 'actions' => ['read', 'update']],
    ['key' => 'apikeys', 'label' => 'API Keys', 'group' => 'Administration', 'actions' => ['read', 'create', 'delete']],
    ['key' => 'webhooks', 'label' => 'Webhooks', 'group' => 'Administration', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'audit', 'label' => 'Audit Logs', 'group' => 'Administration', 'actions' => ['read', 'export']],
    ['key' => 'templates', 'label' => 'Message Templates', 'group' => 'Communication', 'actions' => ['read', 'create', 'update', 'delete']],
    ['key' => 'whatsapp', 'label' => 'WhatsApp', 'group' => 'Communication', 'actions' => ['read', 'update', 'test']],
    ['key' => 'email', 'label' => 'Email', 'group' => 'Communication', 'actions' => ['read', 'update', 'test']],
    // Platform modules only ever resolve true for a platform admin (see
    // lib/tenancy.php); a tenant role that somehow carried them would still be
    // stopped by require_platform_admin() on every platform route.
    ['key' => 'clients', 'label' => 'Client Companies', 'group' => 'Platform', 'actions' => ['read', 'create', 'update', 'delete', 'impersonate']],
    ['key' => 'global_projects', 'label' => 'Global Project Master', 'group' => 'Platform', 'actions' => ['read', 'create', 'update', 'delete', 'manage']],
];

const GV_DATA_SCOPES = [
    ['key' => 'OWN', 'label' => 'Own records only', 'rank' => 1],
    ['key' => 'TEAM', 'label' => 'Own + direct team', 'rank' => 2],
    ['key' => 'TEAM_AND_BELOW', 'label' => 'Own team and all teams below', 'rank' => 3],
    ['key' => 'REGION', 'label' => 'Entire region', 'rank' => 4],
    ['key' => 'ORGANIZATION', 'label' => 'Entire organisation', 'rank' => 5],
    ['key' => 'ALL', 'label' => 'All organisations (platform owner)', 'rank' => 6],
];

function gv_module_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (GV_MODULES as $m) {
            $map[$m['key']] = $m;
        }
    }
    return $map;
}

function gv_scope_rank(): array
{
    static $ranks = null;
    if ($ranks === null) {
        $ranks = [];
        foreach (GV_DATA_SCOPES as $s) {
            $ranks[$s['key']] = $s['rank'];
        }
    }
    return $ranks;
}

/** Strips anything not in the catalogue so a crafted payload cannot invent a grant. */
function sanitize_permissions($input): array
{
    $out = [];
    if (!is_array($input)) {
        return $out;
    }
    $modules = gv_module_map();
    foreach ($input as $moduleKey => $actions) {
        if (!isset($modules[$moduleKey]) || !is_array($actions)) {
            continue;
        }
        $allowed = $modules[$moduleKey]['actions'];
        $clean = array_values(array_unique(array_intersect($actions, $allowed)));
        if ($clean) {
            $out[$moduleKey] = $clean;
        }
    }
    return $out;
}

/** Modules that only ever belong to the platform organisation. */
const GV_PLATFORM_MODULES = ['clients', 'global_projects'];

/**
 * Every grantable permission.
 *
 * Platform modules are excluded by default so a tenant's own Admin role does
 * not claim `clients:create` in the Roles screen. The platform routes would
 * refuse them anyway - require_platform_admin() is the real gate - but a
 * permission list that advertises access nobody has is a lie the UI then
 * repeats.
 */
function all_permissions(bool $includePlatform = false): array
{
    $out = [];
    foreach (GV_MODULES as $m) {
        if (!$includePlatform && in_array($m['key'], GV_PLATFORM_MODULES, true)) {
            continue;
        }
        $out[$m['key']] = $m['actions'];
    }
    return $out;
}

function grant_permissions(array $moduleKeys, array $actions): array
{
    $modules = gv_module_map();
    $out = [];
    foreach ($moduleKeys as $key) {
        if (!isset($modules[$key])) {
            continue;
        }
        $out[$key] = array_values(array_intersect($actions, $modules[$key]['actions']));
    }
    return $out;
}

function is_valid_scope(string $scope): bool
{
    return array_key_exists($scope, gv_scope_rank());
}

// ---------------------------------------------------------------------------
// Effective permissions = role grant + per-user overrides
//
// Spec section 11: a Sales Executive whose role forbids reassignment can still
// be handed that one power individually. Both directions are stored, so the
// override table is also the answer to "why can this person do that?".
// ---------------------------------------------------------------------------

/**
 * Loads a user's special permission rows, dropping any that have expired.
 * Returns rows keyed "module:action".
 */
function user_permission_overrides(string $userId): array
{
    $rows = db_all(
        'SELECT module, action, effect, data_scope, reason, expires_at
           FROM user_permissions
          WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())',
        [$userId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['module'] . ':' . $r['action']] = $r;
    }
    return $out;
}

/**
 * Folds role permissions and user overrides into the set actually enforced.
 *
 * Returns:
 *   permissions - module => [actions]   (what require_permission() checks)
 *   inherited   - module => [actions]   (from the role alone)
 *   special     - list of applied overrides, for the "effective permission" UI
 *   scopes      - module => action => data scope key
 */
function effective_permissions(array $rolePermissions, string $roleScope, $scopeOverrides, array $overrides): array
{
    $modules = gv_module_map();
    $inherited = sanitize_permissions($rolePermissions);
    $permissions = $inherited;
    $special = [];

    foreach ($overrides as $key => $row) {
        [$module, $action] = explode(':', $key, 2);
        // An override naming a module or action outside the catalogue is inert:
        // the catalogue, not the database, decides what is grantable.
        if (!isset($modules[$module]) || !in_array($action, $modules[$module]['actions'], true)) {
            continue;
        }
        $current = $permissions[$module] ?? [];
        if ($row['effect'] === 'REVOKE') {
            $permissions[$module] = array_values(array_diff($current, [$action]));
            if (!$permissions[$module]) {
                unset($permissions[$module]);
            }
        } else {
            if (!in_array($action, $current, true)) {
                $current[] = $action;
            }
            $permissions[$module] = array_values($current);
        }
        $special[] = [
            'module' => $module,
            'action' => $action,
            'effect' => $row['effect'],
            'dataScope' => $row['data_scope'],
            'reason' => $row['reason'],
            'expiresAt' => $row['expires_at'],
        ];
    }

    // Scope resolution, narrowest source first: a user override beats a role
    // override, which beats the role's own blanket data scope.
    $roleScopeMap = is_array($scopeOverrides) ? $scopeOverrides : safe_json($scopeOverrides, []);
    $scopes = [];
    foreach ($permissions as $module => $actions) {
        foreach ($actions as $action) {
            $fromUser = $overrides["$module:$action"]['data_scope'] ?? null;
            $fromRole = $roleScopeMap[$module][$action] ?? null;
            $chosen = $fromUser ?: ($fromRole ?: $roleScope);
            $scopes[$module][$action] = is_valid_scope((string) $chosen) ? $chosen : $roleScope;
        }
    }

    return [
        'permissions' => $permissions,
        'inherited' => $inherited,
        'special' => $special,
        'scopes' => $scopes,
    ];
}
