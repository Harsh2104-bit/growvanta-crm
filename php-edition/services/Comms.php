<?php
declare(strict_types=1);

/**
 * WhatsApp + Email: configuration-aware automation.
 *
 * The rule this file exists to enforce (spec sections 31, 35, 37) is that a
 * channel is GREEN only when it could actually deliver a message right now.
 * Flipping the switch on gets you ORANGE, not GREEN.
 *
 *   RED     the tenant turned the automation off
 *   ORANGE  turned on, but at least one required item is missing or unverified
 *   GREEN   turned on, every item present, credentials proven by a live test
 *
 * check_*_configuration() is the single source of that verdict: the settings
 * screen renders it, the Super Admin overview aggregates it, and - crucially -
 * the send path re-runs it server-side before every message, so a stale or
 * doctored frontend cannot cause a send (section 37).
 */

const GV_COMMS_STATUS_OFF = 'RED';
const GV_COMMS_STATUS_INCOMPLETE = 'ORANGE';
const GV_COMMS_STATUS_ACTIVE = 'GREEN';

/** Variables every template may use; anything else must be explicitly mapped. */
const GV_TEMPLATE_VARIABLES = [
    'customer_name', 'first_name', 'last_name', 'phone', 'email',
    'project_name', 'project_location', 'company_name', 'sales_person_name',
    'sales_person_phone', 'lead_code', 'lead_source', 'budget', 'configuration',
];

// ---------------------------------------------------------------------------
// Settings rows
// ---------------------------------------------------------------------------
function whatsapp_settings(string $orgId): array
{
    $row = db_one('SELECT * FROM whatsapp_settings WHERE organization_id = ?', [$orgId]);
    if (!$row) {
        db_exec(
            'INSERT INTO whatsapp_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())',
            [gv_id(), $orgId]
        );
        $row = db_one('SELECT * FROM whatsapp_settings WHERE organization_id = ?', [$orgId]);
    }
    return $row ?: [];
}

function email_settings(string $orgId): array
{
    $row = db_one('SELECT * FROM email_settings WHERE organization_id = ?', [$orgId]);
    if (!$row) {
        db_exec(
            'INSERT INTO email_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())',
            [gv_id(), $orgId]
        );
        $row = db_one('SELECT * FROM email_settings WHERE organization_id = ?', [$orgId]);
    }
    return $row ?: [];
}

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------
/** Pulls {{variable}} names out of a template body/subject. */
function template_variables(string $body): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $body, $m);
    return array_values(array_unique($m[1] ?? []));
}

/**
 * A template is only usable if every variable it references can actually be
 * filled - either it is a known context variable, or the channel config maps
 * it. Returns the unresolvable ones.
 */
function template_unmapped_variables(?array $template, $variableMap): array
{
    if (!$template) {
        return [];
    }
    $map = is_array($variableMap) ? $variableMap : safe_json($variableMap, []);
    $used = template_variables((string) $template['body'] . ' ' . (string) ($template['subject'] ?? ''));
    $unmapped = [];
    foreach ($used as $var) {
        if (in_array($var, GV_TEMPLATE_VARIABLES, true)) {
            continue;
        }
        if (array_key_exists($var, $map) && $map[$var] !== '' && $map[$var] !== null) {
            continue;
        }
        $unmapped[] = $var;
    }
    return $unmapped;
}

/** Builds the substitution context for one lead. */
function template_context(array $lead, ?array $org = null, ?array $owner = null, ?array $project = null): array
{
    return [
        'customer_name' => trim(full_name($lead['first_name'] ?? null, $lead['last_name'] ?? null)),
        'first_name' => (string) ($lead['first_name'] ?? ''),
        'last_name' => (string) ($lead['last_name'] ?? ''),
        'phone' => (string) ($lead['phone'] ?? ''),
        'email' => (string) ($lead['email'] ?? ''),
        'lead_code' => (string) ($lead['code'] ?? ''),
        'budget' => $lead['budget_max'] ? number_format((float) $lead['budget_max']) : '',
        'configuration' => (string) ($lead['configuration'] ?? ''),
        'lead_source' => (string) ($lead['source_name'] ?? ''),
        'project_name' => (string) ($project['name'] ?? ''),
        'project_location' => (string) ($project['location'] ?? ''),
        'company_name' => (string) ($org['name'] ?? ''),
        'sales_person_name' => $owner ? trim(full_name($owner['first_name'] ?? null, $owner['last_name'] ?? null)) : '',
        'sales_person_phone' => (string) ($owner['phone'] ?? ''),
    ];
}

