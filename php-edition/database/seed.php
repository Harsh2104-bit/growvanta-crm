<?php
declare(strict_types=1);

/**
 * Demo data for the real-estate SaaS platform.
 *
 *     php database/seed.php
 *
 * Builds a deliberately small, legible world rather than a big one:
 *
 *     5 client companies   one of each business type, so project visibility
 *                          can actually be demonstrated
 *     5 projects           2 private to their developer, 2 global, 1 shared
 *                          with named partners
 *     10 users             spread across the companies, in a real hierarchy
 *     25 leads             distributed so every screen has something in it
 *     1 demo user          owning exactly 5 leads, and able to see only those
 *
 * The demo user's 5-lead limit is not a special case in the code: they are a
 * Sales Executive, whose data scope is OWN, so the scope engine confines them
 * to the 5 leads they own. That is the same rule that governs every executive
 * in production.
 *
 * Re-running wipes and rebuilds every non-platform company. The platform
 * organisation and the Super Admin are left alone.
 */

require __DIR__ . '/../bootstrap.php';

const SEED_PASSWORD = 'Growvanta@123';
const DEMO_USER_LEADS = 5;

function say(string $line): void
{
    echo $line . "\n";
}

/** Fails loudly if the distribution below stops adding up to the target. */
function assert_lead_plan(array $plan, int $expected): void
{
    if (count($plan) !== $expected) {
        fwrite(STDERR, 'Lead plan produces ' . count($plan) . " leads, expected $expected.
");
        exit(1);
    }
}

$platformId = platform_organization_id();
if (!$platformId) {
    fwrite(STDERR, "No platform organisation found. Run database/platform_bootstrap.php first.\n");
    exit(1);
}

say('Growvanta demo seed');
say(str_repeat('-', 62));

// ---------------------------------------------------------------------------
// Clean slate: every tenant goes, the platform stays.
// ---------------------------------------------------------------------------
$existing = db_all('SELECT id, name FROM organizations WHERE is_platform = 0');
foreach ($existing as $org) {
    delete_organization($org['id']);
}
// Projects the platform owns are re-created below, so clear those too.
db_exec('DELETE FROM projects WHERE organization_id = ?', [$platformId]);
say('  removed ' . count($existing) . ' existing client company(ies)');

// ---------------------------------------------------------------------------
// 1. Five client companies, one per business type
// ---------------------------------------------------------------------------
$COMPANIES = [
    ['name' => 'Prestige Estates',    'type' => 'DEVELOPER',       'city' => 'Bengaluru', 'admin' => ['Nikhil', 'Prasad']],
    ['name' => 'Sobha Developers',    'type' => 'DEVELOPER',       'city' => 'Bengaluru', 'admin' => ['Meera', 'Nair']],
    ['name' => 'Anarock Partners',    'type' => 'CHANNEL_PARTNER', 'city' => 'Mumbai',    'admin' => ['Rohit', 'Desai']],
    ['name' => 'Square Yards Realty', 'type' => 'BROKER',          'city' => 'Gurugram',  'admin' => ['Kavya', 'Menon']],
    ['name' => 'Homebazaar Media',    'type' => 'AGENCY',          'city' => 'Pune',      'admin' => ['Arjun', 'Shetty']],
];

$orgs = [];
foreach ($COMPANIES as $c) {
    $slug = slugify($c['name']);
    $adminEmail = strtolower($c['admin'][0]) . '@' . $slug . '.example';
    $result = provision_client([
        'companyName' => $c['name'],
        'companyType' => $c['type'],
        'slug' => $slug,
        'city' => $c['city'],
        'country' => 'India',
        'companyEmail' => 'hello@' . $slug . '.example',
        'companyPhone' => '+91 80 4000 ' . random_int(1000, 9999),
        'adminName' => $c['admin'][0] . ' ' . $c['admin'][1],
        'adminEmail' => $adminEmail,
        'adminPhone' => '+91 9' . random_int(100000000, 999999999),
        'password' => SEED_PASSWORD,
    ], null);
    $orgs[$c['name']] = [
        'id' => $result['organizationId'],
        'type' => $c['type'],
        'slug' => $slug,
        'roleIds' => $result['roleIds'],
        'statusIds' => $result['statusIds'],
        'sourceIds' => $result['sourceIds'],
        'adminUserId' => $result['adminUserId'],
        'adminEmail' => $adminEmail,
    ];
    say(sprintf('  company  %-22s %-16s admin %s', $c['name'], $c['type'], $adminEmail));
}

// ---------------------------------------------------------------------------
// 2. Ten users: the five admins above, plus five more forming a hierarchy
//    inside the companies that need one.
// ---------------------------------------------------------------------------
function seed_user(array $org, string $first, string $last, string $roleKey, ?string $managerId): string
{
    $userId = gv_id();
    $title = ucwords(str_replace('_', ' ', $roleKey));
    db_exec(
        'INSERT INTO users
           (id, organization_id, role_id, manager_id, first_name, last_name, email, phone,
            password_hash, job_title, designation, is_active, email_verified_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),NOW())',
        [
            $userId, $org['id'], $org['roleIds'][$roleKey], $managerId,
            $first, $last, strtolower($first) . '@' . $org['slug'] . '.example',
            '+91 9' . random_int(100000000, 999999999),
            hash_password(SEED_PASSWORD), $title, $title,
        ]
    );
    return $userId;
}

