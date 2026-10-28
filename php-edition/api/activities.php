<?php
declare(strict_types=1);

/** /api/activities/* — ported from src/routes/activities.js. */

const ACTIVITIES_COMMUNICATION_TYPES = ['CALL', 'EMAIL', 'MEETING', 'SMS', 'WHATSAPP'];
const ACTIVITIES_IMMUTABLE_TYPES = ['SYSTEM', 'STATUS_CHANGE', 'ASSIGNMENT', 'CONVERSION', 'SCORE_CHANGE'];

const ACTIVITIES_JOIN_SELECT = "activities.*,
    u.first_name AS user_first_name, u.last_name AS user_last_name, u.avatar_url AS user_avatar_url,
    l.code AS lead_code, l.first_name AS lead_first_name, l.last_name AS lead_last_name,
    op.code AS opportunity_code, op.name AS opportunity_name,
    ct.first_name AS contact_first_name, ct.last_name AS contact_last_name";
const ACTIVITIES_JOIN_FROM = 'activities
    LEFT JOIN users u ON u.id = activities.user_id
    LEFT JOIN leads l ON l.id = activities.lead_id
    LEFT JOIN opportunities op ON op.id = activities.opportunity_id
    LEFT JOIN contacts ct ON ct.id = activities.contact_id';

function activities_shape(array $r): array
{
    $a = camelize($r);
    foreach (['userFirstName', 'userLastName', 'userAvatarUrl', 'leadCode', 'leadFirstName', 'leadLastName', 'opportunityCode', 'opportunityName', 'contactFirstName', 'contactLastName'] as $k) {
        unset($a[$k]);
    }
    $a['meta'] = safe_json($r['meta'] ?? '{}', []);
    $a['user'] = $r['user_id'] ? ['id' => $r['user_id'], 'firstName' => $r['user_first_name'], 'lastName' => $r['user_last_name'], 'avatarUrl' => $r['user_avatar_url']] : null;
    $a['lead'] = $r['lead_id'] ? ['id' => $r['lead_id'], 'code' => $r['lead_code'], 'firstName' => $r['lead_first_name'], 'lastName' => $r['lead_last_name']] : null;
    $a['opportunity'] = $r['opportunity_id'] ? ['id' => $r['opportunity_id'], 'code' => $r['opportunity_code'], 'name' => $r['opportunity_name']] : null;
    $a['contact'] = $r['contact_id'] ? ['id' => $r['contact_id'], 'firstName' => $r['contact_first_name'], 'lastName' => $r['contact_last_name']] : null;
    return $a;
}