function template_render(string $body, array $context, $variableMap = null): string
{
    $map = is_array($variableMap) ? $variableMap : safe_json($variableMap, []);
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($context, $map) {
        $key = $m[1];
        if (array_key_exists($key, $context)) {
            return (string) $context[$key];
        }
        // A mapped variable points at another context key, or is a literal.
        if (isset($map[$key])) {
            return (string) ($context[$map[$key]] ?? $map[$key]);
        }
        return '';
    }, $body) ?? $body;
}

// ---------------------------------------------------------------------------
// The three-state verdict
// ---------------------------------------------------------------------------
function comms_verdict(bool $welcomeEnabled, array $checklist): array
{
    $missing = [];
    foreach ($checklist as $item) {
        if (!empty($item['required']) && empty($item['ok'])) {
            $missing[] = $item['label'];
        }
    }
    $configured = $missing === [];

    if (!$welcomeEnabled) {
        $status = GV_COMMS_STATUS_OFF;
    } elseif ($configured) {
        $status = GV_COMMS_STATUS_ACTIVE;
    } else {
        $status = GV_COMMS_STATUS_INCOMPLETE;
    }

    return [
        'enabled' => $welcomeEnabled,
        'configured' => $configured,
        'status' => $status,
        'checklist' => $checklist,
        'missing' => $missing,
        // The single boolean the send path consults. Note it is NOT simply
        // `enabled` - that is the whole point of this module.
        'readyToSend' => $welcomeEnabled && $configured,
    ];
}

function check_whatsapp_configuration(string $orgId): array
{
    $s = whatsapp_settings($orgId);
    $template = !empty($s['welcome_template_id'])
        ? db_one('SELECT * FROM message_templates WHERE id = ? AND organization_id = ?', [$s['welcome_template_id'], $orgId])
        : null;
    $unmapped = template_unmapped_variables($template, $s['variable_map'] ?? null);

    $checklist = [
        ['key' => 'api', 'label' => 'WhatsApp API connected', 'required' => true,
            'ok' => !empty($s['access_token_enc'])],
        ['key' => 'waba', 'label' => 'Business account connected', 'required' => true,
            'ok' => !empty($s['waba_id'])],
        ['key' => 'sender', 'label' => 'Sender number configured', 'required' => true,
            'ok' => !empty($s['phone_number_id']) && !empty($s['sender_number'])],
        ['key' => 'credentials', 'label' => 'API credentials valid', 'required' => true,
            'ok' => gv_bool($s['credentials_valid'] ?? 0)],
        ['key' => 'template', 'label' => 'Welcome template selected', 'required' => true,
            'ok' => (bool) $template],
        ['key' => 'approved', 'label' => 'Template approved by provider', 'required' => true,
            'ok' => $template && $template['status'] === 'APPROVED',
            'detail' => $template && $template['status'] !== 'APPROVED' ? 'Template status is ' . $template['status'] : null],
        ['key' => 'variables', 'label' => 'Template variables mapped', 'required' => true,
            'ok' => $template && !$unmapped,
            'detail' => $unmapped ? 'Unmapped: ' . implode(', ', $unmapped) : null],
        ['key' => 'test', 'label' => 'Test message successful', 'required' => true,
            'ok' => ($s['last_test_status'] ?? null) === 'SUCCESS',
            'detail' => $s['last_test_error'] ?: null],
    ];

    $verdict = comms_verdict(gv_bool($s['welcome_enabled'] ?? 0), $checklist);
    $verdict['channel'] = 'WHATSAPP';
    $verdict['provider'] = $s['provider'] ?? 'META_CLOUD';
    $verdict['senderNumber'] = $s['sender_number'] ?? null;
    $verdict['templateId'] = $s['welcome_template_id'] ?? null;
    $verdict['templateName'] = $template['name'] ?? null;
    $verdict['lastTestAt'] = $s['last_test_at'] ?? null;
    return $verdict;
}

