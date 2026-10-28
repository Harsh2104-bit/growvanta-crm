<?php
declare(strict_types=1);

/**
 * /api/platform/* - the Super Admin surface.
 *
 * Every route here is gated by require_platform_admin(), which checks a column
 * on the user row, not a permission a tenant could grant itself. A tenant
 * admin who edits their own role to include `clients:read` still gets 403,
 * because the permission and the platform flag are independent gates.
 *
 * The one exception is GET /branding/{file}, handled before that gate: a
 * company's logo has to load for its own staff and on its sign-in page, where
 * nobody is a platform administrator and often nobody is signed in at all.
 *
 * Switching into a client writes sessions.active_organization_id; from that
 * point every ordinary route in the app reads and writes that tenant, because
 * lib/auth.php resolves $actor['organizationId'] from the session.
 */

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$subId = $rest[2] ?? null;
$body = request_body();
$query = $_GET;

// ---------------------------------------------------------------------------
// Branding images are served BEFORE the platform guard below.
//
// A company logo appears in that company's own sidebar and on its sign-in
// screen - so the people who need to load it are its ordinary users, and
// visitors who are not signed in at all. Behind require_platform_admin() every
// one of them received a 401 and a broken image; only the platform owner ever
// saw the logo they had uploaded.
//
// Safe to serve unauthenticated: the request is reduced to a basename inside
// the upload directory, must begin with `logo-`, and the stored name carries 12
// random hex characters, so nothing else in that directory is reachable or
// guessable through this route.
// ---------------------------------------------------------------------------
if ($method === 'GET' && $id === 'branding' && $sub !== null) {
    $name = basename($sub);
    $path = gv_config('uploads.dir') . '/' . $name;
    if (!str_starts_with($name, 'logo-') || !is_file($path)) {
        throw not_found('Image not found.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path) ?: 'application/octet-stream';
    // Only ever hand back an image, whatever the bytes turn out to be.
    if (strpos($mime, 'image/') !== 0) {
        throw not_found('Image not found.');
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    return;
}

$a = require_platform_admin();

/** Aggregate counters for one client, used by the list and the detail page. */
function platform_client_stats(string $orgId): array
{
    return [
        'users' => (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1', [$orgId]),
        'teams' => (int) db_scalar('SELECT COUNT(*) FROM teams WHERE organization_id = ?', [$orgId]),
        'projects' => (int) db_scalar('SELECT COUNT(*) FROM projects WHERE organization_id = ? AND is_archived = 0', [$orgId]),
        'leads' => (int) db_scalar('SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_duplicate = 0', [$orgId]),
        'leadsToday' => (int) db_scalar(
            'SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_duplicate = 0 AND created_at >= ?',
            [$orgId, start_of_day()]
        ),
        'leadsThisMonth' => (int) db_scalar(
            'SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_duplicate = 0 AND created_at >= ?',
            [$orgId, date('Y-m-01 00:00:00')]
        ),
    ];
}

function platform_client_shape(array $org, bool $withStats = true): array
{
    $out = camelize($org);
    unset($out['settings']);
    $out['branding'] = org_branding($org);
    $out['billing'] = org_billing($org);
    $out['accessDenial'] = org_access_denial($org);
    $out['usesGlobalProjects'] = org_uses_global_projects((string) $org['company_type']);
    if ($withStats) {
        $out['stats'] = platform_client_stats($org['id']);
        // The Super Admin overview in spec section 39 needs both channels at a
        // glance, so the verdict travels with the client row. It is nested
        // under `messaging` because `email` at the top level is the company's
        // own email address.
        $out['messaging'] = [
            'whatsapp' => ['status' => check_whatsapp_configuration($org['id'])['status']],
            'email' => ['status' => check_email_configuration($org['id'])['status']],
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------
if ($method === 'GET' && $id === 'overview') {
    require_permission('clients', 'read');

    $byType = db_all(
        'SELECT company_type, COUNT(*) AS total FROM organizations WHERE is_platform = 0 GROUP BY company_type'
    );
    $typeCounts = [];
    foreach (GV_COMPANY_TYPES as $t) {
        $typeCounts[$t['key']] = 0;
    }
    foreach ($byType as $r) {
        $typeCounts[$r['company_type']] = (int) $r['total'];
    }

    json_ok([
        'clients' => [
            'total' => (int) db_scalar('SELECT COUNT(*) FROM organizations WHERE is_platform = 0'),
            'active' => (int) db_scalar("SELECT COUNT(*) FROM organizations WHERE is_platform = 0 AND status = 'ACTIVE'"),
            'inactive' => (int) db_scalar("SELECT COUNT(*) FROM organizations WHERE is_platform = 0 AND status = 'INACTIVE'"),
            'suspended' => (int) db_scalar("SELECT COUNT(*) FROM organizations WHERE is_platform = 0 AND status = 'SUSPENDED'"),
            'byType' => $typeCounts,
            'blocked' => (int) db_scalar('SELECT COUNT(*) FROM organizations WHERE is_platform = 0 AND access_blocked = 1'),
            'overdue' => (int) db_scalar("SELECT COUNT(*) FROM organizations WHERE is_platform = 0 AND billing_status = 'PAST_DUE'"),
        ],
        'projects' => [
            'total' => (int) db_scalar('SELECT COUNT(*) FROM projects WHERE is_archived = 0'),
            'global' => (int) db_scalar("SELECT COUNT(*) FROM projects WHERE visibility = 'GLOBAL' AND is_archived = 0"),
            'shared' => (int) db_scalar("SELECT COUNT(*) FROM projects WHERE visibility = 'SELECTED' AND is_archived = 0"),
        ],
        'leads' => [
            'total' => (int) db_scalar('SELECT COUNT(*) FROM leads WHERE is_duplicate = 0'),
            'today' => (int) db_scalar('SELECT COUNT(*) FROM leads WHERE is_duplicate = 0 AND created_at >= ?', [start_of_day()]),
            'thisMonth' => (int) db_scalar('SELECT COUNT(*) FROM leads WHERE is_duplicate = 0 AND created_at >= ?', [date('Y-m-01 00:00:00')]),
        ],
        'users' => (int) db_scalar('SELECT COUNT(*) FROM users WHERE is_active = 1'),
        'leadsByClient' => camelize(db_all(
            'SELECT organizations.id, organizations.name, organizations.company_type, COUNT(leads.id) AS total
               FROM organizations LEFT JOIN leads ON leads.organization_id = organizations.id AND leads.is_duplicate = 0
              WHERE organizations.is_platform = 0
              GROUP BY organizations.id, organizations.name, organizations.company_type
              ORDER BY total DESC LIMIT 12'
        )),
        'leadsBySource' => camelize(db_all(
            'SELECT lead_sources.name, COUNT(leads.id) AS total
               FROM leads JOIN lead_sources ON lead_sources.id = leads.source_id
              WHERE leads.is_duplicate = 0
              GROUP BY lead_sources.name ORDER BY total DESC LIMIT 12'
        )),
        'leadsByProject' => camelize(db_all(
            'SELECT projects.name, COUNT(leads.id) AS total
               FROM leads JOIN projects ON projects.id = leads.project_id
              WHERE leads.is_duplicate = 0
              GROUP BY projects.name ORDER BY total DESC LIMIT 12'
        )),
    ]);

} elseif ($method === 'GET' && $id === 'automation') {
    // Spec section 39: which clients have not finished their setup.
    require_permission('clients', 'read');
    $orgs = db_all('SELECT id, name, company_type, status FROM organizations WHERE is_platform = 0 ORDER BY name ASC');
    json_ok(array_map(fn($o) => [
        'id' => $o['id'],
        'name' => $o['name'],
        'companyType' => $o['company_type'],
        'status' => $o['status'],
        'whatsapp' => check_whatsapp_configuration($o['id']),
        'email' => check_email_configuration($o['id']),
    ], $orgs));

} elseif ($method === 'GET' && $id === 'billing') {
    require_permission('clients', 'read');
    $orgs = db_all('SELECT * FROM organizations WHERE is_platform = 0 ORDER BY name ASC');
    $rows = array_map(fn($o) => [
        'id' => $o['id'], 'name' => $o['name'], 'companyType' => $o['company_type'],
        'status' => $o['status'], 'billing' => org_billing($o),
        'users' => (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1', [$o['id']]),
    ], $orgs);
    json_ok([
        'statuses' => GV_BILLING_STATUSES,
        'clients' => $rows,
        'summary' => [
            'blocked' => count(array_filter($rows, fn($r) => $r['billing']['accessBlocked'])),
            'overdue' => count(array_filter($rows, fn($r) => $r['billing']['overdue'])),
            'trial' => count(array_filter($rows, fn($r) => $r['billing']['status'] === 'TRIAL')),
            'mrr' => round(array_sum(array_map(
                fn($r) => $r['billing']['status'] === 'ACTIVE' ? (float) ($r['billing']['monthlyFee'] ?? 0) : 0,
                $rows
            )), 2),
        ],
    ]);

} elseif ($method === 'GET' && $id === 'company-types') {
    json_ok(GV_COMPANY_TYPES);

} elseif ($method === 'GET' && $id === 'clients' && $sub === null) {
    require_permission('clients', 'read');
    $where = ['organizations.is_platform = 0'];
    $params = [];
    if (!empty($query['q'])) {
        $where[] = '(organizations.name LIKE ? OR organizations.email LIKE ? OR organizations.slug LIKE ?)';
        $like = '%' . $query['q'] . '%';
        array_push($params, $like, $like, $like);
    }
    foreach (['companyType' => 'company_type', 'status' => 'status'] as $q => $col) {
        if (!empty($query[$q])) {
            $where[] = "organizations.$col = ?";
            $params[] = $query[$q];
        }
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $total = (int) db_scalar("SELECT COUNT(*) FROM organizations $whereSql", $params);
    $rows = db_all("SELECT * FROM organizations $whereSql ORDER BY name ASC LIMIT $limit OFFSET $offset", $params);
    json_ok(array_map(fn($o) => platform_client_shape($o), $rows), page_meta($page, $limit, $total));

} elseif ($method === 'POST' && $id === 'clients' && $sub === null) {
    // $sub === null matters: without it this branch also swallows
    // POST /clients/{id}/access and /clients/{id}/logo.
    require_permission('clients', 'create');
    $result = provision_client($body, $a);
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$result['organizationId']]);
    json_created(array_merge(platform_client_shape($org), [
        'adminUserId' => $result['adminUserId'],
    ]));

} elseif ($method === 'GET' && $id === 'clients' && $sub !== null && $subId === null) {
    require_permission('clients', 'read');
    $org = db_one('SELECT * FROM organizations WHERE id = ? AND is_platform = 0', [$sub]);
    if (!$org) {
        throw not_found('Client not found.');
    }
    $payload = platform_client_shape($org);
    $payload['admins'] = camelize(db_all(
        "SELECT users.id, users.first_name, users.last_name, users.email, users.phone, users.is_active, users.last_login_at, roles.name AS role_name
           FROM users JOIN roles ON roles.id = users.role_id
          WHERE users.organization_id = ? AND roles.`key` IN ('admin','super_admin')
          ORDER BY users.created_at ASC",
        [$sub]
    ));
    $payload['messaging'] = [
        'whatsapp' => check_whatsapp_configuration($sub),
        'email' => check_email_configuration($sub),
    ];
    json_ok($payload);

} elseif ($method === 'PATCH' && $id === 'clients' && $sub !== null && $subId === null) {
    require_permission('clients', 'update');
    $org = db_one('SELECT * FROM organizations WHERE id = ? AND is_platform = 0', [$sub]);
    if (!$org) {
        throw not_found('Client not found.');
    }

    $map = [
        'name' => 'name', 'legalName' => 'legal_name', 'companyType' => 'company_type',
        'website' => 'website', 'email' => 'email', 'phone' => 'phone', 'address' => 'address',
        'city' => 'city', 'state' => 'state', 'country' => 'country', 'pincode' => 'pincode',
        'logoUrl' => 'logo_url', 'faviconUrl' => 'favicon_url', 'brandPrimary' => 'brand_primary',
        'brandAccent' => 'brand_accent', 'loginHeadline' => 'login_headline',
        'currency' => 'currency', 'timezone' => 'timezone',
        'planName' => 'plan_name', 'seats' => 'seats', 'monthlyFee' => 'monthly_fee',
        'trialEndsAt' => 'trial_ends_at', 'renewsAt' => 'renews_at',
        'lastPaymentAt' => 'last_payment_at', 'billingNotes' => 'billing_notes',
    ];
    $data = [];
    foreach ($map as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] === '' ? null : $body[$camel];
        }
    }
    if (isset($data['company_type']) && !is_valid_company_type((string) $data['company_type'])) {
        throw bad_request('Choose a valid company type.');
    }
    if (array_key_exists('billingStatus', $body)) {
        $billing = strtoupper((string) $body['billingStatus']);
        if (!is_valid_billing_status($billing)) {
            throw bad_request('Choose a valid billing status.');
        }
        $data['billing_status'] = $billing;
    }

    // Lifecycle is expressed as one status plus the legacy is_active flag, kept
    // in step because auth_load_actor() refuses a session in an inactive org.
    if (array_key_exists('status', $body)) {
        $status = strtoupper((string) $body['status']);
        if (!in_array($status, ['ACTIVE', 'INACTIVE', 'SUSPENDED'], true)) {
            throw bad_request('Status must be ACTIVE, INACTIVE or SUSPENDED.');
        }
        $data['status'] = $status;
        $data['is_active'] = $status === 'ACTIVE' ? 1 : 0;
        $data['suspended_at'] = $status === 'SUSPENDED' ? date('Y-m-d H:i:s') : null;
        $data['suspend_reason'] = $status === 'SUSPENDED' ? ($body['suspendReason'] ?? null) : null;
    }

    if ($data) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE organizations SET $set, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$sub]));
    }

    $after = db_one('SELECT * FROM organizations WHERE id = ?', [$sub]);
    audit_record($sub, $a, 'client.updated', [
        'entityType' => 'ORGANIZATION', 'entityId' => $sub, 'entityLabel' => $after['name'],
        'changes' => gv_diff($org, $after, array_keys($data)),
    ]);
    json_ok(platform_client_shape($after));

} elseif ($method === 'POST' && $id === 'clients' && $sub !== null && $subId === 'access') {
    // Turning off sign-in for a company that has not paid, and turning it back
    // on. Separate from suspension: this is a billing lever, it is reversible in
    // one click, and it says why on the sign-in screen so the customer knows
    // who to call rather than filing a bug.
    require_permission('clients', 'update');
    $org = db_one('SELECT * FROM organizations WHERE id = ? AND is_platform = 0', [$sub]);
    if (!$org) {
        throw not_found('Client not found.');
    }

    $blocked = gv_bool($body['blocked'] ?? false);
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($blocked && $reason === '') {
        $reason = 'Subscription payment outstanding.';
    }

    db_exec(
        'UPDATE organizations
            SET access_blocked = ?, access_blocked_at = ?, access_blocked_reason = ?, updated_at = NOW()
          WHERE id = ?',
        [$blocked ? 1 : 0, $blocked ? date('Y-m-d H:i:s') : null, $blocked ? substr($reason, 0, 300) : null, $sub]
    );

    // Blocking ends every open session immediately, so the lockout is real
    // rather than taking effect whenever people next happen to sign out.
    $endedSessions = 0;
    if ($blocked) {
        $endedSessions = db_exec(
            'UPDATE sessions SET revoked_at = NOW()
              WHERE revoked_at IS NULL AND user_id IN (SELECT id FROM users WHERE organization_id = ?)',
            [$sub]
        );
    }

    audit_record($sub, $a, $blocked ? 'client.access_blocked' : 'client.access_restored', [
        'entityType' => 'ORGANIZATION', 'entityId' => $sub, 'entityLabel' => $org['name'],
        'changes' => ['reason' => $blocked ? $reason : null, 'sessionsEnded' => $endedSessions],
    ]);

    $after = db_one('SELECT * FROM organizations WHERE id = ?', [$sub]);
    json_ok(array_merge(platform_client_shape($after), ['sessionsEnded' => $endedSessions]));

} elseif ($method === 'DELETE' && $id === 'clients' && $sub !== null) {
    require_permission('clients', 'delete');
    $org = db_one('SELECT * FROM organizations WHERE id = ? AND is_platform = 0', [$sub]);
    if (!$org) {
        throw not_found('Client not found.');
    }
    // Deleting a tenant cascades through every table that references it. That
    // is destructive and unrecoverable, so it needs the company name typed back.
    if (($body['confirmName'] ?? null) !== $org['name']) {
        throw bad_request('Type the company name exactly to confirm deletion.');
    }
    $stats = platform_client_stats($sub);
    delete_organization($sub);
    audit_record(platform_organization_id() ?? $sub, $a, 'client.deleted', [
        'entityType' => 'ORGANIZATION', 'entityId' => $sub, 'entityLabel' => $org['name'],
        'changes' => $stats,
    ]);
    json_ok(['deleted' => true, 'removed' => $stats]);

} elseif ($method === 'POST' && $id === 'clients' && $sub !== null && $subId === 'logo') {
    require_permission('clients', 'update');
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$sub]);
    if (!$org) {
        throw not_found('Client not found.');
    }
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw bad_request('Choose a logo image to upload.');
    }
    if ($file['size'] > (int) gv_config('uploads.max_bytes')) {
        throw bad_request('That image is larger than the upload limit.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    if (!isset($exts[$mime])) {
        throw bad_request('Logos must be PNG, JPG, WEBP or SVG.');
    }
    // SVG can carry script, so it is never re-served inline from our origin
    // without a sniff guard; raster types additionally have to decode.
    if ($mime !== 'image/svg+xml' && @getimagesize($file['tmp_name']) === false) {
        throw bad_request('That file is not a readable image.');
    }

    $uploadDir = gv_config('uploads.dir');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $storedName = 'logo-' . $sub . '-' . bin2hex(random_bytes(6)) . '.' . $exts[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) {
        throw bad_request('The logo could not be saved.');
    }
    $url = '/api/platform/branding/' . rawurlencode($storedName);
    db_exec('UPDATE organizations SET logo_url = ?, updated_at = NOW() WHERE id = ?', [$url, $sub]);

    audit_record($sub, $a, 'client.branding_changed', [
        'entityType' => 'ORGANIZATION', 'entityId' => $sub, 'entityLabel' => $org['name'],
        'changes' => ['logoUrl' => $url],
    ]);
    json_ok(['logoUrl' => $url]);

} elseif ($method === 'GET' && $id === 'clients' && $sub !== null && in_array($subId, ['users', 'teams', 'projects', 'leads'], true)) {
    // Read-only windows into a client, for support without switching context.
    require_permission('clients', 'read');
    if (!db_scalar('SELECT id FROM organizations WHERE id = ?', [$sub])) {
        throw not_found('Client not found.');
    }
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);

    if ($subId === 'users') {
        $total = (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id = ?', [$sub]);
        $rows = db_all(
            'SELECT users.id, users.first_name, users.last_name, users.email, users.phone, users.designation,
                    users.is_active, users.last_login_at, roles.name AS role_name, teams.name AS team_name
               FROM users
               LEFT JOIN roles ON roles.id = users.role_id
               LEFT JOIN team_members ON team_members.user_id = users.id
               LEFT JOIN teams ON teams.id = team_members.team_id
              WHERE users.organization_id = ? ORDER BY users.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$sub]
        );
    } elseif ($subId === 'teams') {
        $total = (int) db_scalar('SELECT COUNT(*) FROM teams WHERE organization_id = ?', [$sub]);
        $rows = db_all(
            'SELECT teams.*, COUNT(team_members.user_id) AS member_count
               FROM teams LEFT JOIN team_members ON team_members.team_id = teams.id
              WHERE teams.organization_id = ?
              GROUP BY teams.id ORDER BY teams.name ASC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$sub]
        );
    } elseif ($subId === 'projects') {
        $total = (int) db_scalar('SELECT COUNT(*) FROM projects WHERE organization_id = ?', [$sub]);
        $rows = db_all(
            'SELECT * FROM projects WHERE organization_id = ? ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$sub]
        );
    } else {
        $total = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE organization_id = ? AND is_duplicate = 0', [$sub]);
        $rows = db_all(
            'SELECT leads.id, leads.code, leads.first_name, leads.last_name, leads.phone, leads.email, leads.created_at,
                    lead_statuses.name AS status_name, projects.name AS project_name, lead_sources.name AS source_name,
                    owner.first_name AS owner_first_name, owner.last_name AS owner_last_name
               FROM leads
               LEFT JOIN lead_statuses ON lead_statuses.id = leads.status_id
               LEFT JOIN projects ON projects.id = leads.project_id
               LEFT JOIN lead_sources ON lead_sources.id = leads.source_id
               LEFT JOIN users owner ON owner.id = leads.owner_id
              WHERE leads.organization_id = ? AND leads.is_duplicate = 0
              ORDER BY leads.created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$sub]
        );
    }
    json_ok(camelize($rows), page_meta($page, $limit, $total));


} elseif ($method === 'POST' && $id === 'switch') {
    require_permission('clients', 'impersonate');
    $target = $body['organizationId'] ?? null;

    if ($target === null || $target === '') {
        switch_active_tenant($GLOBALS['GV_SESSION']['id'], null);
        audit_record(platform_organization_id() ?? $a['homeOrganizationId'], $a, 'platform.context_exited', [
            'entityType' => 'ORGANIZATION', 'changes' => [],
        ]);
        // json_ok() echoes and returns - it does not end the request. Without
        // this return the code below then looks up an organisation with a null
        // id, finds none, and throws a 404 after the success body has already
        // been written: the client receives two JSON documents concatenated,
        // fails to parse them, and reports a bare "Request failed (404)" while
        // the exit it asked for has in fact already happened.
        return;
    }

    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$target]);
    if (!$org) {
        throw not_found('Client not found.');
    }
    if (!gv_bool($org['is_active'])) {
        throw bad_request('That company is suspended. Reactivate it before opening its CRM.');
    }
    switch_active_tenant($GLOBALS['GV_SESSION']['id'], $target);

    // Entering a client's data is exactly the kind of thing an audit trail
    // exists for, so it is logged in that client's own log.
    audit_record($target, $a, 'platform.context_entered', [
        'entityType' => 'ORGANIZATION', 'entityId' => $target, 'entityLabel' => $org['name'], 'changes' => [],
    ]);
    json_ok(['organizationId' => $target, 'name' => $org['name'], 'branding' => org_branding($org)]);

} else {
    throw not_found('Unknown platform route.');
}
