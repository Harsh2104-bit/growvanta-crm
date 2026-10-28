<?php
declare(strict_types=1);

/**
 * /api/whatsapp/* - per-tenant WhatsApp configuration and welcome automation.
 *
 * Secrets are write-only: the access token is stored AES-256-GCM encrypted and
 * this endpoint never returns it, only whether one is present. A Super Admin
 * may configure a client by passing ?organizationId=, which resolve_tenant_param()
 * allows only for platform admins (spec section 27).
 */

$id = $rest[0] ?? null;
$body = request_body();
$query = $_GET;
$a = require_auth();
$orgId = resolve_tenant_param($query['organizationId'] ?? ($body['organizationId'] ?? null));

if ($method === 'GET' && $id === null) {
    require_permission('whatsapp', 'read');
    $s = whatsapp_settings($orgId);
    $verdict = check_whatsapp_configuration($orgId);

    json_ok([
        'organizationId' => $orgId,
        'provider' => $s['provider'],
        'isEnabled' => gv_bool($s['is_enabled']),
        'welcomeEnabled' => gv_bool($s['welcome_enabled']),
        // Presence, never the value.
        'hasAccessToken' => !empty($s['access_token_enc']),
        'phoneNumberId' => $s['phone_number_id'],
        'wabaId' => $s['waba_id'],
        'senderNumber' => $s['sender_number'],
        'apiBaseUrl' => $s['api_base_url'],
        'welcomeTemplateId' => $s['welcome_template_id'],
        'variableMap' => safe_json($s['variable_map'], []),
        'verifiedAt' => $s['verified_at'],
        'lastTestAt' => $s['last_test_at'],
        'lastTestStatus' => $s['last_test_status'],
        'lastTestError' => $s['last_test_error'],
        'configuration' => $verdict,
        'templates' => camelize(db_all(
            "SELECT id, name, `key`, status, body, provider_name FROM message_templates
              WHERE organization_id = ? AND channel = 'WHATSAPP' AND is_active = 1 ORDER BY name ASC",
            [$orgId]
        )),
    ]);

} elseif ($method === 'PATCH' && $id === null) {
    require_permission('whatsapp', 'update');
    $s = whatsapp_settings($orgId);

    $data = [];
    $credentialChanged = false;

    foreach (['phoneNumberId' => 'phone_number_id', 'wabaId' => 'waba_id',
              'senderNumber' => 'sender_number', 'apiBaseUrl' => 'api_base_url'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] === '' ? null : trim((string) $body[$camel]);
            if ($data[$col] !== $s[$col]) {
                $credentialChanged = true;
            }
        }
    }
    // An empty string clears the stored token; a missing key leaves it alone.
    if (array_key_exists('accessToken', $body)) {
        $token = (string) $body['accessToken'];
        $data['access_token_enc'] = $token === '' ? null : gv_encrypt($token);
        $credentialChanged = true;
    }
    if (array_key_exists('welcomeTemplateId', $body)) {
        $templateId = $body['welcomeTemplateId'] ?: null;
        if ($templateId && !db_scalar(
            "SELECT id FROM message_templates WHERE id = ? AND organization_id = ? AND channel = 'WHATSAPP'",
            [$templateId, $orgId]
        )) {
            throw bad_request('That WhatsApp template does not exist.');
        }
        $data['welcome_template_id'] = $templateId;
    }
    if (array_key_exists('variableMap', $body)) {
        $data['variable_map'] = to_json(is_array($body['variableMap']) ? $body['variableMap'] : []);
    }
    if (array_key_exists('isEnabled', $body)) {
        $data['is_enabled'] = gv_bool($body['isEnabled']) ? 1 : 0;
    }
    if (array_key_exists('welcomeEnabled', $body)) {
        // Turning the switch on is always allowed. It produces ORANGE, not
        // GREEN, until the checklist passes - refusing the toggle outright
        // would leave the admin with no way to see what is still missing.
        $data['welcome_enabled'] = gv_bool($body['welcomeEnabled']) ? 1 : 0;
    }

    if ($data) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE whatsapp_settings SET $set, updated_at = NOW() WHERE organization_id = ?",
            array_merge(array_values($data), [$orgId]));
    }
    if ($credentialChanged) {
        comms_invalidate_verification($orgId, 'WHATSAPP');
    }

    audit_record($orgId, $a, 'whatsapp.settings_updated', [
        'entityType' => 'INTEGRATION', 'entityLabel' => 'WhatsApp',
        // Never log the token itself, only that it moved.
        'changes' => array_merge(
            array_diff_key($data, ['access_token_enc' => 1]),
            array_key_exists('access_token_enc', $data) ? ['accessToken' => $data['access_token_enc'] ? 'set' : 'cleared'] : []
        ),
    ]);
    json_ok(check_whatsapp_configuration($orgId));

} elseif ($method === 'POST' && $id === 'test') {
    require_permission('whatsapp', 'test');
    $to = trim((string) ($body['phone'] ?? ''));
    if ($to === '') {
        throw bad_request('Enter a WhatsApp number to send the test to.');
    }
    $result = comms_test_whatsapp($orgId, $to, $a['id']);
    audit_record($orgId, $a, 'whatsapp.test_sent', [
        'entityType' => 'INTEGRATION', 'entityLabel' => 'WhatsApp',
        'changes' => ['to' => $to, 'ok' => $result['ok']],
    ]);
    json_ok([
        'ok' => $result['ok'],
        'error' => $result['ok'] ? null : $result['error'],
        'configuration' => check_whatsapp_configuration($orgId),
    ]);

} elseif ($method === 'GET' && $id === 'logs') {
    require_permission('whatsapp', 'read');
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $total = (int) db_scalar(
        "SELECT COUNT(*) FROM message_logs WHERE organization_id = ? AND channel = 'WHATSAPP'", [$orgId]
    );
    $rows = db_all(
        "SELECT message_logs.*, leads.code AS lead_code
           FROM message_logs LEFT JOIN leads ON leads.id = message_logs.lead_id
          WHERE message_logs.organization_id = ? AND message_logs.channel = 'WHATSAPP'
          ORDER BY message_logs.created_at DESC LIMIT $limit OFFSET $offset",
        [$orgId]
    );
    json_ok(camelize($rows), page_meta($page, $limit, $total));

} else {
    throw not_found('Unknown WhatsApp route.');
}
