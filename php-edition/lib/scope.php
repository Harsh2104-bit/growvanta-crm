<?php
declare(strict_types=1);

/**
 * Hierarchy-based data visibility — ported from src/lib/scope.js.
 *
 * Every list/read query is narrowed to the set of users the actor may see,
 * derived from two independent trees walked breadth-first in PHP (not a MySQL
 * recursive CTE, so this still works on the MySQL 5.7 that many shared cPanel
 * hosts still ship):
 *
 *   - the reporting line   (users.manager_id)
 *   - the team tree        (teams.parent_team_id + team_members)
 *
 * Results are cached for one request (there is no cross-request cache here —
 * unlike the long-lived Node process, a PHP request is already short-lived, so
 * the only win worth having is not re-walking the tree twice in one request).
 */

function scope_reporting_descendants(string $orgId, string $rootUserId): array
{
    $rows = db_all(
        'SELECT id, manager_id FROM users WHERE organization_id = ? AND manager_id IS NOT NULL',
        [$orgId]
    );
    $childrenOf = [];
    foreach ($rows as $r) {
        $childrenOf[$r['manager_id']][] = $r['id'];
    }
    $out = [];
    $queue = [$rootUserId];
    while ($queue) {
        $current = array_shift($queue);
        foreach ($childrenOf[$current] ?? [] as $child) {
            if (isset($out[$child])) {
                continue;
            }
            $out[$child] = true;
            $queue[] = $child;
        }
    }
    return array_keys($out);
}

function scope_teams_for_user(string $orgId, string $userId): array
{
    $memberships = db_all('SELECT team_id FROM team_members WHERE user_id = ?', [$userId]);
    $managed = db_all('SELECT id FROM teams WHERE organization_id = ? AND manager_id = ?', [$orgId, $userId]);
    $ids = array_merge(array_column($memberships, 'team_id'), array_column($managed, 'id'));
    return array_values(array_unique($ids));
}

function scope_expand_team_tree(string $orgId, array $rootTeamIds): array
{
    $teams = db_all('SELECT id, parent_team_id FROM teams WHERE organization_id = ?', [$orgId]);
    $childrenOf = [];
    foreach ($teams as $t) {
        if ($t['parent_team_id']) {
            $childrenOf[$t['parent_team_id']][] = $t['id'];
        }
    }
    $out = [];
    foreach ($rootTeamIds as $id) {
        $out[$id] = true;
    }
    $queue = $rootTeamIds;
    while ($queue) {
        $current = array_shift($queue);
        foreach ($childrenOf[$current] ?? [] as $child) {
            if (isset($out[$child])) {
                continue;
            }
            $out[$child] = true;
            $queue[] = $child;
        }
    }
    return array_keys($out);
}

function scope_users_in_teams(array $teamIds): array
{
    if (!$teamIds) {
        return [];
    }
    [$inClause, $params] = db_in_clause($teamIds);
    $members = db_all("SELECT user_id FROM team_members WHERE team_id IN $inClause", $params);
    $managers = db_all("SELECT manager_id FROM teams WHERE id IN $inClause AND manager_id IS NOT NULL", $params);
    return array_values(array_unique(array_merge(
        array_column($members, 'user_id'),
        array_column($managers, 'manager_id')
    )));
}

/**
 * Resolves what the actor can see.
 * Returns: ['unrestricted' => bool, 'userIds' => [...], 'teamIds' => [...],
 *           'canSeeUnassigned' => bool, 'scope' => string, 'rank' => int]
 */