$prestige = $orgs['Prestige Estates'];
$sobha    = $orgs['Sobha Developers'];
$anarock  = $orgs['Anarock Partners'];
$square   = $orgs['Square Yards Realty'];
$agency   = $orgs['Homebazaar Media'];

$prestigeManager = seed_user($prestige, 'Sanjay', 'Iyer', 'sales_manager', $prestige['adminUserId']);
// The demo user. A Sales Executive, so their data scope is OWN.
$demoUserId = seed_user($prestige, 'Demo', 'User', 'sales_executive', $prestigeManager);
$demoEmail = 'demo@' . $prestige['slug'] . '.example';
db_exec('UPDATE users SET email = ? WHERE id = ?', [$demoEmail, $demoUserId]);

$anarockLeader = seed_user($anarock, 'Priya', 'Kulkarni', 'team_leader', $anarock['adminUserId']);
$anarockExec   = seed_user($anarock, 'Imran', 'Sheikh', 'sales_executive', $anarockLeader);
$squareExec    = seed_user($square, 'Neha', 'Bansal', 'sales_executive', $square['adminUserId']);

// A team, so the Team Leader has something to lead and team scope is testable.
$teamId = gv_id();
db_exec(
    'INSERT INTO teams (id, organization_id, name, manager_id, region, is_active, created_at, updated_at)
     VALUES (?,?,?,?,?,1,NOW(),NOW())',
    [$teamId, $anarock['id'], 'Mumbai Team A', $anarockLeader, 'West']
);
foreach ([$anarockLeader => 'LEAD', $anarockExec => 'MEMBER'] as $memberId => $teamRole) {
    db_exec('INSERT INTO team_members (id, team_id, user_id, team_role, joined_at) VALUES (?,?,?,?,NOW())',
        [gv_id(), $teamId, $memberId, $teamRole]);
}

