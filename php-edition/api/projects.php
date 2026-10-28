<?php
declare(strict_types=1);

/**
 * /api/projects/* - the real-estate project master.
 *
 * Read access follows project_visible_where(): your own projects, anything
 * published GLOBAL, and anything explicitly shared with you. Write access is
 * narrower - only the owning tenant, enforced by project_assert_writable().
 *
 * Lead counts returned here are always counted inside the caller's own tenant,
 * which is what keeps two brokers selling the same tower from seeing each
 * other's pipeline (spec section 24).
 */

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$subId = $rest[2] ?? null;
$subAction = $rest[3] ?? null;
$body = request_body();
$query = $_GET;
$a = require_auth();
$orgId = $a['organizationId'];

function projects_parse_list($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
    }
    if (is_string($value) && $value !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
    }
    return [];
}

/** Shared column mapping for create and update. */
function projects_writable_columns(array $body, bool $isCreate): array
{
    $map = [
        'name' => 'name', 'location' => 'location', 'developerName' => 'developer_name',
        'projectType' => 'project_type', 'city' => 'city', 'state' => 'state',
        'country' => 'country', 'pincode' => 'pincode', 'address' => 'address',
        'description' => 'description', 'reraNumber' => 'rera_number', 'status' => 'status',
        'possessionDate' => 'possession_date', 'priceMin' => 'price_min', 'priceMax' => 'price_max',
        'areaMin' => 'area_min', 'areaMax' => 'area_max', 'areaUnit' => 'area_unit',
        'contactName' => 'contact_name', 'contactPhone' => 'contact_phone', 'contactEmail' => 'contact_email',
        'logoUrl' => 'logo_url',
    ];
    $out = [];
    foreach ($map as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $value = $body[$camel];
            $out[$col] = ($value === '' ? null : $value);
        }
    }
    if (array_key_exists('configurations', $body)) {
        $out['configurations'] = to_json(projects_parse_list($body['configurations']));
    }
    if (array_key_exists('amenities', $body)) {
        $out['amenities'] = to_json(projects_parse_list($body['amenities']));
    }
    if (array_key_exists('isActive', $body)) {
        $out['is_active'] = gv_bool($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('isArchived', $body)) {
        $out['is_archived'] = gv_bool($body['isArchived']) ? 1 : 0;
    }

    if (isset($out['project_type']) && !in_array($out['project_type'], GV_PROJECT_TYPES, true)) {
        throw bad_request('Choose a valid project type.');
    }
    if (isset($out['status']) && !in_array($out['status'], GV_PROJECT_STATUSES, true)) {
        throw bad_request('Choose a valid project status.');
    }
    if ($isCreate) {
        foreach (['name' => 'Project name', 'location' => 'Location', 'developer_name' => 'Developer / brand'] as $col => $label) {
            if (empty($out[$col])) {
                throw bad_request("$label is required.");
            }
        }
    }
    return $out;
}

/**
 * Visibility is a platform-level decision for GLOBAL (it publishes a project to
 * every client on the platform), but a tenant may still keep its own projects
 * PRIVATE or share them with named partners.
 */
