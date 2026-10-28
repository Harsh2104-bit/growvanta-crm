<?php
declare(strict_types=1);

/**
 * /api/email/* - per-tenant outgoing email configuration and welcome automation.
 *
 * Same contract as the WhatsApp endpoint: credentials are write-only, a
 * credential change invalidates the previous successful test, and the GREEN
 * verdict is only ever produced by services/Comms.php.
 */

$id = $rest[0] ?? null;
$body = request_body();
$query = $_GET;
$a = require_auth();
$orgId = resolve_tenant_param($query['organizationId'] ?? ($body['organizationId'] ?? null));

if ($method === 'GET' && $id === null) {
    require_permission('email', 'read');
    $s = email_settings($orgId);

    json_ok([
        'organizationId' => $orgId,
        'mode' => $s['mode'],
        'provider' => $s['provider'],
        'isEnabled' => gv_bool($s['is_enabled']),
        'welcomeEnabled' => gv_bool($s['welcome_enabled']),
        // Staff alerts: separate from the buyer-facing welcome message.
        'notifyNewLead' => gv_bool($s['notify_new_lead'] ?? 0),
        'notifyFollowup' => gv_bool($s['notify_followup'] ?? 0),
        'followupLeadMinutes' => (int) ($s['followup_lead_minutes'] ?? 60),
        'notifyCcEmail' => $s['notify_cc_email'] ?? null,
        'notifyDailySummary' => gv_bool($s['notify_daily_summary'] ?? 0),
        'dailySummaryHour' => (int) ($s['daily_summary_hour'] ?? 8),
        'smtpHost' => $s['smtp_host'],
        'smtpPort' => $s['smtp_port'] !== null ? (int) $s['smtp_port'] : null,
        'smtpEncryption' => $s['smtp_encryption'],
        'smtpUsername' => $s['smtp_username'],
        'hasSmtpPassword' => !empty($s['smtp_password_enc']),
        'hasApiKey' => !empty($s['api_key_enc']),
        'fromEmail' => $s['from_email'],
        'fromName' => $s['from_name'],
        'replyTo' => $s['reply_to'],
        'welcomeTemplateId' => $s['welcome_template_id'],
        'variableMap' => safe_json($s['variable_map'], []),
        'verifiedAt' => $s['verified_at'],
        'lastTestAt' => $s['last_test_at'],
        'lastTestStatus' => $s['last_test_status'],
        'lastTestError' => $s['last_test_error'],
        'configuration' => check_email_configuration($orgId),
        'templates' => camelize(db_all(
            "SELECT id, name, `key`, status, subject, body FROM message_templates
              WHERE organization_id = ? AND channel = 'EMAIL' AND is_active = 1 ORDER BY name ASC",
            [$orgId]
        )),
    ]);

} elseif ($method === 'PATCH' && $id === null) {
    require_permission('email', 'update');
    $s = email_settings($orgId);

    $data = [];
    $credentialChanged = false;

    if (array_key_exists('mode', $body)) {
        $mode = strtoupper((string) $body['mode']);
        if (!in_array($mode, ['SMTP', 'API'], true)) {
            throw bad_request('Mode must be SMTP or API.');
        }
        $data['mode'] = $mode;
        $credentialChanged = $mode !== $s['mode'];
    }
    foreach (['smtpHost' => 'smtp_host', 'smtpUsername' => 'smtp_username', 'provider' => 'provider'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] === '' ? null : trim((string) $body[$camel]);
            if ($data[$col] !== $s[$col]) {
                $credentialChanged = true;
            }
        }
    }
    if (array_key_exists('smtpPort', $body)) {
        $data['smtp_port'] = $body['smtpPort'] === '' || $body['smtpPort'] === null ? null : (int) $body['smtpPort'];
        $credentialChanged = true;
    }
    if (array_key_exists('smtpEncryption', $body)) {
        $enc = strtoupper((string) $body['smtpEncryption']);
        if (!in_array($enc, ['NONE', 'TLS', 'SSL'], true)) {
            throw bad_request('Encryption must be NONE, TLS or SSL.');
        }
        $data['smtp_encryption'] = $enc;
        $credentialChanged = true;
    }
    if (array_key_exists('smtpPassword', $body)) {
        $pass = (string) $body['smtpPassword'];
        $data['smtp_password_enc'] = $pass === '' ? null : gv_encrypt($pass);
        $credentialChanged = true;
    }
    if (array_key_exists('apiKey', $body)) {
        $key = (string) $body['apiKey'];
        $data['api_key_enc'] = $key === '' ? null : gv_encrypt($key);
        $credentialChanged = true;
    }
    foreach (['fromEmail' => 'from_email', 'fromName' => 'from_name', 'replyTo' => 'reply_to'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $value = $body[$camel] === '' ? null : trim((string) $body[$camel]);
            if ($value !== null && in_array($col, ['from_email', 'reply_to'], true) && !normalize_email($value)) {
                throw bad_request('Enter a valid email address.');
            }
            $data[$col] = $value;
        }
    }
    if (array_key_exists('welcomeTemplateId', $body)) {
        $templateId = $body['welcomeTemplateId'] ?: null;
        if ($templateId && !db_scalar(
            "SELECT id FROM message_templates WHERE id = ? AND organization_id = ? AND channel = 'EMAIL'",
            [$templateId, $orgId]
        )) {
            throw bad_request('That email template does not exist.');
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
        $data['welcome_enabled'] = gv_bool($body['welcomeEnabled']) ? 1 : 0;
    }
    if (array_key_exists('notifyNewLead', $body)) {
        $data['notify_new_lead'] = gv_bool($body['notifyNewLead']) ? 1 : 0;
    }
    if (array_key_exists('notifyFollowup', $body)) {
        $data['notify_followup'] = gv_bool($body['notifyFollowup']) ? 1 : 0;
    }
    if (array_key_exists('followupLeadMinutes', $body)) {
        // The sweep runs every 15 minutes, so a shorter lead time would simply
        // arrive late and look broken. Clamped rather than rejected.
        $data['followup_lead_minutes'] = max(15, min(1440, (int) $body['followupLeadMinutes']));
    }
    if (array_key_exists('notifyDailySummary', $body)) {
        $data['notify_daily_summary'] = gv_bool($body['notifyDailySummary']) ? 1 : 0;
    }
    if (array_key_exists('dailySummaryHour', $body)) {
        $data['daily_summary_hour'] = max(0, min(23, (int) $body['dailySummaryHour']));
    }
    if (array_key_exists('notifyCcEmail', $body)) {
        $cc = trim((string) $body['notifyCcEmail']);
        if ($cc !== '' && !filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            throw bad_request('The copy-to address is not a valid email address.');
        }
        $data['notify_cc_email'] = $cc !== '' ? $cc : null;
    }

    if ($data) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE email_settings SET $set, updated_at = NOW() WHERE organization_id = ?",
            array_merge(array_values($data), [$orgId]));
    }
    if ($credentialChanged) {
        comms_invalidate_verification($orgId, 'EMAIL');
    }

    audit_record($orgId, $a, 'email.settings_updated', [
        'entityType' => 'INTEGRATION', 'entityLabel' => 'Email',
        'changes' => array_merge(
            array_diff_key($data, ['smtp_password_enc' => 1, 'api_key_enc' => 1]),
            array_key_exists('smtp_password_enc', $data) ? ['smtpPassword' => $data['smtp_password_enc'] ? 'set' : 'cleared'] : [],
            array_key_exists('api_key_enc', $data) ? ['apiKey' => $data['api_key_enc'] ? 'set' : 'cleared'] : []
        ),
    ]);
    json_ok(check_email_configuration($orgId));

} elseif ($method === 'POST' && $id === 'test') {
    require_permission('email', 'test');
    $to = normalize_email((string) ($body['email'] ?? ''));
    if (!$to) {
        throw bad_request('Enter a valid email address to send the test to.');
    }
    $result = comms_test_email($orgId, $to, $a['id']);
    audit_record($orgId, $a, 'email.test_sent', [
        'entityType' => 'INTEGRATION', 'entityLabel' => 'Email',
        'changes' => ['to' => $to, 'ok' => $result['ok']],
    ]);
    json_ok([
        'ok' => $result['ok'],
        'error' => $result['ok'] ? null : $result['error'],
        'configuration' => check_email_configuration($orgId),
    ]);

} elseif ($method === 'GET' && $id === 'logs') {
    require_permission('email', 'read');
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $total = (int) db_scalar(
        "SELECT COUNT(*) FROM message_logs WHERE organization_id = ? AND channel = 'EMAIL'", [$orgId]
    );
    $rows = db_all(
        "SELECT message_logs.*, leads.code AS lead_code
           FROM message_logs LEFT JOIN leads ON leads.id = message_logs.lead_id
          WHERE message_logs.organization_id = ? AND message_logs.channel = 'EMAIL'
          ORDER BY message_logs.created_at DESC LIMIT $limit OFFSET $offset",
        [$orgId]
    );
    json_ok(camelize($rows), page_meta($page, $limit, $total));

} else {
    throw not_found('Unknown email route.');
}
