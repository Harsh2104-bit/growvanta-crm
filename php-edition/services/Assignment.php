<?php
declare(strict_types=1);

/**
 * Lead distribution engine — ported from src/services/assignment.js. Rules
 * run in `priority` order; the first match wins unless it opts to keep going.
 * Round-robin fairness survives across requests because the rotation cursor
 * lives on the rule row in MySQL, incremented atomically in the same
 * statement that reads it (no in-memory state to lose between PHP requests).
 */

const GV_ASSIGNMENT_STRATEGIES = [
    ['key' => 'ROUND_ROBIN', 'label' => 'Round robin across selected users'],
    ['key' => 'LOAD_BALANCED', 'label' => 'Least open leads first'],
    ['key' => 'USER', 'label' => 'Always a specific user'],
    ['key' => 'TEAM', 'label' => 'Team queue (team set, owner left open)'],
    ['key' => 'TEAM_ROUND_ROBIN', 'label' => 'Round robin inside a team'],
    ['key' => 'MANAGER', 'label' => 'The team manager'],
];

function assignable_users(string $orgId, array $userIds): array
{
    if (!$userIds) {
        return [];
    }
    [$in, $p] = db_in_clause($userIds);
    $users = db_all(
        "SELECT id, assignment_weight FROM users WHERE organization_id = ? AND id IN $in AND is_active = 1 AND is_assignable = 1 ORDER BY created_at ASC",
        array_merge([$orgId], $p)
    );
    $expanded = [];
    foreach ($users as $u) {
        $weight = max(1, min(10, (int) $u['assignment_weight']));
        for ($i = 0; $i < $weight; $i++) {
            $expanded[] = $u['id'];
        }
    }
    return $expanded;
}

function team_member_ids(string $teamId): array
{
    $rows = db_all('SELECT user_id FROM team_members WHERE team_id = ?', [$teamId]);
    return array_column($rows, 'user_id');
}

function least_loaded_user(string $orgId, array $candidateIds): ?string
{
    $unique = array_values(array_unique($candidateIds));
    if (!$unique) {
        return null;
    }
    [$in, $p] = db_in_clause($unique);
    $rows = db_all(
        "SELECT l.owner_id, COUNT(*) AS cnt FROM leads l
         JOIN lead_statuses s ON s.id = l.status_id
         WHERE l.organization_id = ? AND l.owner_id IN $in AND l.is_converted = 0 AND l.is_duplicate = 0
           AND s.is_lost = 0 AND s.is_junk = 0
         GROUP BY l.owner_id",
        array_merge([$orgId], $p)
    );
    $byUser = [];
    foreach ($rows as $r) {
        $byUser[$r['owner_id']] = (int) $r['cnt'];
    }
    $best = $unique[0];
    $bestCount = $byUser[$best] ?? 0;
    foreach ($unique as $id) {
        $count = $byUser[$id] ?? 0;
        if ($count < $bestCount) {
            $best = $id;
            $bestCount = $count;
        }
    }
    return $best;
}

/** Advances a rule's rotation cursor atomically and returns the index consumed. */
function next_cursor(string $ruleId): int
{
    db_exec('UPDATE assignment_rules SET cursor_pos = cursor_pos + 1, assigned_count = assigned_count + 1 WHERE id = ?', [$ruleId]);
    $row = db_one('SELECT cursor_pos FROM assignment_rules WHERE id = ?', [$ruleId]);
    return ((int) $row['cursor_pos']) - 1;
}

function resolve_assignment_target(array $rule, array $lead): array
{
    $targetUserIds = safe_json($rule['target_user_ids'], []);
    $orgId = $lead['organizationId'];

    switch ($rule['strategy']) {
        case 'USER':
            $pool = assignable_users($orgId, array_slice($targetUserIds, 0, 1));
            return ['ownerId' => $pool[0] ?? null, 'teamId' => $rule['target_team_id'] ?: null];

        case 'TEAM':
            return ['ownerId' => null, 'teamId' => $rule['target_team_id'] ?: null];

        case 'MANAGER':
            if (!$rule['target_team_id']) {
                return ['ownerId' => null, 'teamId' => null];
            }
            $team = db_one('SELECT manager_id FROM teams WHERE id = ?', [$rule['target_team_id']]);
            return ['ownerId' => $team['manager_id'] ?? null, 'teamId' => $rule['target_team_id']];

        case 'TEAM_ROUND_ROBIN':
            if (!$rule['target_team_id']) {
                return ['ownerId' => null, 'teamId' => null];
            }
            $pool = assignable_users($orgId, team_member_ids($rule['target_team_id']));
            if (!$pool) {
                return ['ownerId' => null, 'teamId' => $rule['target_team_id']];
            }
            $idx = next_cursor($rule['id']);
            return ['ownerId' => $pool[$idx % count($pool)], 'teamId' => $rule['target_team_id']];

        case 'LOAD_BALANCED':
            $base = $targetUserIds ?: ($rule['target_team_id'] ? team_member_ids($rule['target_team_id']) : []);
            $pool = assignable_users($orgId, $base);
            if (!$pool) {
                return ['ownerId' => null, 'teamId' => $rule['target_team_id'] ?: null];
            }
            db_exec('UPDATE assignment_rules SET assigned_count = assigned_count + 1 WHERE id = ?', [$rule['id']]);
            return ['ownerId' => least_loaded_user($orgId, $pool), 'teamId' => $rule['target_team_id'] ?: null];

        case 'ROUND_ROBIN':
        default:
            $base = $targetUserIds ?: ($rule['target_team_id'] ? team_member_ids($rule['target_team_id']) : []);
            $pool = assignable_users($orgId, $base);
            if (!$pool) {
                return ['ownerId' => null, 'teamId' => $rule['target_team_id'] ?: null];
            }
            $idx = next_cursor($rule['id']);
            return ['ownerId' => $pool[$idx % count($pool)], 'teamId' => $rule['target_team_id'] ?: null];
    }
}

/** @return array{ownerId:?string,teamId:?string,ruleId:string,ruleName:string}|null */
function resolve_assignment(array $lead): ?array
{
    $rules = db_all(
        'SELECT * FROM assignment_rules WHERE organization_id = ? AND is_active = 1 ORDER BY priority ASC, created_at ASC',
        [$lead['organizationId']]
    );
    $result = null;
    foreach ($rules as $rule) {
        if (!evaluate_group($lead, $rule['conditions'])) {
            continue;
        }
        $target = resolve_assignment_target($rule, $lead);
        if (!$target['ownerId'] && !$target['teamId']) {
            continue;
        }
        $result = array_merge($target, ['ruleId' => $rule['id'], 'ruleName' => $rule['name']]);
        if (gv_bool($rule['stop_on_match'])) {
            break;
        }
    }
    return $result;
}

/** Org-wide load-balanced fallback used when no rule matched. */
function fallback_round_robin(string $orgId): ?array
{
    $org = db_one('SELECT settings FROM organizations WHERE id = ?', [$orgId]);
    $settings = safe_json($org['settings'] ?? '{}', []);
    if (($settings['autoAssignFallback'] ?? true) === false) {
        return null;
    }
    $users = db_all('SELECT id FROM users WHERE organization_id = ? AND is_active = 1 AND is_assignable = 1 ORDER BY created_at ASC', [$orgId]);
    if (!$users) {
        return null;
    }
    return ['ownerId' => least_loaded_user($orgId, array_column($users, 'id')), 'teamId' => null, 'ruleName' => 'Default load balancing'];
}
