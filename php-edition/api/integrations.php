<?php
declare(strict_types=1);

/**
 * /api/integrations/* — ported from src/routes/integrations.js.
 *
 * These are real configuration records, not decorative toggles: each provider
 * declares the fields it needs, the CRM stores them per organisation, and the
 * status reflects whether the credentials are actually present. Nothing claims
 * to be live until real credentials exist.
 *
 * Difference from the Node version: secret values are encrypted at rest with
 * AES-256-GCM (lib/security.php::gv_encrypt) rather than stored as plain JSON,
 * because on shared hosting the database is a likelier exposure than the app.
 */

const INTEGRATION_PROVIDERS = [
    'google_ads' => [
        'label' => 'Google Ads',
        'blurb' => 'Import lead-form submissions and sync conversions back to Google Ads.',
        'docs' => 'docs/INTEGRATIONS.md#google-ads',
        'fields' => [
            ['key' => 'customerId', 'label' => 'Customer ID', 'placeholder' => '123-456-7890', 'required' => true],
            ['key' => 'refreshToken', 'label' => 'OAuth refresh token', 'secret' => true, 'required' => true],
            ['key' => 'conversionActionId', 'label' => 'Conversion action ID'],
        ],
        'envKeys' => ['GOOGLE_ADS_DEVELOPER_TOKEN', 'GOOGLE_ADS_CLIENT_ID', 'GOOGLE_ADS_CLIENT_SECRET'],
        'capabilities' => ['Lead form ingestion (webhook)', 'Offline conversion upload'],
    ],
    'meta_ads' => [
        'label' => 'Meta Ads (Facebook & Instagram)',
        'blurb' => 'Receive Lead Ads in real time through the Meta webhook.',
        'docs' => 'docs/INTEGRATIONS.md#meta-ads',
        'fields' => [
            ['key' => 'pageId', 'label' => 'Page ID', 'required' => true],
            ['key' => 'pageAccessToken', 'label' => 'Page access token', 'secret' => true, 'required' => true],
        ],
        'envKeys' => ['META_APP_ID', 'META_APP_SECRET', 'META_VERIFY_TOKEN'],
        'capabilities' => ['Lead Ads webhook', 'Conversions API'],
    ],
    'google_analytics' => [
        'label' => 'Google Analytics 4',
        'blurb' => 'Send CRM-side conversion events back to GA4 for closed-loop reporting.',
        'docs' => 'docs/INTEGRATIONS.md#ga4',
        'fields' => [['key' => 'measurementId', 'label' => 'Measurement ID', 'placeholder' => 'G-XXXXXXX']],
        'envKeys' => ['GA4_MEASUREMENT_ID', 'GA4_API_SECRET'],
        'capabilities' => ['Measurement Protocol events'],
    ],
    'whatsapp' => [
        'label' => 'WhatsApp Business',
        'blurb' => 'Log WhatsApp conversations against leads and send template messages.',
        'docs' => 'docs/INTEGRATIONS.md#whatsapp',
        'fields' => [
            ['key' => 'phoneNumberId', 'label' => 'Phone number ID', 'required' => true],
            ['key' => 'accessToken', 'label' => 'Access token', 'secret' => true, 'required' => true],
        ],
        'envKeys' => ['WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_ACCESS_TOKEN'],
        'capabilities' => ['Inbound message webhook', 'Template send'],
    ],
    'smtp' => [
        'label' => 'Email (SMTP)',
        'blurb' => 'Deliver notification and follow-up email. Without it, notifications stay in-app.',
        'docs' => 'docs/ENVIRONMENT.md#smtp',
        'fields' => [
            ['key' => 'host', 'label' => 'SMTP host', 'required' => true],
            ['key' => 'port', 'label' => 'Port', 'placeholder' => '587'],
            ['key' => 'username', 'label' => 'Username'],
            ['key' => 'password', 'label' => 'Password', 'secret' => true],
            ['key' => 'fromAddress', 'label' => 'From address'],
        ],
        'envKeys' => ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM'],
        'capabilities' => ['Notification email', 'Password reset and invitations'],
    ],
    'sms' => [
        'label' => 'SMS gateway',
        'blurb' => 'Send SMS follow-ups and log them on the lead timeline.',
        'docs' => 'docs/INTEGRATIONS.md#sms',
        'fields' => [
            ['key' => 'provider', 'label' => 'Provider', 'placeholder' => 'twilio / msg91 / gupshup'],
            ['key' => 'apiKey', 'label' => 'API key', 'secret' => true],
            ['key' => 'senderId', 'label' => 'Sender ID'],
        ],
        'envKeys' => ['SMS_PROVIDER_KEY'],
        'capabilities' => ['Outbound SMS', 'Delivery receipts'],
    ],
    'zapier' => [
        'label' => 'Zapier / Make / n8n',
        'blurb' => 'Any no-code tool can post leads to the REST API and subscribe to webhooks.',
        'docs' => 'docs/API.md',
        'fields' => [],
        'envKeys' => [],
        'capabilities' => ['REST ingestion API', 'Outbound webhooks'],
    ],
];

const INTEGRATION_SECRET_KEYS = ['refreshToken', 'pageAccessToken', 'accessToken', 'password', 'apiKey', 'apiSecret'];
const INTEGRATION_REDACTED = '••••••••';

