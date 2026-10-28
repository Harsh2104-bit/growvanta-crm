<?php
declare(strict_types=1);

/** /api/saved-views/* — ported from src/routes/savedViews.js. */

function saved_views_shape(array $v): array
{
    $out = camelize($v);
    $out['filters'] = safe_json($v['filters'] ?? '{}', []);
    $out['columns'] = safe_json($v['columns'] ?? '[]', []);
    $out['sort'] = safe_json($v['sort'] ?? '{}', []);
    return $out;
}

$id = $rest[0] ?? null;
$body = request_body();
$query = $_GET;
$a = require_auth();

if ($method === 'GET' && $id === null) {
    // Own views plus anything a colleague has shared organisation-wide.
    $params = [$a['organizationId']];
    $sql = 'SELECT sv.*, u.first_name AS user_first_name, u.last_name AS user_last_name
            FROM saved_views sv LEFT JOIN users u ON u.id = sv.user_id
            WHERE sv.organization_id = ?';
    if (!empty($query['entity'])) {
        $sql .= ' AND sv.entity = ?';
        $params[] = (string) $query['entity'];
    }
    $sql .= ' AND (sv.user_id = ? OR sv.is_shared = 1) ORDER BY sv.is_default DESC, sv.name ASC';
    $params[] = $a['id'];
    $rows = db_all($sql, $params);
    json_ok(array_map(function ($r) use ($a) {
        $out = saved_views_shape($r);
        unset($out['userFirstName'], $out['userLastName']);
        $out['user'] = ['firstName' => $r['user_first_name'], 'lastName' => $r['user_last_name']];
        $out['owned'] = $r['user_id'] === $a['id'];
        return $out;
    }, $rows));
} elseif ($method === 'POST' && $id === null) {
    $v = validate($body);
    $v->required('entity', 'Entity')->in('entity', ['LEAD', 'OPPORTUNITY', 'TASK', 'CONTACT', 'ACCOUNT']);
    $v->required('name', 'View name')->maxLength('name', 80);
    $v->validate();

    if (!empty($body['isDefault'])) {
        db_exec('UPDATE saved_views SET is_default = 0 WHERE user_id = ? AND entity = ?', [$a['id'], $body['entity']]);
    }
    $viewId = gv_id();
    db_exec(
        'INSERT INTO saved_views (id, organization_id, user_id, entity, name, filters, columns, sort, is_shared, is_default, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $viewId, $a['organizationId'], $a['id'], $body['entity'], $body['name'],
            to_json($body['filters'] ?? []), to_json($body['columns'] ?? []), to_json($body['sort'] ?? []),
            !empty($body['isShared']) ? 1 : 0, !empty($body['isDefault']) ? 1 : 0,
        ]
    );
    json_created(saved_views_shape(db_one('SELECT * FROM saved_views WHERE id = ?', [$viewId])));
} elseif ($method === 'PATCH' && $id) {
    $view = db_one('SELECT * FROM saved_views WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$view) {
        throw not_found('View not found');
    }
    if ($view['user_id'] !== $a['id']) {
        throw forbidden('You can only edit views you created.');
    }
    if (!empty($body['isDefault'])) {
        db_exec('UPDATE saved_views SET is_default = 0 WHERE user_id = ? AND entity = ?', [$a['id'], $view['entity']]);
    }
    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    foreach (['filters' => 'filters', 'columns' => 'columns', 'sort' => 'sort'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = to_json($body[$camel]);
        }
    }
    foreach (['isShared' => 'is_shared', 'isDefault' => 'is_default'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ? 1 : 0;
        }
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE saved_views SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$view['id']]));
    }
    json_ok(saved_views_shape(db_one('SELECT * FROM saved_views WHERE id = ?', [$view['id']])));
} elseif ($method === 'DELETE' && $id) {
    $view = db_one('SELECT * FROM saved_views WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$view) {
        throw not_found('View not found');
    }
    if ($view['user_id'] !== $a['id']) {
        throw forbidden('You can only delete views you created.');
    }
    db_exec('DELETE FROM saved_views WHERE id = ?', [$view['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown saved-views route');
}
