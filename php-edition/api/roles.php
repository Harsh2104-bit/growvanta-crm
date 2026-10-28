<?php
declare(strict_types=1);

/** /api/roles/* — ported from src/routes/roles.js. */

/**
 * Guard rails on role writes: a payload can never outrank the actor's own
 * role, never grant a data scope wider than the actor's own, and never grant
 * a permission the actor does not themselves hold.
 */
function roles_assert_within_authority(array $actor, array $payload): array
{
    $actorRole = db_one('SELECT * FROM roles WHERE id = ?', [$actor['role']['id']]);
    $level = $payload['level'] ?? 50;
    if ((int) $level < (int) $actorRole['level']) {
        throw forbidden('You cannot create a role with more authority than your own.');
    }
    if (!is_valid_scope($payload['dataScope'])) {
        throw bad_request('Unknown data scope');
    }
    $ranks = gv_scope_rank();
    if (($ranks[$payload['dataScope']] ?? 0) > ($ranks[$actorRole['data_scope']] ?? 0)) {
        throw forbidden('You cannot grant a data scope wider than your own.');
    }

    $clean = sanitize_permissions($payload['permissions'] ?? []);
    $mine = safe_json($actorRole['permissions'], []);
    foreach ($clean as $moduleKey => $actions) {
        $held = $mine[$moduleKey] ?? [];
        $excess = array_diff($actions, $held);
        if ($excess) {
            throw forbidden("You cannot grant $moduleKey:" . implode(',', $excess) . ' because you do not hold it yourself.');
        }
    }
    return $clean;
}

function roles_shape(array $r): array
{
    $out = camelize($r);
    $out['permissions'] = safe_json($r['permissions'], []);
    return $out;
}

$id = $rest[0] ?? null;
$body = request_body();

