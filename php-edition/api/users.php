<?php
declare(strict_types=1);

/** /api/users/* — ported from src/routes/users.js. */

function users_shape(array $u): array
{
    $out = camelize($u);
    unset($out['passwordHash'], $out['twoFactorSecret']);
    $out['fullName'] = trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
    return $out;
}

/** An admin may only grant a role at/below their own authority and data scope. */
function users_assert_can_grant_role(array $actor, string $roleId): array
{
    $role = db_one('SELECT * FROM roles WHERE id = ? AND organization_id = ?', [$roleId, $actor['organizationId']]);
    if (!$role) {
        throw bad_request('Unknown role');
    }
    $actorRole = db_one('SELECT * FROM roles WHERE id = ?', [$actor['role']['id']]);
    if ((int) $role['level'] < (int) $actorRole['level']) {
        throw forbidden('You cannot grant a role with more authority than your own.');
    }
    $ranks = gv_scope_rank();
    if (($ranks[$role['data_scope']] ?? 0) > ($ranks[$actorRole['data_scope']] ?? 0)) {
        throw forbidden('You cannot grant a data scope wider than your own.');
    }
    // Creating an administrator is the Super Admin's job alone. A company admin
    // can build their whole sales hierarchy but cannot mint a second admin, so
    // the account that controls a tenant is only ever granted by the platform.
    if (in_array($role['key'], ['admin', 'super_admin'], true) && !is_platform_admin($actor)) {
        throw forbidden('Only a platform administrator can create another administrator.');
    }
    return $role;
}