function resolve_scope(array $actor, ?string $scopeKey = null): array
{
    // $scopeKey lets one actor resolve different visibility per permission:
    // read the whole region but export only your own rows (spec section 12).
    $scope = $scopeKey ?: ($actor['role']['dataScope'] ?? 'OWN');
    $cacheKey = $actor['id'] . '|' . $actor['organizationId'] . '|' . $scope;
    static $cache = [];
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $ranks = gv_scope_rank();
    $rank = $ranks[$scope] ?? 1;
    $orgId = $actor['organizationId'];

    $userIds = [$actor['id'] => true];
    $teamIds = [];
    $unrestricted = false;

    if ($rank >= $ranks['ORGANIZATION']) {
        $unrestricted = true;
        $allTeams = db_all('SELECT id FROM teams WHERE organization_id = ?', [$orgId]);
        $teamIds = array_column($allTeams, 'id');
    } elseif ($rank === $ranks['REGION']) {
        $region = $actor['region'] ?? null;
        $peers = $region
            ? db_all('SELECT id FROM users WHERE organization_id = ? AND region = ?', [$orgId, $region])
            : db_all('SELECT id FROM users WHERE organization_id = ? AND id = ?', [$orgId, $actor['id']]);
        foreach ($peers as $u) {
            $userIds[$u['id']] = true;
        }
        $regionTeams = $region
            ? db_all('SELECT id FROM teams WHERE organization_id = ? AND region = ?', [$orgId, $region])
            : db_all('SELECT id FROM teams WHERE organization_id = ? AND manager_id = ?', [$orgId, $actor['id']]);
        $teamIds = scope_expand_team_tree($orgId, array_column($regionTeams, 'id'));
        foreach (scope_reporting_descendants($orgId, $actor['id']) as $id) {
            $userIds[$id] = true;
        }
    } elseif ($rank === $ranks['TEAM_AND_BELOW']) {
        $own = scope_teams_for_user($orgId, $actor['id']);
        $teamIds = scope_expand_team_tree($orgId, $own);
        foreach (scope_users_in_teams($teamIds) as $id) {
            $userIds[$id] = true;
        }
        foreach (scope_reporting_descendants($orgId, $actor['id']) as $id) {
            $userIds[$id] = true;
        }
    } elseif ($rank === $ranks['TEAM']) {
        $teamIds = scope_teams_for_user($orgId, $actor['id']);
        foreach (scope_users_in_teams($teamIds) as $id) {
            $userIds[$id] = true;
        }
        $directs = db_all('SELECT id FROM users WHERE organization_id = ? AND manager_id = ?', [$orgId, $actor['id']]);
        foreach ($directs as $u) {
            $userIds[$u['id']] = true;
        }
    } else {
        $teamIds = scope_teams_for_user($orgId, $actor['id']);
    }

    $perms = $actor['permissions'] ?? [];
    $canSeeUnassigned = $unrestricted
        || $rank >= $ranks['TEAM_AND_BELOW']
        || in_array('assign', $perms['leads'] ?? [], true);

    $value = [
        'unrestricted' => $unrestricted,
        'userIds' => array_keys($userIds),
        'teamIds' => array_values(array_unique($teamIds)),
        'canSeeUnassigned' => $canSeeUnassigned,
        'scope' => $scope,
        'rank' => $rank,
    ];
    $cache[$cacheKey] = $value;
    return $value;
}

/**
 * Builds a SQL WHERE fragment (with `?` placeholders and matching params)
 * restricting rows to the actor's visibility. Returns ['sql' => string, 'params' => array]
 * where sql is either '' (no restriction) or '(...)' ready to AND into a query.
 */
function visibility_where(array $scopeInfo, string $ownerColumn = 'owner_id', ?string $teamColumn = 'team_id', bool $includeUnassigned = true): array
{
    if ($scopeInfo['unrestricted']) {
        return ['sql' => '', 'params' => []];
    }
    $clauses = [];
    $params = [];

    [$in, $p] = db_in_clause($scopeInfo['userIds']);
    $clauses[] = "$ownerColumn IN $in";
    $params = array_merge($params, $p);

    if ($teamColumn && $scopeInfo['teamIds']) {
        [$in2, $p2] = db_in_clause($scopeInfo['teamIds']);
        $clauses[] = "$teamColumn IN $in2";
        $params = array_merge($params, $p2);
    }
    if ($includeUnassigned && $scopeInfo['canSeeUnassigned']) {
        $clauses[] = "$ownerColumn IS NULL";
    }
    return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
}

function can_act_on_user(array $actor, string $targetUserId): bool
{
    if ($actor['id'] === $targetUserId) {
        return true;
    }
    $scopeInfo = resolve_scope($actor);
    return $scopeInfo['unrestricted'] || in_array($targetUserId, $scopeInfo['userIds'], true);
}
