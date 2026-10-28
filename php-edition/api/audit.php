<?php
declare(strict_types=1);

/**
 * /api/audit-logs/* — ported from src/routes/audit.js.
 *
 * Read and export only. There is deliberately no POST, PATCH or DELETE: the
 * trail is written by the server as a side effect of real operations and must
 * stay immutable to be worth anything in a dispute.
 */

function audit_route_where(array $query, string $orgId): array
{
    $clauses = ['audit_logs.organization_id = ?'];
    $params = [$orgId];
    foreach (['userId' => 'user_id', 'entityType' => 'entity_type', 'entityId' => 'entity_id'] as $key => $col) {
        if (!empty($query[$key])) {
            $clauses[] = "audit_logs.$col = ?";
            $params[] = (string) $query[$key];
        }
    }
    if (!empty($query['action'])) {
        $clauses[] = 'audit_logs.action LIKE ?';
        $params[] = '%' . $query['action'] . '%';
    }
    if (!empty($query['q'])) {
        $term = '%' . trim((string) $query['q']) . '%';
        $clauses[] = '(audit_logs.action LIKE ? OR audit_logs.entity_label LIKE ? OR audit_logs.actor_label LIKE ?)';
        array_push($params, $term, $term, $term);
    }
    $from = parse_date($query['from'] ?? null);
    $to = parse_date($query['to'] ?? null);
    if ($from) {
        $clauses[] = 'audit_logs.created_at >= ?';
        $params[] = $from;
    }
    if ($to) {
        $clauses[] = 'audit_logs.created_at <= ?';
        $params[] = $to;
    }
    return ['sql' => 'WHERE ' . implode(' AND ', $clauses), 'params' => $params];
}

const AUDIT_JOIN_FROM = 'audit_logs LEFT JOIN users u ON u.id = audit_logs.user_id';
const AUDIT_JOIN_SELECT = 'audit_logs.*, u.first_name AS user_first_name, u.last_name AS user_last_name, u.email AS user_email, u.avatar_url AS user_avatar_url';

$id = $rest[0] ?? null;
$query = $_GET;
$a = actor();

if ($method === 'GET' && $id === null) {
    require_permission('audit', 'read');
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $where = audit_route_where($query, $a['organizationId']);
    $rows = db_all('SELECT ' . AUDIT_JOIN_SELECT . ' FROM ' . AUDIT_JOIN_FROM . " {$where['sql']} ORDER BY audit_logs.created_at DESC LIMIT ? OFFSET ?", array_merge($where['params'], [$limit, $offset]));
    $total = (int) db_scalar('SELECT COUNT(*) FROM ' . AUDIT_JOIN_FROM . " {$where['sql']}", $where['params']);
    json_ok(array_map(function ($r) {
        $out = camelize($r);
        unset($out['userFirstName'], $out['userLastName'], $out['userEmail'], $out['userAvatarUrl']);
        $out['changes'] = safe_json($r['changes'] ?? '{}', []);
        $out['user'] = $r['user_id'] ? ['id' => $r['user_id'], 'firstName' => $r['user_first_name'], 'lastName' => $r['user_last_name'], 'email' => $r['user_email'], 'avatarUrl' => $r['user_avatar_url']] : null;
        return $out;
    }, $rows), page_meta($page, $limit, $total));
} elseif ($method === 'GET' && $id === 'actions') {
    require_permission('audit', 'read');
    $rows = db_all('SELECT action, COUNT(*) AS cnt FROM audit_logs WHERE organization_id = ? GROUP BY action ORDER BY cnt DESC LIMIT 100', [$a['organizationId']]);
    json_ok(array_map(fn($r) => ['action' => $r['action'], 'count' => (int) $r['cnt']], $rows));
} elseif ($method === 'GET' && $id === 'export') {
    require_permission('audit', 'export');
    $where = audit_route_where($query, $a['organizationId']);
    $rows = db_all('SELECT ' . AUDIT_JOIN_SELECT . ' FROM ' . AUDIT_JOIN_FROM . " {$where['sql']} ORDER BY audit_logs.created_at DESC LIMIT 20000", $where['params']);

    // Exporting the audit trail is itself an auditable event.
    audit_record($a['organizationId'], $a, 'audit.exported', ['entityLabel' => count($rows) . ' entries', 'changes' => ['count' => count($rows), 'filters' => $query]]);

    $csv = to_csv($rows, [
        ['key' => 'created_at', 'label' => 'Timestamp'],
        ['label' => 'User', 'value' => fn($r) => $r['user_id'] ? trim($r['user_first_name'] . ' ' . $r['user_last_name']) : ($r['actor_label'] ?: 'system')],
        ['label' => 'Email', 'value' => fn($r) => $r['user_email'] ?? ''],
        ['key' => 'action', 'label' => 'Action'],
        ['key' => 'entity_type', 'label' => 'Entity Type'],
        ['key' => 'entity_id', 'label' => 'Entity ID'],
        ['key' => 'entity_label', 'label' => 'Entity'],
        ['label' => 'Changes', 'value' => fn($r) => $r['changes']],
        ['key' => 'ip', 'label' => 'IP'],
        ['key' => 'user_agent', 'label' => 'User Agent'],
    ]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="growvanta-audit-' . time() . '.csv"');
    echo $csv;
} else {
    throw not_found('Unknown audit route');
}