function check_email_configuration(string $orgId): array
{
    $s = email_settings($orgId);
    $template = !empty($s['welcome_template_id'])
        ? db_one('SELECT * FROM message_templates WHERE id = ? AND organization_id = ?', [$s['welcome_template_id'], $orgId])
        : null;
    $unmapped = template_unmapped_variables($template, $s['variable_map'] ?? null);

    $mode = $s['mode'] ?? 'SMTP';
    $providerOk = $mode === 'SMTP'
        ? (!empty($s['smtp_host']) && !empty($s['smtp_port']))
        : !empty($s['api_key_enc']);

    $checklist = [
        ['key' => 'provider', 'label' => $mode === 'SMTP' ? 'SMTP server configured' : 'Email provider connected',
            'required' => true, 'ok' => $providerOk],
        ['key' => 'credentials', 'label' => 'Credentials valid', 'required' => true,
            'ok' => gv_bool($s['credentials_valid'] ?? 0)],
        ['key' => 'from_email', 'label' => 'Sender email configured', 'required' => true,
            'ok' => !empty($s['from_email'])],
        ['key' => 'from_name', 'label' => 'Sender name configured', 'required' => true,
            'ok' => !empty($s['from_name'])],
        ['key' => 'template', 'label' => 'Welcome email template selected', 'required' => true,
            'ok' => (bool) $template],
        ['key' => 'subject', 'label' => 'Template has a subject line', 'required' => true,
            'ok' => $template && trim((string) $template['subject']) !== ''],
        ['key' => 'variables', 'label' => 'Template variables mapped', 'required' => true,
            'ok' => $template && !$unmapped,
            'detail' => $unmapped ? 'Unmapped: ' . implode(', ', $unmapped) : null],
        ['key' => 'test', 'label' => 'Test email successful', 'required' => true,
            'ok' => ($s['last_test_status'] ?? null) === 'SUCCESS',
            'detail' => $s['last_test_error'] ?: null],
    ];

    $verdict = comms_verdict(gv_bool($s['welcome_enabled'] ?? 0), $checklist);
    $verdict['channel'] = 'EMAIL';
    $verdict['mode'] = $mode;
    $verdict['fromEmail'] = $s['from_email'] ?? null;
    $verdict['fromName'] = $s['from_name'] ?? null;
    $verdict['templateId'] = $s['welcome_template_id'] ?? null;
    $verdict['templateName'] = $template['name'] ?? null;
    $verdict['lastTestAt'] = $s['last_test_at'] ?? null;
    return $verdict;
}

// ---------------------------------------------------------------------------
// Delivery log
// ---------------------------------------------------------------------------
function comms_log(string $orgId, string $channel, array $fields): void
{
    db_exec(
        'INSERT INTO message_logs
           (id, organization_id, channel, lead_id, template_id, recipient, subject, body_preview,
            status, reason, provider_message_id, provider_response, is_test, triggered_by, user_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            gv_id(), $orgId, $channel,
            $fields['leadId'] ?? null, $fields['templateId'] ?? null, $fields['recipient'] ?? null,
            isset($fields['subject']) ? substr((string) $fields['subject'], 0, 300) : null,
            isset($fields['body']) ? substr((string) $fields['body'], 0, 500) : null,
            $fields['status'], isset($fields['reason']) ? substr((string) $fields['reason'], 0, 300) : null,
            $fields['providerMessageId'] ?? null,
            isset($fields['providerResponse']) ? substr((string) $fields['providerResponse'], 0, 4000) : null,
            !empty($fields['isTest']) ? 1 : 0, $fields['triggeredBy'] ?? null, $fields['userId'] ?? null,
        ]
    );
}

// ---------------------------------------------------------------------------
// WhatsApp transport (Meta Cloud API)
// ---------------------------------------------------------------------------
function whatsapp_send_raw(array $settings, string $to, array $payloadExtra): array
{
    $token = gv_decrypt($settings['access_token_enc'] ?? null);
    if (!$token) {
        return ['ok' => false, 'error' => 'No access token stored.'];
    }
    $base = $settings['api_base_url'] ?: 'https://graph.facebook.com/v21.0';
    $url = rtrim($base, '/') . '/' . rawurlencode((string) $settings['phone_number_id']) . '/messages';

    $body = array_merge([
        'messaging_product' => 'whatsapp',
        'to' => digits_only($to),
    ], $payloadExtra);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Network error: ' . $curlError];
    }
    $decoded = json_decode((string) $response, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'messageId' => $decoded['messages'][0]['id'] ?? null, 'raw' => (string) $response];
    }
    $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
    return ['ok' => false, 'error' => $message, 'raw' => (string) $response];
}