function users_assert_can_manage(string $targetId): void
{
    $a = actor();
    if ($targetId === $a['id']) {
        return;
    }
    $scope = scope_info();
    if ($scope['unrestricted']) {
        return;
    }
    if (!in_array($targetId, $scope['userIds'], true)) {
        throw forbidden('That user is outside your management scope.');
    }
}

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $id === null) {
    require_permission('users', 'read');
    $a = actor();
    $scope = scope_info();
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $clauses = ['organization_id = ?'];
    $params = [$a['organizationId']];
    if (!$scope['unrestricted'] && ($query['scoped'] ?? null) !== 'false') {
        [$in, $p] = db_in_clause($scope['userIds']);
        $clauses[] = "id IN $in";
        $params = array_merge($params, $p);
    }
    if (!empty($query['q'])) {
        $term = '%' . trim((string) $query['q']) . '%';
        $clauses[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR job_title LIKE ?)';
        array_push($params, $term, $term, $term, $term);
    }
    if (!empty($query['roleId'])) {
        $clauses[] = 'role_id = ?';
        $params[] = (string) $query['roleId'];
    }
    if (!empty($query['region'])) {
        $clauses[] = 'region = ?';
        $params[] = (string) $query['region'];
    }
    if (isset($query['isActive'])) {
        $clauses[] = 'is_active = ?';
        $params[] = $query['isActive'] === 'true' ? 1 : 0;
    }
    if (!empty($query['teamId'])) {
        $clauses[] = 'id IN (SELECT user_id FROM team_members WHERE team_id = ?)';
        $params[] = (string) $query['teamId'];
    }
    // Qualified with `users.` because roles and users both carry a name, and
    // the manager self-join brings a third set of ambiguous columns.
    $where = 'WHERE ' . implode(' AND ', array_map(
        fn($c) => str_replace(
            ['organization_id', 'first_name', 'last_name', 'email', 'job_title', 'role_id', 'region', 'is_active', 'id IN'],
            ['users.organization_id', 'users.first_name', 'users.last_name', 'users.email', 'users.job_title', 'users.role_id', 'users.region', 'users.is_active', 'users.id IN'],
            $c
        ),
        $clauses
    ));
    [$sortCol, $dir] = parse_sort($query, ['createdAt', 'firstName', 'lastLoginAt'], 'first_name', 'ASC');
    $rows = db_all(
        "SELECT users.*, roles.name AS role_name, roles.`key` AS role_key, roles.data_scope AS role_data_scope,
                mgr.first_name AS manager_first_name, mgr.last_name AS manager_last_name
           FROM users
           LEFT JOIN roles ON roles.id = users.role_id
           LEFT JOIN users mgr ON mgr.id = users.manager_id
         $where ORDER BY users.$sortCol $dir LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    $total = (int) db_scalar("SELECT COUNT(*) FROM users LEFT JOIN roles ON roles.id = users.role_id $where", $params);
    json_ok(array_map(function ($u) {
        $out = users_shape($u);
        unset($out['roleName'], $out['roleKey'], $out['roleDataScope'], $out['managerFirstName'], $out['managerLastName']);
        $out['role'] = $u['role_id']
            ? ['id' => $u['role_id'], 'name' => $u['role_name'], 'key' => $u['role_key'], 'dataScope' => $u['role_data_scope']]
            : null;
        $out['managerName'] = $u['manager_id']
            ? trim($u['manager_first_name'] . ' ' . ($u['manager_last_name'] ?? ''))
            : null;
        return $out;
    }, $rows), page_meta($page, $limit, $total));
} elseif ($method === 'GET' && $id === 'assignable') {
    require_auth();
    $a = actor();
    $scope = scope_info();
    $clauses = ['organization_id = ?', 'is_active = 1', 'is_assignable = 1'];
    $params = [$a['organizationId']];
    if (!$scope['unrestricted']) {
        [$in, $p] = db_in_clause($scope['userIds']);
        $clauses[] = "id IN $in";
        $params = array_merge($params, $p);
    }
    $rows = db_all('SELECT id, first_name, last_name, email, avatar_url FROM users WHERE ' . implode(' AND ', $clauses) . ' ORDER BY first_name ASC LIMIT 500', $params);
    json_ok(array_map(fn($u) => array_merge(camelize($u), ['fullName' => trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))]), $rows));
} elseif ($method === 'GET' && $id === 'hierarchy') {
    require_permission('users', 'read');
    $a = actor();
    $rows = db_all(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.avatar_url, u.manager_id, u.job_title, r.name AS role_name, r.`key` AS role_key, r.level AS role_level
         FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.first_name ASC',
        [$a['organizationId']]
    );
    $byId = [];
    foreach ($rows as $u) {
        $byId[$u['id']] = [
            'id' => $u['id'], 'firstName' => $u['first_name'], 'lastName' => $u['last_name'], 'email' => $u['email'],
            'avatarUrl' => $u['avatar_url'], 'managerId' => $u['manager_id'], 'jobTitle' => $u['job_title'],
            'fullName' => trim($u['first_name'] . ' ' . ($u['last_name'] ?? '')),
            'role' => ['name' => $u['role_name'], 'key' => $u['role_key'], 'level' => (int) $u['role_level']],
            'children' => [],
        ];
    }
    $roots = [];
    foreach ($byId as $id2 => &$node) {
        if ($node['managerId'] && isset($byId[$node['managerId']])) {
            $byId[$node['managerId']]['children'][] = &$node;
        } else {
            $roots[] = &$node;
        }
    }
    unset($node);
    json_ok($roots);
} elseif ($method === 'POST' && $id === null) {
    require_permission('users', 'create');
    $a = actor();
    $v = validate($body);
    $v->required('firstName', 'First name')->maxLength('firstName', 60);
    $v->required('email', 'Email')->email('email');
    $v->required('roleId', 'Role');
    $v->validate();

    $email = strtolower(trim((string) $body['email']));
    users_assert_can_grant_role($a, $body['roleId']);
    if (db_one('SELECT id FROM users WHERE organization_id = ? AND email = ?', [$a['organizationId'], $email])) {
        throw bad_request('A user with that email already exists in this organisation.');
    }

    $inviteToken = null;
    if (!empty($body['password'])) {
        $strength = check_password_strength((string) $body['password']);
        if (!$strength['ok']) {
            throw bad_request($strength['message']);
        }
        $passwordHash = hash_password((string) $body['password']);
    } else {
        $inviteToken = random_token(32);
        $passwordHash = hash_password(random_token(24));
    }

    $userId = gv_id();
    db_exec(
        'INSERT INTO users (id, organization_id, role_id, first_name, last_name, email, phone, job_title, region, manager_id, password_hash, is_assignable, assignment_weight, email_verified_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $userId, $a['organizationId'], $body['roleId'], trim($body['firstName']), trim((string) ($body['lastName'] ?? '')), $email,
            $body['phone'] ?? null, $body['jobTitle'] ?? null, $body['region'] ?? null, $body['managerId'] ?? null, $passwordHash,
            array_key_exists('isAssignable', $body) ? (bool_val($body['isAssignable']) ? 1 : 0) : 1, (int) ($body['assignmentWeight'] ?? 1),
            !empty($body['password']) ? date('Y-m-d H:i:s') : null,
        ]
    );
    if (!empty($body['teamIds']) && is_array($body['teamIds'])) {
        foreach ($body['teamIds'] as $teamId) {
            db_exec('INSERT INTO team_members (id, team_id, user_id, joined_at) VALUES (?,?,?,NOW())', [gv_id(), $teamId, $userId]);
        }
    }

    $inviteUrl = null;
    if ($inviteToken) {
        db_exec(
            'INSERT INTO auth_tokens (id, user_id, type, token_hash, expires_at, created_at) VALUES (?,?,?,?,?,NOW())',
            [gv_id(), $userId, 'INVITE', sha256($inviteToken), date('Y-m-d H:i:s', time() + 7 * 86400)]
        );
        $inviteUrl = gv_config('app_url') . '/login.html#/invite?token=' . $inviteToken;
        if (!gv_config('mail.host')) {
            error_log("[users] SMTP not configured. Invite link for $email: $inviteUrl");
        }
    }

    $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
    audit_record($a['organizationId'], $a, 'user.created', ['entityType' => 'USER', 'entityId' => $userId, 'entityLabel' => $email, 'changes' => ['roleId' => $body['roleId'], 'managerId' => $body['managerId'] ?? null]]);
    json_created(array_merge(users_shape($user), ['inviteUrl' => $inviteUrl]));
} elseif ($method === 'PATCH' && $id === 'me' && $sub === 'profile') {
    require_auth();
    $a = actor();
    $data = [];
    foreach (['firstName' => 'first_name', 'lastName' => 'last_name', 'phone' => 'phone', 'jobTitle' => 'job_title', 'avatarUrl' => 'avatar_url'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE users SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$a['id']]));
    }
    json_ok(users_shape(db_one('SELECT * FROM users WHERE id = ?', [$a['id']])));
} elseif ($method === 'GET' && $id && $sub === 'permissions') {
    // The "effective permission" view from spec section 11: what the role
    // gives, what was granted or revoked for this person specifically, and the
    // result the server actually enforces.
    require_permission('users', 'read');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }
    $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
    $overrides = user_permission_overrides($user['id']);
    $eff = effective_permissions(
        safe_json($role['permissions'], []),
        $role['data_scope'],
        $role['scope_overrides'] ?? null,
        $overrides
    );
    json_ok([
        'userId' => $user['id'],
        'role' => ['id' => $role['id'], 'key' => $role['key'], 'name' => $role['name'], 'dataScope' => $role['data_scope']],
        'inherited' => $eff['inherited'],
        'special' => $eff['special'],
        'effective' => $eff['permissions'],
        'scopes' => $eff['scopes'],
        'catalogue' => GV_MODULES,
        'dataScopes' => GV_DATA_SCOPES,
    ]);

} elseif ($method === 'PUT' && $id && $sub === 'permissions') {
    require_permission('users', 'manage');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }
    users_assert_can_manage($user['id']);

    $rows = is_array($body['permissions'] ?? null) ? $body['permissions'] : [];
    $modules = gv_module_map();
    $clean = [];
    foreach ($rows as $row) {
        $module = (string) ($row['module'] ?? '');
        $action = (string) ($row['action'] ?? '');
        if (!isset($modules[$module]) || !in_array($action, $modules[$module]['actions'], true)) {
            throw bad_request("Unknown permission: $module:$action");
        }
        $effect = strtoupper((string) ($row['effect'] ?? 'GRANT'));
        if (!in_array($effect, ['GRANT', 'REVOKE'], true)) {
            throw bad_request('Effect must be GRANT or REVOKE.');
        }
        $scope = $row['dataScope'] ?? null;
        if ($scope !== null && $scope !== '' && !is_valid_scope((string) $scope)) {
            throw bad_request('Invalid data scope on ' . $module . ':' . $action);
        }
        // Nobody may hand themselves a power they do not already hold - that
        // would make the permission system self-serve.
        if ($user['id'] === $a['id'] && $effect === 'GRANT' && !has_permission($a, $module, $action)) {
            throw forbidden('You cannot grant yourself a permission you do not already have.');
        }
        // Platform modules are not a tenant's to hand out.
        if (in_array($module, ['clients', 'global_projects'], true) && !is_platform_admin($a)) {
            throw forbidden('Platform permissions can only be granted by a platform administrator.');
        }
        $clean[] = [
            'module' => $module, 'action' => $action, 'effect' => $effect,
            'dataScope' => $scope ?: null, 'reason' => $row['reason'] ?? null,
            'expiresAt' => parse_date($row['expiresAt'] ?? null),
        ];
    }

    db_transaction(function () use ($clean, $user, $a) {
        // Replace wholesale: the UI always sends the complete override set, so
        // removing a row from the payload is how an override is withdrawn.
        db_exec('DELETE FROM user_permissions WHERE user_id = ?', [$user['id']]);
        foreach ($clean as $row) {
            db_exec(
                'INSERT INTO user_permissions
                   (id, organization_id, user_id, module, action, effect, data_scope, reason, granted_by_id, expires_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                [
                    gv_id(), $user['organization_id'], $user['id'], $row['module'], $row['action'],
                    $row['effect'], $row['dataScope'], $row['reason'], $a['id'], $row['expiresAt'],
                ]
            );
        }
    });

    audit_record($a['organizationId'], $a, 'user.permissions_changed', [
        'entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email'],
        'changes' => ['overrides' => $clean],
    ]);

    $role = db_one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
    $eff = effective_permissions(
        safe_json($role['permissions'], []),
        $role['data_scope'],
        $role['scope_overrides'] ?? null,
        user_permission_overrides($user['id'])
    );
    json_ok(['special' => $eff['special'], 'effective' => $eff['permissions'], 'scopes' => $eff['scopes']]);

} elseif ($method === 'GET' && $id) {
    require_permission('users', 'read');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }
    $leadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE owner_id = ?', [$user['id']]);
    $siteVisits = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE owner_id = ? AND site_visit_at IS NOT NULL', [$user['id']]);
    $revenue = (float) (db_scalar('SELECT SUM(COALESCE(expected_value, budget_max)) FROM leads WHERE owner_id = ? AND is_converted = 1', [$user['id']]) ?? 0);
    json_ok(array_merge(users_shape($user), ['stats' => ['leads' => $leadCount, 'siteVisits' => $siteVisits, 'revenue' => $revenue]]));
} elseif ($method === 'PATCH' && $id) {
    require_permission('users', 'update');
    $a = actor();
    $before = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$before) {
        throw not_found('User not found');
    }
    users_assert_can_manage($before['id']);

    $data = [];
    foreach (['firstName' => 'first_name', 'lastName' => 'last_name', 'phone' => 'phone', 'jobTitle' => 'job_title', 'region' => 'region'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if (array_key_exists('email', $body)) {
        $data['email'] = strtolower(trim((string) $body['email']));
    }
    if (array_key_exists('isAssignable', $body)) {
        $data['is_assignable'] = bool_val($body['isAssignable']) ? 1 : 0;
    }
    if (array_key_exists('assignmentWeight', $body)) {
        $data['assignment_weight'] = (int) $body['assignmentWeight'];
    }
    $deactivating = false;
    if (array_key_exists('isActive', $body)) {
        if ($before['id'] === $a['id'] && !$body['isActive']) {
            throw bad_request('You cannot deactivate your own account.');
        }
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
        $deactivating = !bool_val($body['isActive']);
    }
    if (array_key_exists('roleId', $body) && $body['roleId'] !== $before['role_id']) {
        users_assert_can_grant_role($a, $body['roleId']);
        if ($before['id'] === $a['id']) {
            throw forbidden('You cannot change your own role.');
        }
        $data['role_id'] = $body['roleId'];
    }
    if (array_key_exists('managerId', $body)) {
        if ($body['managerId'] === $before['id']) {
            throw bad_request('A user cannot report to themselves.');
        }
        if ($body['managerId']) {
            $cursor = $body['managerId'];
            $seen = [$before['id'] => true];
            while ($cursor) {
                if (isset($seen[$cursor])) {
                    throw bad_request('That would create a reporting loop.');
                }
                $seen[$cursor] = true;
                $cursor = db_scalar('SELECT manager_id FROM users WHERE id = ?', [$cursor]);
            }
        }
        $data['manager_id'] = $body['managerId'] ?: null;
    }

    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE users SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    if (array_key_exists('teamIds', $body) && is_array($body['teamIds'])) {
        if ($body['teamIds']) {
            [$in, $p] = db_in_clause($body['teamIds']);
            db_exec("DELETE FROM team_members WHERE user_id = ? AND team_id NOT IN $in", array_merge([$before['id']], $p));
        } else {
            db_exec('DELETE FROM team_members WHERE user_id = ?', [$before['id']]);
        }
        foreach ($body['teamIds'] as $teamId) {
            if (!db_one('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?', [$teamId, $before['id']])) {
                db_exec('INSERT INTO team_members (id, team_id, user_id, joined_at) VALUES (?,?,?,NOW())', [gv_id(), $teamId, $before['id']]);
            }
        }
    }
    if ($deactivating) {
        db_exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$before['id']]);
    }

    $user = db_one('SELECT * FROM users WHERE id = ?', [$before['id']]);
    $changes = gv_diff($before, $user, ['first_name', 'last_name', 'email', 'phone', 'job_title', 'region', 'role_id', 'manager_id', 'is_active']);
    if ($changes) {
        audit_record($a['organizationId'], $a, isset($data['role_id']) ? 'user.role_changed' : 'user.updated', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email'], 'changes' => $changes]);
    }
    json_ok(users_shape($user));
} elseif ($method === 'POST' && $id && $sub === 'set-password') {
    // An administrator setting a user's password outright, rather than emailing
    // a reset link. Sales teams need this: somebody is locked out, standing at
    // the desk, and the reset email goes to an address they cannot open.
    //
    // It is a powerful thing to hand out, so it is fenced on four sides.
    require_permission('users', 'manage');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }

    // 1. Not yourself. Changing your own password must go through the profile
    //    screen, which asks for the current one - otherwise anyone who finds an
    //    unlocked laptop can take the account without knowing the old password.
    if ($user['id'] === $a['id']) {
        throw bad_request('Change your own password from your profile, where the current one is required.');
    }

    // 2. Inside the part of the organisation you manage.
    users_assert_can_manage($user['id']);

    // 3. Never upward. Scope alone would let anyone with organisation-wide
    //    reach - a business head, say - take the administrator's account.
    $targetRole = db_one('SELECT level, name FROM roles WHERE id = ?', [$user['role_id']]);
    $actorRole = db_one('SELECT level FROM roles WHERE id = ?', [$a['role']['id']]);
    if ($targetRole && $actorRole && (int) $targetRole['level'] < (int) $actorRole['level']) {
        throw forbidden('You cannot set the password of someone with more authority than you.');
    }

    // 4. A platform administrator's account is never reachable from inside a
    //    client company, whatever that company's own roles allow.
    if (gv_bool($user['is_platform_admin']) && !is_platform_admin($a)) {
        throw forbidden('That account is managed by the platform owner.');
    }

    $password = (string) ($body['password'] ?? '');
    $strength = check_password_strength($password);
    if (!$strength['ok']) {
        throw bad_request($strength['message']);
    }

    db_exec('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()), updated_at = NOW() WHERE id = ?',
        [hash_password($password), $user['id']]);

    // Any reset link already in flight is now stale, and every session the
    // person had is signed out - a forced password change that leaves the old
    // session working has not actually taken the account back.
    db_exec("DELETE FROM auth_tokens WHERE user_id = ? AND type IN ('PASSWORD_RESET','INVITE')", [$user['id']]);
    $revoked = (int) db_scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$user['id']]);
    db_exec('DELETE FROM sessions WHERE user_id = ?', [$user['id']]);

    audit_record($a['organizationId'], $a, 'user.password_set', [
        'entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email'],
        'changes' => ['sessionsRevoked' => $revoked],
    ]);

    json_ok([
        'message' => $revoked > 0
            ? "Password updated. $revoked active session(s) were signed out."
            : 'Password updated.',
        'sessionsRevoked' => $revoked,
    ]);

} elseif ($method === 'POST' && $id && $sub === 'reset-password') {
    require_permission('users', 'manage');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }
    users_assert_can_manage($user['id']);
    $token = random_token(32);
    db_exec(
        'INSERT INTO auth_tokens (id, user_id, type, token_hash, expires_at, created_at) VALUES (?,?,?,?,?,NOW())',
        [gv_id(), $user['id'], 'PASSWORD_RESET', sha256($token), date('Y-m-d H:i:s', time() + 86400)]
    );
    audit_record($a['organizationId'], $a, 'user.password_reset_issued', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email']]);
    json_ok(['message' => 'Reset link generated.', 'resetUrl' => gv_config('app_url') . '/login.html#/reset?token=' . $token]);
} elseif ($method === 'DELETE' && $id) {
    require_permission('users', 'delete');
    $a = actor();
    $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$user) {
        throw not_found('User not found');
    }
    if ($user['id'] === $a['id']) {
        throw bad_request('You cannot delete your own account.');
    }
    users_assert_can_manage($user['id']);
    db_exec('UPDATE users SET is_active = 0, is_assignable = 0 WHERE id = ?', [$user['id']]);
    db_exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$user['id']]);
    audit_record($a['organizationId'], $a, 'user.deactivated', ['entityType' => 'USER', 'entityId' => $user['id'], 'entityLabel' => $user['email']]);
    json_ok(['deactivated' => true, 'message' => 'User deactivated. Their records were preserved.']);
} else {
    throw not_found('Unknown users route');
}
