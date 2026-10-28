<?php
declare(strict_types=1);

/** /api/webhooks/* — outbound webhook configuration, ported from src/routes/webhooks.js. */

function webhooks_validate_events(array $events): array
{
    $bad = array_filter($events, fn($e) => $e !== '*' && !in_array($e, GV_WEBHOOK_EVENTS, true));
    if ($bad) {
        throw bad_request('Unknown event(s): ' . implode(', ', $bad));
    }
    return $events;
}

/**
 * Outbound URLs are restricted to https and non-loopback hosts in production:
 * a webhook pointing at localhost or a private range would turn this endpoint
 * into an SSRF primitive against the CRM's own network.
 */
function webhooks_assert_safe_url(string $raw): string
{
    $parts = parse_url($raw);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        throw bad_request('Enter a full https:// URL');
    }
    if (gv_config('env') === 'production') {
        if (strtolower($parts['scheme']) !== 'https') {
            throw bad_request('Webhook URLs must use https in production.');
        }
        $host = strtolower($parts['host']);
        $isPrivate = $host === 'localhost' || $host === '::1'
            || preg_match('/^127\./', $host) || preg_match('/^10\./', $host)
            || preg_match('/^192\.168\./', $host) || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host)
            || str_ends_with($host, '.internal');
        if ($isPrivate) {
            throw bad_request('Webhook URLs cannot point at private or loopback addresses.');
        }
    }
    return $raw;
}

function webhooks_new_secret(): string
{
    return 'whsec_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $id === 'events') {
    require_permission('webhooks', 'read');
    json_ok(GV_WEBHOOK_EVENTS);
} elseif ($method === 'GET' && $id === null) {
    require_permission('webhooks', 'read');
    $rows = db_all(
        'SELECT w.*, (SELECT COUNT(*) FROM webhook_deliveries WHERE webhook_id = w.id) AS delivery_count
         FROM webhooks w WHERE w.organization_id = ? ORDER BY w.created_at DESC',
        [$orgId]
    );
    // The secret is needed by the integrator to verify signatures, so it is
    // shown to admins who already hold webhooks:read.
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['events' => safe_json($r['events'] ?? '[]', []), 'deliveryCount' => (int) $r['delivery_count']]), $rows));
} elseif ($method === 'GET' && $id && $sub === 'deliveries') {
    require_permission('webhooks', 'read');
    $hook = db_one('SELECT * FROM webhooks WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$hook) {
        throw not_found('Webhook not found');
    }
    $rows = db_all('SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY created_at DESC LIMIT 50', [$hook['id']]);
    json_ok(array_map('camelize', $rows));
} elseif ($method === 'POST' && $id === null) {
    require_permission('webhooks', 'create');
    $v = validate($body);
    $v->required('name', 'Webhook name')->maxLength('name', 80);
    $v->required('url', 'URL');
    $v->validate();
    $events = $body['events'] ?? [];
    if (!is_array($events) || !$events) {
        throw bad_request('Choose at least one event');
    }
    webhooks_validate_events($events);
    $url = webhooks_assert_safe_url((string) $body['url']);

    $hookId = gv_id();
    db_exec(
        'INSERT INTO webhooks (id, organization_id, name, url, secret, events, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
        [$hookId, $orgId, $body['name'], $url, webhooks_new_secret(), to_json($events), array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1]
    );
    $row = db_one('SELECT * FROM webhooks WHERE id = ?', [$hookId]);
    audit_record($orgId, $a, 'webhook.created', ['entityType' => 'WEBHOOK', 'entityId' => $hookId, 'entityLabel' => $row['name'], 'changes' => ['url' => $url, 'events' => $events]]);
    json_created(array_merge(camelize($row), ['events' => $events]));
} elseif ($method === 'POST' && $id && $sub === 'test') {
    require_permission('webhooks', 'update');
    $hook = db_one('SELECT * FROM webhooks WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$hook) {
        throw not_found('Webhook not found');
    }
    json_ok(send_test_webhook($hook));
} elseif ($method === 'POST' && $id && $sub === 'rotate-secret') {
    require_permission('webhooks', 'update');
    $hook = db_one('SELECT * FROM webhooks WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$hook) {
        throw not_found('Webhook not found');
    }
    $secret = webhooks_new_secret();
    db_exec('UPDATE webhooks SET secret = ? WHERE id = ?', [$secret, $hook['id']]);
    audit_record($orgId, $a, 'webhook.secret_rotated', ['entityType' => 'WEBHOOK', 'entityId' => $hook['id'], 'entityLabel' => $hook['name']]);
    json_ok(['secret' => $secret]);
} elseif ($method === 'PATCH' && $id) {
    require_permission('webhooks', 'update');
    $before = db_one('SELECT * FROM webhooks WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Webhook not found');
    }
    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('url', $body)) {
        $data['url'] = webhooks_assert_safe_url((string) $body['url']);
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('events', $body)) {
        webhooks_validate_events($body['events']);
        $data['events'] = to_json($body['events']);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE webhooks SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    $row = db_one('SELECT * FROM webhooks WHERE id = ?', [$before['id']]);
    json_ok(array_merge(camelize($row), ['events' => safe_json($row['events'] ?? '[]', [])]));
} elseif ($method === 'DELETE' && $id) {
    require_permission('webhooks', 'delete');
    $hook = db_one('SELECT * FROM webhooks WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$hook) {
        throw not_found('Webhook not found');
    }
    db_exec('DELETE FROM webhooks WHERE id = ?', [$hook['id']]);
    audit_record($orgId, $a, 'webhook.deleted', ['entityType' => 'WEBHOOK', 'entityId' => $hook['id'], 'entityLabel' => $hook['name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown webhooks route');
}
