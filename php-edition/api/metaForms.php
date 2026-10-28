<?php
declare(strict_types=1);

/**
 * /api/meta-forms/* — connects a Meta (Facebook/Instagram) Lead Ads form to
 * this CRM and decides how its leads are routed.
 *
 * New in the PHP edition: the Node version had no Meta-specific admin surface,
 * because Lead Ads ingestion was not part of it. The webhook receiver
 * (webhooks/meta_leads.php) reads exactly the rows this endpoint writes.
 */

function meta_forms_shape(array $r): array
{
    $out = camelize($r);
    // The page access token is write-only: admins can replace it, never read it back.
    $out['pageAccessToken'] = $r['page_access_token'] ? '••••••••' : null;
    $out['hasToken'] = (bool) $r['page_access_token'];
    return $out;
}

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;
$a = actor();
$orgId = $a['organizationId'];

// ---------------------------------------------------------------------------
// Connect Facebook - the flow a client company uses to attach its OWN page.
//
// Kept in this file because it writes the same meta_lead_forms rows the manual
// route below does; the difference is only who supplies the token. A customer
// should never have to see a Page ID, let alone a Graph API Explorer.
// ---------------------------------------------------------------------------
if ($method === 'POST' && $id === 'connect' && $sub === null) {
    // $sub must be null. Without that test this branch also swallows
    // POST /connect/pages below, so choosing pages silently restarted the
    // authorisation instead of saving anything - and the pages never attached.
    require_permission('integrations', 'update');
    json_ok(meta_connect_start($orgId, $a['id']));

} elseif ($method === 'GET' && $id === 'connect' && $sub === 'pages') {
    require_permission('integrations', 'update');
    json_ok(['pages' => meta_connect_pages($orgId, $a['id'])]);

} elseif ($method === 'POST' && $id === 'connect' && $sub === 'pages') {
    require_permission('integrations', 'update');
    $pageIds = $body['pageIds'] ?? [];
    if (!is_array($pageIds) || !$pageIds) {
        throw bad_request('Choose at least one page to connect.');
    }
    json_ok(meta_connect_attach($orgId, $a['id'], array_map('strval', $pageIds), $a));

} elseif ($method === 'GET' && $id === 'diagnose') {
    require_permission('integrations', 'read');
    // Read-only, but it reaches Facebook with the app's own credentials, so it
    // is gated like everything else and scoped to the caller's organisation.
    json_ok(meta_connect_diagnose($orgId));

} elseif ($method === 'GET' && $id === 'connect' && $sub === 'status') {
    require_permission('integrations', 'read');
    // Drives the screen: is the app set up at all, and is an authorisation
    // sitting here waiting for the admin to pick their pages?
    json_ok([
        'configured' => (string) gv_config('meta.app_id') !== '' && (string) gv_config('meta.app_secret') !== '',
        'awaitingPageChoice' => meta_connect_pending($a['id']) !== null,
        'redirectUri' => meta_redirect_uri(),
        'webhookUrl' => rtrim((string) gv_config('app_url'), '/') . '/webhooks/meta_leads.php',
    ]);

} elseif ($method === 'GET' && $id === null) {
    require_permission('integrations', 'read');
    $rows = db_all(
        'SELECT f.*, s.name AS source_name, p.name AS project_name, c.name AS campaign_name, u.first_name AS owner_first_name, u.last_name AS owner_last_name, t.name AS team_name,
                (SELECT COUNT(*) FROM leads WHERE leads.meta_form_id = f.meta_form_id AND leads.organization_id = f.organization_id) AS lead_count
         FROM meta_lead_forms f
         LEFT JOIN lead_sources s ON s.id = f.source_id
         LEFT JOIN projects p ON p.id = f.project_id
         LEFT JOIN campaigns c ON c.id = f.campaign_id
         LEFT JOIN users u ON u.id = f.assign_to_user_id
         LEFT JOIN teams t ON t.id = f.assign_to_team_id
         WHERE f.organization_id = ? ORDER BY f.created_at DESC',
        [$orgId]
    );
    json_ok([
        'forms' => array_map(function ($r) {
            $out = meta_forms_shape($r);
            $out['leadCount'] = (int) $r['lead_count'];
            return $out;
        }, $rows),
        'webhookUrl' => gv_config('app_url') . '/webhooks/meta_leads.php',
        'verifyTokenConfigured' => (string) gv_config('meta.verify_token') !== '',
        'appSecretConfigured' => (string) gv_config('meta.app_secret') !== '',
        'setupNote' => 'Add the webhook URL in your Meta app under Webhooks > Page > leadgen, using META_VERIFY_TOKEN from .env as the verify token.',
    ]);
} elseif ($method === 'GET' && $id === 'events') {
    require_permission('integrations', 'read');
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $rows = db_all(
        'SELECT * FROM meta_webhook_events WHERE organization_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
        [$orgId, $limit, $offset]
    );
    $total = (int) db_scalar('SELECT COUNT(*) FROM meta_webhook_events WHERE organization_id = ?', [$orgId]);
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['rawPayload' => safe_json($r['raw_payload'] ?? '{}', [])]), $rows), page_meta($page, $limit, $total));
} elseif ($method === 'POST' && $id === null) {
    require_permission('integrations', 'update');
    $v = validate($body);
    $v->required('metaPageId', 'Page ID')->maxLength('metaPageId', 64);
    $v->required('metaFormId', 'Form ID')->maxLength('metaFormId', 64);
    $v->required('pageAccessToken', 'Page access token');
    $v->validate();

    $existing = db_one('SELECT * FROM meta_lead_forms WHERE meta_page_id = ? AND meta_form_id = ?', [$body['metaPageId'], $body['metaFormId']]);
    if ($existing && $existing['organization_id'] !== $orgId) {
        throw conflict('That Meta form is already connected to another organisation.');
    }

    // Optional by design: a Meta lead form cannot ask which tower the buyer
    // means, so this is the one place that answer can be given. When it is
    // given it must be a project this company can actually see, or its leads
    // would be attributed to somebody else's inventory.
    $projectId = null;
    if (!empty($body['projectId'])) {
        $vis = project_visible_where($orgId);
        $projectId = db_scalar(
            "SELECT projects.id FROM projects WHERE projects.id = ? AND {$vis['sql']}",
            array_merge([$body['projectId']], $vis['params'])
        );
        if (!$projectId) {
            throw bad_request('That project is not available to your company.');
        }
    }

    $token = gv_encrypt((string) $body['pageAccessToken']);
    if ($existing) {
        db_exec(
            'UPDATE meta_lead_forms SET form_name = ?, page_access_token = ?, source_id = ?, project_id = ?, campaign_id = ?, assign_to_user_id = ?, assign_to_team_id = ?, is_active = 1, last_error = NULL, updated_at = NOW() WHERE id = ?',
            [$body['formName'] ?? $existing['form_name'], $token, $body['sourceId'] ?? null, $projectId, $body['campaignId'] ?? null, $body['assignToUserId'] ?? null, $body['assignToTeamId'] ?? null, $existing['id']]
        );
        $formId = $existing['id'];
    } else {
        $formId = gv_id();
        db_exec(
            'INSERT INTO meta_lead_forms (id, organization_id, meta_page_id, meta_form_id, form_name, page_access_token, source_id, project_id, campaign_id, assign_to_user_id, assign_to_team_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
            [$formId, $orgId, $body['metaPageId'], $body['metaFormId'], $body['formName'] ?? null, $token, $body['sourceId'] ?? null, $projectId, $body['campaignId'] ?? null, $body['assignToUserId'] ?? null, $body['assignToTeamId'] ?? null]
        );
    }

    audit_record($orgId, $a, $existing ? 'meta_form.updated' : 'meta_form.connected', [
        'entityType' => 'META_FORM', 'entityId' => $formId, 'entityLabel' => $body['formName'] ?? $body['metaFormId'],
        'changes' => ['metaPageId' => $body['metaPageId'], 'metaFormId' => $body['metaFormId']],
    ]);
    json_created(meta_forms_shape(db_one('SELECT * FROM meta_lead_forms WHERE id = ?', [$formId])));
} elseif ($method === 'PATCH' && $id) {
    require_permission('integrations', 'update');
    $before = db_one('SELECT * FROM meta_lead_forms WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Connected form not found');
    }
    $data = [];
    foreach (['formName' => 'form_name', 'sourceId' => 'source_id', 'projectId' => 'project_id', 'campaignId' => 'campaign_id', 'assignToUserId' => 'assign_to_user_id', 'assignToTeamId' => 'assign_to_team_id'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    // Same rule as connecting a form: a project must be one this company can see,
    // or its leads would be filed against somebody else's inventory.
    if (!empty($data['project_id'])) {
        $vis = project_visible_where($orgId);
        $visible = db_scalar(
            "SELECT projects.id FROM projects WHERE projects.id = ? AND {$vis['sql']}",
            array_merge([$data['project_id']], $vis['params'])
        );
        if (!$visible) {
            throw bad_request('That project is not available to your company.');
        }
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    // An empty/redacted token means "leave the stored one alone".
    if (!empty($body['pageAccessToken']) && $body['pageAccessToken'] !== '••••••••') {
        $data['page_access_token'] = gv_encrypt((string) $body['pageAccessToken']);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE meta_lead_forms SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    json_ok(meta_forms_shape(db_one('SELECT * FROM meta_lead_forms WHERE id = ?', [$before['id']])));
} elseif ($method === 'DELETE' && $id) {
    require_permission('integrations', 'update');
    $row = db_one('SELECT * FROM meta_lead_forms WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Connected form not found');
    }
    db_exec('DELETE FROM meta_lead_forms WHERE id = ?', [$row['id']]);
    audit_record($orgId, $a, 'meta_form.disconnected', ['entityType' => 'META_FORM', 'entityId' => $row['id'], 'entityLabel' => $row['form_name'] ?: $row['meta_form_id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown meta-forms route');
}