/**
 * WhatsApp business-initiated messages must use a pre-approved template, so
 * the body we store is only ever the preview; the provider renders the real
 * text from provider_name plus positional parameters.
 */
function whatsapp_send_template(array $settings, array $template, string $to, array $context): array
{
    $vars = template_variables((string) $template['body']);
    $params = [];
    foreach ($vars as $var) {
        $params[] = ['type' => 'text', 'text' => (string) ($context[$var] ?? '')];
    }
    $payload = [
        'type' => 'template',
        'template' => [
            'name' => $template['provider_name'] ?: $template['key'],
            'language' => ['code' => $template['language'] ?: 'en'],
        ],
    ];
    if ($params) {
        $payload['template']['components'] = [['type' => 'body', 'parameters' => $params]];
    }
    return whatsapp_send_raw($settings, $to, $payload);
}

// ---------------------------------------------------------------------------
// Email transport (SMTP, no PHPMailer)
// ---------------------------------------------------------------------------
function smtp_expect($socket, string $expectedCode, string $stage): array
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        // A multiline reply has a dash in the 4th column; the last line has a space.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    $code = substr(trim($response), 0, 3);
    if ($code !== $expectedCode) {
        return ['ok' => false, 'error' => "$stage failed: " . trim($response)];
    }
    return ['ok' => true, 'response' => $response];
}

function smtp_send(array $settings, string $toEmail, string $toName, string $subject, string $htmlBody): array
{
    $host = (string) $settings['smtp_host'];
    $port = (int) $settings['smtp_port'];
    $encryption = strtoupper((string) ($settings['smtp_encryption'] ?? 'TLS'));
    $username = (string) ($settings['smtp_username'] ?? '');
    // A tenant's credentials are sealed in the database and have to be opened;
    // the platform's own mailer is configured in .env, where the password is
    // already plain. Both arrive here rather than each growing its own sender.
    $password = isset($settings['smtp_password_plain'])
        ? (string) $settings['smtp_password_plain']
        : (string) (gv_decrypt($settings['smtp_password_enc'] ?? null) ?? '');
    $fromEmail = (string) $settings['from_email'];
    $fromName = (string) $settings['from_name'];

    $transport = $encryption === 'SSL' ? "ssl://$host" : $host;
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client("$transport:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['ok' => false, 'error' => "Cannot reach $host:$port - $errstr"];
    }
    stream_set_timeout($socket, 15);

    $say = function (string $command) use ($socket) {
        fwrite($socket, $command . "\r\n");
    };

    try {
        $r = smtp_expect($socket, '220', 'Greeting');
        if (!$r['ok']) {
            return $r;
        }
        $ehlo = 'EHLO ' . (parse_url(gv_config('app_url'), PHP_URL_HOST) ?: 'localhost');
        $say($ehlo);
        $r = smtp_expect($socket, '250', 'EHLO');
        if (!$r['ok']) {
            return $r;
        }

        if ($encryption === 'TLS') {
            $say('STARTTLS');
            $r = smtp_expect($socket, '220', 'STARTTLS');
            if (!$r['ok']) {
                return $r;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['ok' => false, 'error' => 'TLS negotiation failed.'];
            }
            // The server forgets everything it told us before TLS, so EHLO again.
            $say($ehlo);
            $r = smtp_expect($socket, '250', 'EHLO (TLS)');
            if (!$r['ok']) {
                return $r;
            }
        }

        if ($username !== '') {
            $say('AUTH LOGIN');
            $r = smtp_expect($socket, '334', 'AUTH');
            if (!$r['ok']) {
                return $r;
            }
            $say(base64_encode($username));
            $r = smtp_expect($socket, '334', 'Username');
            if (!$r['ok']) {
                return $r;
            }
            $say(base64_encode($password));
            $r = smtp_expect($socket, '235', 'Password');
            if (!$r['ok']) {
                return ['ok' => false, 'error' => 'Authentication rejected by the mail server.'];
            }
        }

        $say('MAIL FROM:<' . $fromEmail . '>');
        $r = smtp_expect($socket, '250', 'MAIL FROM');
        if (!$r['ok']) {
            return $r;
        }
        $say('RCPT TO:<' . $toEmail . '>');
        $r = smtp_expect($socket, '250', 'RCPT TO');
        if (!$r['ok']) {
            return $r;
        }
        $say('DATA');
        $r = smtp_expect($socket, '354', 'DATA');
        if (!$r['ok']) {
            return $r;
        }

        $boundary = 'gv' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . comms_encode_header($fromName) . ' <' . $fromEmail . '>',
            'To: ' . ($toName !== '' ? comms_encode_header($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: ' . comms_encode_header($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . $boundary . '@' . (parse_url(gv_config('app_url'), PHP_URL_HOST) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if (!empty($settings['reply_to'])) {
            $headers[] = 'Reply-To: ' . $settings['reply_to'];
        }

        // Dot-stuffing: a line that is just "." would otherwise end the message.
        $safeBody = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $htmlBody));
        $safeBody = str_replace("\n", "\r\n", (string) $safeBody);

        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $safeBody . "\r\n.\r\n");
        $r = smtp_expect($socket, '250', 'Message body');
        if (!$r['ok']) {
            return $r;
        }

        $say('QUIT');
        return ['ok' => true, 'raw' => trim($r['response'] ?? '')];
    } finally {
        @fclose($socket);
    }
}