/** Never send stored secrets back to the browser — only whether they are set. */
function integrations_redact(array $config): array
{
    $out = [];
    foreach ($config as $key => $value) {
        $out[$key] = (in_array($key, INTEGRATION_SECRET_KEYS, true) && $value) ? INTEGRATION_REDACTED : $value;
    }
    return $out;
}

function integrations_evaluate_status(string $provider, array $stored): array
{
    $spec = INTEGRATION_PROVIDERS[$provider] ?? null;
    if (!$spec) {
        return ['status' => 'NOT_CONFIGURED', 'missing' => [], 'missingEnv' => []];
    }
    $missing = [];
    foreach ($spec['fields'] as $f) {
        if (!empty($f['required']) && empty($stored[$f['key']])) {
            $missing[] = $f['label'];
        }
    }
    $missingEnv = array_values(array_filter($spec['envKeys'], fn($k) => !env($k)));
    return [
        'status' => ($missing || $missingEnv) ? 'NOT_CONFIGURED' : 'CONFIGURED',
        'missing' => $missing,
        'missingEnv' => $missingEnv,
    ];
}

/** Decrypts secret-keyed values so status checks see the real presence of a value. */
function integrations_decrypt_config(array $stored): array
{
    foreach (INTEGRATION_SECRET_KEYS as $key) {
        if (!empty($stored[$key])) {
            $stored[$key] = gv_decrypt($stored[$key]) ?? $stored[$key];
        }
    }
    return $stored;
}

function integrations_encrypt_config(array $config): array
{
    foreach (INTEGRATION_SECRET_KEYS as $key) {
        if (!empty($config[$key])) {
            $config[$key] = gv_encrypt((string) $config[$key]);
        }
    }
    return $config;
}

$provider = $rest[0] ?? null;
$body = request_body();
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $provider === null) {
    require_permission('integrations', 'read');
    $rows = db_all('SELECT * FROM integrations WHERE organization_id = ?', [$orgId]);
    $byProvider = array_column($rows, null, 'provider');

    $list = [];
    foreach (INTEGRATION_PROVIDERS as $key => $spec) {
        $row = $byProvider[$key] ?? null;
        $stored = integrations_decrypt_config(safe_json($row['config'] ?? '{}', []));
        $evaluation = integrations_evaluate_status($key, $stored);
        $list[] = array_merge([
            'provider' => $key, 'label' => $spec['label'], 'blurb' => $spec['blurb'], 'docs' => $spec['docs'],
            'fields' => $spec['fields'], 'capabilities' => $spec['capabilities'], 'envKeys' => $spec['envKeys'],
            'isEnabled' => $row ? gv_bool($row['is_enabled']) : false,
            'config' => integrations_redact($stored),
            'lastSyncedAt' => $row['last_synced_at'] ?? null,
            'lastError' => $row['last_error'] ?? null,
        ], $evaluation);
    }
    json_ok([
        'integrations' => $list,
        'appUrl' => gv_config('app_url'),
        'note' => 'Credentials are stored per organisation and encrypted at rest. Anything marked "not configured" is inert - the CRM will never claim to have sent something it could not.',
    ]);
} elseif ($method === 'PATCH' && $provider) {
    require_permission('integrations', 'update');
    $spec = INTEGRATION_PROVIDERS[$provider] ?? null;
    if (!$spec) {
        throw not_found('Unknown integration');
    }
    $existing = db_one('SELECT * FROM integrations WHERE organization_id = ? AND provider = ?', [$orgId, $provider]);
    $stored = integrations_decrypt_config(safe_json($existing['config'] ?? '{}', []));

    $incoming = $body['config'] ?? [];
    $allowed = array_column($spec['fields'], 'key');
    $merged = $stored;
    foreach ($incoming as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        // A redacted placeholder means "leave the stored secret alone".
        if ($value === INTEGRATION_REDACTED) {
            continue;
        }
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        } else {
            $merged[$key] = $value;
        }
    }

    $evaluation = integrations_evaluate_status($provider, $merged);
    $isEnabled = array_key_exists('isEnabled', $body) ? bool_val($body['isEnabled']) : ($existing ? gv_bool($existing['is_enabled']) : false);
    if ($isEnabled && $evaluation['status'] !== 'CONFIGURED') {
        throw bad_request("Cannot enable {$spec['label']} yet. Missing: " . implode(', ', array_merge($evaluation['missing'], $evaluation['missingEnv'])));
    }

    $encrypted = to_json(integrations_encrypt_config($merged));
    if ($existing) {
        db_exec('UPDATE integrations SET config = ?, is_enabled = ?, status = ?, updated_at = NOW() WHERE id = ?', [$encrypted, $isEnabled ? 1 : 0, $evaluation['status'], $existing['id']]);
        $rowId = $existing['id'];
    } else {
        $rowId = gv_id();
        db_exec(
            'INSERT INTO integrations (id, organization_id, provider, name, config, is_enabled, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [$rowId, $orgId, $provider, $spec['label'], $encrypted, $isEnabled ? 1 : 0, $evaluation['status']]
        );
    }

    audit_record($orgId, $a, 'integration.updated', ['entityType' => 'INTEGRATION', 'entityId' => $rowId, 'entityLabel' => $spec['label'], 'changes' => ['isEnabled' => $isEnabled, 'fieldsSet' => array_keys($merged)]]);
    json_ok(array_merge(['provider' => $provider, 'isEnabled' => $isEnabled, 'config' => integrations_redact($merged)], $evaluation));
} else {
    throw not_found('Unknown integrations route');
}