say('  users    ' . (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id <> ?', [$platformId])
    . ' across the five companies');

// ---------------------------------------------------------------------------
// 3. Five projects, covering all three visibility modes
// ---------------------------------------------------------------------------
function seed_project(string $ownerOrgId, array $p): string
{
    $projectId = gv_id();
    db_exec(
        'INSERT INTO projects
           (id, organization_id, code, name, slug, location, developer_name, project_type, visibility,
            city, state, country, description, rera_number, status, possession_date,
            price_min, price_max, area_min, area_max, configurations, amenities,
            contact_name, contact_phone, is_active, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())',
        [
            $projectId, $ownerOrgId, project_next_code($ownerOrgId), $p['name'],
            project_unique_slug($ownerOrgId, $p['name']),
            $p['location'], $p['developer'], $p['type'], $p['visibility'],
            $p['city'], $p['state'], 'India', $p['description'], $p['rera'], $p['status'], $p['possession'],
            $p['priceMin'], $p['priceMax'], $p['areaMin'], $p['areaMax'],
            to_json($p['configurations']), to_json($p['amenities']),
            $p['contactName'], '+91 9' . random_int(100000000, 999999999),
        ]
    );
    return $projectId;
}

$projects = [];

$projects['Prestige Lakeside Habitat'] = seed_project($prestige['id'], [
    'name' => 'Prestige Lakeside Habitat', 'location' => 'Varthur, Whitefield', 'developer' => 'Prestige Estates',
    'type' => 'RESIDENTIAL', 'visibility' => 'PRIVATE', 'city' => 'Bengaluru', 'state' => 'Karnataka',
    'description' => 'Lakefront township with 3,426 apartments across 102 acres.',
    'rera' => 'PRM/KA/RERA/1251/446/PR/171015/000524', 'status' => 'ONGOING', 'possession' => '2027-06-30',
    'priceMin' => 9500000, 'priceMax' => 21000000, 'areaMin' => 1245, 'areaMax' => 2400,
    'configurations' => ['2 BHK', '3 BHK', '4 BHK'],
    'amenities' => ['Clubhouse', 'Swimming pool', 'Lake view deck', 'Gym', 'Kids play area'],
    'contactName' => 'Site Sales Desk',
]);

$projects['Sobha Dream Acres'] = seed_project($sobha['id'], [
    'name' => 'Sobha Dream Acres', 'location' => 'Panathur, Balagere', 'developer' => 'Sobha Developers',
    'type' => 'RESIDENTIAL', 'visibility' => 'PRIVATE', 'city' => 'Bengaluru', 'state' => 'Karnataka',
    'description' => 'Value homes on 81 acres with a 12-acre central park.',
    'rera' => 'PRM/KA/RERA/1251/446/PR/180118/001234', 'status' => 'READY_TO_MOVE', 'possession' => '2026-03-31',
    'priceMin' => 6200000, 'priceMax' => 11500000, 'areaMin' => 645, 'areaMax' => 1300,
    'configurations' => ['1 BHK', '2 BHK', '3 BHK'],
    'amenities' => ['Central park', 'Gym', 'Amphitheatre', 'Jogging track'],
    'contactName' => 'Sobha Experience Centre',
]);

// Global: every broker, channel partner and agency on the platform can sell
// these. Developers deliberately do not see them.
$projects['DLF The Camellias'] = seed_project($platformId, [
    'name' => 'DLF The Camellias', 'location' => 'Golf Course Road, Sector 42', 'developer' => 'DLF',
    'type' => 'RESIDENTIAL', 'visibility' => 'GLOBAL', 'city' => 'Gurugram', 'state' => 'Haryana',
    'description' => 'Ultra-luxury residences overlooking the Aravalli golf course.',
    'rera' => 'HRERA-GGM-142-2018', 'status' => 'READY_TO_MOVE', 'possession' => '2026-01-31',
    'priceMin' => 180000000, 'priceMax' => 450000000, 'areaMin' => 7300, 'areaMax' => 16000,
    'configurations' => ['4 BHK', '5 BHK', 'Penthouse'],
    'amenities' => ['Golf course view', 'Private elevators', 'Concierge', 'Spa'],
    'contactName' => 'DLF Channel Desk',
]);

$projects['Godrej Woods'] = seed_project($platformId, [
    'name' => 'Godrej Woods', 'location' => 'Sector 43, Noida', 'developer' => 'Godrej Properties',
    'type' => 'RESIDENTIAL', 'visibility' => 'GLOBAL', 'city' => 'Noida', 'state' => 'Uttar Pradesh',
    'description' => 'Forest-themed residences with 3,000 trees on site.',
    'rera' => 'UPRERAPRJ226170', 'status' => 'ONGOING', 'possession' => '2028-12-31',
    'priceMin' => 25000000, 'priceMax' => 62000000, 'areaMin' => 1650, 'areaMax' => 3200,
    'configurations' => ['3 BHK', '4 BHK'],
    'amenities' => ['Forest trail', 'Clubhouse', 'Swimming pool', 'Tennis court'],
    'contactName' => 'Godrej Partner Desk',
]);

// Shared with named partners only.
$projects['M3M Golf Estate'] = seed_project($platformId, [
    'name' => 'M3M Golf Estate', 'location' => 'Sector 65, Golf Course Extn', 'developer' => 'M3M India',
    'type' => 'RESIDENTIAL', 'visibility' => 'SELECTED', 'city' => 'Gurugram', 'state' => 'Haryana',
    'description' => 'Golf-facing towers with a 9-hole executive course.',
    'rera' => 'HRERA-GGM-318-2019', 'status' => 'NEW_LAUNCH', 'possession' => '2029-06-30',
    'priceMin' => 38000000, 'priceMax' => 95000000, 'areaMin' => 2300, 'areaMax' => 5400,
    'configurations' => ['3 BHK', '4 BHK', 'Duplex'],
    'amenities' => ['Golf course', 'Sky lounge', 'Clubhouse', 'Concierge'],
    'contactName' => 'M3M Partner Desk',
]);
project_set_access($projects['M3M Golf Estate'], [$anarock['id'], $square['id']], null);

say('  projects 5 (2 private, 2 global, 1 shared with named partners)');

// ---------------------------------------------------------------------------
// 4. Twenty-five leads
// ---------------------------------------------------------------------------
$FIRST = ['Rahul', 'Sneha', 'Vikram', 'Ananya', 'Karthik', 'Divya', 'Aditya', 'Pooja', 'Harsh', 'Ritu',
    'Manish', 'Shreya', 'Gaurav', 'Nandini', 'Siddharth', 'Tanvi', 'Rohan', 'Ishita', 'Varun', 'Aarti',
    'Deepak', 'Megha', 'Nitin', 'Swati', 'Abhishek'];
$LAST = ['Sharma', 'Iyer', 'Reddy', 'Kapoor', 'Menon', 'Bose', 'Joshi', 'Rao', 'Malhotra', 'Pillai'];
$CONFIGS = ['2 BHK', '3 BHK', '4 BHK'];
$PURPOSES = ['END_USE', 'INVESTMENT'];
$FUNDING = ['SELF', 'HOME_LOAN', 'MIXED'];
$POSSESSION = ['IMMEDIATE', 'WITHIN_6_MONTHS', 'WITHIN_1_YEAR', 'WITHIN_2_YEARS', 'FLEXIBLE'];

/**
 * Who owns which leads. The demo user gets exactly DEMO_USER_LEADS; the rest
 * are spread so managers and admins have something in scope too.
 */
$plan = array_merge(
    array_fill(0, DEMO_USER_LEADS, ['org' => $prestige, 'owner' => $demoUserId, 'project' => 'Prestige Lakeside Habitat']),
    array_fill(0, 2, ['org' => $prestige, 'owner' => $prestigeManager, 'project' => 'Prestige Lakeside Habitat']),
    array_fill(0, 1, ['org' => $prestige, 'owner' => $prestige['adminUserId'], 'project' => 'Prestige Lakeside Habitat']),
    array_fill(0, 3, ['org' => $sobha, 'owner' => $sobha['adminUserId'], 'project' => 'Sobha Dream Acres']),
    array_fill(0, 3, ['org' => $anarock, 'owner' => $anarockExec, 'project' => 'DLF The Camellias', 'team' => $teamId]),
    array_fill(0, 2, ['org' => $anarock, 'owner' => $anarockLeader, 'project' => 'M3M Golf Estate', 'team' => $teamId]),
    array_fill(0, 2, ['org' => $anarock, 'owner' => $anarock['adminUserId'], 'project' => 'Godrej Woods']),
    array_fill(0, 3, ['org' => $square, 'owner' => $squareExec, 'project' => 'DLF The Camellias']),
    array_fill(0, 1, ['org' => $square, 'owner' => $square['adminUserId'], 'project' => 'M3M Golf Estate']),
    array_fill(0, 2, ['org' => $agency, 'owner' => $agency['adminUserId'], 'project' => 'Godrej Woods']),
    array_fill(0, 1, ['org' => $agency, 'owner' => null, 'project' => null])
);
// 5 + 2 + 1 + 3 + 3 + 2 + 2 + 3 + 1 + 2 + 1 = 25
assert_lead_plan($plan, 25);

$statusKeys = ['new', 'contacted', 'interested', 'qualified', 'site_visit_scheduled', 'site_visit_done', 'negotiation', 'booked'];
$sourceKeys = ['meta_ads', 'google_ads', '99acres', 'magicbricks', 'housing', 'website', 'walk_in', 'referral'];

$made = 0;
foreach ($plan as $i => $row) {
    $org = $row['org'];
    $statusKey = $statusKeys[$i % count($statusKeys)];
    $sourceKey = $sourceKeys[$i % count($sourceKeys)];
    $budgetMin = [5000000, 7500000, 12000000, 25000000][$i % 4];
    $first = $FIRST[$i % count($FIRST)];

    $input = [
        'firstName' => $first,
        'lastName' => $LAST[$i % count($LAST)],
        'phone' => '98' . str_pad((string) (11000000 + $i * 137), 8, '0', STR_PAD_LEFT),
        'email' => strtolower($first) . $i . '@example.com',
        'city' => $org['type'] === 'DEVELOPER' ? 'Bengaluru' : 'Gurugram',
        'country' => 'India',
        'statusId' => $org['statusIds'][$statusKey] ?? null,
        'sourceId' => $org['sourceIds'][$sourceKey] ?? null,
        'projectId' => $row['project'] ? $projects[$row['project']] : null,
        'ownerId' => $row['owner'],
        'teamId' => $row['team'] ?? null,
        'configuration' => $CONFIGS[$i % count($CONFIGS)],
        'budgetMin' => $budgetMin,
        'budgetMax' => $budgetMin * 2,
        'expectedValue' => $budgetMin * 2,
        'purpose' => $PURPOSES[$i % count($PURPOSES)],
        'funding' => $FUNDING[$i % count($FUNDING)],
        'possessionTimeline' => $POSSESSION[$i % count($POSSESSION)],
        'utmSource' => in_array($sourceKey, ['meta_ads', 'google_ads'], true) ? $sourceKey : null,
        'utmCampaign' => in_array($sourceKey, ['meta_ads', 'google_ads'], true) ? 'launch-' . strtolower($org['type']) : null,
        'notes' => 'Seed enquiry for demonstration.',
    ];

    // Some leads carry a site visit, so the funnel and the site-visit metrics
    // are not all zero on a fresh install.
    if (in_array($statusKey, ['site_visit_scheduled', 'negotiation', 'booked'], true)) {
        $input['siteVisitAt'] = date('Y-m-d H:i:s', strtotime('+' . (($i % 9) + 1) . ' days'));
    } elseif ($statusKey === 'site_visit_done') {
        $input['siteVisitAt'] = date('Y-m-d H:i:s', strtotime('-' . (($i % 7) + 1) . ' days'));
    }
    if (in_array($statusKey, ['contacted', 'interested', 'qualified'], true)) {
        $input['nextFollowUpAt'] = date('Y-m-d H:i:s', strtotime(($i % 3 === 0 ? '-' : '+') . (($i % 4) + 1) . ' days'));
    }

    create_lead($input, [
        'organizationId' => $org['id'],
        'actor' => null,
        'origin' => 'SEED',
        // Seed phone numbers are generated unique; flagging duplicates here
        // would only add noise.
        'duplicatePolicy' => 'ALLOW',
    ]);
    $made++;
}
flush_domain_events();

say("  leads    $made across the five companies");

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$demoLeadCount = (int) db_scalar('SELECT COUNT(*) FROM leads WHERE owner_id = ?', [$demoUserId]);

say(str_repeat('-', 62));
say('Done. Every account below uses the password: ' . SEED_PASSWORD);
say('');
say('  Super Admin            superadmin@growvanta.com');
foreach ($orgs as $name => $o) {
    say(sprintf('  %-22s %s', $name, $o['adminEmail']));
}
say('');
say("  DEMO USER              $demoEmail   (sees exactly $demoLeadCount leads)");
say('');
say(sprintf(
    '  totals: %d companies · %d projects · %d users · %d leads',
    (int) db_scalar('SELECT COUNT(*) FROM organizations WHERE is_platform = 0'),
    (int) db_scalar('SELECT COUNT(*) FROM projects'),
    (int) db_scalar('SELECT COUNT(*) FROM users WHERE organization_id <> ?', [$platformId]),
    (int) db_scalar('SELECT COUNT(*) FROM leads')
));