/** RFC 2047 encoding so non-ASCII names survive the header. */
function comms_encode_header(string $value): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return '"' . str_replace('"', '', $value) . '"';
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

// ---------------------------------------------------------------------------
// Welcome automation - the lead.created entry point
// ---------------------------------------------------------------------------
/**
 * Sends the welcome messages for a newly created lead, or records exactly why
 * it did not. Never throws: a lead must still be created if the tenant's
 * WhatsApp credentials have expired.
 */
function comms_send_welcome(string $orgId, string $leadId): array
{
    $results = [];
    try {
        $lead = db_one(
            'SELECT leads.*, lead_sources.name AS source_name
               FROM leads LEFT JOIN lead_sources ON lead_sources.id = leads.source_id
              WHERE leads.id = ? AND leads.organization_id = ?',
            [$leadId, $orgId]
        );
        if (!$lead) {
            return $results;
        }
        $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        $owner = $lead['owner_id'] ? db_one('SELECT * FROM users WHERE id = ?', [$lead['owner_id']]) : null;
        $project = $lead['project_id'] ? db_one('SELECT * FROM projects WHERE id = ?', [$lead['project_id']]) : null;
        $context = template_context($lead, $org, $owner, $project);

        $results['whatsapp'] = comms_deliver_whatsapp($orgId, $lead, $context, 'lead.created');
        $results['email'] = comms_deliver_email($orgId, $lead, $context, 'lead.created');
    } catch (Throwable $e) {
        error_log('[comms] welcome automation failed: ' . $e->getMessage());
    }
    return $results;
}

function comms_deliver_whatsapp(string $orgId, array $lead, array $context, string $trigger): string
{
    $verdict = check_whatsapp_configuration($orgId);

    if (!$verdict['enabled']) {
        return 'DISABLED';
    }
    if (!$verdict['readyToSend']) {
        comms_log($orgId, 'WHATSAPP', [
            'leadId' => $lead['id'], 'recipient' => $lead['phone'], 'status' => 'SKIPPED',
            'reason' => 'Configuration incomplete: ' . implode('; ', $verdict['missing']),
            'triggeredBy' => $trigger,
        ]);
        return 'SKIPPED_INCOMPLETE';
    }
    if (empty($lead['phone'])) {
        comms_log($orgId, 'WHATSAPP', [
            'leadId' => $lead['id'], 'status' => 'SKIPPED',
            'reason' => 'Lead has no phone number.', 'triggeredBy' => $trigger,
        ]);
        return 'SKIPPED_NO_PHONE';
    }

    $settings = whatsapp_settings($orgId);
    $template = db_one('SELECT * FROM message_templates WHERE id = ?', [$settings['welcome_template_id']]);
    $sent = whatsapp_send_template($settings, $template, (string) $lead['phone'], $context);

    comms_log($orgId, 'WHATSAPP', [
        'leadId' => $lead['id'], 'templateId' => $template['id'], 'recipient' => $lead['phone'],
        'body' => template_render((string) $template['body'], $context, $settings['variable_map'] ?? null),
        'status' => $sent['ok'] ? 'SENT' : 'FAILED',
        'reason' => $sent['ok'] ? null : $sent['error'],
        'providerMessageId' => $sent['messageId'] ?? null,
        'providerResponse' => $sent['raw'] ?? null,
        'triggeredBy' => $trigger,
    ]);
    return $sent['ok'] ? 'SENT' : 'FAILED';
}

