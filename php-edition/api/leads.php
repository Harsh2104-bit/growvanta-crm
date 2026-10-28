<?php
declare(strict_types=1);

/**
 * /api/leads/* — ported from src/routes/leads.js. Included from api/index.php
 * with $method and $rest (path segments after "leads") in scope; the caller
 * has already run require_auth()/scope_info().
 */

const LEADS_SORTABLE = [
    'createdAt' => 'leads.created_at', 'updatedAt' => 'leads.updated_at', 'score' => 'leads.score',
    'firstName' => 'leads.first_name', 'company' => 'leads.company', 'nextFollowUpAt' => 'leads.next_follow_up_at',
    'expectedValue' => 'leads.expected_value', 'code' => 'leads.code',
];

/**
 * Field-level permission gates (spec section 10).
 *
 * Who may edit a lead and who may change which project, source or status it is
 * counted against are different questions in a real-estate org, because the
 * last three decide whose campaign gets credited and whose target moves.
 * $before is null on create, where there is no previous value to compare.
 */
function leads_assert_field_permissions(array $actor, array $body, ?array $before): void
{
    $gates = [
        'statusId' => ['status_id', 'change_status', 'change the status of a lead'],
        'projectId' => ['project_id', 'change_project', 'move a lead to a different project'],
        'sourceId' => ['source_id', 'change_source', 'change the source of a lead'],
    ];
    foreach ($gates as $field => [$column, $action, $description]) {
        if (!array_key_exists($field, $body)) {
            continue;
        }
        // Setting a value that is already there is not a change.
        if ($before !== null && (string) ($before[$column] ?? '') === (string) ($body[$field] ?? '')) {
            continue;
        }
        // On create, choosing an initial value is part of creating; only an
        // edit of an existing lead needs the separate grant.
        if ($before === null) {
            continue;
        }
        if (!has_permission($actor, 'leads', $action)) {
            throw forbidden("You do not have permission to $description.");
        }
    }
}

/**
 * A lead may only point at a project this tenant can actually see. Without this
 * check a crafted projectId would attach a lead to another company's private
 * stock, which is the one cross-tenant leak the project sharing model allows
 * for if it is not closed here.
 */
function leads_assert_project_usable(string $orgId, $projectId): void
{
    if ($projectId === null || $projectId === '') {
        return;
    }
    $vis = project_visible_where($orgId);
    $found = db_scalar(
        "SELECT projects.id FROM projects WHERE projects.id = ? AND {$vis['sql']}",
        array_merge([$projectId], $vis['params'])
    );
    if (!$found) {
        throw bad_request('That project is not available to your company.');
    }
}

/** Shapes one row from the joined list/board query (aliases below) into the wire shape. */
function leads_route_shape_joined(array $r, array $tagsByLead = []): array
{
    $lead = camelize($r);
    unset(
        $lead['statusName'], $lead['statusColor'], $lead['statusKey'],
        $lead['sourceName'], $lead['sourceKey'], $lead['campaignName'], $lead['campaignCode'],
        $lead['ownerFirstName'], $lead['ownerLastName'], $lead['ownerAvatarUrl'], $lead['teamName'],
        $lead['projectLocation'], $lead['projectCity']
    );
    $lead['fullName'] = trim($r['first_name'] . ' ' . ($r['last_name'] ?? ''));
    $lead['customFields'] = safe_json($r['custom_fields'] ?? '{}', []);
    $lead['status'] = $r['status_id'] ? ['id' => $r['status_id'], 'name' => $r['status_name'], 'color' => $r['status_color'], 'key' => $r['status_key']] : null;
    $lead['source'] = $r['source_id'] ? ['id' => $r['source_id'], 'name' => $r['source_name'], 'key' => $r['source_key']] : null;
    $lead['campaign'] = $r['campaign_id'] ? ['id' => $r['campaign_id'], 'name' => $r['campaign_name'], 'code' => $r['campaign_code']] : null;
    $lead['owner'] = $r['owner_id'] ? ['id' => $r['owner_id'], 'firstName' => $r['owner_first_name'], 'lastName' => $r['owner_last_name'], 'avatarUrl' => $r['owner_avatar_url']] : null;
    $lead['team'] = $r['team_id'] ? ['id' => $r['team_id'], 'name' => $r['team_name']] : null;
    // projectName is left flat as well as nested: the list table reads the flat
    // one, the detail view the nested object.
    $lead['project'] = $r['project_id']
        ? ['id' => $r['project_id'], 'name' => $r['project_name'], 'location' => $r['project_location'], 'city' => $r['project_city']]
        : null;
    $lead['tags'] = $tagsByLead[$r['id']] ?? [];
    return $lead;
}

