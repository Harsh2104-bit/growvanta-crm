<?php
declare(strict_types=1);

/**
 * Outbound webhooks — ported from src/services/webhooks.js.
 *
 * Every delivery is recorded (status, body, duration) so an integrator can
 * debug their endpoint from the CRM. Payloads are HMAC-SHA256 signed with the
 * webhook's own secret and sent as `X-Growvanta-Signature`, so the receiver
 * can verify the call really came from us.
 *
 * Delivery runs synchronously inside flush_domain_events() — under PHP-FPM
 * that call happens after fastcgi_finish_request() has already returned the
 * response to the browser, so the retry/backoff loop below does not make the
 * user wait. Under classic mod_php it simply extends the request a little.
 */

const GV_WEBHOOK_EVENTS = [
    'lead.created', 'lead.updated', 'lead.assigned', 'lead.status_changed', 'lead.converted',
    'task.created', 'task.completed',
];

const GV_WEBHOOK_TIMEOUT_MS = 8000;
const GV_WEBHOOK_MAX_ATTEMPTS = 3;

/** @return array{ok:bool, statusCode?:int, error?:string} */
function deliver_webhook(array $webhook, string $event, $payload): array
{
    $body = to_json([
        'event' => $event,
        'firedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
        'organizationId' => $webhook['organization_id'],
        'data' => $payload,
    ]);
    $signature = sign_webhook_payload($webhook['secret'], $body);

    $attempt = 0;
    $lastError = null;
    $statusCode = null;

    while ($attempt < GV_WEBHOOK_MAX_ATTEMPTS) {
        $attempt++;
        $startedAt = microtime(true);
        $ch = curl_init($webhook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: Growvanta-CRM-Webhook/1.0',
                "X-Growvanta-Event: $event",
                "X-Growvanta-Signature: sha256=$signature",
                "X-Growvanta-Delivery-Attempt: $attempt",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => GV_WEBHOOK_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => GV_WEBHOOK_TIMEOUT_MS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $responseText = curl_exec($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($responseText === false) {
            $lastError = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? 'Timed out' : curl_error($ch);
            curl_close($ch);
            try {
                db_exec(
                    'INSERT INTO webhook_deliveries (id, webhook_id, event, payload, error, attempt, duration_ms, created_at) VALUES (?,?,?,?,?,?,?,NOW())',
                    [gv_id(), $webhook['id'], $event, $body, $lastError, $attempt, $durationMs]
                );
            } catch (Throwable $e) {
                error_log('[webhooks] failed to log delivery: ' . $e->getMessage());
            }
        } else {
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $responseSnippet = substr((string) $responseText, 0, 500);
            db_exec(
                'INSERT INTO webhook_deliveries (id, webhook_id, event, payload, status_code, response, attempt, duration_ms, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
                [gv_id(), $webhook['id'], $event, $body, $statusCode, $responseSnippet, $attempt, $durationMs]
            );
            if ($statusCode >= 200 && $statusCode < 300) {
                db_exec('UPDATE webhooks SET failure_count = 0, last_status = ?, last_fired_at = NOW() WHERE id = ?', [$statusCode, $webhook['id']]);
                return ['ok' => true, 'statusCode' => $statusCode];
            }
            $lastError = "HTTP $statusCode";
        }

        if ($attempt < GV_WEBHOOK_MAX_ATTEMPTS) {
            usleep(500000 * $attempt);
        }
    }

    try {
        db_exec('UPDATE webhooks SET failure_count = failure_count + 1, last_status = ?, last_fired_at = NOW() WHERE id = ?', [$statusCode, $webhook['id']]);
    } catch (Throwable $e) {
        error_log('[webhooks] failed to update failure count: ' . $e->getMessage());
    }
    return ['ok' => false, 'error' => $lastError];
}

function dispatch_webhooks(string $event, string $orgId, $data): void
{
    $hooks = db_all('SELECT * FROM webhooks WHERE organization_id = ? AND is_active = 1', [$orgId]);
    foreach ($hooks as $hook) {
        $events = safe_json($hook['events'], []);
        if (!in_array($event, $events, true) && !in_array('*', $events, true)) {
            continue;
        }
        try {
            deliver_webhook($hook, $event, $data);
        } catch (Throwable $e) {
            error_log("[webhooks] delivery failed {$hook['url']}: " . $e->getMessage());
        }
    }
}

/** Test fire used by the admin UI's "Send test event" button. */
function send_test_webhook(array $webhook): array
{
    return deliver_webhook($webhook, 'lead.created', [
        'id' => 'test_lead_id', 'code' => 'LD-TEST', 'firstName' => 'Test', 'lastName' => 'Lead',
        'email' => 'test.lead@example.com', 'note' => 'This is a test delivery from Growvanta CRM.',
    ]);
}

function register_webhook_listeners(): void
{
    foreach (GV_EVENTS as $event) {
        if (!in_array($event, GV_WEBHOOK_EVENTS, true)) {
            continue;
        }
        on_domain_event($event, function (array $payload) use ($event) {
            $orgId = $payload['organizationId'] ?? null;
            if (!$orgId) {
                return;
            }
            dispatch_webhooks($event, $orgId, $payload['data'] ?? null);
        });
    }
}