function comms_deliver_email(string $orgId, array $lead, array $context, string $trigger): string
{
    $verdict = check_email_configuration($orgId);

    if (!$verdict['enabled']) {
        return 'DISABLED';
    }
    if (!$verdict['readyToSend']) {
        comms_log($orgId, 'EMAIL', [
            'leadId' => $lead['id'], 'recipient' => $lead['email'], 'status' => 'SKIPPED',
            'reason' => 'Configuration incomplete: ' . implode('; ', $verdict['missing']),
            'triggeredBy' => $trigger,
        ]);
        return 'SKIPPED_INCOMPLETE';
    }
    if (empty($lead['email'])) {
        comms_log($orgId, 'EMAIL', [
            'leadId' => $lead['id'], 'status' => 'SKIPPED',
            'reason' => 'Lead has no email address.', 'triggeredBy' => $trigger,
        ]);
        return 'SKIPPED_NO_EMAIL';
    }

    $settings = email_settings($orgId);
    $template = db_one('SELECT * FROM message_templates WHERE id = ?', [$settings['welcome_template_id']]);
    $subject = template_render((string) $template['subject'], $context, $settings['variable_map'] ?? null);
    $body = template_render((string) $template['body'], $context, $settings['variable_map'] ?? null);

    $sent = smtp_send($settings, (string) $lead['email'], $context['customer_name'], $subject, $body);

    comms_log($orgId, 'EMAIL', [
        'leadId' => $lead['id'], 'templateId' => $template['id'], 'recipient' => $lead['email'],
        'subject' => $subject, 'body' => $body,
        'status' => $sent['ok'] ? 'SENT' : 'FAILED',
        'reason' => $sent['ok'] ? null : $sent['error'],
        'triggeredBy' => $trigger,
    ]);
    return $sent['ok'] ? 'SENT' : 'FAILED';
}

// ---------------------------------------------------------------------------
// Test sends (spec section 38)
// ---------------------------------------------------------------------------
/**
 * Test sends deliberately bypass the welcome toggle - you test in order to
 * turn it on - but still require the transport itself to be configured. They
 * are logged with is_test = 1 so they never pollute delivery reporting, and
 * they never touch a lead record.
 */
function comms_test_whatsapp(string $orgId, string $toPhone, ?string $userId): array
{
    $settings = whatsapp_settings($orgId);
    if (empty($settings['access_token_enc']) || empty($settings['phone_number_id'])) {
        $error = 'Add an access token and sender number before sending a test.';
        comms_record_test($orgId, 'WHATSAPP', false, $error);
        comms_log($orgId, 'WHATSAPP', ['recipient' => $toPhone, 'status' => 'FAILED', 'reason' => $error, 'isTest' => 1, 'userId' => $userId, 'triggeredBy' => 'manual.test']);
        return ['ok' => false, 'error' => $error];
    }

    $template = !empty($settings['welcome_template_id'])
        ? db_one('SELECT * FROM message_templates WHERE id = ?', [$settings['welcome_template_id']])
        : null;

    $context = array_merge(array_fill_keys(GV_TEMPLATE_VARIABLES, ''), [
        'customer_name' => 'Test Contact',
        'company_name' => (string) db_scalar('SELECT name FROM organizations WHERE id = ?', [$orgId]),
        'project_name' => 'Sample Project',
    ]);

    $sent = $template
        ? whatsapp_send_template($settings, $template, $toPhone, $context)
        : whatsapp_send_raw($settings, $toPhone, ['type' => 'text', 'text' => ['body' => 'Growvanta CRM test message.']]);

    comms_record_test($orgId, 'WHATSAPP', $sent['ok'], $sent['ok'] ? null : $sent['error']);
    comms_log($orgId, 'WHATSAPP', [
        'templateId' => $template['id'] ?? null, 'recipient' => $toPhone,
        'status' => $sent['ok'] ? 'SENT' : 'FAILED', 'reason' => $sent['ok'] ? null : $sent['error'],
        'providerMessageId' => $sent['messageId'] ?? null, 'providerResponse' => $sent['raw'] ?? null,
        'isTest' => 1, 'userId' => $userId, 'triggeredBy' => 'manual.test',
    ]);
    return $sent;
}

