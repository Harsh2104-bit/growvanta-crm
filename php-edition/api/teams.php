<?php
declare(strict_types=1);

/** /api/teams/* — ported from src/routes/teams.js. */

function teams_load_full(string $id): array
{
    $team = db_one(
        'SELECT t.*, m.first_name AS manager_first_name, m.last_name AS manager_last_name, m.email AS manager_email, m.avatar_url AS manager_avatar_url,
                pt.name AS parent_team_name
         FROM teams t LEFT JOIN users m ON m.id = t.manager_id LEFT JOIN teams pt ON pt.id = t.parent_team_id WHERE t.id = ?',
        [$id]
    );
    if (!$team) {
        return [];
    }
    $members = db_all(
        'SELECT tm.team_role, u.id, u.first_name, u.last_name, u.email, u.avatar_url, r.name AS role_name
         FROM team_members tm JOIN users u ON u.id = tm.user_id LEFT JOIN roles r ON r.id = u.role_id WHERE tm.team_id = ?',
        [$id]
    );
    $leadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE team_id = ?', [$id]);
    return teams_shape($team, $members, $leadCount);
}

function teams_shape(array $t, array $members, int $leadCount): array
{
    $out = camelize($t);
    unset($out['managerFirstName'], $out['managerLastName'], $out['managerEmail'], $out['managerAvatarUrl'], $out['parentTeamName']);
    $out['territory'] = safe_json($t['territory'] ?? null, ['countries' => [], 'states' => [], 'cities' => []]);
    $out['manager'] = $t['manager_id'] ? ['id' => $t['manager_id'], 'firstName' => $t['manager_first_name'], 'lastName' => $t['manager_last_name'], 'email' => $t['manager_email'], 'avatarUrl' => $t['manager_avatar_url']] : null;
    $out['parentTeam'] = $t['parent_team_id'] ? ['id' => $t['parent_team_id'], 'name' => $t['parent_team_name']] : null;
    $out['members'] = array_map(fn($m) => [
        'teamRole' => $m['team_role'],
        'user' => ['id' => $m['id'], 'firstName' => $m['first_name'], 'lastName' => $m['last_name'], 'email' => $m['email'], 'avatarUrl' => $m['avatar_url'], 'role' => ['name' => $m['role_name']]],
    ], $members);
    $out['memberCount'] = count($members);
    $out['leadCount'] = $leadCount;
    return $out;
}

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$subId = $rest[2] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $id === null) {
    require_auth();
    $a = actor();
    $sql = 'SELECT * FROM teams WHERE organization_id = ?';
    $params = [$a['organizationId']];
    if (isset($query['isActive'])) {
        $sql .= ' AND is_active = ?';
        $params[] = $query['isActive'] === 'true' ? 1 : 0;
    }
    $sql .= ' ORDER BY name ASC';
    $teams = db_all($sql, $params);
    json_ok(array_map(fn($t) => teams_load_full($t['id']), $teams));
} elseif ($method === 'GET' && $id === 'tree') {
    require_auth();
    $a = actor();
    $teams = db_all('SELECT * FROM teams WHERE organization_id = ? ORDER BY name ASC', [$a['organizationId']]);
    $byId = [];
    foreach ($teams as $t) {
        $byId[$t['id']] = array_merge(teams_load_full($t['id']), ['children' => []]);
    }
    $roots = [];
    foreach ($byId as $tid => &$node) {
        if ($node['parentTeamId'] && isset($byId[$node['parentTeamId']])) {
            $byId[$node['parentTeamId']]['children'][] = &$node;
        } else {
            $roots[] = &$node;
        }
    }
    unset($node);
    json_ok($roots);
} elseif ($method === 'POST' && $id === null) {
    require_permission('teams', 'create');
    $a = actor();
    $v = validate($body);
    $v->required('name', 'Team name')->maxLength('name', 100);
    $v->validate();

    $teamId = gv_id();
    db_exec(
        'INSERT INTO teams (id, organization_id, name, description, parent_team_id, manager_id, region, territory, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$teamId, $a['organizationId'], $body['name'], $body['description'] ?? null, $body['parentTeamId'] ?? null, $body['managerId'] ?? null, $body['region'] ?? null, isset($body['territory']) ? to_json($body['territory']) : null]
    );
    if (!empty($body['memberIds']) && is_array($body['memberIds'])) {
        foreach ($body['memberIds'] as $userId) {
            db_exec('INSERT INTO team_members (id, team_id, user_id, joined_at) VALUES (?,?,?,NOW())', [gv_id(), $teamId, $userId]);
        }
    }
    audit_record($a['organizationId'], $a, 'team.created', ['entityType' => 'TEAM', 'entityId' => $teamId, 'entityLabel' => $body['name']]);
    json_created(teams_load_full($teamId));
} elseif ($method === 'GET' && $id && $sub === null) {
    require_auth();
    $a = actor();
    $team = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$team) {
        throw not_found('Team not found');
    }
    $memberIds = array_column(db_all('SELECT user_id FROM team_members WHERE team_id = ?', [$team['id']]), 'user_id');
    $memberIds[] = '__none__';
    [$in, $p] = db_in_clause($memberIds);
    $leads = (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND (team_id = ? OR owner_id IN $in)", array_merge([$team['organization_id'], $team['id']], $p));
    $converted = (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_converted = 1 AND (team_id = ? OR owner_id IN $in)", array_merge([$team['organization_id'], $team['id']], $p));
    $revenue = (float) (db_scalar("SELECT SUM(COALESCE(expected_value, budget_max)) FROM leads WHERE organization_id = ? AND is_converted = 1 AND (team_id = ? OR owner_id IN $in)", array_merge([$team['organization_id'], $team['id']], $p)) ?? 0);
    $siteVisits = (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND site_visit_at IS NOT NULL AND (team_id = ? OR owner_id IN $in)", array_merge([$team['organization_id'], $team['id']], $p));
    json_ok(array_merge(teams_load_full($team['id']), ['stats' => ['leads' => $leads, 'converted' => $converted, 'revenue' => round2($revenue), 'siteVisits' => $siteVisits]]));
} elseif ($method === 'PATCH' && $id && $sub === null) {
    require_permission('teams', 'update');
    $a = actor();
    $before = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$before) {
        throw not_found('Team not found');
    }
    $data = [];
    foreach (['name' => 'name', 'description' => 'description', 'region' => 'region', 'managerId' => 'manager_id'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = $body['isActive'] ? 1 : 0;
    }
    if (array_key_exists('territory', $body)) {
        $data['territory'] = $body['territory'] ? to_json($body['territory']) : null;
    }
    if (array_key_exists('parentTeamId', $body)) {
        if ($body['parentTeamId'] === $before['id']) {
            throw bad_request('A team cannot be its own parent.');
        }
        if ($body['parentTeamId']) {
            $cursor = $body['parentTeamId'];
            $seen = [$before['id'] => true];
            while ($cursor) {
                if (isset($seen[$cursor])) {
                    throw bad_request('That would create a loop in the team tree.');
                }
                $seen[$cursor] = true;
                $cursor = db_scalar('SELECT parent_team_id FROM teams WHERE id = ?', [$cursor]);
            }
        }
        $data['parent_team_id'] = $body['parentTeamId'] ?: null;
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE teams SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    if (array_key_exists('memberIds', $body) && is_array($body['memberIds'])) {
        if ($body['memberIds']) {
            [$in, $p] = db_in_clause($body['memberIds']);
            db_exec("DELETE FROM team_members WHERE team_id = ? AND user_id NOT IN $in", array_merge([$before['id']], $p));
        } else {
            db_exec('DELETE FROM team_members WHERE team_id = ?', [$before['id']]);
        }
        foreach ($body['memberIds'] as $userId) {
            if (!db_one('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?', [$before['id'], $userId])) {
                db_exec('INSERT INTO team_members (id, team_id, user_id, joined_at) VALUES (?,?,?,NOW())', [gv_id(), $before['id'], $userId]);
            }
        }
    }
    $team = db_one('SELECT * FROM teams WHERE id = ?', [$before['id']]);
    $changes = gv_diff($before, $team, array_keys($data));
    audit_record($a['organizationId'], $a, 'team.updated', ['entityType' => 'TEAM', 'entityId' => $team['id'], 'entityLabel' => $team['name'], 'changes' => $changes]);
    json_ok(teams_load_full($team['id']));
} elseif ($method === 'POST' && $id && $sub === 'members' && $subId === null) {
    require_permission('teams', 'update');
    $a = actor();
    $team = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$team) {
        throw not_found('Team not found');
    }
    $userId = $body['userId'] ?? null;
    $user = $userId ? db_one('SELECT id FROM users WHERE id = ? AND organization_id = ?', [$userId, $team['organization_id']]) : null;
    if (!$user) {
        throw bad_request('Unknown user');
    }
    $existing = db_one('SELECT id FROM team_members WHERE team_id = ? AND user_id = ?', [$team['id'], $userId]);
    $teamRole = $body['teamRole'] ?? 'MEMBER';
    if ($existing) {
        db_exec('UPDATE team_members SET team_role = ? WHERE id = ?', [$teamRole, $existing['id']]);
    } else {
        db_exec('INSERT INTO team_members (id, team_id, user_id, team_role, joined_at) VALUES (?,?,?,?,NOW())', [gv_id(), $team['id'], $userId, $teamRole]);
    }
    json_ok(['added' => true]);
} elseif ($method === 'DELETE' && $id && $sub === 'members' && $subId) {
    require_permission('teams', 'update');
    $a = actor();
    $team = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$team) {
        throw not_found('Team not found');
    }
    db_exec('DELETE FROM team_members WHERE team_id = ? AND user_id = ?', [$team['id'], $subId]);
    json_ok(['removed' => true]);
} elseif ($method === 'DELETE' && $id && $sub === null) {
    require_permission('teams', 'delete');
    $a = actor();
    $team = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$team) {
        throw not_found('Team not found');
    }
    $childCount = (int) db_scalar('SELECT COUNT(*) FROM teams WHERE parent_team_id = ?', [$team['id']]);
    if ($childCount > 0) {
        throw bad_request('Move or delete the child teams first.');
    }
    db_exec('UPDATE leads SET team_id = NULL WHERE team_id = ?', [$team['id']]);
    db_exec('DELETE FROM teams WHERE id = ?', [$team['id']]);
    audit_record($a['organizationId'], $a, 'team.deleted', ['entityType' => 'TEAM', 'entityId' => $team['id'], 'entityLabel' => $team['name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown teams route');
}
