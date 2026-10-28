<?php
declare(strict_types=1);

/** /api/notifications/* — ported from src/routes/notifications.js. Always personal: the actor's own rows only. */

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$query = $_GET;

$a = require_auth();

if ($method === 'GET' && $id === null) {
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $clauses = ['user_id = ?'];
    $params = [$a['id']];
    if (($query['unread'] ?? null) === 'true') {
        $clauses[] = 'is_read = 0';
    }
    if (!empty($query['type'])) {
        $clauses[] = 'type = ?';
        $params[] = (string) $query['type'];
    }
    $where = 'WHERE ' . implode(' AND ', $clauses);
    $rows = db_all("SELECT * FROM notifications $where ORDER BY created_at DESC LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));
    $total = (int) db_scalar("SELECT COUNT(*) FROM notifications $where", $params);
    $unread = (int) db_scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$a['id']]);
    json_ok(array_map('camelize', $rows), array_merge(page_meta($page, $limit, $total), ['unread' => $unread]));
} elseif ($method === 'GET' && $id === 'unread-count') {
    json_ok(['unread' => (int) db_scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$a['id']])]);
} elseif ($method === 'POST' && $id === 'read-all') {
    $count = db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0', [$a['id']]);
    json_ok(['updated' => $count]);
} elseif ($method === 'POST' && $id === 'sweep') {
    json_ok(run_due_sweep());
} elseif ($method === 'POST' && $id && $sub === 'read') {
    $row = db_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $a['id']]);
    if (!$row) {
        throw not_found('Notification not found');
    }
    db_exec('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?', [$row['id']]);
    json_ok(camelize(db_one('SELECT * FROM notifications WHERE id = ?', [$row['id']])));
} elseif ($method === 'DELETE' && $id) {
    $row = db_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $a['id']]);
    if (!$row) {
        throw not_found('Notification not found');
    }
    db_exec('DELETE FROM notifications WHERE id = ?', [$row['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown notifications route');
}
