<?php
declare(strict_types=1);

/** /api/settings/* — organisation config, lead statuses/sources, custom fields and tags. Ported from src/routes/settings.js. */

$section = $rest[0] ?? null;
$id = $rest[1] ?? null;
$body = request_body();
$query = $_GET;
$a = actor();
$orgId = $a['organizationId'];

// ---------------------------------------------------------------------------
// Organisation
// ---------------------------------------------------------------------------
if ($method === 'GET' && $section === 'organization') {
    require_auth();
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    $userCount = (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1', [$orgId]);
    $leadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE organization_id = ?', [$orgId]);
    json_ok(array_merge(camelize($org), ['settings' => safe_json($org['settings'], []), 'stats' => ['users' => $userCount, 'leads' => $leadCount]]));
} elseif ($method === 'PATCH' && $section === 'organization') {
    require_permission('settings', 'update');
    $before = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    $data = [];
    foreach (['name' => 'name', 'domain' => 'domain', 'logoUrl' => 'logo_url', 'timezone' => 'timezone', 'currency' => 'currency', 'locale' => 'locale'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if (array_key_exists('settings', $body)) {
        $current = safe_json($before['settings'], []);
        $incoming = is_array($body['settings']) ? $body['settings'] : safe_json($body['settings'], []);
        $merged = $current;
        foreach (['autoAssignFallback', 'duplicatePolicy', 'defaultPipelineId', 'workingHours', 'fiscalYearStart'] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = $incoming[$key];
            }
        }
        if (!empty($merged['duplicatePolicy']) && !in_array($merged['duplicatePolicy'], ['FLAG', 'REJECT', 'ALLOW'], true)) {
            throw bad_request('duplicatePolicy must be FLAG, REJECT or ALLOW');
        }
        $data['settings'] = to_json($merged);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE organizations SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$orgId]));
    }
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    audit_record($orgId, $a, 'organization.updated', ['entityType' => 'ORGANIZATION', 'entityId' => $orgId, 'entityLabel' => $org['name'], 'changes' => gv_diff($before, $org, array_keys($data))]);
    json_ok(array_merge(camelize($org), ['settings' => safe_json($org['settings'], [])]));