/** Fetches {leadId: [{id,name,color}, ...]} for a set of lead ids in one query. */
function leads_route_tags_map(array $leadIds): array
{
    if (!$leadIds) {
        return [];
    }
    [$in, $p] = db_in_clause($leadIds);
    $rows = db_all("SELECT lt.lead_id, t.id, t.name, t.color FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id WHERE lt.lead_id IN $in", $p);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['lead_id']][] = ['id' => $r['id'], 'name' => $r['name'], 'color' => $r['color']];
    }
    return $out;
}

const LEADS_JOIN_SELECT = "leads.*,
    s.name AS status_name, s.color AS status_color, s.`key` AS status_key,
    src.name AS source_name, src.`key` AS source_key,
    c.name AS campaign_name, c.code AS campaign_code,
    o.first_name AS owner_first_name, o.last_name AS owner_last_name, o.avatar_url AS owner_avatar_url,
    tm.name AS team_name,
    pj.name AS project_name, pj.location AS project_location, pj.city AS project_city";
const LEADS_JOIN_FROM = 'leads
    LEFT JOIN lead_statuses s ON s.id = leads.status_id
    LEFT JOIN lead_sources src ON src.id = leads.source_id
    LEFT JOIN campaigns c ON c.id = leads.campaign_id
    LEFT JOIN users o ON o.id = leads.owner_id
    LEFT JOIN teams tm ON tm.id = leads.team_id
    LEFT JOIN projects pj ON pj.id = leads.project_id';

/** Ensures the actor may see this specific lead, not just leads in general (mirrors loadVisibleLead). */
function leads_route_load_visible_row(string $id): array
{
    $a = actor();
    $sql = 'SELECT ' . LEADS_JOIN_SELECT . ' FROM ' . LEADS_JOIN_FROM . ' WHERE leads.id = ? AND leads.organization_id = ?';
    $row = db_one($sql, [$id, $a['organizationId']]);
    if (!$row) {
        throw not_found('Lead not found');
    }
    $scope = scope_info();
    if (!$scope['unrestricted']) {
        $visible = in_array($row['owner_id'], $scope['userIds'], true)
            || ($row['team_id'] && in_array($row['team_id'], $scope['teamIds'], true))
            || ($row['owner_id'] === null && $scope['canSeeUnassigned'])
            || $row['created_by_id'] === $a['id'];
        if (!$visible) {
            throw forbidden('This lead is outside your access scope.');
        }
    }
    return $row;
}

/** Bulk-op id resolution: organization + visibility scoped, matching scopedLeadIds. */
function leads_route_scoped_ids($ids): array
{
    if (!is_array($ids) || !$ids) {
        throw bad_request('Select at least one lead');
    }
    $a = actor();
    $scope = scope_info();
    [$in, $p] = db_in_clause(array_slice($ids, 0, 1000));
    $sql = "SELECT id FROM leads WHERE id IN $in AND organization_id = ?";
    $params = array_merge($p, [$a['organizationId']]);
    if (!$scope['unrestricted']) {
        $vis = visibility_where($scope, 'owner_id', 'team_id');
        if ($vis['sql'] !== '') {
            $sql .= " AND {$vis['sql']}";
            $params = array_merge($params, $vis['params']);
        }
    }
    return array_column(db_all($sql, $params), 'id');
}

const LEADS_CREATE_MAX_LEN = [
    'firstName' => 80, 'lastName' => 80, 'phone' => 30, 'altPhone' => 30, 'company' => 150, 'jobTitle' => 120,
    'website' => 200, 'country' => 80, 'state' => 80, 'city' => 80, 'address' => 400, 'pincode' => 20,
    'medium' => 80, 'landingPage' => 400, 'utmSource' => 120, 'utmMedium' => 120, 'utmCampaign' => 120,
    'utmTerm' => 120, 'utmContent' => 120, 'notes' => 5000,
];

