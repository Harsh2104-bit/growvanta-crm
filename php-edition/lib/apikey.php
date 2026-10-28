<?php
declare(strict_types=1);

/**
 * API-key auth for the public ingestion API (/api/v1) — Meta and website
 * integrations authenticate this way, never with a session cookie.
 */
function api_key_auth(?string $requiredScope = null): array
{
    $raw = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if (!$raw) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($header, 'bearer ') === 0) {
            $raw = trim(substr($header, 7));
        }
    }
    if (!$raw) {
        throw unauthenticated('API key required. Send it in the X-API-Key header.');
    }

    $key = db_one('SELECT * FROM api_keys WHERE key_hash = ?', [sha256($raw)]);
    if (!$key || $key['revoked_at'] || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
        throw unauthenticated('Invalid or revoked API key');
    }
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$key['organization_id']]);
    if (!$org || !gv_bool($org['is_active'])) {
        throw forbidden('Organisation is inactive');
    }

    $scopes = safe_json($key['scopes'], []);
    if ($requiredScope && !in_array($requiredScope, $scopes, true) && !in_array('*', $scopes, true)) {
        throw forbidden("API key is missing the \"$requiredScope\" scope");
    }

    $GLOBALS['GV_API_KEY'] = ['id' => $key['id'], 'prefix' => $key['prefix'], 'name' => $key['name'], 'scopes' => $scopes];
    $GLOBALS['GV_ORG_ID'] = $key['organization_id'];

    db_exec('UPDATE api_keys SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?', [$key['id']]);

    return ['organizationId' => $key['organization_id'], 'apiKey' => $GLOBALS['GV_API_KEY']];
}
