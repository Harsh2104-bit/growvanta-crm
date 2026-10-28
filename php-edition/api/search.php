<?php
declare(strict_types=1);

/**
 * /api/search — global search, ported from src/routes/search.js.
 *
 * Leads, projects and people are queried independently and capped, so one very
 * common term
 * cannot make the endpoint expensive. Phone terms are normalised to the same
 * last-10-digit form used for duplicate matching, so "+91 98765 43210" finds a
 * lead stored as "9876543210".
 */

$a = require_auth();
$scope = scope_info();
$query = $_GET;
$term = trim((string) ($query['q'] ?? ''));

if ($method !== 'GET' || ($rest[0] ?? null) !== null) {
    throw not_found('Unknown search route');
}
if (strlen($term) < 2) {
    json_ok(['query' => $term, 'results' => [], 'total' => 0]);
    return;
}

$orgId = $a['organizationId'];
$perType = min(10, (int) ($query['limit'] ?? 6) ?: 6);
$like = '%' . $term . '%';
$phone = normalize_phone($term);
$results = [];

/** Appends a visibility fragment to a clause list, if the scope restricts anything. */
$applyVis = function (array $visibility, array &$clauses, array &$params): void {
    if ($visibility['sql'] !== '') {
        $clauses[] = $visibility['sql'];
        $params = array_merge($params, $visibility['params']);
    }
};

if (has_permission($a, 'leads', 'read')) {
    $clauses = ['leads.organization_id = ?', 'leads.is_duplicate = 0'];
    $params = [$orgId];
    $applyVis(visibility_where($scope, 'leads.owner_id', 'leads.team_id'), $clauses, $params);
    $match = 'leads.first_name LIKE ? OR leads.last_name LIKE ? OR leads.email LIKE ? OR leads.company LIKE ? OR leads.code LIKE ? OR leads.phone LIKE ?';
    array_push($params, $like, $like, $like, $like, $like, $like);
    if ($phone) {
        $match .= ' OR leads.phone_norm = ?';
        $params[] = $phone;
    }
    $clauses[] = "($match)";
    $rows = db_all(
        'SELECT leads.id, leads.code, leads.first_name, leads.last_name, leads.company, leads.email, leads.phone,
                s.name AS status_name, s.color AS status_color
         FROM leads LEFT JOIN lead_statuses s ON s.id = leads.status_id
         WHERE ' . implode(' AND ', $clauses) . " ORDER BY leads.updated_at DESC LIMIT $perType",
        $params
    );
    foreach ($rows as $r) {
        $results[] = [
            'type' => 'lead', 'id' => $r['id'],
            'title' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
            'subtitle' => implode(' · ', array_filter([$r['code'], $r['company'], $r['email'] ?: $r['phone']])),
            'badge' => $r['status_name'], 'badgeColor' => $r['status_color'],
            'link' => "#/leads/{$r['id']}",
        ];
    }
}

if (has_permission($a, 'projects', 'read')) {
    // Uses the same visibility predicate as the projects list, so search can
    // never surface a project another company keeps private.
    $vis = project_visible_where($orgId);
    $rows = db_all(
        "SELECT projects.id, projects.name, projects.code, projects.location, projects.city, projects.status
           FROM projects
          WHERE {$vis['sql']} AND projects.is_archived = 0
            AND (projects.name LIKE ? OR projects.location LIKE ? OR projects.developer_name LIKE ? OR projects.code LIKE ?)
          ORDER BY projects.name ASC LIMIT $perType",
        array_merge($vis['params'], [$like, $like, $like, $like])
    );
    foreach ($rows as $r) {
        $results[] = [
            'type' => 'project', 'id' => $r['id'], 'title' => $r['name'],
            'subtitle' => implode(' · ', array_filter([$r['code'], $r['location'] ?: $r['city']])),
            'badge' => $r['status'],
            'link' => "#/projects/{$r['id']}",
        ];
    }
}

if (has_permission($a, 'users', 'read')) {
    $clauses = ['users.organization_id = ?', 'users.is_active = 1'];
    $params = [$orgId];
    $clauses[] = '(users.first_name LIKE ? OR users.last_name LIKE ? OR users.email LIKE ?)';
    array_push($params, $like, $like, $like);
    $rows = db_all(
        'SELECT users.id, users.first_name, users.last_name, users.email, roles.name AS role_name
           FROM users LEFT JOIN roles ON roles.id = users.role_id
          WHERE ' . implode(' AND ', $clauses) . " ORDER BY users.first_name ASC LIMIT $perType",
        $params
    );
    foreach ($rows as $r) {
        $results[] = [
            'type' => 'user', 'id' => $r['id'],
            'title' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
            'subtitle' => implode(' · ', array_filter([$r['role_name'], $r['email']])),
            'link' => '#/users',
        ];
    }
}

json_ok(['query' => $term, 'results' => $results, 'total' => count($results)]);