function leads_route_validate_input(array $body): void
{
    $v = validate($body);
    $v->required('firstName', 'First name')->maxLength('firstName', 80);
    if (!empty($body['email'])) {
        $v->email('email');
    }
    foreach (LEADS_CREATE_MAX_LEN as $field => $max) {
        if (!empty($body[$field])) {
            $v->maxLength($field, $max);
        }
    }
    if (!empty($body['priority'])) {
        $v->in('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT']);
    }
    $v->validate();
}

// ---------------------------------------------------------------------------

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;

if ($method === 'GET' && $id === null) {
    // ---- List --------------------------------------------------------------
    require_permission('leads', 'read');
    $a = actor();
    $scope = scope_info();
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $where = build_lead_where($query, $scope, $a['organizationId']);
    // parse_sort validates ?sort= against the allow-list (camelCase keys of
    // LEADS_SORTABLE) before returning it snake_cased, so $sortCol is always
    // one of those trusted column names — safe to qualify and interpolate.
    [$sortCol, $dir] = parse_sort($query, array_keys(LEADS_SORTABLE), 'created_at');
    $orderColumn = 'leads.' . $sortCol;

    $rows = db_all(
        'SELECT ' . LEADS_JOIN_SELECT . ' FROM ' . LEADS_JOIN_FROM . " {$where['sql']} ORDER BY $orderColumn $dir LIMIT ? OFFSET ?",
        array_merge($where['params'], [$limit, $offset])
    );
    $total = (int) db_scalar('SELECT COUNT(*) FROM ' . LEADS_JOIN_FROM . " {$where['sql']}", $where['params']);
    $tagsByLead = leads_route_tags_map(array_column($rows, 'id'));

    json_ok(array_map(fn($r) => leads_route_shape_joined($r, $tagsByLead), $rows), page_meta($page, $limit, $total));
} elseif ($method === 'GET' && $id === 'board') {
    // ---- Kanban board --------------------------------------------------------
    require_permission('leads', 'read');
    $a = actor();
    $scope = scope_info();
    $where = build_lead_where($query, $scope, $a['organizationId']);
    $perColumn = min(50, (int) ($query['perColumn'] ?? 20) ?: 20);

    $statuses = db_all('SELECT * FROM lead_statuses WHERE organization_id = ? AND is_active = 1 ORDER BY sort_order ASC', [$a['organizationId']]);
    $columns = [];
    foreach ($statuses as $status) {
        $colSql = $where['sql'] . ' AND leads.status_id = ?';
        $colParams = array_merge($where['params'], [$status['id']]);
        $items = db_all('SELECT ' . LEADS_JOIN_SELECT . ' FROM ' . LEADS_JOIN_FROM . " $colSql ORDER BY leads.updated_at DESC LIMIT $perColumn", $colParams);
        $total = (int) db_scalar('SELECT COUNT(*) FROM ' . LEADS_JOIN_FROM . " $colSql", $colParams);
        $tagsByLead = leads_route_tags_map(array_column($items, 'id'));
        $columns[] = [
            'status' => camelize($status), 'total' => $total,
            'items' => array_map(fn($r) => leads_route_shape_joined($r, $tagsByLead), $items),
        ];
    }
    json_ok($columns);
} elseif ($method === 'GET' && $id === 'export') {
    // ---- Export (XLSX by default, CSV on request) -----------------------------
    require_permission('leads', 'export');
    $a = actor();
    // Export gets its own visibility resolution: a manager may be allowed to
    // read the whole region but only to export their own team (spec section 12).
    $scope = scope_for('leads', 'export');
    $where = build_lead_where($query, $scope, $a['organizationId']);
    $rows = db_all('SELECT ' . LEADS_JOIN_SELECT . ' FROM ' . LEADS_JOIN_FROM . " {$where['sql']} ORDER BY leads.created_at DESC LIMIT 20000", $where['params']);
    $tagsByLead = leads_route_tags_map(array_column($rows, 'id'));

    // Flatten the joined aliases into plain columns so both writers see the
    // same shape and the two formats cannot drift apart.
    foreach ($rows as &$r) {
        $r['owner_name'] = $r['owner_id'] ? trim($r['owner_first_name'] . ' ' . $r['owner_last_name']) : '';
        $r['tags'] = implode('; ', array_column($tagsByLead[$r['id']] ?? [], 'name'));
    }
    unset($r);

    $columns = [
        ['key' => 'code', 'label' => 'Lead ID', 'type' => 'string'],
        ['key' => 'first_name', 'label' => 'First Name', 'type' => 'string'],
        ['key' => 'last_name', 'label' => 'Last Name', 'type' => 'string'],
        ['key' => 'phone', 'label' => 'Phone', 'type' => 'string'],
        ['key' => 'alt_phone', 'label' => 'Alternate Phone', 'type' => 'string'],
        ['key' => 'email', 'label' => 'Email', 'type' => 'string'],
        ['key' => 'project_name', 'label' => 'Project', 'type' => 'string'],
        ['key' => 'configuration', 'label' => 'Configuration', 'type' => 'string'],
        ['key' => 'budget_min', 'label' => 'Budget From', 'type' => 'number'],
        ['key' => 'budget_max', 'label' => 'Budget To', 'type' => 'number'],
        ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'string'],
        ['key' => 'funding', 'label' => 'Funding', 'type' => 'string'],
        ['key' => 'possession_timeline', 'label' => 'Possession Timeline', 'type' => 'string'],
        ['key' => 'source_name', 'label' => 'Lead Source', 'type' => 'string'],
        ['key' => 'campaign_name', 'label' => 'Campaign', 'type' => 'string'],
        ['key' => 'utm_source', 'label' => 'UTM Source', 'type' => 'string'],
        ['key' => 'utm_medium', 'label' => 'UTM Medium', 'type' => 'string'],
        ['key' => 'utm_campaign', 'label' => 'UTM Campaign', 'type' => 'string'],
        ['key' => 'status_name', 'label' => 'Status', 'type' => 'string'],
        ['key' => 'score', 'label' => 'Score', 'type' => 'number'],
        ['key' => 'priority', 'label' => 'Priority', 'type' => 'string'],
        ['key' => 'owner_name', 'label' => 'Assigned To', 'type' => 'string'],
        ['key' => 'team_name', 'label' => 'Team', 'type' => 'string'],
        ['key' => 'city', 'label' => 'City', 'type' => 'string'],
        ['key' => 'state', 'label' => 'State', 'type' => 'string'],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'string'],
        ['key' => 'site_visit_at', 'label' => 'Site Visit', 'type' => 'datetime'],
        ['key' => 'last_contacted_at', 'label' => 'Last Contacted', 'type' => 'datetime'],
        ['key' => 'next_follow_up_at', 'label' => 'Next Follow-up', 'type' => 'datetime'],
        ['key' => 'created_at', 'label' => 'Created At', 'type' => 'datetime'],
    ];

    $format = strtolower((string) ($query['format'] ?? 'xlsx'));
    export_record($a['organizationId'], $a, 'LEADS', strtoupper($format), count($rows), $query);

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="growvanta-leads-' . date('Y-m-d') . '.csv"');
        echo to_csv($rows, $columns);
    } else {
        xlsx_download('growvanta-leads-' . date('Y-m-d') . '.xlsx', $columns, $rows, 'Leads');
    }
} elseif ($method === 'POST' && $id === 'check-duplicates') {
    require_permission('leads', 'read');
    $a = actor();
    json_ok(find_duplicates($a['organizationId'], $body, $body['excludeId'] ?? null));
} elseif ($method === 'POST' && $id === null) {
    // ---- Create ---------------------------------------------------------------
    require_permission('leads', 'create');
    leads_route_validate_input($body);
    $a = actor();
    $scope = scope_info();
    if (!empty($body['email'])) {
        $body['email'] = trim($body['email']) ?: null;
    }
    if (!empty($body['ownerId'])) {
        if (!has_permission($a, 'leads', 'assign')) {
            throw forbidden('You do not have permission to assign leads to another user.');
        }
        $assignScope = scope_for('leads', 'assign');
        if (!$assignScope['unrestricted'] && !in_array($body['ownerId'], $assignScope['userIds'], true)) {
            throw forbidden('You cannot assign leads to that user - they are outside the part of the organisation you manage.');
        }
    }
    leads_assert_field_permissions($a, $body, null);

    // A property enquiry is always about something. Required here, where a
    // person is typing and can choose, rather than inside create_lead() - a
    // form, an import or a Meta lead that arrives without one must still be
    // accepted, because a lead nobody can attribute beats a lead that was
    // refused at the door. Those land in the "No project" filter instead.
    if (empty($body['projectId']) && empty($body['project'])) {
        throw bad_request('Choose the project this enquiry is about.');
    }

    leads_assert_project_usable($a['organizationId'], $body['projectId'] ?? null);
    $settings = safe_json(db_scalar('SELECT settings FROM organizations WHERE id = ?', [$a['organizationId']]), []);
    $policy = !empty($body['allowDuplicate']) ? 'ALLOW' : ($settings['duplicatePolicy'] ?? 'FLAG');

    $result = create_lead($body, ['organizationId' => $a['organizationId'], 'actor' => $a, 'origin' => 'MANUAL', 'duplicatePolicy' => $policy]);
    json_created(array_merge($result['lead'], ['fullName' => trim($result['lead']['firstName'] . ' ' . ($result['lead']['lastName'] ?? '')), 'duplicates' => $result['duplicates'], 'assignment' => $result['assignment']]));
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'assign') {
    require_permission('leads', 'assign');
    $a = actor();
    $scope = scope_info();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $ownerId = $body['ownerId'] ?? null;
    $teamId = $body['teamId'] ?? null;
    if (!$ownerId && !$teamId) {
        throw bad_request('Choose a user or a team');
    }
    // Any lead in the selection that already has an owner turns this into a
    // reassignment, which needs the stronger permission.
    [$inSel, $selParams] = db_in_clause($ids);
    $alreadyOwned = (int) db_scalar(
        "SELECT COUNT(*) FROM leads WHERE organization_id = ? AND owner_id IS NOT NULL AND id IN $inSel",
        array_merge([$a['organizationId']], $selParams)
    );
    if ($alreadyOwned > 0 && !has_permission($a, 'leads', 'reassign')) {
        throw forbidden("You do not have permission to reassign leads, and $alreadyOwned of the selected leads already have an owner.");
    }
    if ($ownerId) {
        $assignScope = scope_for('leads', $alreadyOwned > 0 ? 'reassign' : 'assign');
        if (!$assignScope['unrestricted'] && !in_array($ownerId, $assignScope['userIds'], true)) {
            throw forbidden('You cannot assign leads to that user - they are outside the part of the organisation you manage.');
        }
    }
    $count = assign_leads($a['organizationId'], $ids, $ownerId ?: null, $teamId ?: null, $a);
    json_ok(['updated' => $count]);
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'status') {
    // change_status as well as update: without it this route is a way around
    // the dedicated grant, since a tick-box selection of one lead does exactly
    // what PATCH /api/leads/{id} does under a weaker permission.
    require_permission('leads', 'update');
    require_permission('leads', 'change_status');
    $a = actor();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $status = lead_resolve_status($a['organizationId'], $body['statusId'] ?? null);
    $extra = [];
    $extraParams = [];
    if (gv_bool($status['is_qualified'])) {
        $extra[] = 'qualified_at = NOW()';
    }
    if (gv_bool($status['is_lost'])) {
        $extra[] = 'lost_at = NOW()';
        $extra[] = 'lost_reason = ?';
        $extraParams[] = (string) ($body['lostReason'] ?? '');
    }
    if ($ids) {
        [$in, $p] = db_in_clause($ids);
        db_exec("UPDATE leads SET status_id = ?" . ($extra ? ', ' . implode(', ', $extra) : '') . " WHERE id IN $in", array_merge([$status['id']], $extraParams, $p));
        foreach ($ids as $leadId) {
            db_exec(
                'INSERT INTO activities (id, organization_id, type, subject, lead_id, user_id, occurred_at, created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())',
                [gv_id(), $a['organizationId'], 'STATUS_CHANGE', "Bulk status change to {$status['name']}", $leadId, $a['id']]
            );
            emit_domain_event('lead.status_changed', ['organizationId' => $a['organizationId'], 'entityType' => 'LEAD', 'data' => ['id' => $leadId, 'statusId' => $status['id'], 'status' => camelize($status)]]);
        }
    }
    audit_record($a['organizationId'], $a, 'lead.bulk_status_changed', ['entityType' => 'LEAD', 'entityLabel' => count($ids) . ' leads', 'changes' => ['statusId' => $status['id'], 'statusName' => $status['name'], 'leadIds' => $ids]]);
    json_ok(['updated' => count($ids)]);
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'tag') {
    require_permission('leads', 'update');
    $a = actor();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $names = array_values(array_filter(array_map(fn($t) => trim((string) $t), $body['tags'] ?? [])));
    if (!$names) {
        throw bad_request('Provide at least one tag');
    }
    $tagRows = [];
    foreach ($names as $name) {
        $tag = db_one('SELECT * FROM tags WHERE organization_id = ? AND name = ?', [$a['organizationId'], $name]);
        if (!$tag) {
            $tagId = gv_id();
            db_exec('INSERT INTO tags (id, organization_id, name, created_at) VALUES (?,?,?,NOW())', [$tagId, $a['organizationId'], $name]);
            $tag = ['id' => $tagId, 'name' => $name];
        }
        $tagRows[] = $tag;
    }
    foreach ($ids as $leadId) {
        foreach ($tagRows as $tag) {
            if (!db_one('SELECT id FROM lead_tags WHERE lead_id = ? AND tag_id = ?', [$leadId, $tag['id']])) {
                db_exec('INSERT INTO lead_tags (id, lead_id, tag_id) VALUES (?,?,?)', [gv_id(), $leadId, $tag['id']]);
            }
        }
    }
    json_ok(['updated' => count($ids), 'tags' => array_column($tagRows, 'name')]);
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'fields') {
    require_permission('leads', 'update');
    $a = actor();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $allowed = ['priority' => 'priority', 'sourceId' => 'source_id', 'campaignId' => 'campaign_id', 'city' => 'city', 'state' => 'state', 'country' => 'country', 'medium' => 'medium'];
    $data = [];
    foreach ($allowed as $camel => $col) {
        if (array_key_exists($camel, $body['fields'] ?? [])) {
            $data[$col] = $body['fields'][$camel] ?: null;
        }
    }
    if (array_key_exists('nextFollowUpAt', $body['fields'] ?? [])) {
        $data['next_follow_up_at'] = parse_date($body['fields']['nextFollowUpAt']);
    }
    if (!$data) {
        throw bad_request('No supported fields to update');
    }
    if ($ids) {
        [$in, $p] = db_in_clause($ids);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE leads SET $sets WHERE id IN $in", array_merge(array_values($data), $p));
    }
    audit_record($a['organizationId'], $a, 'lead.bulk_updated', ['entityType' => 'LEAD', 'entityLabel' => count($ids) . ' leads', 'changes' => $data]);
    json_ok(['updated' => count($ids)]);
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'follow-up') {
    // Replaces bulk task creation: the follow-up lives on the lead, so setting
    // it in bulk is a single UPDATE rather than a row per lead.
    require_permission('leads', 'update');
    $a = actor();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $dueInDays = (int) ($body['dueInDays'] ?? 1);
    $dueAt = add_days(date('Y-m-d H:i:s'), $dueInDays ?: 1);
    $count = 0;
    if ($ids) {
        [$in, $p] = db_in_clause($ids);
        $count = db_exec(
            "UPDATE leads SET next_follow_up_at = ?, updated_at = NOW() WHERE organization_id = ? AND id IN $in",
            array_merge([$dueAt, $a['organizationId']], $p)
        );
    }
    audit_record($a['organizationId'], $a, 'lead.bulk_follow_up_set', [
        'entityType' => 'LEAD', 'entityLabel' => count($ids) . ' leads',
        'changes' => ['nextFollowUpAt' => $dueAt],
    ]);
    json_ok(['updated' => $count]);
} elseif ($method === 'POST' && $id === 'bulk' && $sub === 'delete') {
    require_permission('leads', 'delete');
    $a = actor();
    $ids = leads_route_scoped_ids($body['leadIds'] ?? null);
    $leads = [];
    if ($ids) {
        [$in, $p] = db_in_clause($ids);
        $leads = db_all("SELECT id, code FROM leads WHERE id IN $in", $p);
        db_exec("DELETE FROM leads WHERE id IN $in", $p);
    }
    audit_record($a['organizationId'], $a, 'lead.bulk_deleted', ['entityType' => 'LEAD', 'entityLabel' => count($leads) . ' leads', 'changes' => ['codes' => array_column($leads, 'code')]]);
    json_ok(['deleted' => count($leads)]);
} elseif ($method === 'POST' && $id === 'merge') {
    require_permission('leads', 'update');
    $a = actor();
    $targetId = $body['targetId'] ?? null;
    $sourceIds = $body['sourceIds'] ?? null;
    if (!$targetId || !is_array($sourceIds) || !$sourceIds) {
        throw bad_request('Provide a target lead and at least one source lead');
    }
    leads_route_load_visible_row($targetId);
    $result = merge_leads($a['organizationId'], $targetId, $sourceIds, $a['id']);
    audit_record($a['organizationId'], $a, 'lead.merged', ['entityType' => 'LEAD', 'entityId' => $targetId, 'changes' => ['mergedIds' => $sourceIds]]);
    emit_domain_event('lead.merged', ['organizationId' => $a['organizationId'], 'entityType' => 'LEAD', 'data' => camelize($result['merged'])]);
    $merged = camelize($result['merged']);
    $merged['fullName'] = trim($merged['firstName'] . ' ' . ($merged['lastName'] ?? ''));
    json_ok(['merged' => $result['mergedCount'], 'lead' => $merged]);
} elseif ($method === 'GET' && $id && $sub === null) {
    // ---- Single lead 360 view --------------------------------------------------
    require_permission('leads', 'read');
    $a = actor();
    $row = leads_route_load_visible_row($id);
    $tagsByLead = leads_route_tags_map([$row['id']]);

    $activities = db_all(
        'SELECT a.*, u.id AS u_id, u.first_name AS u_first_name, u.last_name AS u_last_name, u.avatar_url AS u_avatar_url
         FROM activities a LEFT JOIN users u ON u.id = a.user_id WHERE a.lead_id = ? ORDER BY a.occurred_at DESC LIMIT 200',
        [$row['id']]
    );
    $documents = db_all(
        'SELECT d.*, u.id AS ub_id, u.first_name AS ub_first_name, u.last_name AS ub_last_name
         FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by_id WHERE d.lead_id = ? ORDER BY d.created_at DESC',
        [$row['id']]
    );
    $duplicates = find_duplicates($a['organizationId'], camelize($row), $row['id']);
    $score = compute_score($a['organizationId'], lead_build_context($row));

    $auditTrail = has_permission($a, 'audit', 'read')
        ? db_all('SELECT * FROM audit_logs WHERE organization_id = ? AND entity_type = ? AND entity_id = ? ORDER BY created_at DESC LIMIT 100', [$a['organizationId'], 'LEAD', $row['id']])
        : [];

    json_ok([
        'lead' => leads_route_shape_joined($row, $tagsByLead),
        'activities' => array_map(fn($r) => array_merge(camelize($r), ['meta' => safe_json($r['meta'] ?? '{}', []), 'user' => $r['user_id'] ? ['id' => $r['u_id'], 'firstName' => $r['u_first_name'], 'lastName' => $r['u_last_name'], 'avatarUrl' => $r['u_avatar_url']] : null]), $activities),
        'documents' => array_map(fn($r) => array_merge(camelize($r), ['uploadedBy' => $r['uploaded_by_id'] ? ['id' => $r['ub_id'], 'firstName' => $r['ub_first_name'], 'lastName' => $r['ub_last_name']] : null]), $documents),
        'duplicates' => $duplicates,
        'score' => $score,
        'auditTrail' => array_map(fn($r) => array_merge(camelize($r), ['changes' => safe_json($r['changes'] ?? '{}', [])]), $auditTrail),
    ]);
} elseif ($method === 'PATCH' && $id && $sub === null) {
    require_permission('leads', 'update');
    $a = actor();
    $existing = leads_route_load_visible_row($id);

    if (array_key_exists('ownerId', $body)) {
        // Taking a lead off somebody is a different act from placing an
        // unowned one, and the spec (section 16) gates them separately. The
        // permission is checked BEFORE the target scope so the refusal names
        // the real reason rather than blaming the chosen user.
        $isReassign = !empty($existing['owner_id']) && $existing['owner_id'] !== $body['ownerId'];
        $needed = $isReassign ? 'reassign' : 'assign';
        if (!has_permission($a, 'leads', $needed)) {
            throw forbidden($isReassign
                ? 'You do not have permission to reassign leads.'
                : 'You do not have permission to assign leads.');
        }
        if (!empty($body['ownerId'])) {
            $assignScope = scope_for('leads', $needed);
            if (!$assignScope['unrestricted'] && !in_array($body['ownerId'], $assignScope['userIds'], true)) {
                throw forbidden('You cannot assign leads to that user - they are outside the part of the organisation you manage.');
            }
        }
    }
    leads_assert_field_permissions($a, $body, $existing);
    leads_assert_project_usable($a['organizationId'], $body['projectId'] ?? null);

    $lead = update_lead($id, $body, ['organizationId' => $a['organizationId'], 'actor' => $a]);
    $lead['fullName'] = trim($lead['firstName'] . ' ' . ($lead['lastName'] ?? ''));
    json_ok($lead);
} elseif ($method === 'DELETE' && $id && $sub === null) {
    require_permission('leads', 'delete');
    $a = actor();
    $row = leads_route_load_visible_row($id);
    db_exec('DELETE FROM leads WHERE id = ?', [$row['id']]);
    audit_record($a['organizationId'], $a, 'lead.deleted', ['entityType' => 'LEAD', 'entityId' => $row['id'], 'entityLabel' => "{$row['code']} {$row['first_name']}"]);
    emit_domain_event('lead.deleted', ['organizationId' => $a['organizationId'], 'entityType' => 'LEAD', 'data' => camelize($row)]);
    json_ok(['deleted' => true]);
} elseif ($method === 'POST' && $id && $sub === 'activities') {
    require_permission('activities', 'create');
    $a = actor();
    $row = leads_route_load_visible_row($id);
    $v = validate($body);
    $v->required('type', 'Activity type')->in('type', ['CALL', 'EMAIL', 'MEETING', 'SMS', 'WHATSAPP', 'NOTE']);
    $v->validate();

    $occurredAt = parse_date($body['occurredAt'] ?? null) ?? date('Y-m-d H:i:s');
    $activityId = gv_id();
    db_exec(
        'INSERT INTO activities (id, organization_id, type, subject, body, direction, outcome, duration_sec, meta, occurred_at, lead_id, user_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            $activityId, $a['organizationId'], $body['type'], $body['subject'] ?? null, $body['body'] ?? null,
            $body['direction'] ?? null, $body['outcome'] ?? null, isset($body['durationSec']) ? (int) $body['durationSec'] : null,
            '{}', $occurredAt, $row['id'], $a['id'],
        ]
    );

    $leadPatch = [];
    if (in_array($body['type'], ['CALL', 'EMAIL', 'MEETING', 'WHATSAPP', 'SMS'], true)) {
        $leadPatch['last_contacted_at'] = $occurredAt;
    }
    $nextFollowUp = parse_date($body['nextFollowUpAt'] ?? null);
    if ($nextFollowUp) {
        $leadPatch['next_follow_up_at'] = $nextFollowUp;
    }
    if ($leadPatch) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($leadPatch)));
        db_exec("UPDATE leads SET $sets WHERE id = ?", array_merge(array_values($leadPatch), [$row['id']]));
    }
    rescore_lead($a['organizationId'], $row['id'], $a['id']);
    audit_record($a['organizationId'], $a, 'activity.logged', ['entityType' => 'LEAD', 'entityId' => $row['id'], 'changes' => ['type' => $body['type'], 'subject' => $body['subject'] ?? null]]);

    $activity = db_one('SELECT * FROM activities WHERE id = ?', [$activityId]);
    $activityOut = camelize($activity);
    $activityOut['meta'] = safe_json($activity['meta'] ?? '{}', []);
    $activityOut['user'] = ['id' => $a['id'], 'firstName' => $a['firstName'], 'lastName' => $a['lastName'], 'avatarUrl' => $a['avatarUrl']];
    json_created($activityOut);
} elseif ($method === 'POST' && $id && $sub === 'rescore') {
    require_permission('leads', 'update');
    $a = actor();
    leads_route_load_visible_row($id);
    $updated = rescore_lead($a['organizationId'], $id, $a['id']);
    json_ok($updated ? camelize($updated) : ['unchanged' => true]);
} else {
    throw not_found('Unknown leads route');
}
