<?php
declare(strict_types=1);

/** /api/targets/* — ported from src/routes/targets.js. */

const TARGET_METRICS = ['LEADS', 'CALLS', 'MEETINGS', 'QUALIFIED', 'OPPORTUNITIES', 'CONVERSIONS', 'REVENUE'];
const TARGET_PERIODS = ['MONTH', 'QUARTER', 'YEAR'];

/** @return array{start: string, end: string} DATE bounds for the period containing $startInput. */
function targets_period_bounds(string $period, $startInput): array
{
    $ts = $startInput ? strtotime((string) $startInput) : time();
    if ($ts === false) {
        $ts = time();
    }
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);

    if ($period === 'MONTH') {
        return ['start' => date('Y-m-01', $ts), 'end' => date('Y-m-t', $ts)];
    }
    if ($period === 'QUARTER') {
        $startMonth = intdiv($month - 1, 3) * 3 + 1;
        $endMonth = $startMonth + 2;
        return [
            'start' => sprintf('%04d-%02d-01', $year, $startMonth),
            'end' => date('Y-m-t', mktime(0, 0, 0, $endMonth, 1, $year)),
        ];
    }
    return ['start' => "$year-01-01", 'end' => "$year-12-31"];
}

/** Resolves the achieved value for a target's metric, scope and window. */
function targets_measure_actual(string $orgId, array $target)
{
    $from = $target['period_start'] . ' 00:00:00';
    $to = $target['period_end'] . ' 23:59:59';

    $memberIds = null;
    if ($target['user_id']) {
        $memberIds = [$target['user_id']];
    } elseif ($target['team_id']) {
        $memberIds = array_column(db_all('SELECT user_id FROM team_members WHERE team_id = ?', [$target['team_id']]), 'user_id');
        if (!$memberIds) {
            return 0;
        }
    }

    $ownerSql = '';
    $userSql = '';
    $memberParams = [];
    if ($memberIds) {
        [$in, $p] = db_in_clause($memberIds);
        $ownerSql = " AND owner_id IN $in";
        $userSql = " AND user_id IN $in";
        $memberParams = $p;
    }

    switch ($target['metric']) {
        case 'LEADS':
            return (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND created_at >= ? AND created_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'QUALIFIED':
            return (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND qualified_at >= ? AND qualified_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'CONVERSIONS':
            return (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND converted_at >= ? AND converted_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'CALLS':
            return (int) db_scalar("SELECT COUNT(*) FROM activities WHERE organization_id = ? AND type = 'CALL' AND occurred_at >= ? AND occurred_at <= ?$userSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'MEETINGS':
            return (int) db_scalar("SELECT COUNT(*) FROM activities WHERE organization_id = ? AND type = 'MEETING' AND occurred_at >= ? AND occurred_at <= ?$userSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'SITE_VISITS':
            return (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND site_visit_at >= ? AND site_visit_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'BOOKINGS':
            return (int) db_scalar("SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_converted = 1 AND converted_at >= ? AND converted_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams));
        case 'REVENUE':
            // Booking value: the lead's own budget, since there is no deal record.
            return round2((float) (db_scalar("SELECT SUM(COALESCE(expected_value, budget_max)) FROM leads WHERE organization_id = ? AND is_converted = 1 AND converted_at >= ? AND converted_at <= ?$ownerSql", array_merge([$orgId, $from, $to], $memberParams)) ?? 0));
        default:
            return 0;
    }
}

/** Upserts one target row: one target per (owner, metric, periodStart). */
function targets_upsert(string $orgId, ?string $userId, ?string $teamId, string $metric, string $period, array $bounds, float $value): array
{
    $existing = db_one(
        'SELECT * FROM targets WHERE organization_id = ? AND metric = ? AND period_start = ?'
        . ($userId ? ' AND user_id = ?' : ' AND user_id IS NULL')
        . ($teamId ? ' AND team_id = ?' : ' AND team_id IS NULL'),
        array_merge([$orgId, $metric, $bounds['start']], $userId ? [$userId] : [], $teamId ? [$teamId] : [])
    );
    if ($existing) {
        db_exec('UPDATE targets SET period = ?, period_end = ?, target_value = ?, updated_at = NOW() WHERE id = ?', [$period, $bounds['end'], $value, $existing['id']]);
        return ['row' => db_one('SELECT * FROM targets WHERE id = ?', [$existing['id']]), 'existed' => true];
    }
    $targetId = gv_id();
    db_exec(
        'INSERT INTO targets (id, organization_id, user_id, team_id, metric, period, period_start, period_end, target_value, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$targetId, $orgId, $userId, $teamId, $metric, $period, $bounds['start'], $bounds['end'], $value]
    );
    return ['row' => db_one('SELECT * FROM targets WHERE id = ?', [$targetId]), 'existed' => false];
}

$id = $rest[0] ?? null;
$body = request_body();
$query = $_GET;
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $id === 'metrics') {
    require_permission('targets', 'read');
    json_ok(['metrics' => TARGET_METRICS, 'periods' => TARGET_PERIODS]);
} elseif ($method === 'GET' && $id === null) {
    require_permission('targets', 'read');
    $scope = scope_info();
    $clauses = ['t.organization_id = ?'];
    $params = [$orgId];
    foreach (['userId' => 'user_id', 'teamId' => 'team_id', 'period' => 'period'] as $key => $col) {
        if (!empty($query[$key])) {
            $clauses[] = "t.$col = ?";
            $params[] = (string) $query[$key];
        }
    }
    if (!$scope['unrestricted']) {
        [$uIn, $uP] = db_in_clause($scope['userIds']);
        [$tIn, $tP] = db_in_clause($scope['teamIds']);
        $clauses[] = "(t.user_id IN $uIn OR t.team_id IN $tIn)";
        $params = array_merge($params, $uP, $tP);
    }
    $rows = db_all(
        'SELECT t.*, u.first_name AS user_first_name, u.last_name AS user_last_name, tm.name AS team_name
         FROM targets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN teams tm ON tm.id = t.team_id
         WHERE ' . implode(' AND ', $clauses) . ' ORDER BY t.period_start DESC, t.metric ASC',
        $params
    );

    // Attainment is computed live so the number always matches the reports.
    json_ok(array_map(function ($r) use ($orgId) {
        $out = camelize($r);
        unset($out['userFirstName'], $out['userLastName'], $out['teamName']);
        $out['user'] = $r['user_id'] ? ['id' => $r['user_id'], 'firstName' => $r['user_first_name'], 'lastName' => $r['user_last_name']] : null;
        $out['team'] = $r['team_id'] ? ['id' => $r['team_id'], 'name' => $r['team_name']] : null;
        $out['ownerLabel'] = $r['user_id'] ? trim($r['user_first_name'] . ' ' . ($r['user_last_name'] ?? '')) : ($r['team_name'] ?: 'Organisation');
        $actual = targets_measure_actual($orgId, $r);
        $out['actual'] = $actual;
        $out['attainment'] = pct($actual, (float) $r['target_value']);
        return $out;
    }, $rows));
} elseif ($method === 'POST' && $id === 'bulk') {
    require_permission('targets', 'create');
    $userIds = $body['userIds'] ?? null;
    $metric = $body['metric'] ?? '';
    $period = $body['period'] ?? '';
    if (!is_array($userIds) || !$userIds) {
        throw bad_request('Select at least one user');
    }
    if (!in_array($metric, TARGET_METRICS, true)) {
        throw bad_request('Unknown metric');
    }
    if (!in_array($period, TARGET_PERIODS, true)) {
        throw bad_request('Unknown period');
    }
    $bounds = targets_period_bounds($period, $body['periodStart'] ?? null);
    $value = (float) ($body['targetValue'] ?? 0);
    $count = 0;
    foreach ($userIds as $userId) {
        targets_upsert($orgId, $userId, null, $metric, $period, $bounds, $value);
        $count++;
    }
    audit_record($orgId, $a, 'target.bulk_set', ['entityType' => 'TARGET', 'entityLabel' => "$count users", 'changes' => ['metric' => $metric, 'period' => $period, 'targetValue' => $value]]);
    json_ok(['updated' => $count]);
} elseif ($method === 'POST' && $id === null) {
    require_permission('targets', 'create');
    $v = validate($body);
    $v->required('metric', 'Metric')->in('metric', TARGET_METRICS);
    $v->required('period', 'Period')->in('period', TARGET_PERIODS);
    $v->required('periodStart', 'Period start');
    $v->validate();

    $userId = $body['userId'] ?? null;
    $teamId = $body['teamId'] ?? null;
    if (!$userId && !$teamId) {
        throw bad_request('Set the target on a user or a team.');
    }
    if ($userId && $teamId) {
        throw bad_request('A target belongs to either a user or a team, not both.');
    }
    $bounds = targets_period_bounds($body['period'], $body['periodStart']);
    $result = targets_upsert($orgId, $userId ?: null, $teamId ?: null, $body['metric'], $body['period'], $bounds, (float) ($body['targetValue'] ?? 0));
    $row = $result['row'];
    audit_record($orgId, $a, $result['existed'] ? 'target.updated' : 'target.created', [
        'entityType' => 'TARGET', 'entityId' => $row['id'], 'entityLabel' => "{$row['metric']} {$row['period']}", 'changes' => ['targetValue' => $row['target_value']],
    ]);
    json_created(camelize($row));
} elseif ($method === 'PATCH' && $id) {
    require_permission('targets', 'update');
    $target = db_one('SELECT * FROM targets WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$target) {
        throw not_found('Target not found');
    }
    if (!isset($body['targetValue']) || !is_numeric($body['targetValue'])) {
        throw bad_request('targetValue must be a number');
    }
    db_exec('UPDATE targets SET target_value = ?, updated_at = NOW() WHERE id = ?', [(float) $body['targetValue'], $target['id']]);
    json_ok(camelize(db_one('SELECT * FROM targets WHERE id = ?', [$target['id']])));
} elseif ($method === 'DELETE' && $id) {
    require_permission('targets', 'delete');
    $target = db_one('SELECT * FROM targets WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$target) {
        throw not_found('Target not found');
    }
    db_exec('DELETE FROM targets WHERE id = ?', [$target['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown targets route');
}
