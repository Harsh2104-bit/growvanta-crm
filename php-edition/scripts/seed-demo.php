<?php
declare(strict_types=1);

/**
 * Builds the public demo company.
 *
 *   php scripts/seed-demo.php            create it (does nothing if it exists)
 *   php scripts/seed-demo.php --reset    wipe it and build it again
 *
 * The demo sign-in is printed on a public page, which means anyone may use it,
 * change what they find and delete things. That is fine — as long as it is a
 * company of its own. Nothing here touches a real customer's data, and --reset
 * puts the demo back to a clean state whenever a visitor has made a mess of it.
 *
 * Run --reset from cron once a night if the demo is linked publicly.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require __DIR__ . '/../bootstrap.php';

const DEMO_COMPANY  = 'Skyline Realty (Demo)';
const DEMO_EMAIL    = 'demo@growvantacrm.online';
const DEMO_PASSWORD = 'GrowDemo@2026';

$reset = in_array('--reset', array_slice($argv, 1), true);

echo "\nDemo workspace\n" . str_repeat('-', 60) . "\n";

$existing = db_one('SELECT id FROM organizations WHERE name = ?', [DEMO_COMPANY]);
if ($existing && !$reset) {
    echo "  already exists ({$existing['id']}) - pass --reset to rebuild it\n\n";
    echo "  sign in : " . DEMO_EMAIL . "\n";
    echo "  password: " . DEMO_PASSWORD . "\n\n";
    exit(0);
}
if ($existing) {
    delete_organization($existing['id']);
    echo "  removed the previous demo workspace\n";
}

// ---------------------------------------------------------------------------
// The company and its admin
// ---------------------------------------------------------------------------
$prov = provision_client([
    'companyName' => DEMO_COMPANY,
    'companyType' => 'DEVELOPER',
    'adminName'   => 'Demo Admin',
    'adminEmail'  => DEMO_EMAIL,
    'password'    => DEMO_PASSWORD,
], null);

$orgId = $prov['organizationId'];
$adminId = $prov['adminUserId'];
echo "  company created: " . DEMO_COMPANY . "\n";

// ---------------------------------------------------------------------------
// A small hierarchy, so the org chart and the visibility rules have something
// to show. A demo with one user cannot demonstrate who-sees-whose-leads.
// ---------------------------------------------------------------------------
function demo_user(string $orgId, string $roleKey, string $first, string $last, string $email, ?string $managerId = null): string
{
    $roleId = db_scalar('SELECT id FROM roles WHERE organization_id = ? AND `key` = ?', [$orgId, $roleKey]);
    $id = gv_id();
    db_exec(
        'INSERT INTO users (id, organization_id, role_id, manager_id, first_name, last_name, email,
                            password_hash, job_title, is_active, is_assignable, email_verified_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,1,1,NOW(),NOW(),NOW())',
        [$id, $orgId, $roleId, $managerId, $first, $last, $email,
         hash_password(DEMO_PASSWORD), ucwords(str_replace('_', ' ', $roleKey))]
    );
    return $id;
}

$managerId = demo_user($orgId, 'sales_manager', 'Priya', 'Nair', 'priya.demo@growvantacrm.online', $adminId);
$tlId      = demo_user($orgId, 'team_leader', 'Arjun', 'Mehta', 'arjun.demo@growvantacrm.online', $managerId);
$exec1     = demo_user($orgId, 'sales_executive', 'Kavya', 'Reddy', 'kavya.demo@growvantacrm.online', $tlId);
$exec2     = demo_user($orgId, 'sales_executive', 'Imran', 'Shaikh', 'imran.demo@growvantacrm.online', $tlId);
$teleId    = demo_user($orgId, 'telecaller', 'Neha', 'Joshi', 'neha.demo@growvantacrm.online', $tlId);
echo "  5 team members created under the admin\n";

$teamId = gv_id();
db_exec('INSERT INTO teams (id, organization_id, name, manager_id, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())',
    [$teamId, $orgId, 'West Zone Sales', $tlId]);
foreach ([$exec1, $exec2, $teleId] as $member) {
    db_exec('INSERT INTO team_members (id, team_id, user_id, joined_at) VALUES (?,?,?,NOW())', [gv_id(), $teamId, $member]);
}

// ---------------------------------------------------------------------------
// Inventory
// ---------------------------------------------------------------------------
$projects = [
    ['name' => 'Skyline Heights', 'location' => 'Wakad, Pune', 'city' => 'Pune', 'state' => 'Maharashtra',
     'type' => 'RESIDENTIAL', 'status' => 'UNDER_CONSTRUCTION', 'rera' => 'P52100051234',
     'min' => 8500000, 'max' => 14500000, 'areaMin' => 780, 'areaMax' => 1320,
     'config' => ['2 BHK', '3 BHK'], 'possession' => '+14 months',
     'amenities' => ['Clubhouse', 'Rooftop pool', 'Gym', 'Kids play area']],
    ['name' => 'Skyline Business Bay', 'location' => 'Hinjawadi Phase 2, Pune', 'city' => 'Pune', 'state' => 'Maharashtra',
     'type' => 'COMMERCIAL', 'status' => 'READY_TO_MOVE', 'rera' => 'P52100067890',
     'min' => 12000000, 'max' => 48000000, 'areaMin' => 550, 'areaMax' => 2400,
     'config' => ['Office suites', 'Retail'], 'possession' => '-2 months',
     'amenities' => ['Double-height lobby', '3-level parking', 'Backup power']],
    ['name' => 'Skyline Greens', 'location' => 'Shirgaon, Lonavala', 'city' => 'Lonavala', 'state' => 'Maharashtra',
     'type' => 'RESIDENTIAL', 'status' => 'NEW_LAUNCH', 'rera' => 'P52100078451',
     'min' => 19000000, 'max' => 36000000, 'areaMin' => 2100, 'areaMax' => 3800,
     'config' => ['3 BHK Villa', '4 BHK Villa'], 'possession' => '+30 months',
     'amenities' => ['Private plots', 'Clubhouse', 'Organic farm']],
];

$projectIds = [];
foreach ($projects as $p) {
    $id = gv_id();
    db_exec(
        'INSERT INTO projects (id, organization_id, code, name, slug, location, developer_name, project_type,
                               visibility, city, state, country, rera_number, status, possession_date,
                               price_min, price_max, area_min, area_max, area_unit, configurations, amenities,
                               description, is_active, created_by_id, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW(),NOW())',
        [
            $id, $orgId, project_next_code($orgId), $p['name'], project_unique_slug($orgId, $p['name']),
            $p['location'], 'Skyline Group', $p['type'], 'PRIVATE', $p['city'], $p['state'], 'India',
            $p['rera'], $p['status'], date('Y-m-d', strtotime($p['possession'])),
            $p['min'], $p['max'], $p['areaMin'], $p['areaMax'], 'SQFT', to_json($p['config']), to_json($p['amenities']),
            $p['name'] . ' by Skyline Group at ' . $p['location'] . '. Demonstration data.', $adminId,
        ]
    );
    $projectIds[$p['name']] = $id;
}
echo "  3 projects created\n";

// ---------------------------------------------------------------------------
// Leads spread across the pipeline, so every screen has something in it
// ---------------------------------------------------------------------------
$statusId = fn(string $key) => db_scalar('SELECT id FROM lead_statuses WHERE organization_id = ? AND `key` = ?', [$orgId, $key]);
$sourceId = fn(string $name) => db_scalar('SELECT id FROM lead_sources WHERE organization_id = ? AND name = ?', [$orgId, $name]);

$sources = array_column(db_all('SELECT name FROM lead_sources WHERE organization_id = ? LIMIT 8', [$orgId]), 'name');
$owners = [$exec1, $exec2, $teleId];

$leads = [
    ['Rohan', 'Kulkarni',  '9822011001', 'rohan.k@example.com',    'Skyline Heights',      'new',                  0,  8500000, 11000000, '2 BHK', 'WITHIN_1_YEAR'],
    ['Sneha', 'Patil',     '9822011002', 'sneha.patil@example.com','Skyline Heights',      'contacted',            1, 10000000, 13000000, '3 BHK', 'WITHIN_6_MONTHS'],
    ['Aditya','Rao',       '9822011003', 'aditya.rao@example.com', 'Skyline Business Bay', 'interested',           2, 15000000, 22000000, 'Office suites', 'IMMEDIATE'],
    ['Meera', 'Shah',      '9822011004', 'meera.shah@example.com', 'Skyline Greens',       'qualified',            0, 20000000, 28000000, '3 BHK Villa', 'WITHIN_2_YEARS'],
    ['Vikram','Desai',     '9822011005', 'vikram.desai@example.com','Skyline Heights',     'site_visit_scheduled', 1,  9000000, 12000000, '2 BHK', 'WITHIN_1_YEAR'],
    ['Farah', 'Khan',      '9822011006', 'farah.khan@example.com', 'Skyline Greens',       'site_visit_done',      2, 24000000, 33000000, '4 BHK Villa', 'FLEXIBLE'],
    ['Nikhil','Jain',      '9822011007', 'nikhil.jain@example.com','Skyline Business Bay', 'negotiation',          0, 18000000, 25000000, 'Retail', 'IMMEDIATE'],
    ['Ananya','Bhat',      '9822011008', 'ananya.bhat@example.com','Skyline Heights',      'booked',               1, 11500000, 11500000, '3 BHK', 'IMMEDIATE'],
    ['Suresh','Iyer',      '9822011009', 'suresh.iyer@example.com','Skyline Heights',      'contacted',            2,  8500000,  9500000, '2 BHK', 'WITHIN_1_YEAR'],
    ['Pooja', 'Gupta',     '9822011010', 'pooja.gupta@example.com','Skyline Greens',       'interested',           0, 19000000, 24000000, '3 BHK Villa', 'WITHIN_2_YEARS'],
    ['Rahul', 'Menon',     '9822011011', 'rahul.menon@example.com','Skyline Business Bay', 'new',                  1, 12000000, 16000000, 'Office suites', 'WITHIN_6_MONTHS'],
    ['Divya', 'Sharma',    '9822011012', 'divya.sharma@example.com','Skyline Heights',     'qualified',            2, 10500000, 14000000, '3 BHK', 'WITHIN_1_YEAR'],
    ['Karan', 'Malhotra',  '9822011013', 'karan.m@example.com',    'Skyline Greens',       'site_visit_scheduled', 0, 26000000, 34000000, '4 BHK Villa', 'FLEXIBLE'],
    ['Tanvi', 'Deshpande', '9822011014', 'tanvi.d@example.com',    'Skyline Heights',      'lost',                 1,  7000000,  8000000, '2 BHK', 'WITHIN_1_YEAR'],
    ['Zoya',  'Ansari',    '9822011015', 'zoya.ansari@example.com','Skyline Business Bay', 'contacted',            2, 13000000, 19000000, 'Retail', 'WITHIN_6_MONTHS'],
];

$made = 0;
foreach ($leads as $i => $l) {
    [$first, $last, $phone, $email, $project, $status, $ownerIdx, $budgetMin, $budgetMax, $config, $possession] = $l;

    $res = create_lead([
        'firstName' => $first, 'lastName' => $last, 'phone' => $phone, 'email' => $email,
        'city' => 'Pune', 'state' => 'Maharashtra',
        'projectId' => $projectIds[$project] ?? null,
        'budgetMin' => $budgetMin, 'budgetMax' => $budgetMax,
        'configuration' => $config, 'possessionTimeline' => $possession,
        'statusId' => $statusId($status),
        'sourceId' => $sources ? $sourceId($sources[$i % count($sources)]) : null,
        'ownerId' => $owners[$ownerIdx],
        'priority' => $i % 4 === 0 ? 'HIGH' : ($i % 3 === 0 ? 'LOW' : 'MEDIUM'),
        'notes' => 'Demonstration enquiry — safe to edit or delete.',
    ], ['organizationId' => $orgId, 'actor' => ['id' => $adminId, 'organizationId' => $orgId], 'origin' => 'MANUAL']);

    $leadId = db_scalar('SELECT id FROM leads WHERE organization_id = ? AND phone = ? LIMIT 1', [$orgId, $phone]);
    if (!$leadId) {
        continue;
    }
    $made++;

    // Follow-ups spread across the next few days, plus two inside the hour so
    // the "follow-up coming up" alert has something real to show.
    $when = $i < 2 ? '+40 minutes' : '+' . ($i - 1) . ' days';
    db_exec('UPDATE leads SET next_follow_up_at = ?, last_contacted_at = ? WHERE id = ?', [
        date('Y-m-d H:i:s', strtotime($when)),
        $i % 3 === 0 ? null : date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days')),
        $leadId,
    ]);

    // A couple of timeline entries, so the lead does not look untouched.
    if ($i % 2 === 0) {
        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, user_id, occurred_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())',
            [gv_id(), $orgId, 'CALL', 'Intro call', 'Walked through layout options and payment plan.',
             $leadId, $owners[$ownerIdx], date('Y-m-d H:i:s', strtotime('-' . ($i + 1) . ' days'))]
        );
    }
    if ($i % 3 === 0) {
        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, user_id, occurred_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())',
            [gv_id(), $orgId, 'NOTE', 'Site visit preference', 'Prefers a weekend visit, morning slot.',
             $leadId, $owners[$ownerIdx], date('Y-m-d H:i:s', strtotime('-' . $i . ' days'))]
        );
    }
}

// Seeding queues a lead.created event per lead. Flushing those would send a
// welcome message to fifteen fictional buyers and alert the demo team about
// each one, so the queue is dropped instead of drained.
$GLOBALS['GV_EVENT_QUEUE'] = [];

echo "  $made leads created across the pipeline\n";
echo str_repeat('-', 60) . "\n";
echo "Demo is ready.\n\n";
echo "  URL     : " . rtrim((string) gv_config('app_url'), '/') . "/login.html\n";
echo "  sign in : " . DEMO_EMAIL . "\n";
echo "  password: " . DEMO_PASSWORD . "\n\n";
echo "  Everyone on the demo team uses the same password.\n";
echo "  Re-run with --reset to wipe it clean.\n\n";
