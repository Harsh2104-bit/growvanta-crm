<?php
declare(strict_types=1);

/**
 * Public ingestion API (`/api/v1`) — ported from src/routes/publicApi.js.
 *
 * Authenticated with an API key rather than a session, so ad platforms, landing
 * pages and integration middleware can post leads without a browser. Field
 * names accept both camelCase and the snake_case that most webhook senders use.
 */

require_once __DIR__ . '/publicApi.inc.php';

$resource = $rest[0] ?? null;
$action = $rest[1] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $resource === 'ping') {
    $auth = api_key_auth(null);
    $org = db_one('SELECT name, slug FROM organizations WHERE id = ?', [$auth['organizationId']]);
    json_ok([
        'status' => 'ok', 'organization' => $org['name'] ?? null,
        'key' => $auth['apiKey']['name'], 'scopes' => $auth['apiKey']['scopes'],
        'serverTime' => gmdate('c'),
    ]);
} elseif ($method === 'GET' && $resource === 'metadata') {
    $auth = api_key_auth(null);
    $orgId = $auth['organizationId'];
    json_ok([
        'sources' => db_all('SELECT `key`, name FROM lead_sources WHERE organization_id = ? AND is_active = 1', [$orgId]),
        'statuses' => db_all('SELECT `key`, name FROM lead_statuses WHERE organization_id = ? AND is_active = 1', [$orgId]),
        'projects' => db_all('SELECT id, name, location FROM projects WHERE organization_id = ? AND is_active = 1 AND is_archived = 0', [$orgId]),
        'fieldAliases' => PUBLIC_API_ALIASES,
    ]);
} elseif ($method === 'POST' && $resource === 'leads' && $action === null) {
    // POST /api/v1/leads — ?duplicates=reject|flag|allow (default flag)
    rate_limit_capture();
    $auth = api_key_auth('leads:create');
    $orgId = $auth['organizationId'];

    $input = public_api_normalize($body);
    if (empty($input['firstName'])) {
        throw bad_request('firstName (or name) is required');
    }
    if (empty($input['email']) && empty($input['phone'])) {
        throw bad_request('Provide at least an email address or a phone number');
    }
    $policyParam = strtoupper((string) ($query['duplicates'] ?? 'flag'));
    $duplicatePolicy = in_array($policyParam, ['REJECT', 'FLAG', 'ALLOW'], true) ? $policyParam : 'FLAG';
    if (empty($input['sourceId'])) {
        $input['sourceId'] = 'api';
    }

    $result = create_lead($input, ['organizationId' => $orgId, 'origin' => 'API', 'duplicatePolicy' => $duplicatePolicy]);
    $lead = $result['lead'];

    audit_record($orgId, null, 'lead.created_via_api', [
        'entityType' => 'LEAD', 'entityId' => $lead['id'], 'entityLabel' => "{$lead['code']} {$lead['firstName']}",
        'changes' => ['apiKey' => $auth['apiKey']['prefix'], 'duplicatePolicy' => $duplicatePolicy],
    ]);

    $owner = $lead['ownerId'] ? db_one('SELECT first_name, last_name FROM users WHERE id = ?', [$lead['ownerId']]) : null;
    json_created([
        'id' => $lead['id'], 'code' => $lead['code'],
        'status' => $lead['status']['name'] ?? null,
        'owner' => $owner ? trim($owner['first_name'] . ' ' . ($owner['last_name'] ?? '')) : null,
        'assignedBy' => $result['assignment']['ruleName'] ?? null,
        'score' => (int) $lead['score'],
        'duplicateWarnings' => array_map(fn($d) => ['id' => $d['id'], 'code' => $d['code'], 'matchedOn' => $d['matchedOn']], $result['duplicates']),
    ]);
} elseif ($method === 'POST' && $resource === 'leads' && $action === 'batch') {
    // Bulk ingest, capped so a single call cannot monopolise the process.
    $auth = api_key_auth('leads:create');
    $orgId = $auth['organizationId'];
    $items = $body['leads'] ?? null;
    if (!is_array($items)) {
        throw bad_request('Send { "leads": [ ... ] }');
    }
    if (count($items) > 200) {
        throw bad_request('A batch may contain at most 200 leads');
    }

    $results = [];
    foreach ($items as $index => $item) {
        $input = public_api_normalize(is_array($item) ? $item : []);
        try {
            if (empty($input['firstName'])) {
                throw new RuntimeException('firstName is required');
            }
            if (empty($input['email']) && empty($input['phone'])) {
                throw new RuntimeException('email or phone is required');
            }
            if (empty($input['sourceId'])) {
                $input['sourceId'] = 'api';
            }
            $createdLead = create_lead($input, ['organizationId' => $orgId, 'origin' => 'API', 'duplicatePolicy' => 'FLAG']);
            $results[] = ['index' => $index, 'ok' => true, 'id' => $createdLead['lead']['id'], 'code' => $createdLead['lead']['code'], 'duplicates' => count($createdLead['duplicates'])];
        } catch (Throwable $e) {
            $results[] = ['index' => $index, 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    json_ok([
        'accepted' => count(array_filter($results, fn($r) => $r['ok'])),
        'rejected' => count(array_filter($results, fn($r) => !$r['ok'])),
        'results' => $results,
    ]);
} elseif ($method === 'POST' && $resource === 'leads' && $action === 'check-duplicate') {
    // Duplicate probe — lets an integrator check before posting.
    $auth = api_key_auth('leads:create');
    $duplicates = find_duplicates($auth['organizationId'], public_api_normalize($body));
    json_ok([
        'isDuplicate' => count($duplicates) > 0,
        'matches' => array_map(fn($d) => ['id' => $d['id'], 'code' => $d['code'], 'matchedOn' => $d['matchedOn'], 'confidence' => $d['confidence']], $duplicates),
    ]);
} elseif ($method === 'GET' && $resource === 'leads') {
    // Read-back for integrations that reconcile their own records.
    $auth = api_key_auth('leads:read');
    $orgId = $auth['organizationId'];
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);

    $clauses = ['leads.organization_id = ?', 'leads.is_duplicate = 0'];
    $params = [$orgId];
    if (!empty($query['since'])) {
        $since = parse_date($query['since']);
        if ($since) {
            $clauses[] = 'leads.created_at >= ?';
            $params[] = $since;
        }
    }
    $where = 'WHERE ' . implode(' AND ', $clauses);
    $rows = db_all(
        'SELECT leads.id, leads.code, leads.first_name, leads.last_name, leads.email, leads.phone, leads.company,
                leads.city, leads.state, leads.score, leads.score_band, leads.created_at, leads.is_converted,
                s.`key` AS status_key, s.name AS status_name,
                src.`key` AS source_key, src.name AS source_name,
                c.code AS campaign_code, c.name AS campaign_name
         FROM leads
         LEFT JOIN lead_statuses s ON s.id = leads.status_id
         LEFT JOIN lead_sources src ON src.id = leads.source_id
         LEFT JOIN campaigns c ON c.id = leads.campaign_id
         ' . $where . ' ORDER BY leads.created_at DESC LIMIT ? OFFSET ?',
        array_merge($params, [$limit, $offset])
    );
    $total = (int) db_scalar("SELECT COUNT(*) FROM leads $where", $params);

    json_ok(array_map(fn($r) => [
        'id' => $r['id'], 'code' => $r['code'], 'firstName' => $r['first_name'], 'lastName' => $r['last_name'],
        'email' => $r['email'], 'phone' => $r['phone'], 'company' => $r['company'], 'city' => $r['city'], 'state' => $r['state'],
        'score' => (int) $r['score'], 'scoreBand' => $r['score_band'], 'createdAt' => $r['created_at'],
        'isConverted' => gv_bool($r['is_converted']),
        'status' => $r['status_key'] ? ['key' => $r['status_key'], 'name' => $r['status_name']] : null,
        'source' => $r['source_key'] ? ['key' => $r['source_key'], 'name' => $r['source_name']] : null,
        'campaign' => $r['campaign_code'] ? ['code' => $r['campaign_code'], 'name' => $r['campaign_name']] : null,
    ], $rows), page_meta($page, $limit, $total));
} else {
    throw not_found('Unknown /api/v1 route');
}