function comms_test_email(string $orgId, string $toEmail, ?string $userId): array
{
    $settings = email_settings($orgId);
    if (empty($settings['from_email']) || empty($settings['smtp_host'])) {
        $error = 'Configure the SMTP server and sender address before sending a test.';
        comms_record_test($orgId, 'EMAIL', false, $error);
        comms_log($orgId, 'EMAIL', ['recipient' => $toEmail, 'status' => 'FAILED', 'reason' => $error, 'isTest' => 1, 'userId' => $userId, 'triggeredBy' => 'manual.test']);
        return ['ok' => false, 'error' => $error];
    }

    $orgName = (string) db_scalar('SELECT name FROM organizations WHERE id = ?', [$orgId]);
    $subject = 'Growvanta CRM - test email';
    $body = '<p>This is a test email from <strong>' . htmlspecialchars($orgName, ENT_QUOTES) . '</strong>.</p>'
        . '<p>If you are reading this, your outgoing email configuration works.</p>';

    $sent = smtp_send($settings, $toEmail, '', $subject, $body);

    comms_record_test($orgId, 'EMAIL', $sent['ok'], $sent['ok'] ? null : $sent['error']);
    comms_log($orgId, 'EMAIL', [
        'recipient' => $toEmail, 'subject' => $subject,
        'status' => $sent['ok'] ? 'SENT' : 'FAILED', 'reason' => $sent['ok'] ? null : $sent['error'],
        'isTest' => 1, 'userId' => $userId, 'triggeredBy' => 'manual.test',
    ]);
    return $sent;
}

/**
 * A passing test is the only thing that sets credentials_valid, and a failing
 * one clears it. That is what stops a channel from sitting on GREEN after its
 * token is revoked.
 */
function comms_record_test(string $orgId, string $channel, bool $ok, ?string $error): void
{
    $table = $channel === 'WHATSAPP' ? 'whatsapp_settings' : 'email_settings';
    db_exec(
        "UPDATE $table
            SET last_test_at = NOW(), last_test_status = ?, last_test_error = ?,
                credentials_valid = ?, verified_at = CASE WHEN ? = 1 THEN NOW() ELSE verified_at END
          WHERE organization_id = ?",
        [$ok ? 'SUCCESS' : 'FAILED', $error ? substr($error, 0, 500) : null, $ok ? 1 : 0, $ok ? 1 : 0, $orgId]
    );
}

/**
 * Any edit to the transport credentials invalidates the previous proof, which
 * drops the channel from GREEN back to ORANGE until it is retested.
 */
function comms_invalidate_verification(string $orgId, string $channel): void
{
    $table = $channel === 'WHATSAPP' ? 'whatsapp_settings' : 'email_settings';
    db_exec(
        "UPDATE $table SET credentials_valid = 0, last_test_status = NULL, last_test_error = NULL WHERE organization_id = ?",
        [$orgId]
    );
}

/**
 * Subscribes welcome automation to lead creation. Listeners run after
 * fastcgi_finish_request(), so a slow WhatsApp round trip never delays the
 * response that told the salesperson their lead was saved.
 */
function register_comms_listeners(): void
{
    on_domain_event('lead.created', function (array $payload) {
        $orgId = $payload['organizationId'] ?? null;
        $leadId = $payload['data']['id'] ?? null;
        if ($orgId && $leadId) {
            comms_send_welcome($orgId, $leadId);
        }
    });
}
