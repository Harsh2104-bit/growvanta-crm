<?php
declare(strict_types=1);

/**
 * Run once, after database/migrations/001_realestate_saas.sql.
 *
 *   php database/platform_bootstrap.php
 *
 * Creates the platform tier that the SQL migration could not, because these
 * steps need PHP: hashing the Super Admin's password, and recomputing the
 * permission JSON for roles that predate the real-estate modules.
 *
 * Safe to run more than once - every step checks for its own result first.
 */

require __DIR__ . '/../bootstrap.php';

// On a live install the shared SEED_PASSWORD default must never become the
// platform owner's password - it ships in .env.example, so it is public. When
// nothing was supplied we mint a random one and print it once.
$generated = false;
$password = (string) (getenv('SUPER_ADMIN_PASSWORD') ?: '');
if ($password === '') {
    if (in_array(gv_config('env'), ['local', 'development'], true)) {
        $password = (string) gv_config('seed_password');
    } else {
        $password = gv_random_password();
        $generated = true;
    }
}
$email = normalize_email(getenv('SUPER_ADMIN_EMAIL') ?: 'superadmin@growvanta.com');

/** Readable but unguessable: ambiguous characters left out on purpose. */
function gv_random_password(int $length = 18): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#%+=';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

echo "Growvanta platform bootstrap\n";
echo str_repeat('-', 60) . "\n";

// ---------------------------------------------------------------------------
// 1. Existing tenants get a company type and a status
// ---------------------------------------------------------------------------
$existing = db_all('SELECT id, name, company_type, status FROM organizations WHERE is_platform = 0');
foreach ($existing as $org) {
    if (!$org['company_type'] || !is_valid_company_type($org['company_type'])) {
        db_exec("UPDATE organizations SET company_type = 'DEVELOPER' WHERE id = ?", [$org['id']]);
        echo "  tenant '{$org['name']}' typed as DEVELOPER\n";
    }
}

// ---------------------------------------------------------------------------
// 2. The platform organisation
// ---------------------------------------------------------------------------
$platformId = db_scalar('SELECT id FROM organizations WHERE is_platform = 1 LIMIT 1');

if ($platformId) {
    echo "  platform organisation already exists ($platformId)\n";
} else {
    $prov = provision_organization('Growvanta Platform', 'growvanta-platform', 'INR', 'Asia/Kolkata', [
        'isPlatform' => true,
        'companyType' => 'AGENCY',
        'legalName' => 'Growvanta Platform',
    ]);
    $platformId = $prov['organizationId'];
    echo "  platform organisation created ($platformId)\n";
}

// ---------------------------------------------------------------------------
// 3. Refresh system role permissions everywhere
//
// Roles created before this release have permission JSON that predates the
// projects/templates/whatsapp/email modules and the split lead actions. System
// roles are regenerated from their bundle; custom roles an admin has edited are
// left completely alone.
// ---------------------------------------------------------------------------
$bundleFor = [];
foreach (GV_DEFAULT_ROLES as $role) {
    $bundleFor[$role['key']] = $role['permissions'];
}

$refreshed = 0;
$roles = db_all('SELECT id, organization_id, `key`, permissions, is_system FROM roles');
foreach ($roles as $role) {
    if (!array_key_exists($role['key'], $bundleFor)) {
        continue; // a role the customer invented; not ours to rewrite
    }
    // Only the platform organisation's own roles carry the platform modules.
    $fresh = $bundleFor[$role['key']] === null
        ? all_permissions($role['organization_id'] === $platformId)
        : gv_role_permissions($bundleFor[$role['key']]);
    if (to_json($fresh) !== to_json(safe_json($role['permissions'], []))) {
        db_exec('UPDATE roles SET permissions = ?, updated_at = NOW() WHERE id = ?', [to_json($fresh), $role['id']]);
        $refreshed++;
    }
}
echo "  refreshed permissions on $refreshed role(s)\n";

