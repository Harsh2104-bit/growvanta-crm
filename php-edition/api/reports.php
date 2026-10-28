<?php
declare(strict_types=1);

/** /api/reports/* — thin HTTP layer over services/Reports.php, ported from src/routes/reports.js. */

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$query = $_GET;

$a = actor();
$orgId = $a['organizationId'];
$scope = scope_info();

if ($method === 'GET' && $id === 'dashboard') {
    require_permission('dashboard', 'read');
    json_ok(reports_dashboard($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'leads') {
    require_permission('reports', 'read');
    json_ok(lead_report($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'performance') {
    require_permission('reports', 'read');
    json_ok(performance_report($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'marketing') {
    require_permission('reports', 'read');
    json_ok(marketing_report($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'trend') {
    require_permission('reports', 'read');
    json_ok(reports_lead_trend($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'leaderboard') {
    require_permission('dashboard', 'read');
    json_ok(reports_team_leaderboard($orgId, $scope, $query));
} elseif ($method === 'GET' && $id === 'filters') {
    // Filter options for the report toolbars, scoped to what the actor can see.
    require_permission('dashboard', 'read');
    $statuses = db_all('SELECT id, name, color, `key`, is_lost, is_qualified FROM lead_statuses WHERE organization_id = ? ORDER BY sort_order ASC', [$orgId]);
    $sources = db_all('SELECT id, name, `key` FROM lead_sources WHERE organization_id = ? ORDER BY sort_order ASC', [$orgId]);

    if ($scope['unrestricted']) {
        $teams = db_all('SELECT id, name FROM teams WHERE organization_id = ?', [$orgId]);
        $users = db_all('SELECT id, first_name, last_name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY first_name ASC', [$orgId]);
    } else {
        [$tIn, $tP] = db_in_clause($scope['teamIds']);
        $teams = db_all("SELECT id, name FROM teams WHERE organization_id = ? AND id IN $tIn", array_merge([$orgId], $tP));
        [$uIn, $uP] = db_in_clause($scope['userIds']);
        $users = db_all("SELECT id, first_name, last_name FROM users WHERE organization_id = ? AND is_active = 1 AND id IN $uIn ORDER BY first_name ASC", array_merge([$orgId], $uP));
    }

    $dimensions = [];
    foreach (GV_LEAD_DIMENSIONS as $key => $dim) {
        $dimensions[] = ['key' => $key, 'label' => $dim['label']];
    }

    json_ok([
        'statuses' => array_map('camelize', $statuses),
        'sources' => array_map('camelize', $sources),
        'teams' => array_map('camelize', $teams),
        'users' => array_map(fn($u) => ['id' => $u['id'], 'name' => trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''))], $users),
        'dimensions' => $dimensions,
    ]);
} elseif ($method === 'GET' && $id === 'export' && $sub) {
    require_permission('reports', 'export');
    $rows = [];
    $columns = [];

    if ($sub === 'leads') {
        $result = lead_report($orgId, $scope, $query);
        $rows = $result['rows'];
        $columns = [
            ['key' => 'label', 'label' => $result['dimensionLabel']],
            ['key' => 'leads', 'label' => 'Leads'], ['key' => 'qualified', 'label' => 'Qualified'], ['key' => 'converted', 'label' => 'Converted'],
            ['key' => 'qualificationRate', 'label' => 'Qualification %'], ['key' => 'conversionRate', 'label' => 'Conversion %'],
            ['key' => 'expectedValue', 'label' => 'Expected Value'], ['key' => 'share', 'label' => 'Share %'],
        ];
    } elseif ($sub === 'performance') {
        $rows = performance_report($orgId, $scope, $query);
        $columns = [
            ['key' => 'name', 'label' => 'User'], ['key' => 'email', 'label' => 'Email'],
            ['key' => 'leads', 'label' => 'Leads'], ['key' => 'calls', 'label' => 'Calls'],
            ['key' => 'meetings', 'label' => 'Meetings'], ['key' => 'emails', 'label' => 'Emails'],
            ['key' => 'converted', 'label' => 'Converted'], ['key' => 'conversionRate', 'label' => 'Conversion %'],
            ['key' => 'siteVisits', 'label' => 'Site Visits'],
            ['key' => 'deals', 'label' => 'Bookings'], ['key' => 'revenue', 'label' => 'Booking Value'],
        ];
    } elseif ($sub === 'marketing') {
        $result = marketing_report($orgId, $scope, $query);
        $rows = $result['sources'];
        $columns = [
            ['key' => 'label', 'label' => 'Lead Source'],
            ['key' => 'leads', 'label' => 'Leads'], ['key' => 'qualified', 'label' => 'Qualified'],
            ['key' => 'siteVisits', 'label' => 'Site Visits'], ['key' => 'converted', 'label' => 'Bookings'],
            ['key' => 'revenue', 'label' => 'Booking Value'],
            ['key' => 'costPerLead', 'label' => 'Cost / Lead'], ['key' => 'spend', 'label' => 'Spend'],
            ['key' => 'costPerBooking', 'label' => 'Cost / Booking'],
            ['key' => 'conversionRate', 'label' => 'Conversion %'], ['key' => 'roi', 'label' => 'ROI %'],
        ];
    } else {
        throw not_found("Unknown report \"$sub\"");
    }

    audit_record($orgId, $a, 'report.exported', ['entityLabel' => $sub, 'changes' => ['report' => $sub, 'rows' => count($rows), 'filters' => $query]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="growvanta-' . $sub . '-report-' . time() . '.csv"');
    echo to_csv($rows, $columns);
} else {
    throw not_found('Unknown reports route');
}
