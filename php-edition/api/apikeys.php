<?php
declare(strict_types=1);

/** /api/api-keys/* — ported from src/routes/apikeys.js. */

const API_KEY_SCOPES = [
    ['key' => 'leads:create', 'label' => 'Create leads'],
    ['key' => 'leads:read', 'label' => 'Read leads'],
    ['key' => '*', 'label' => 'Full ingestion access'],
];

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $id === 'scopes') {
    require_permission('apikeys', 'read');
    json_ok(API_KEY_SCOPES);
} elseif ($method === 'GET' && $id === null) {
    require_permission('apikeys', 'read');
    $rows = db_all(
        'SELECT k.*, u.first_name AS cb_first_name, u.last_name AS cb_last_name
         FROM api_keys k LEFT JOIN users u ON u.id = k.created_by_id WHERE k.organization_id = ? ORDER BY k.created_at DESC',
        [$orgId]
    );
    // The raw key is shown exactly once, at creation. Only the prefix survives.
    json_ok(array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'prefix' => $r['prefix'],
        'scopes' => safe_json($r['scopes'] ?? '[]', []),
        'lastUsedAt' => $r['last_used_at'], 'useCount' => (int) $r['use_count'],
        'expiresAt' => $r['expires_at'], 'revokedAt' => $r['revoked_at'], 'createdAt' => $r['created_at'],
        'createdBy' => $r['created_by_id'] ? trim($r['cb_first_name'] . ' ' . $r['cb_last_name']) : null,
        'status' => $r['revoked_at'] ? 'REVOKED' : (($r['expires_at'] && strtotime($r['expires_at']) < time()) ? 'EXPIRED' : 'ACTIVE'),
    ], $rows));
} elseif ($method === 'POST' && $id === null) {
    require_permission('apikeys', 'create');
    $v = validate($body);
    $v->required('name', 'Key name')->maxLength('name', 80);
    $v->validate();
    $scopes = $body['scopes'] ?? [];
    $allowed = array_column(API_KEY_SCOPES, 'key');
    if (!is_array($scopes) || !$scopes || array_diff($scopes, $allowed)) {
        throw bad_request('Choose at least one valid scope: ' . implode(', ', $allowed));
    }

    ['raw' => $raw, 'prefix' => $prefix, 'hash' => $hash] = generate_api_key();
    $keyId = gv_id();
    db_exec(
        'INSERT INTO api_keys (id, organization_id, name, prefix, key_hash, scopes, created_by_id, expires_at, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
        [$keyId, $orgId, $body['name'], $prefix, $hash, to_json($scopes), $a['id'], parse_date($body['expiresAt'] ?? null)]
    );
    audit_record($orgId, $a, 'api_key.created', ['entityType' => 'API_KEY', 'entityId' => $keyId, 'entityLabel' => $body['name'], 'changes' => ['scopes' => $scopes, 'prefix' => $prefix]]);
    json_created([
        'id' => $keyId, 'name' => $body['name'], 'prefix' => $prefix, 'scopes' => $scopes, 'key' => $raw,
        'warning' => 'Copy this key now. It is hashed on save and cannot be shown again.',
    ]);
} elseif ($method === 'POST' && $id && $sub === 'revoke') {
    require_permission('apikeys', 'delete');
    $key = db_one('SELECT * FROM api_keys WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$key) {
        throw not_found('API key not found');
    }
    if ($key['revoked_at']) {
        throw bad_request('That key is already revoked.');
    }
    db_exec('UPDATE api_keys SET revoked_at = NOW() WHERE id = ?', [$key['id']]);
    audit_record($orgId, $a, 'api_key.revoked', ['entityType' => 'API_KEY', 'entityId' => $key['id'], 'entityLabel' => $key['name']]);
    json_ok(['revoked' => true]);
} elseif ($method === 'DELETE' && $id) {
    require_permission('apikeys', 'delete');
    $key = db_one('SELECT * FROM api_keys WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$key) {
        throw not_found('API key not found');
    }
    db_exec('DELETE FROM api_keys WHERE id = ?', [$key['id']]);
    audit_record($orgId, $a, 'api_key.deleted', ['entityType' => 'API_KEY', 'entityId' => $key['id'], 'entityLabel' => $key['name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown api-keys route');
}