if ($method === 'GET' && $id === 'catalogue') {
    require_auth();
    json_ok(['modules' => GV_MODULES, 'dataScopes' => GV_DATA_SCOPES]);
} elseif ($method === 'GET' && $id === null) {
    require_permission('roles', 'read');
    $a = actor();
    // Belt and braces alongside provisioning: a company admin never sees the
    // platform-owner role, even on an older install where the row still exists.
    $hidePlatformRole = is_platform_admin($a) ? '' : " AND r.`key` <> 'super_admin'";
    $rows = db_all(
        'SELECT r.*, pr.name AS parent_role_name, (SELECT COUNT(*) FROM users WHERE role_id = r.id) AS user_count
         FROM roles r LEFT JOIN roles pr ON pr.id = r.parent_role_id
          WHERE r.organization_id = ?' . $hidePlatformRole . ' ORDER BY r.level ASC, r.name ASC',
        [$a['organizationId']]
    );
    json_ok(array_map(function ($r) {
        $out = roles_shape($r);
        $out['parentRole'] = $r['parent_role_id'] ? ['id' => $r['parent_role_id'], 'name' => $r['parent_role_name']] : null;
        $out['userCount'] = (int) $r['user_count'];
        unset($out['parentRoleName']);
        return $out;
    }, $rows));
} elseif ($method === 'POST' && $id === null) {
    require_permission('roles', 'create');
    $a = actor();
    $v = validate($body);
    $v->required('name', 'Role name')->maxLength('name', 80);
    $v->required('dataScope', 'Data scope');
    $v->validate();

    $permissions = roles_assert_within_authority($a, $body);
    $key = str_replace('-', '_', slugify($body['name']));
    if (db_one('SELECT id FROM roles WHERE organization_id = ? AND `key` = ?', [$a['organizationId'], $key])) {
        $key .= '_' . substr(base_convert((string) time(), 10, 36), -4);
    }
    $roleId = gv_id();
    db_exec(
        'INSERT INTO roles (id, organization_id, `key`, name, description, parent_role_id, level, data_scope, permissions, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$roleId, $a['organizationId'], $key, $body['name'], $body['description'] ?? null, $body['parentRoleId'] ?? null, $body['level'] ?? 50, $body['dataScope'], to_json($permissions)]
    );
    $role = db_one('SELECT * FROM roles WHERE id = ?', [$roleId]);
    audit_record($a['organizationId'], $a, 'role.created', ['entityType' => 'ROLE', 'entityId' => $roleId, 'entityLabel' => $role['name'], 'changes' => ['dataScope' => $role['data_scope'], 'permissions' => $permissions]]);
    json_created(roles_shape($role));
} elseif ($method === 'GET' && $id) {
    require_permission('roles', 'read');
    $a = actor();
    $role = db_one('SELECT * FROM roles WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$role) {
        throw not_found('Role not found');
    }
    $userCount = (int) db_scalar('SELECT COUNT(*) FROM users WHERE role_id = ?', [$role['id']]);
    json_ok(array_merge(roles_shape($role), ['userCount' => $userCount]));
} elseif ($method === 'PATCH' && $id) {
    require_permission('roles', 'update');
    $a = actor();
    $before = db_one('SELECT * FROM roles WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$before) {
        throw not_found('Role not found');
    }
    if (gv_bool($before['is_system']) && (isset($body['dataScope']) || isset($body['permissions']))) {
        throw bad_request('Built-in Super Admin and Admin roles cannot have their access reduced. Create a custom role instead.');
    }
    if ($before['id'] === $a['role']['id']) {
        throw forbidden('You cannot edit the role you are currently signed in with.');
    }

    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('description', $body)) {
        $data['description'] = $body['description'] ?: null;
    }
    if (array_key_exists('parentRoleId', $body)) {
        if ($body['parentRoleId'] === $before['id']) {
            throw bad_request('A role cannot be its own parent.');
        }
        $data['parent_role_id'] = $body['parentRoleId'] ?: null;
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = $body['isActive'] ? 1 : 0;
    }
    if (array_key_exists('level', $body) || array_key_exists('dataScope', $body) || array_key_exists('permissions', $body)) {
        $permissions = roles_assert_within_authority($a, [
            'level' => $body['level'] ?? $before['level'],
            'dataScope' => $body['dataScope'] ?? $before['data_scope'],
            'permissions' => $body['permissions'] ?? safe_json($before['permissions'], []),
        ]);
        if (array_key_exists('level', $body)) {
            $data['level'] = $body['level'];
        }
        if (array_key_exists('dataScope', $body)) {
            $data['data_scope'] = $body['dataScope'];
        }
        if (array_key_exists('permissions', $body)) {
            $data['permissions'] = to_json($permissions);
        }
    }

    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE roles SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    $role = db_one('SELECT * FROM roles WHERE id = ?', [$before['id']]);
    $changes = gv_diff($before, $role, ['name', 'level', 'data_scope', 'permissions', 'is_active']);
    audit_record($a['organizationId'], $a, isset($data['permissions']) ? 'role.permissions_changed' : 'role.updated', ['entityType' => 'ROLE', 'entityId' => $role['id'], 'entityLabel' => $role['name'], 'changes' => $changes]);
    json_ok(roles_shape($role));
} elseif ($method === 'DELETE' && $id) {
    require_permission('roles', 'delete');
    $a = actor();
    $role = db_one('SELECT * FROM roles WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$role) {
        throw not_found('Role not found');
    }
    if (gv_bool($role['is_system'])) {
        throw bad_request('Built-in roles cannot be deleted.');
    }
    $userCount = (int) db_scalar('SELECT COUNT(*) FROM users WHERE role_id = ?', [$role['id']]);
    if ($userCount > 0) {
        throw bad_request("$userCount user(s) still hold this role. Move them first.");
    }
    db_exec('DELETE FROM roles WHERE id = ?', [$role['id']]);
    audit_record($a['organizationId'], $a, 'role.deleted', ['entityType' => 'ROLE', 'entityId' => $role['id'], 'entityLabel' => $role['name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown roles route');
}