$id = $rest[0] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $id === null) {
    require_permission('activities', 'read');
    $a = actor();
    $scope = scope_info();
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);

    $clauses = ['activities.organization_id = ?'];
    $params = [$a['organizationId']];
    if (!$scope['unrestricted']) {
        [$in, $p] = db_in_clause($scope['userIds']);
        $clauses[] = "(activities.user_id IN $in OR activities.user_id IS NULL)";
        $params = array_merge($params, $p);
    }
    if (!empty($query['channel']) && $query['channel'] === 'communication') {
        [$in, $p] = db_in_clause(ACTIVITIES_COMMUNICATION_TYPES);
        $clauses[] = "activities.type IN $in";
        $params = array_merge($params, $p);
    } elseif (!empty($query['type'])) {
        $types = array_values(array_filter(array_map('trim', explode(',', (string) $query['type']))));
        if ($types) {
            [$in, $p] = db_in_clause($types);
            $clauses[] = "activities.type IN $in";
            $params = array_merge($params, $p);
        }
    }
    foreach (['userId' => 'user_id', 'leadId' => 'lead_id', 'opportunityId' => 'opportunity_id', 'direction' => 'direction'] as $key => $col) {
        if (!empty($query[$key])) {
            $clauses[] = "activities.$col = ?";
            $params[] = (string) $query[$key];
        }
    }
    $range = date_range($query);
    [$dateSql, $dateParams] = date_filter_clause('activities.occurred_at', $range);
    if ($dateSql) {
        $clauses[] = $dateSql;
        $params = array_merge($params, $dateParams);
    }
    $where = 'WHERE ' . implode(' AND ', $clauses);

    $rows = db_all('SELECT ' . ACTIVITIES_JOIN_SELECT . ' FROM ' . ACTIVITIES_JOIN_FROM . " $where ORDER BY activities.occurred_at DESC LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));
    $total = (int) db_scalar('SELECT COUNT(*) FROM ' . ACTIVITIES_JOIN_FROM . " $where", $params);
    json_ok(array_map('activities_shape', $rows), page_meta($page, $limit, $total));
} elseif ($method === 'GET' && $id === 'communication-summary') {
    require_permission('activities', 'read');
    $a = actor();
    $scope = scope_info();
    $clauses = ['organization_id = ?'];
    [$in, $p] = db_in_clause(ACTIVITIES_COMMUNICATION_TYPES);
    $clauses[] = "type IN $in";
    $params = array_merge([$a['organizationId']], $p);
    if (!$scope['unrestricted']) {
        [$in2, $p2] = db_in_clause($scope['userIds']);
        $clauses[] = "user_id IN $in2";
        $params = array_merge($params, $p2);
    }
    $range = date_range($query);
    [$dateSql, $dateParams] = date_filter_clause('occurred_at', $range);
    if ($dateSql) {
        $clauses[] = $dateSql;
        $params = array_merge($params, $dateParams);
    }
    $where = 'WHERE ' . implode(' AND ', $clauses);

    $byType = db_all("SELECT type, COUNT(*) AS cnt FROM activities $where GROUP BY type", $params);
    $byDirection = db_all("SELECT direction, COUNT(*) AS cnt FROM activities $where GROUP BY direction", $params);
    $byOutcome = db_all("SELECT outcome, COUNT(*) AS cnt FROM activities $where AND type = 'CALL' GROUP BY outcome", $params);
    $durations = db_one("SELECT SUM(duration_sec) AS sum_sec, AVG(duration_sec) AS avg_sec FROM activities $where AND type = 'CALL'", $params);

    json_ok([
        'byChannel' => array_map(fn($r) => ['channel' => $r['type'], 'count' => (int) $r['cnt']], $byType),
        'byDirection' => array_map(fn($r) => ['direction' => $r['direction'] ?? 'UNSPECIFIED', 'count' => (int) $r['cnt']], $byDirection),
        'callOutcomes' => array_map(fn($r) => ['outcome' => $r['outcome'] ?? 'Not recorded', 'count' => (int) $r['cnt']], $byOutcome),
        'totalCallSeconds' => (int) ($durations['sum_sec'] ?? 0),
        'averageCallSeconds' => (int) round((float) ($durations['avg_sec'] ?? 0)),
    ]);
} elseif ($method === 'PATCH' && $id) {
    require_permission('activities', 'update');
    $a = actor();
    $activity = db_one('SELECT * FROM activities WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$activity) {
        throw not_found('Activity not found');
    }
    if ($activity['user_id'] !== $a['id']) {
        throw forbidden('You can only edit activities you logged.');
    }
    if (in_array($activity['type'], ACTIVITIES_IMMUTABLE_TYPES, true)) {
        throw forbidden('System timeline entries cannot be edited.');
    }
    $data = [];
    foreach (['subject' => 'subject', 'body' => 'body', 'outcome' => 'outcome', 'direction' => 'direction'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if (array_key_exists('durationSec', $body)) {
        $data['duration_sec'] = $body['durationSec'] !== null && $body['durationSec'] !== '' ? (int) $body['durationSec'] : null;
    }
    if (array_key_exists('occurredAt', $body)) {
        $data['occurred_at'] = parse_date($body['occurredAt']) ?? $activity['occurred_at'];
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE activities SET $sets WHERE id = ?", array_merge(array_values($data), [$activity['id']]));
    }
    json_ok(activities_shape(db_one('SELECT ' . ACTIVITIES_JOIN_SELECT . ' FROM ' . ACTIVITIES_JOIN_FROM . ' WHERE activities.id = ?', [$activity['id']])));
} elseif ($method === 'DELETE' && $id) {
    require_permission('activities', 'delete');
    $a = actor();
    $activity = db_one('SELECT * FROM activities WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$activity) {
        throw not_found('Activity not found');
    }
    if (in_array($activity['type'], ACTIVITIES_IMMUTABLE_TYPES, true)) {
        throw forbidden('System timeline entries cannot be deleted - they are the audit trail.');
    }
    db_exec('DELETE FROM activities WHERE id = ?', [$activity['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown activities route');
}