// ---------------------------------------------------------------------------
// 3b. Remove the platform-owner role from tenants
//
// Earlier releases provisioned `super_admin` into every organisation, leaving
// an unassignable, always-empty role in each company's Roles screen. It belongs
// to the platform tier alone. Only removed where nobody holds it, so a tenant
// that somehow put a real person on it is left alone and reported instead.
// ---------------------------------------------------------------------------
$strayRoles = db_all(
    "SELECT r.id, r.organization_id, o.name AS org_name,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS holders
       FROM roles r JOIN organizations o ON o.id = r.organization_id
      WHERE r.`key` = 'super_admin' AND o.is_platform = 0"
);
$removedRoles = 0;
foreach ($strayRoles as $role) {
    if ((int) $role['holders'] > 0) {
        echo "  ! '{$role['org_name']}' has {$role['holders']} user(s) on the platform-owner role - left in place
";
        continue;
    }
    // Children point at it as their parent; the FK is ON DELETE SET NULL, but
    // re-parenting to `admin` keeps the tree intact.
    $adminRoleId = db_scalar(
        "SELECT id FROM roles WHERE organization_id = ? AND `key` = 'admin'",
        [$role['organization_id']]
    );
    db_exec('UPDATE roles SET parent_role_id = ? WHERE parent_role_id = ?', [$adminRoleId, $role['id']]);
    db_exec('DELETE FROM roles WHERE id = ?', [$role['id']]);
    $removedRoles++;
}
echo "  removed the platform-owner role from $removedRoles tenant(s)
";

// ---------------------------------------------------------------------------
// 4. Communication scaffolding for tenants that predate it
// ---------------------------------------------------------------------------
$allOrgs = db_all('SELECT id, name FROM organizations');
$commsAdded = 0;
$templatesAdded = 0;
foreach ($allOrgs as $org) {
    if (!db_scalar('SELECT id FROM whatsapp_settings WHERE organization_id = ?', [$org['id']])) {
        db_exec('INSERT INTO whatsapp_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())', [gv_id(), $org['id']]);
        $commsAdded++;
    }
    if (!db_scalar('SELECT id FROM email_settings WHERE organization_id = ?', [$org['id']])) {
        db_exec('INSERT INTO email_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())', [gv_id(), $org['id']]);
        $commsAdded++;
    }
    foreach (GV_DEFAULT_TEMPLATES as $t) {
        if (db_scalar('SELECT id FROM message_templates WHERE organization_id = ? AND `key` = ?', [$org['id'], $t['key']])) {
            continue;
        }
        db_exec(
            'INSERT INTO message_templates
               (id, organization_id, channel, name, `key`, provider_name, language, subject, body,
                variables, category, status, is_active, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())',
            [
                gv_id(), $org['id'], $t['channel'], $t['name'], $t['key'], $t['providerName'] ?? null,
                'en', $t['subject'] ?? null, $t['body'],
                to_json(template_variables($t['body'] . ' ' . ($t['subject'] ?? ''))),
                $t['category'], 'DRAFT',
            ]
        );
        $templatesAdded++;
    }
}
echo "  created $commsAdded channel config row(s) and $templatesAdded template(s)\n";

// ---------------------------------------------------------------------------
// 5. The Super Admin
// ---------------------------------------------------------------------------
$existingAdmin = db_one('SELECT id, organization_id FROM users WHERE email = ?', [$email]);

if ($existingAdmin) {
    db_exec('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [$existingAdmin['id']]);
    echo "  existing user $email promoted to platform administrator\n";
} else {
    $roleId = db_scalar('SELECT id FROM roles WHERE organization_id = ? AND `key` = ?', [$platformId, 'super_admin']);
    if (!$roleId) {
        fwrite(STDERR, "  ! platform organisation has no super_admin role - cannot continue\n");
        exit(1);
    }
    $userId = gv_id();
    db_exec(
        'INSERT INTO users
           (id, organization_id, role_id, first_name, last_name, email, password_hash,
            job_title, designation, is_active, is_platform_admin, email_verified_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,1,1,NOW(),NOW(),NOW())',
        [$userId, $platformId, $roleId, 'Platform', 'Administrator', $email, hash_password($password),
            'Platform Owner', 'Super Admin']
    );
    echo "  Super Admin created\n";
    echo "     email    : $email\n";
    echo "     password : $password\n";
    if ($generated) {
        echo "     ^ generated for this install and NOT stored anywhere - copy it now.\n";
    }
}

echo str_repeat('-', 60) . "\n";
echo "Done. Sign in as the Super Admin to create client companies.\n";
