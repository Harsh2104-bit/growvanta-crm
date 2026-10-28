<?php
declare(strict_types=1);

/** /api/forms/* — web form builder, ported from src/routes/forms.js. */

require_once __DIR__ . '/forms.inc.php';

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $id === null) {
    require_permission('forms', 'read');
    $a = actor();
    $rows = db_all(
        'SELECT f.*, p.id AS project_obj_id, p.name AS project_name, (SELECT COUNT(*) FROM form_submissions WHERE form_id = f.id) AS submission_count
         FROM web_forms f LEFT JOIN projects p ON p.id = f.project_id WHERE f.organization_id = ? ORDER BY f.created_at DESC',
        [$a['organizationId']]
    );
    json_ok(array_map(function ($r) {
        $shaped = forms_shape($r);
        $shaped['project'] = $r['project_id'] ? ['id' => $r['project_obj_id'], 'name' => $r['project_name']] : null;
        $shaped['submissionCount'] = (int) $r['submission_count'];
        unset($shaped['projectObjId'], $shaped['projectName']);
        return $shaped;
    }, $rows));
} elseif ($method === 'GET' && $id === 'field-catalogue') {
    require_permission('forms', 'read');
    json_ok(['types' => FORMS_FIELD_TYPES, 'mappable' => FORMS_MAPPABLE]);
} elseif ($method === 'POST' && $id === null) {
    require_permission('forms', 'create');
    $a = actor();
    $v = validate($body);
    $v->required('name', 'Form name')->maxLength('name', 120);
    $v->validate();
    forms_validate_fields($body['fields'] ?? []);

    $formId = gv_id();
    $publicKey = substr(slugify($body['name']), 0, 20) . '-' . bin2hex(random_bytes(6));
    db_exec(
        'INSERT INTO web_forms (id, organization_id, name, public_key, description, fields, source_id, project_id, assign_to_user_id, assign_to_team_id,
            use_assignment_rules, redirect_url, success_message, submit_label, allowed_origins, is_active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $formId, $a['organizationId'], $body['name'], $publicKey, $body['description'] ?? null, to_json($body['fields']),
            $body['sourceId'] ?? null, $body['projectId'] ?? null, $body['assignToUserId'] ?? null, $body['assignToTeamId'] ?? null,
            array_key_exists('useAssignmentRules', $body) ? (bool_val($body['useAssignmentRules']) ? 1 : 0) : 1,
            $body['redirectUrl'] ?? null, $body['successMessage'] ?? 'Thanks! Our team will contact you shortly.',
            $body['submitLabel'] ?? 'Submit', $body['allowedOrigins'] ?? '', array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1,
        ]
    );
    $row = db_one('SELECT * FROM web_forms WHERE id = ?', [$formId]);
    audit_record($a['organizationId'], $a, 'form.created', ['entityType' => 'WEB_FORM', 'entityId' => $formId, 'entityLabel' => $row['name']]);
    json_created(forms_shape($row));
} elseif ($method === 'GET' && $id && $sub === null) {
    require_permission('forms', 'read');
    $a = actor();
    $form = db_one('SELECT * FROM web_forms WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$form) {
        throw not_found('Form not found');
    }
    $submissions = db_all('SELECT * FROM form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT 25', [$form['id']]);
    $total = (int) db_scalar('SELECT COUNT(*) FROM form_submissions WHERE form_id = ?', [$form['id']]);
    $accepted = (int) db_scalar("SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND status = 'ACCEPTED'", [$form['id']]);
    $duplicates = (int) db_scalar("SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND status = 'DUPLICATE'", [$form['id']]);
    json_ok([
        'form' => forms_shape($form),
        'stats' => ['total' => $total, 'accepted' => $accepted, 'duplicates' => $duplicates],
        'submissions' => array_map(fn($s) => array_merge(camelize($s), ['payload' => safe_json($s['payload'] ?? '{}', [])]), $submissions),
    ]);
} elseif ($method === 'GET' && $id && $sub === 'submissions') {
    require_permission('forms', 'read');
    $a = actor();
    $form = db_one('SELECT * FROM web_forms WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$form) {
        throw not_found('Form not found');
    }
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $rows = db_all('SELECT * FROM form_submissions WHERE form_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?', [$form['id'], $limit, $offset]);
    $total = (int) db_scalar('SELECT COUNT(*) FROM form_submissions WHERE form_id = ?', [$form['id']]);
    json_ok(array_map(fn($s) => array_merge(camelize($s), ['payload' => safe_json($s['payload'] ?? '{}', [])]), $rows), page_meta($page, $limit, $total));
} elseif ($method === 'PATCH' && $id) {
    require_permission('forms', 'update');
    $a = actor();
    $before = db_one('SELECT * FROM web_forms WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$before) {
        throw not_found('Form not found');
    }
    $data = [];
    foreach (['name' => 'name', 'description' => 'description', 'sourceId' => 'source_id', 'projectId' => 'project_id', 'assignToUserId' => 'assign_to_user_id', 'assignToTeamId' => 'assign_to_team_id', 'redirectUrl' => 'redirect_url', 'allowedOrigins' => 'allowed_origins'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: ($camel === 'allowedOrigins' ? '' : null);
        }
    }
    if (array_key_exists('successMessage', $body) && $body['successMessage']) {
        $data['success_message'] = $body['successMessage'];
    }
    if (array_key_exists('submitLabel', $body) && $body['submitLabel']) {
        $data['submit_label'] = $body['submitLabel'];
    }
    if (array_key_exists('useAssignmentRules', $body)) {
        $data['use_assignment_rules'] = bool_val($body['useAssignmentRules']) ? 1 : 0;
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('fields', $body)) {
        forms_validate_fields($body['fields']);
        $data['fields'] = to_json($body['fields']);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE web_forms SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    json_ok(forms_shape(db_one('SELECT * FROM web_forms WHERE id = ?', [$before['id']])));
} elseif ($method === 'DELETE' && $id) {
    require_permission('forms', 'delete');
    $a = actor();
    $form = db_one('SELECT * FROM web_forms WHERE id = ? AND organization_id = ?', [$id, $a['organizationId']]);
    if (!$form) {
        throw not_found('Form not found');
    }
    db_exec('DELETE FROM web_forms WHERE id = ?', [$form['id']]);
    audit_record($a['organizationId'], $a, 'form.deleted', ['entityType' => 'WEB_FORM', 'entityId' => $form['id'], 'entityLabel' => $form['name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown forms route');
}
