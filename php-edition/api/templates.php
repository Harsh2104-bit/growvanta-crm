<?php
declare(strict_types=1);

/**
 * /api/templates/* - WhatsApp and email message templates.
 *
 * Templates are tenant-scoped. Validation here is what stops a template from
 * silently rendering blanks in production: every {{variable}} must either be a
 * known context variable or be explicitly mapped, and that same check feeds
 * the ORANGE/GREEN verdict in services/Comms.php.
 */

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;
$a = require_auth();
$orgId = $a['organizationId'];

function templates_shape(array $row): array
{
    $out = camelize($row);
    $out['variables'] = template_variables((string) $row['body'] . ' ' . (string) ($row['subject'] ?? ''));
    $out['unknownVariables'] = array_values(array_diff($out['variables'], GV_TEMPLATE_VARIABLES));
    return $out;
}

if ($method === 'GET' && $id === null) {
    require_permission('templates', 'read');
    $where = ['organization_id = ?'];
    $params = [$orgId];
    if (!empty($query['channel'])) {
        $where[] = 'channel = ?';
        $params[] = strtoupper($query['channel']);
    }
    if (($query['activeOnly'] ?? '') === 'true') {
        $where[] = 'is_active = 1';
    }
    $rows = db_all(
        'SELECT * FROM message_templates WHERE ' . implode(' AND ', $where) . ' ORDER BY channel ASC, name ASC',
        $params
    );
    json_ok(array_map('templates_shape', $rows));

} elseif ($method === 'GET' && $id === 'variables') {
    require_permission('templates', 'read');
    json_ok([
        'supported' => GV_TEMPLATE_VARIABLES,
        'sample' => [
            'customer_name' => 'Rahul Sharma',
            'project_name' => 'ABC Residency',
            'project_location' => 'Sector 84, Gurugram',
            'company_name' => (string) db_scalar('SELECT name FROM organizations WHERE id = ?', [$orgId]),
            'sales_person_name' => trim(full_name($a['firstName'], $a['lastName'])),
        ],
    ]);

} elseif ($method === 'POST' && $id === 'preview') {
    require_permission('templates', 'read');
    // Renders against sample data so an author can see the result before the
    // template ever touches a customer.
    $sample = array_merge(array_fill_keys(GV_TEMPLATE_VARIABLES, ''), [
        'customer_name' => 'Rahul Sharma', 'first_name' => 'Rahul', 'last_name' => 'Sharma',
        'phone' => '+91 98765 43210', 'email' => 'rahul@example.com',
        'project_name' => 'ABC Residency', 'project_location' => 'Sector 84, Gurugram',
        'company_name' => (string) db_scalar('SELECT name FROM organizations WHERE id = ?', [$orgId]),
        'sales_person_name' => trim(full_name($a['firstName'], $a['lastName'])),
        'sales_person_phone' => '+91 90000 00000', 'lead_code' => 'LD-1001',
        'budget' => '75,00,000', 'configuration' => '3 BHK', 'lead_source' => 'Meta Ads',
    ], is_array($body['context'] ?? null) ? $body['context'] : []);

    json_ok([
        'subject' => template_render((string) ($body['subject'] ?? ''), $sample),
        'body' => template_render((string) ($body['body'] ?? ''), $sample),
        'variables' => template_variables((string) ($body['body'] ?? '') . ' ' . (string) ($body['subject'] ?? '')),
    ]);

} elseif ($method === 'GET' && $id !== null) {
    require_permission('templates', 'read');
    $row = db_one('SELECT * FROM message_templates WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Template not found.');
    }
    json_ok(templates_shape($row));

} elseif ($method === 'POST' && $id === null) {
    require_permission('templates', 'create');
    $channel = strtoupper(trim((string) ($body['channel'] ?? '')));
    if (!in_array($channel, ['WHATSAPP', 'EMAIL'], true)) {
        throw bad_request('Channel must be WHATSAPP or EMAIL.');
    }
    $name = trim((string) ($body['name'] ?? ''));
    $text = trim((string) ($body['body'] ?? ''));
    if ($name === '' || $text === '') {
        throw bad_request('Template name and body are required.');
    }
    if ($channel === 'EMAIL' && trim((string) ($body['subject'] ?? '')) === '') {
        throw bad_request('Email templates need a subject line.');
    }

    $key = slugify($body['key'] ?? $name) ?: ('tpl-' . bin2hex(random_bytes(3)));
    if (db_scalar('SELECT id FROM message_templates WHERE organization_id = ? AND `key` = ?', [$orgId, $key])) {
        $key .= '-' . bin2hex(random_bytes(2));
    }

    $templateId = gv_id();
    db_exec(
        'INSERT INTO message_templates
           (id, organization_id, channel, name, `key`, provider_name, language, subject, body,
            variables, category, status, is_active, created_by_id, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $templateId, $orgId, $channel, $name, $key, $body['providerName'] ?? null,
            $body['language'] ?? 'en', $body['subject'] ?? null, $text,
            to_json(template_variables($text . ' ' . (string) ($body['subject'] ?? ''))),
            $body['category'] ?? 'UTILITY',
            // WhatsApp templates need provider approval; claiming APPROVED here
            // would be the exact "fake green" the spec forbids.
            $channel === 'WHATSAPP' ? 'DRAFT' : (($body['status'] ?? 'DRAFT') === 'APPROVED' ? 'APPROVED' : 'DRAFT'),
            !empty($body['isActive']) ? 1 : 1, $a['id'],
        ]
    );
    audit_record($orgId, $a, 'template.created', [
        'entityType' => 'TEMPLATE', 'entityId' => $templateId, 'entityLabel' => $name,
        'changes' => ['channel' => $channel],
    ]);
    json_created(templates_shape(db_one('SELECT * FROM message_templates WHERE id = ?', [$templateId])));

} elseif ($method === 'PATCH' && $id !== null) {
    require_permission('templates', 'update');
    $row = db_one('SELECT * FROM message_templates WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Template not found.');
    }

    $map = ['name' => 'name', 'providerName' => 'provider_name', 'language' => 'language',
        'subject' => 'subject', 'body' => 'body', 'category' => 'category'];
    $data = [];
    foreach ($map as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel];
        }
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = gv_bool($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('status', $body)) {
        $status = strtoupper((string) $body['status']);
        if (!in_array($status, ['DRAFT', 'PENDING', 'APPROVED', 'REJECTED'], true)) {
            throw bad_request('Invalid template status.');
        }
        $data['status'] = $status;
    }
    if (isset($data['body']) || isset($data['subject'])) {
        $text = ($data['body'] ?? $row['body']) . ' ' . ($data['subject'] ?? $row['subject'] ?? '');
        $data['variables'] = to_json(template_variables($text));
        // Editing the wording of an approved WhatsApp template invalidates the
        // provider's approval, so it drops back to DRAFT unless explicitly set.
        if ($row['channel'] === 'WHATSAPP' && $row['status'] === 'APPROVED' && !array_key_exists('status', $body)) {
            $data['status'] = 'DRAFT';
        }
    }

    if ($data) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE message_templates SET $set, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$id]));
    }
    $after = db_one('SELECT * FROM message_templates WHERE id = ?', [$id]);
    audit_record($orgId, $a, 'template.updated', [
        'entityType' => 'TEMPLATE', 'entityId' => $id, 'entityLabel' => $after['name'],
        'changes' => gv_diff($row, $after, array_keys($data)),
    ]);
    json_ok(templates_shape($after));

} elseif ($method === 'DELETE' && $id !== null) {
    require_permission('templates', 'delete');
    $row = db_one('SELECT * FROM message_templates WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Template not found.');
    }
    // A template wired into a live welcome automation cannot just vanish -
    // that would silently drop the channel to ORANGE with no explanation.
    $inUse = db_scalar('SELECT id FROM whatsapp_settings WHERE welcome_template_id = ?', [$id])
        ?: db_scalar('SELECT id FROM email_settings WHERE welcome_template_id = ?', [$id]);
    if ($inUse) {
        throw conflict('This template is used by a welcome automation. Pick a different template there first.');
    }
    db_exec('DELETE FROM message_templates WHERE id = ?', [$id]);
    audit_record($orgId, $a, 'template.deleted', [
        'entityType' => 'TEMPLATE', 'entityId' => $id, 'entityLabel' => $row['name'], 'changes' => [],
    ]);
    json_ok(['deleted' => true]);

} else {
    throw not_found('Unknown templates route.');
}
