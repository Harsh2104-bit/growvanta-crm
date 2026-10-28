<?php
declare(strict_types=1);

/**
 * Meta (Facebook / Instagram) Lead Ads webhook endpoint.
 *
 * Configure this URL in the Meta app dashboard under
 * Webhooks > Page > leadgen:
 *
 *     https://your-domain.com/webhooks/meta_leads.php
 *
 * GET  — Meta's subscription handshake. Answers hub.challenge when
 *        hub.verify_token matches META_VERIFY_TOKEN from .env.
 * POST — a delivery. The signature is verified against META_APP_SECRET before
 *        the body is parsed, and we always answer 200 once the payload is
 *        logged: Meta retries (and eventually disables) a subscription that
 *        returns errors, and a per-lead failure is recorded in
 *        meta_webhook_events rather than being pushed back at the platform.
 */

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? null);
        $token = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? null);
        $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');
        $expected = (string) gv_config('meta.verify_token');

        if ($mode === 'subscribe' && $expected !== '' && is_string($token) && hash_equals($expected, $token)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
        } else {
            http_response_code(403);
            echo json_encode(['error' => ['code' => 'VERIFY_FAILED', 'message' => 'hub.verify_token did not match META_VERIFY_TOKEN.']]);
        }
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use GET to verify or POST to deliver.']]);
        return;
    }

    $raw = file_get_contents('php://input') ?: '';
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

    if (!meta_verify_signature($raw, $signature)) {
        // Record the attempt before refusing it.
        //
        // This used to write to the PHP error log and nothing else, which made
        // a rejected delivery indistinguishable from no delivery: the events
        // list stayed empty either way, and "Facebook never called" and
        // "Facebook called and we turned it away" look identical from the
        // screen. They need completely different fixes, so the difference has
        // to be visible.
        //
        // No organisation is attached, because an unverified caller's claim
        // about which company it is cannot be trusted.
        try {
            db_exec(
                'INSERT INTO meta_webhook_events (id, organization_id, meta_page_id, meta_form_id, meta_lead_id, raw_payload, status, error, created_at, processed_at)
                 VALUES (?,NULL,NULL,NULL,NULL,?,?,?,NOW(),NOW())',
                [
                    gv_id(),
                    substr($raw, 0, 2000),
                    $signature ? 'BAD_SIGNATURE' : 'NO_SIGNATURE',
                    $signature
                        ? 'A delivery arrived but X-Hub-Signature-256 did not match META_APP_SECRET. The app secret in .env is probably from a different app.'
                        : 'A delivery arrived with no X-Hub-Signature-256 header at all.',
                ]
            );
        } catch (Throwable $logError) {
            error_log('[meta] could not record the rejected delivery: ' . $logError->getMessage());
        }

        error_log('[meta] rejected a delivery with an invalid or missing X-Hub-Signature-256');
        http_response_code(401);
        echo json_encode(['error' => ['code' => 'BAD_SIGNATURE', 'message' => 'Invalid signature.']]);
        return;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => ['code' => 'BAD_PAYLOAD', 'message' => 'Body was not valid JSON.']]);
        return;
    }

    // Acknowledge immediately, then do the Graph API round-trips after the
    // response is flushed — Meta times a delivery out at ~20 seconds.
    http_response_code(200);
    echo json_encode(['received' => true]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }

    meta_process_webhook($payload);
    flush_domain_events();
} catch (Throwable $e) {
    error_log('[meta] webhook handler error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(200); // never hand Meta an error it will retry forever
        echo json_encode(['received' => true]);
    }
}