function projects_apply_visibility(array $actor, string $projectId, ?string $visibility, $accessOrgIds): void
{
    if ($visibility === null) {
        return;
    }
    if (!in_array($visibility, GV_PROJECT_VISIBILITY, true)) {
        throw bad_request('Choose a valid visibility option.');
    }
    if ($visibility === 'GLOBAL' && !is_platform_admin($actor)) {
        throw forbidden('Only a platform administrator can publish a project to every company.');
    }
    db_exec('UPDATE projects SET visibility = ? WHERE id = ?', [$visibility, $projectId]);

    if ($visibility === 'SELECTED') {
        if (!is_platform_admin($actor)) {
            throw forbidden('Only a platform administrator can share a project with other companies.');
        }
        project_set_access($projectId, projects_parse_list($accessOrgIds), $actor['id']);
    } elseif ($visibility === 'PRIVATE') {
        // Dropping back to private must actually revoke, not just hide.
        db_exec('DELETE FROM project_access WHERE project_id = ?', [$projectId]);
    }
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------
if ($method === 'GET' && $id === null) {
    require_permission('projects', 'read');

    $vis = project_visible_where($orgId);
    $where = [$vis['sql']];
    $params = $vis['params'];

    if (!empty($query['q'])) {
        $where[] = '(projects.name LIKE ? OR projects.location LIKE ? OR projects.developer_name LIKE ? OR projects.code LIKE ?)';
        $like = '%' . $query['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    foreach (['projectType' => 'project_type', 'status' => 'status', 'city' => 'city', 'visibility' => 'visibility'] as $q => $col) {
        if (!empty($query[$q])) {
            $where[] = "projects.$col = ?";
            $params[] = $query[$q];
        }
    }
    // "ownership" splits the list the way a broker thinks about it: stock I
    // created versus stock somebody shared with me.
    if (($query['ownership'] ?? '') === 'own') {
        $where[] = 'projects.organization_id = ?';
        $params[] = $orgId;
    } elseif (($query['ownership'] ?? '') === 'shared') {
        $where[] = 'projects.organization_id <> ?';
        $params[] = $orgId;
    }
    if (($query['includeArchived'] ?? '') !== 'true') {
        $where[] = 'projects.is_archived = 0';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $total = (int) db_scalar("SELECT COUNT(*) FROM projects $whereSql", $params);

    [$sortCol, $sortDir] = parse_sort($query, ['name', 'createdAt', 'location', 'priceMin', 'possessionDate'], 'created_at');
    $rows = db_all(
        "SELECT projects.*, organizations.name AS owner_org_name
           FROM projects JOIN organizations ON organizations.id = projects.organization_id
           $whereSql ORDER BY projects.$sortCol $sortDir LIMIT $limit OFFSET $offset",
        $params
    );

    $counts = project_lead_counts($orgId, array_column($rows, 'id'));
    $data = array_map(fn($r) => project_present($r, $orgId, [
        'lead_count' => $counts[$r['id']] ?? 0,
        'owner_org_name' => $r['owner_org_name'],
    ]), $rows);

    json_ok($data, page_meta($page, $limit, $total));

} elseif ($method === 'GET' && $id === 'options') {
    // Lightweight list for the lead form's project picker.
    require_permission('projects', 'read');
    $vis = project_visible_where($orgId);
    $rows = db_all(
        "SELECT projects.id, projects.name, projects.location, projects.city, projects.developer_name,
                projects.project_type, projects.configurations
           FROM projects
          WHERE {$vis['sql']} AND projects.is_active = 1 AND projects.is_archived = 0
          ORDER BY projects.name ASC",
        $vis['params']
    );
    // The lead form uses `configurations` to suggest 2 BHK / 3 BHK for the
    // project the caller actually picked.
    foreach ($rows as &$row) {
        $row['configurations'] = safe_json($row['configurations'], []);
    }
    unset($row);
    json_ok(camelize($rows));

} elseif ($method === 'GET' && $id === 'meta') {
    require_auth();
    json_ok([
        'types' => GV_PROJECT_TYPES,
        'statuses' => GV_PROJECT_STATUSES,
        'visibility' => GV_PROJECT_VISIBILITY,
        'canPublishGlobal' => is_platform_admin($a),
    ]);

} elseif ($method === 'GET' && $id === 'export') {
    require_permission('projects', 'export');
    $vis = project_visible_where($orgId);
    $rows = db_all(
        "SELECT projects.*, organizations.name AS owner_org_name
           FROM projects JOIN organizations ON organizations.id = projects.organization_id
          WHERE {$vis['sql']} AND projects.is_archived = 0 ORDER BY projects.name ASC",
        $vis['params']
    );
    $counts = project_lead_counts($orgId, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $r['lead_count'] = $counts[$r['id']] ?? 0;
        $r['configurations'] = implode(', ', safe_json($r['configurations'], []));
    }
    unset($r);

    export_record($orgId, $a, 'PROJECTS', 'XLSX', count($rows), $query);
    xlsx_download('projects-' . date('Y-m-d') . '.xlsx', [
        ['key' => 'code', 'label' => 'Project Code', 'type' => 'string'],
        ['key' => 'name', 'label' => 'Project Name', 'type' => 'string'],
        ['key' => 'developer_name', 'label' => 'Developer / Brand', 'type' => 'string'],
        ['key' => 'location', 'label' => 'Location', 'type' => 'string'],
        ['key' => 'city', 'label' => 'City', 'type' => 'string'],
        ['key' => 'project_type', 'label' => 'Type', 'type' => 'string'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
        ['key' => 'configurations', 'label' => 'Configurations', 'type' => 'string'],
        ['key' => 'price_min', 'label' => 'Price From', 'type' => 'number'],
        ['key' => 'price_max', 'label' => 'Price To', 'type' => 'number'],
        ['key' => 'rera_number', 'label' => 'RERA Number', 'type' => 'string'],
        ['key' => 'possession_date', 'label' => 'Possession', 'type' => 'date'],
        ['key' => 'owner_org_name', 'label' => 'Owned By', 'type' => 'string'],
        ['key' => 'lead_count', 'label' => 'Leads (your company)', 'type' => 'number'],
    ], $rows, 'Projects');
    return;

} elseif ($method === 'GET' && $id !== null && $sub === 'images' && $subId !== null && $subAction === 'file') {
    // Serving the binary goes through the same visibility check as the record,
    // so a shared project's photos are readable but a private one's are not.
    require_permission('projects', 'read');
    project_load_visible($orgId, $id);
    $image = db_one('SELECT * FROM project_images WHERE id = ? AND project_id = ?', [$subId, $id]);
    if (!$image) {
        throw not_found('Image not found.');
    }
    $path = gv_config('uploads.dir') . '/' . basename((string) $image['url']);
    if (!is_file($path)) {
        throw not_found('The image file is missing from storage.');
    }
    header('Content-Type: ' . ($image['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    return;

} elseif ($method === 'GET' && $id !== null && $sub === null) {
    require_permission('projects', 'read');
    $project = project_load_visible($orgId, $id);
    $counts = project_lead_counts($orgId, [$id]);

    $ownerOrg = db_one('SELECT name, company_type FROM organizations WHERE id = ?', [$project['organization_id']]);
    $payload = project_present($project, $orgId, [
        'lead_count' => $counts[$id] ?? 0,
        'owner_org_name' => $ownerOrg['name'] ?? null,
    ]);
    $payload['images'] = project_images($id);
    // Only the platform admin needs (or may see) the sharing list.
    $payload['sharedWith'] = is_platform_admin($a) ? project_access_org_ids($id) : [];
    json_ok($payload);

} elseif ($method === 'POST' && $id === null) {
    require_permission('projects', 'create');
    $data = projects_writable_columns($body, true);

    $projectId = gv_id();
    $data['id'] = $projectId;
    $data['organization_id'] = $orgId;
    $data['code'] = project_next_code($orgId);
    $data['slug'] = project_unique_slug($orgId, (string) $data['name']);
    $data['created_by_id'] = $a['id'];
    $data['visibility'] = 'PRIVATE';

    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
    $ph = implode(',', array_fill(0, count($data), '?'));
    db_exec("INSERT INTO projects ($cols, created_at, updated_at) VALUES ($ph, NOW(), NOW())", array_values($data));

    projects_apply_visibility($a, $projectId, $body['visibility'] ?? null, $body['sharedWith'] ?? []);

    audit_record($orgId, $a, 'project.created', [
        'entityType' => 'PROJECT', 'entityId' => $projectId, 'entityLabel' => $data['name'],
        'changes' => ['name' => $data['name'], 'location' => $data['location']],
    ]);

    json_created(project_present(db_one('SELECT * FROM projects WHERE id = ?', [$projectId]), $orgId));

} elseif ($method === 'PATCH' && $id !== null && $sub === null) {
    require_permission('projects', 'update');
    $project = project_load_visible($orgId, $id);
    project_assert_writable($a, $project);

    $data = projects_writable_columns($body, false);
    if ($data) {
        if (isset($data['name']) && $data['name'] !== $project['name']) {
            $data['slug'] = project_unique_slug($orgId, (string) $data['name']);
        }
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE projects SET $set, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$id]));
    }
    projects_apply_visibility($a, $id, $body['visibility'] ?? null, $body['sharedWith'] ?? []);

    $after = db_one('SELECT * FROM projects WHERE id = ?', [$id]);
    audit_record($orgId, $a, 'project.updated', [
        'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $after['name'],
        'changes' => gv_diff($project, $after, array_keys($data)),
    ]);
    json_ok(project_present($after, $orgId));

} elseif ($method === 'DELETE' && $id !== null && $sub === null) {
    require_permission('projects', 'delete');
    $project = project_load_visible($orgId, $id);
    project_assert_writable($a, $project);

    // A project with leads attached is archived rather than deleted - dropping
    // it would orphan the attribution on every lead that came through it.
    $leadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE project_id = ?', [$id]);
    if ($leadCount > 0) {
        db_exec('UPDATE projects SET is_archived = 1, is_active = 0, updated_at = NOW() WHERE id = ?', [$id]);
        audit_record($orgId, $a, 'project.archived', [
            'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $project['name'],
            'changes' => ['reason' => "$leadCount leads reference this project"],
        ]);
        json_ok(['archived' => true, 'leadCount' => $leadCount,
            'message' => "Archived instead of deleted: $leadCount leads are attached to this project."]);
    } else {
        db_exec('DELETE FROM projects WHERE id = ?', [$id]);
        audit_record($orgId, $a, 'project.deleted', [
            'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $project['name'], 'changes' => [],
        ]);
        json_ok(['deleted' => true]);
    }

} elseif ($method === 'GET' && $id !== null && $sub === 'images') {
    require_permission('projects', 'read');
    project_load_visible($orgId, $id);
    json_ok(project_images($id));

} elseif ($method === 'POST' && $id !== null && $sub === 'images') {
    require_permission('projects', 'manage');
    $project = project_load_visible($orgId, $id);
    project_assert_writable($a, $project);

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw bad_request('Choose an image to upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw bad_request('The upload did not complete. Please try again.');
    }
    if ($file['size'] > (int) gv_config('uploads.max_bytes')) {
        throw bad_request('That image is larger than the upload limit.');
    }

    // Sniff the real type. The browser-supplied name and type are both
    // attacker-controlled, so neither decides whether this file is kept.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        throw bad_request('Project images must be JPG, PNG or WEBP.');
    }
    // A real image decodes; this rejects a PHP script renamed to .jpg even if
    // its leading bytes were crafted to sniff as an image.
    if (@getimagesize($file['tmp_name']) === false) {
        throw bad_request('That file is not a readable image.');
    }

    $uploadDir = gv_config('uploads.dir');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $storedName = 'project-' . time() . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) {
        throw bad_request('The image could not be saved.');
    }

    $imageId = gv_id();
    $isFirst = (int) db_scalar('SELECT COUNT(*) FROM project_images WHERE project_id = ?', [$id]) === 0;
    db_exec(
        'INSERT INTO project_images (id, project_id, url, caption, is_cover, sort_order, file_size, mime_type, uploaded_by_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())',
        [
            $imageId, $id, $storedName, $_POST['caption'] ?? null, $isFirst ? 1 : 0,
            (int) db_scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM project_images WHERE project_id = ?', [$id]),
            (int) $file['size'], $mime, $a['id'],
        ]
    );
    // The stored URL is an API path, not a filesystem path, so the visibility
    // check runs on every fetch.
    db_exec('UPDATE project_images SET url = ? WHERE id = ?', [$storedName, $imageId]);
    project_sync_cover($id);

    audit_record($orgId, $a, 'project.image_added', [
        'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $project['name'],
        'changes' => ['imageId' => $imageId, 'mimeType' => $mime],
    ]);
    json_created(camelize(db_one('SELECT * FROM project_images WHERE id = ?', [$imageId])));

} elseif ($method === 'PATCH' && $id !== null && $sub === 'images' && $subId !== null) {
    require_permission('projects', 'manage');
    $project = project_load_visible($orgId, $id);
    project_assert_writable($a, $project);
    if (!db_scalar('SELECT id FROM project_images WHERE id = ? AND project_id = ?', [$subId, $id])) {
        throw not_found('Image not found.');
    }
    if (array_key_exists('isCover', $body)) {
        db_exec('UPDATE project_images SET is_cover = 0 WHERE project_id = ?', [$id]);
        db_exec('UPDATE project_images SET is_cover = ? WHERE id = ?', [gv_bool($body['isCover']) ? 1 : 0, $subId]);
    }
    if (array_key_exists('caption', $body)) {
        db_exec('UPDATE project_images SET caption = ? WHERE id = ?', [$body['caption'] ?: null, $subId]);
    }
    if (array_key_exists('sortOrder', $body)) {
        db_exec('UPDATE project_images SET sort_order = ? WHERE id = ?', [(int) $body['sortOrder'], $subId]);
    }
    project_sync_cover($id);
    json_ok(project_images($id));

} elseif ($method === 'DELETE' && $id !== null && $sub === 'images' && $subId !== null) {
    require_permission('projects', 'manage');
    $project = project_load_visible($orgId, $id);
    project_assert_writable($a, $project);
    $image = db_one('SELECT * FROM project_images WHERE id = ? AND project_id = ?', [$subId, $id]);
    if (!$image) {
        throw not_found('Image not found.');
    }
    $path = gv_config('uploads.dir') . '/' . basename((string) $image['url']);
    if (is_file($path)) {
        @unlink($path);
    }
    db_exec('DELETE FROM project_images WHERE id = ?', [$subId]);
    project_sync_cover($id);

    audit_record($orgId, $a, 'project.image_removed', [
        'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $project['name'],
        'changes' => ['imageId' => $subId],
    ]);
    json_ok(project_images($id));

} elseif ($method === 'PUT' && $id !== null && $sub === 'access') {
    // Granting a project to named companies is a platform-owner action - it is
    // the mechanism behind the broker model in spec section 23.
    require_platform_admin();
    require_permission('global_projects', 'manage');
    $project = db_one('SELECT * FROM projects WHERE id = ?', [$id]);
    if (!$project) {
        throw not_found('Project not found.');
    }
    $orgIds = projects_parse_list($body['organizationIds'] ?? []);
    db_exec('UPDATE projects SET visibility = ?, updated_at = NOW() WHERE id = ?', [$orgIds ? 'SELECTED' : 'PRIVATE', $id]);
    project_set_access($id, $orgIds, $a['id']);

    audit_record($project['organization_id'], $a, 'project.access_changed', [
        'entityType' => 'PROJECT', 'entityId' => $id, 'entityLabel' => $project['name'],
        'changes' => ['organizationIds' => $orgIds],
    ]);
    json_ok(['sharedWith' => project_access_org_ids($id)]);

} else {
    throw not_found('Unknown projects route.');
}