// ---------------------------------------------------------------------------
// Lead statuses
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'lead-statuses') {
    require_auth();
    $rows = db_all(
        'SELECT s.*, (SELECT COUNT(*) FROM leads WHERE leads.status_id = s.id) AS lead_count FROM lead_statuses s WHERE s.organization_id = ? ORDER BY s.sort_order ASC',
        [$orgId]
    );
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['leadCount' => (int) $r['lead_count']]), $rows));
} elseif ($method === 'POST' && $section === 'lead-statuses' && $id === 'reorder') {
    require_permission('settings', 'update');
    $order = $body['order'] ?? null;
    if (!is_array($order)) {
        throw bad_request('order must be an array of status ids');
    }
    foreach ($order as $index => $statusId) {
        db_exec('UPDATE lead_statuses SET sort_order = ? WHERE id = ? AND organization_id = ?', [$index, $statusId, $orgId]);
    }
    json_ok(['reordered' => count($order)]);
} elseif ($method === 'POST' && $section === 'lead-statuses' && $id === null) {
    require_permission('settings', 'update');
    $v = validate($body);
    $v->required('name', 'Status name')->maxLength('name', 60);
    $v->validate();

    $key = str_replace('-', '_', slugify($body['name']));
    if (db_one('SELECT id FROM lead_statuses WHERE organization_id = ? AND `key` = ?', [$orgId, $key])) {
        $key .= '_' . substr(base_convert((string) time(), 10, 36), -4);
    }
    $maxOrder = (int) (db_scalar('SELECT MAX(sort_order) FROM lead_statuses WHERE organization_id = ?', [$orgId]) ?? 0);

    // Exactly one default / converted status per organisation.
    if (!empty($body['isDefault'])) {
        db_exec('UPDATE lead_statuses SET is_default = 0 WHERE organization_id = ?', [$orgId]);
    }
    if (!empty($body['isConverted'])) {
        db_exec('UPDATE lead_statuses SET is_converted = 0 WHERE organization_id = ?', [$orgId]);
    }
    $statusId = gv_id();
    db_exec(
        'INSERT INTO lead_statuses (id, organization_id, name, `key`, color, sort_order, probability, is_active, is_default, is_qualified, is_converted, is_lost, is_junk, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            $statusId, $orgId, $body['name'], $key, $body['color'] ?? '#6366f1', $body['sortOrder'] ?? ($maxOrder + 1), (int) ($body['probability'] ?? 0),
            array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1,
            !empty($body['isDefault']) ? 1 : 0, !empty($body['isQualified']) ? 1 : 0,
            !empty($body['isConverted']) ? 1 : 0, !empty($body['isLost']) ? 1 : 0, !empty($body['isJunk']) ? 1 : 0,
        ]
    );
    $row = db_one('SELECT * FROM lead_statuses WHERE id = ?', [$statusId]);
    audit_record($orgId, $a, 'lead_status.created', ['entityType' => 'LEAD_STATUS', 'entityId' => $statusId, 'entityLabel' => $row['name']]);
    json_created(camelize($row));
} elseif ($method === 'PATCH' && $section === 'lead-statuses' && $id) {
    require_permission('settings', 'update');
    $before = db_one('SELECT * FROM lead_statuses WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Status not found');
    }
    if (!empty($body['isDefault'])) {
        db_exec('UPDATE lead_statuses SET is_default = 0 WHERE organization_id = ?', [$orgId]);
    }
    if (!empty($body['isConverted'])) {
        db_exec('UPDATE lead_statuses SET is_converted = 0 WHERE organization_id = ?', [$orgId]);
    }
    $data = [];
    foreach (['name' => 'name', 'color' => 'color'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel];
        }
    }
    foreach (['sortOrder' => 'sort_order', 'probability' => 'probability'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = (int) $body[$camel];
        }
    }
    foreach (['isActive' => 'is_active', 'isDefault' => 'is_default', 'isQualified' => 'is_qualified', 'isConverted' => 'is_converted', 'isLost' => 'is_lost', 'isJunk' => 'is_junk'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = bool_val($body[$camel]) ? 1 : 0;
        }
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE lead_statuses SET $sets WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    $row = db_one('SELECT * FROM lead_statuses WHERE id = ?', [$before['id']]);
    audit_record($orgId, $a, 'lead_status.updated', ['entityType' => 'LEAD_STATUS', 'entityId' => $row['id'], 'entityLabel' => $row['name'], 'changes' => gv_diff($before, $row, array_keys($data))]);
    json_ok(camelize($row));
} elseif ($method === 'DELETE' && $section === 'lead-statuses' && $id) {
    require_permission('settings', 'update');
    $status = db_one('SELECT * FROM lead_statuses WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$status) {
        throw not_found('Status not found');
    }
    $leadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE status_id = ?', [$status['id']]);
    if ($leadCount > 0) {
        throw bad_request("$leadCount lead(s) still use this status. Move them first, or deactivate the status instead.");
    }
    if (gv_bool($status['is_default'])) {
        throw bad_request('Set another status as the default before deleting this one.');
    }
    db_exec('DELETE FROM lead_statuses WHERE id = ?', [$status['id']]);
    json_ok(['deleted' => true]);

// ---------------------------------------------------------------------------
// Lead sources
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'lead-sources') {
    require_auth();
    $rows = db_all(
        'SELECT s.*, (SELECT COUNT(*) FROM leads WHERE leads.source_id = s.id) AS lead_count FROM lead_sources s WHERE s.organization_id = ? ORDER BY s.sort_order ASC',
        [$orgId]
    );
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['leadCount' => (int) $r['lead_count']]), $rows));
} elseif ($method === 'POST' && $section === 'lead-sources' && $id === null) {
    require_permission('settings', 'update');
    $v = validate($body);
    $v->required('name', 'Source name')->maxLength('name', 60);
    $v->validate();

    $key = str_replace('-', '_', slugify($body['name']));
    if (db_one('SELECT id FROM lead_sources WHERE organization_id = ? AND `key` = ?', [$orgId, $key])) {
        $key .= '_' . substr(base_convert((string) time(), 10, 36), -4);
    }
    $sourceId = gv_id();
    db_exec(
        'INSERT INTO lead_sources (id, organization_id, name, `key`, category, cost_per_lead, is_active, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
        [
            $sourceId, $orgId, $body['name'], $key, $body['category'] ?? 'OTHER',
            (isset($body['costPerLead']) && $body['costPerLead'] !== '' && $body['costPerLead'] !== null) ? (float) $body['costPerLead'] : null,
            array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1, (int) ($body['sortOrder'] ?? 99),
        ]
    );
    json_created(camelize(db_one('SELECT * FROM lead_sources WHERE id = ?', [$sourceId])));
} elseif ($method === 'PATCH' && $section === 'lead-sources' && $id) {
    require_permission('settings', 'update');
    $before = db_one('SELECT * FROM lead_sources WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Source not found');
    }
    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('category', $body)) {
        $data['category'] = $body['category'];
    }
    if (array_key_exists('sortOrder', $body)) {
        $data['sort_order'] = (int) $body['sortOrder'];
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('costPerLead', $body)) {
        $data['cost_per_lead'] = ($body['costPerLead'] === null || $body['costPerLead'] === '') ? null : (float) $body['costPerLead'];
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE lead_sources SET $sets WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    json_ok(camelize(db_one('SELECT * FROM lead_sources WHERE id = ?', [$before['id']])));
} elseif ($method === 'DELETE' && $section === 'lead-sources' && $id) {
    require_permission('settings', 'update');
    $source = db_one('SELECT * FROM lead_sources WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$source) {
        throw not_found('Source not found');
    }
    if ((int) db_scalar('SELECT COUNT(*) FROM leads WHERE source_id = ?', [$source['id']]) > 0) {
        throw bad_request('Leads still reference this source. Deactivate it instead.');
    }
    db_exec('DELETE FROM lead_sources WHERE id = ?', [$source['id']]);
    json_ok(['deleted' => true]);

// ---------------------------------------------------------------------------
// Custom fields
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'custom-fields') {
    require_auth();
    $params = [$orgId];
    $sql = 'SELECT * FROM custom_fields WHERE organization_id = ?';
    if (!empty($query['entity'])) {
        $sql .= ' AND entity = ?';
        $params[] = (string) $query['entity'];
    }
    $sql .= ' ORDER BY entity ASC, sort_order ASC';
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['options' => safe_json($r['options'] ?? '[]', [])]), db_all($sql, $params)));
} elseif ($method === 'POST' && $section === 'custom-fields' && $id === null) {
    require_permission('settings', 'update');
    $v = validate($body);
    $v->required('entity', 'Entity')->in('entity', ['LEAD', 'CONTACT', 'ACCOUNT', 'OPPORTUNITY']);
    $v->required('label', 'Label')->maxLength('label', 80);
    $v->required('type', 'Type')->in('type', GV_CUSTOM_FIELD_TYPES);
    $v->validate();

    if (in_array($body['type'], ['SELECT', 'MULTISELECT'], true) && empty($body['options'])) {
        throw bad_request('Choice fields need at least one option.');
    }
    $key = str_replace('-', '_', slugify($body['label']));
    if (db_one('SELECT id FROM custom_fields WHERE organization_id = ? AND entity = ? AND `key` = ?', [$orgId, $body['entity'], $key])) {
        $key .= '_' . substr(base_convert((string) time(), 10, 36), -4);
    }
    $fieldId = gv_id();
    db_exec(
        'INSERT INTO custom_fields (id, organization_id, entity, `key`, label, type, options, is_required, is_active, show_in_list, default_value, help_text, sort_order, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            $fieldId, $orgId, $body['entity'], $key, $body['label'], $body['type'], to_json($body['options'] ?? []),
            !empty($body['isRequired']) ? 1 : 0, array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1,
            !empty($body['showInList']) ? 1 : 0, $body['defaultValue'] ?? null, $body['helpText'] ?? null, (int) ($body['sortOrder'] ?? 0),
        ]
    );
    $row = db_one('SELECT * FROM custom_fields WHERE id = ?', [$fieldId]);
    audit_record($orgId, $a, 'custom_field.created', ['entityType' => 'CUSTOM_FIELD', 'entityId' => $fieldId, 'entityLabel' => "{$row['entity']}.{$row['key']}"]);
    json_created(array_merge(camelize($row), ['options' => safe_json($row['options'], [])]));
} elseif ($method === 'PATCH' && $section === 'custom-fields' && $id) {
    require_permission('settings', 'update');
    $before = db_one('SELECT * FROM custom_fields WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Custom field not found');
    }
    // `entity` is deliberately not updatable: moving a field would orphan its values.
    $data = [];
    foreach (['label' => 'label', 'type' => 'type', 'defaultValue' => 'default_value', 'helpText' => 'help_text'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    if (array_key_exists('sortOrder', $body)) {
        $data['sort_order'] = (int) $body['sortOrder'];
    }
    foreach (['isRequired' => 'is_required', 'isActive' => 'is_active', 'showInList' => 'show_in_list'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = bool_val($body[$camel]) ? 1 : 0;
        }
    }
    if (array_key_exists('options', $body)) {
        $data['options'] = to_json($body['options']);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE custom_fields SET $sets WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    $row = db_one('SELECT * FROM custom_fields WHERE id = ?', [$before['id']]);
    json_ok(array_merge(camelize($row), ['options' => safe_json($row['options'], [])]));
} elseif ($method === 'DELETE' && $section === 'custom-fields' && $id) {
    require_permission('settings', 'update');
    $row = db_one('SELECT * FROM custom_fields WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Custom field not found');
    }
    db_exec('DELETE FROM custom_fields WHERE id = ?', [$row['id']]);
    audit_record($orgId, $a, 'custom_field.deleted', ['entityType' => 'CUSTOM_FIELD', 'entityId' => $row['id'], 'entityLabel' => "{$row['entity']}.{$row['key']}"]);
    json_ok(['deleted' => true, 'note' => 'Stored values are dropped the next time each record is saved.']);

// ---------------------------------------------------------------------------
// Tags
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'tags') {
    require_auth();
    $rows = db_all(
        'SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM lead_tags WHERE lead_tags.tag_id = t.id) AS usage_count
         FROM tags t WHERE t.organization_id = ? ORDER BY t.name ASC',
        [$orgId]
    );
    json_ok(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color'], 'usageCount' => (int) $r['usage_count']], $rows));
} elseif ($method === 'POST' && $section === 'tags' && $id === null) {
    require_permission('leads', 'update');
    $name = trim((string) ($body['name'] ?? ''));
    if ($name === '') {
        throw bad_request('Tag name is required');
    }
    $existing = db_one('SELECT * FROM tags WHERE organization_id = ? AND name = ?', [$orgId, $name]);
    if ($existing) {
        if (!empty($body['color'])) {
            db_exec('UPDATE tags SET color = ? WHERE id = ?', [$body['color'], $existing['id']]);
        }
        json_created(camelize(db_one('SELECT * FROM tags WHERE id = ?', [$existing['id']])));
    } else {
        $tagId = gv_id();
        db_exec('INSERT INTO tags (id, organization_id, name, color, created_at) VALUES (?,?,?,?,NOW())', [$tagId, $orgId, $name, $body['color'] ?? '#64748b']);
        json_created(camelize(db_one('SELECT * FROM tags WHERE id = ?', [$tagId])));
    }
} elseif ($method === 'DELETE' && $section === 'tags' && $id) {
    require_permission('settings', 'update');
    $tag = db_one('SELECT * FROM tags WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$tag) {
        throw not_found('Tag not found');
    }
    db_exec('DELETE FROM tags WHERE id = ?', [$tag['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown settings route');
}
