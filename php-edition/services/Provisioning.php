<?php
declare(strict_types=1);

/**
 * Organisation bootstrap — ported from src/services/provisioning.js.
 *
 * A brand-new tenant is useless without roles, statuses, sources and a
 * lead pipeline, so signup and the seed script share this one provisioning path.
 * Everything created here is editable afterwards — these are defaults, not
 * hard-coded business rules.
 */

const GV_DEFAULT_ROLES = [
    ['key' => 'super_admin', 'name' => 'Super Admin', 'level' => 0, 'dataScope' => 'ALL', 'isSystem' => true,
        'description' => 'Platform owner. Full access to every module and every record.', 'permissions' => null /* all */],
    ['key' => 'admin', 'name' => 'Admin', 'level' => 10, 'dataScope' => 'ORGANIZATION', 'isSystem' => true,
        'description' => 'Runs the organisation: users, roles, configuration and all data.', 'permissions' => null /* all */],
    ['key' => 'business_head', 'name' => 'Business Head', 'level' => 20, 'dataScope' => 'ORGANIZATION',
        'description' => 'Org-wide visibility, full CRM access, read-only administration.', 'permissions' => 'BUSINESS_HEAD'],
    ['key' => 'regional_head', 'name' => 'Regional Head', 'level' => 30, 'dataScope' => 'REGION',
        'description' => 'Everything inside their region.', 'permissions' => 'REGIONAL_HEAD'],
    ['key' => 'sales_manager', 'name' => 'Sales Manager', 'level' => 40, 'dataScope' => 'TEAM_AND_BELOW',
        'description' => 'Their teams and every team below them.', 'permissions' => 'SALES_MANAGER'],
    ['key' => 'team_leader', 'name' => 'Team Leader', 'level' => 50, 'dataScope' => 'TEAM',
        'description' => 'Own records plus their direct team.', 'permissions' => 'TEAM_LEADER'],
    ['key' => 'sales_executive', 'name' => 'Sales Executive', 'level' => 60, 'dataScope' => 'OWN',
        'description' => 'Their own leads and site visits.', 'permissions' => 'SALES_EXECUTIVE'],
    ['key' => 'telecaller', 'name' => 'Telecaller', 'level' => 70, 'dataScope' => 'OWN',
        'description' => 'Calls and qualifies assigned leads. Cannot create or reassign them.', 'permissions' => 'TELECALLER'],
    ['key' => 'marketing_user', 'name' => 'Marketing User', 'level' => 45, 'dataScope' => 'ORGANIZATION',
        'description' => 'Web forms, lead sources and marketing analytics across the organisation.', 'permissions' => 'MARKETING_USER'],
    ['key' => 'viewer', 'name' => 'Viewer', 'level' => 80, 'dataScope' => 'ORGANIZATION',
        'description' => 'Read-only access for auditors and stakeholders.', 'permissions' => 'VIEWER'],
];

/**
 * Resolves the named permission bundle for a role.
 *
 * The module list is deliberately short: leads and projects are the product.
 * Lead actions are split fine because in a real-estate org the question is
 * rarely "can they edit a lead" but "can they move it to another executive,
 * change which project it is counted against, or rewrite its source" - each of
 * which moves someone's commission.
 */
function gv_role_permissions(?string $bundle): array
{
    // Every lead action except delete; the set a senior manager gets.
    $leadsFull = ['read', 'create', 'update', 'export', 'assign', 'reassign', 'import', 'merge',
        'change_status', 'change_project', 'change_source'];

    switch ($bundle) {
        case 'BUSINESS_HEAD':
            return array_merge(
                ['leads' => array_merge($leadsFull, ['delete'])],
                grant_permissions(['projects'], ['read', 'create', 'update', 'delete', 'manage', 'export']),
                grant_permissions(['activities'], ['read', 'create', 'update']),
                grant_permissions(['targets'], ['read', 'create', 'update', 'delete']),
                grant_permissions(['dashboard', 'reports', 'audit'], ['read', 'export']),
                grant_permissions(['users', 'teams', 'roles', 'settings', 'automation', 'forms'], ['read']),
                grant_permissions(['templates'], ['read', 'create', 'update']),
                grant_permissions(['whatsapp', 'email'], ['read']),
                ['imports' => ['read', 'create', 'export'], 'documents' => ['read', 'create', 'delete']]
            );
        case 'REGIONAL_HEAD':
            return array_merge(
                ['leads' => $leadsFull],
                grant_permissions(['projects'], ['read', 'export']),
                grant_permissions(['activities'], ['read', 'create', 'update']),
                grant_permissions(['targets'], ['read', 'create', 'update']),
                grant_permissions(['dashboard', 'reports'], ['read', 'export']),
                grant_permissions(['users', 'teams'], ['read']),
                ['documents' => ['read', 'create', 'delete'], 'imports' => ['read', 'create', 'export']]
            );
        case 'SALES_MANAGER':
            return array_merge(
                ['leads' => ['read', 'create', 'update', 'export', 'assign', 'reassign', 'import',
                    'change_status', 'change_project', 'change_source']],
                grant_permissions(['projects'], ['read', 'export']),
                grant_permissions(['activities'], ['read', 'create', 'update']),
                grant_permissions(['targets'], ['read', 'create', 'update']),
                grant_permissions(['dashboard', 'reports'], ['read', 'export']),
                grant_permissions(['users', 'teams'], ['read']),
                ['documents' => ['read', 'create', 'delete'], 'imports' => ['read', 'create', 'export']]
            );
        case 'TEAM_LEADER':
            // Can move leads around inside their own team - the scope engine,
            // not this list, is what confines "inside their own team".
            return array_merge(
                ['leads' => ['read', 'create', 'update', 'assign', 'reassign', 'change_status']],
                grant_permissions(['projects'], ['read']),
                grant_permissions(['activities'], ['read', 'create', 'update']),
                grant_permissions(['dashboard', 'reports'], ['read']),
                grant_permissions(['users', 'teams'], ['read']),
                ['documents' => ['read', 'create'], 'targets' => ['read']]
            );
        case 'SALES_EXECUTIVE':
            // Works leads; cannot hand them to anyone else and cannot export.
            return array_merge(
                ['leads' => ['read', 'create', 'update', 'change_status']],
                grant_permissions(['projects'], ['read']),
                grant_permissions(['activities'], ['read', 'create', 'update']),
                grant_permissions(['dashboard'], ['read']),
                ['documents' => ['read', 'create'], 'targets' => ['read'], 'reports' => ['read']]
            );
        case 'TELECALLER':
            return [
                'leads' => ['read', 'update', 'change_status'],
                'projects' => ['read'],
                'activities' => ['read', 'create'], 'dashboard' => ['read'], 'documents' => ['read'],
            ];
        case 'MARKETING_USER':
            return [
                'leads' => ['read', 'create', 'export', 'import', 'change_source'],
                'projects' => ['read'],
                'forms' => ['read', 'create', 'update', 'delete'],
                'reports' => ['read', 'export'], 'dashboard' => ['read'],
                'imports' => ['read', 'create', 'export'],
                'activities' => ['read'], 'documents' => ['read', 'create'],
                'templates' => ['read', 'create', 'update'],
            ];
        case 'VIEWER':
            return [
                'leads' => ['read'], 'projects' => ['read'], 'activities' => ['read'],
                'dashboard' => ['read'], 'reports' => ['read'], 'documents' => ['read'],
            ];
        default:
            return all_permissions();
    }
}

const GV_ROLE_PARENTS = [
    'admin' => 'super_admin', 'business_head' => 'admin', 'regional_head' => 'business_head',
    'sales_manager' => 'regional_head', 'team_leader' => 'sales_manager', 'sales_executive' => 'team_leader',
    'telecaller' => 'team_leader', 'marketing_user' => 'admin', 'viewer' => 'admin',
];

/**
 * The colour ramp is the funnel: a lead starts neutral slate, cools through
 * blue as it is worked, and turns progressively greener as it closes — landing
 * on the brand green itself at Converted. Lost and Junk step out of the ramp
 * entirely. Admins can recolour any of these; these are only the defaults.
 */
const GV_DEFAULT_STATUSES = [
    ['key' => 'new', 'name' => 'New', 'color' => '#64748b', 'probability' => 5, 'isDefault' => true],
    ['key' => 'assigned', 'name' => 'Assigned', 'color' => '#0284c7', 'probability' => 10],
    ['key' => 'contacted', 'name' => 'Contacted', 'color' => '#0891b2', 'probability' => 20],
    ['key' => 'interested', 'name' => 'Interested', 'color' => '#0d9488', 'probability' => 30],
    ['key' => 'qualified', 'name' => 'Qualified', 'color' => '#0a5c2b', 'probability' => 40, 'isQualified' => true],
    // The site visit is the hinge of a real-estate funnel: almost nothing
    // closes without one, so scheduled and completed are separate states
    // rather than a single "meeting" step.
    ['key' => 'site_visit_scheduled', 'name' => 'Site Visit Scheduled', 'color' => '#12a64f', 'probability' => 55],
    ['key' => 'site_visit_done', 'name' => 'Site Visit Done', 'color' => '#16a34a', 'probability' => 70],
    ['key' => 'negotiation', 'name' => 'Negotiation', 'color' => '#22c55e', 'probability' => 85],
    ['key' => 'booked', 'name' => 'Booked', 'color' => '#35e06f', 'probability' => 100, 'isConverted' => true],
    ['key' => 'lost', 'name' => 'Lost', 'color' => '#dc2626', 'probability' => 0, 'isLost' => true],
    ['key' => 'junk', 'name' => 'Junk', 'color' => '#94a3b8', 'probability' => 0, 'isJunk' => true, 'isLost' => true],
];

const GV_DEFAULT_SOURCES = [
    ['key' => 'website', 'name' => 'Website', 'category' => 'DIRECT'],
    ['key' => 'landing_page', 'name' => 'Landing Page', 'category' => 'DIRECT'],
    ['key' => 'google_ads', 'name' => 'Google Ads', 'category' => 'PAID', 'costPerLead' => 320],
    ['key' => 'meta_ads', 'name' => 'Meta Ads', 'category' => 'PAID', 'costPerLead' => 210],
    ['key' => 'linkedin', 'name' => 'LinkedIn', 'category' => 'SOCIAL', 'costPerLead' => 540],
    ['key' => 'whatsapp', 'name' => 'WhatsApp', 'category' => 'SOCIAL'],
    ['key' => 'instagram', 'name' => 'Instagram', 'category' => 'SOCIAL', 'costPerLead' => 180],
    ['key' => 'facebook', 'name' => 'Facebook', 'category' => 'SOCIAL', 'costPerLead' => 195],
    ['key' => 'organic_search', 'name' => 'Organic Search', 'category' => 'ORGANIC'],
    // The Indian property portals every developer and broker actually buys from.
    ['key' => '99acres', 'name' => '99acres', 'category' => 'PORTAL', 'costPerLead' => 450],
    ['key' => 'magicbricks', 'name' => 'MagicBricks', 'category' => 'PORTAL', 'costPerLead' => 420],
    ['key' => 'housing', 'name' => 'Housing.com', 'category' => 'PORTAL', 'costPerLead' => 390],
    ['key' => 'propertyportal', 'name' => 'Other Property Portal', 'category' => 'PORTAL'],
    ['key' => 'walk_in', 'name' => 'Walk-in', 'category' => 'DIRECT'],
    ['key' => 'calling', 'name' => 'Calling', 'category' => 'OUTBOUND'],
    ['key' => 'channel_partner', 'name' => 'Channel Partner', 'category' => 'REFERRAL'],
    ['key' => 'webhook', 'name' => 'Webhook', 'category' => 'OTHER'],
    ['key' => 'referral', 'name' => 'Referral', 'category' => 'REFERRAL'],
    ['key' => 'import', 'name' => 'Import', 'category' => 'OTHER'],
    ['key' => 'api', 'name' => 'API', 'category' => 'OTHER'],
    ['key' => 'manual', 'name' => 'Manual Entry', 'category' => 'OTHER'],
    ['key' => 'campaign', 'name' => 'Campaign', 'category' => 'PAID'],
    ['key' => 'other', 'name' => 'Other', 'category' => 'OTHER'],
];

/* Same idea as the lead statuses: the stage colour greens up as the deal
   approaches Closed Won, which lands on the brand green. */

const GV_DEFAULT_SCORING_RULES = [
    ['name' => 'Email provided', 'kind' => 'FIELD_PRESENT', 'field' => 'email', 'points' => 5],
    ['name' => 'Phone provided', 'kind' => 'FIELD_PRESENT', 'field' => 'phone', 'points' => 5],
    ['name' => 'Company provided', 'kind' => 'FIELD_PRESENT', 'field' => 'company', 'points' => 5],
    ['name' => 'Job title provided', 'kind' => 'FIELD_PRESENT', 'field' => 'jobTitle', 'points' => 5],
    ['name' => 'Came from Google Ads', 'kind' => 'SOURCE_IS', 'value' => 'google_ads', 'points' => 10],
    ['name' => 'Came from a referral', 'kind' => 'SOURCE_IS', 'value' => 'referral', 'points' => 15],
    ['name' => 'High expected value (> 500000)', 'kind' => 'FIELD_GT', 'field' => 'expectedValue', 'value' => '500000', 'points' => 20],
    ['name' => 'Has been called at least once', 'kind' => 'ACTIVITY_COUNT', 'field' => 'CALL', 'value' => '1', 'points' => 10],
    ['name' => 'Has met the prospect', 'kind' => 'ACTIVITY_COUNT', 'field' => 'MEETING', 'value' => '1', 'points' => 15],
    ['name' => 'Marked qualified', 'kind' => 'STATUS_IS', 'value' => 'qualified', 'points' => 20],
    ['name' => 'Stale: no activity for 30 days', 'kind' => 'NO_ACTIVITY_DAYS', 'value' => '30', 'points' => -10],
];

/**
 * Seed templates for both channels. They ship as DRAFT, not APPROVED, on
 * purpose: WhatsApp templates must be registered and approved at Meta before
 * they can be sent, and pretending otherwise would put a tenant on GREEN with
 * a template the provider would reject.
 */
const GV_DEFAULT_TEMPLATES = [
    [
        'channel' => 'WHATSAPP', 'key' => 'welcome_whatsapp', 'name' => 'Welcome - WhatsApp',
        'providerName' => 'lead_welcome', 'category' => 'UTILITY',
        'body' => "Hello {{customer_name}},

Thank you for your interest in {{project_name}}. "
            . "Our representative {{sales_person_name}} will contact you shortly.

- {{company_name}}",
    ],
    [
        'channel' => 'EMAIL', 'key' => 'welcome_email', 'name' => 'Welcome - Email',
        'category' => 'UTILITY',
        'subject' => 'Thank you for your interest in {{project_name}}',
        'body' => "<p>Hello {{customer_name}},</p>"
            . "<p>Thank you for your interest in <strong>{{project_name}}</strong>, {{project_location}}.</p>"
            . "<p>Our representative {{sales_person_name}} will be in touch with you shortly. "
            . "You can reach them directly on {{sales_person_phone}}.</p>"
            . "<p>Warm regards,<br>{{company_name}}</p>",
    ],
    [
        'channel' => 'WHATSAPP', 'key' => 'site_visit_reminder', 'name' => 'Site Visit Reminder',
        'providerName' => 'site_visit_reminder', 'category' => 'UTILITY',
        'body' => "Hi {{customer_name}}, this is a reminder about your site visit to {{project_name}} "
            . "at {{project_location}}. {{sales_person_name}} will meet you there.",
    ],
];

const GV_DEFAULT_INTEGRATIONS = ['google_ads', 'meta_ads', 'google_analytics', 'whatsapp', 'smtp', 'sms', 'zapier'];

/**
 * Creates a fresh organisation with its full set of defaults (roles, lead
 * statuses/sources, scoring rules, integration stubs).
 * Everything is created inside one transaction; nothing here creates a user —
 * that is left to the signup flow / seed script, matching the Node version.
 *
 * @return array{organizationId:string, roleIds:array, statusIds:array, sourceIds:array, templateIds:array}
 */
function provision_organization(string $name, ?string $slug = null, string $currency = 'INR', string $timezone = 'Asia/Kolkata', array $options = []): array
{
    return db_transaction(function () use ($name, $slug, $currency, $timezone, $options) {
        $orgId = gv_id();
        db_exec(
            'INSERT INTO organizations
               (id, name, legal_name, slug, is_platform, company_type, currency, timezone, settings,
                website, email, phone, address, city, state, country, pincode,
                logo_url, brand_primary, brand_accent, created_by_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
            [
                $orgId, $name, $options['legalName'] ?? null, $slug ?: slugify($name),
                !empty($options['isPlatform']) ? 1 : 0,
                $options['companyType'] ?? 'DEVELOPER',
                $currency, $timezone,
                to_json(['autoAssignFallback' => true, 'duplicatePolicy' => 'FLAG']),
                $options['website'] ?? null, $options['email'] ?? null, $options['phone'] ?? null,
                $options['address'] ?? null, $options['city'] ?? null, $options['state'] ?? null,
                $options['country'] ?? null, $options['pincode'] ?? null,
                $options['logoUrl'] ?? null, $options['brandPrimary'] ?? null, $options['brandAccent'] ?? null,
                $options['createdById'] ?? null,
            ]
        );

        $roleIds = [];
        foreach (GV_DEFAULT_ROLES as $role) {
            // `super_admin` is the platform owner's role. Creating it inside
            // every tenant left an unassignable, always-empty role sitting in
            // each company's Roles screen, implying a tier of access their
            // admin does not have and cannot grant.
            if ($role['key'] === 'super_admin' && empty($options['isPlatform'])) {
                continue;
            }
            $roleId = gv_id();
            db_exec(
                'INSERT INTO roles (id, organization_id, name, `key`, description, level, data_scope, permissions, is_system, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                [
                    $roleId, $orgId, $role['name'], $role['key'], $role['description'], $role['level'], $role['dataScope'],
                    to_json($role['permissions'] === null
                        ? all_permissions(!empty($options['isPlatform']))
                        : gv_role_permissions($role['permissions'])),
                    $role['isSystem'] ?? false ? 1 : 0,
                ]
            );
            $roleIds[$role['key']] = $roleId;
        }
        foreach (GV_ROLE_PARENTS as $child => $parent) {
            if (isset($roleIds[$child], $roleIds[$parent])) {
                db_exec('UPDATE roles SET parent_role_id = ? WHERE id = ?', [$roleIds[$parent], $roleIds[$child]]);
            }
        }

        $statusIds = [];
        foreach (GV_DEFAULT_STATUSES as $i => $s) {
            $id = gv_id();
            db_exec(
                'INSERT INTO lead_statuses (id, organization_id, name, `key`, color, sort_order, probability, is_default, is_converted, is_lost, is_junk, is_qualified, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
                [
                    $id, $orgId, $s['name'], $s['key'], $s['color'], $i, $s['probability'],
                    !empty($s['isDefault']) ? 1 : 0, !empty($s['isConverted']) ? 1 : 0, !empty($s['isLost']) ? 1 : 0,
                    !empty($s['isJunk']) ? 1 : 0, !empty($s['isQualified']) ? 1 : 0,
                ]
            );
            $statusIds[$s['key']] = $id;
        }

        $sourceIds = [];
        foreach (GV_DEFAULT_SOURCES as $i => $s) {
            $id = gv_id();
            db_exec(
                'INSERT INTO lead_sources (id, organization_id, name, `key`, category, cost_per_lead, sort_order, created_at) VALUES (?,?,?,?,?,?,?,NOW())',
                [$id, $orgId, $s['name'], $s['key'], $s['category'], $s['costPerLead'] ?? null, $i]
            );
            $sourceIds[$s['key']] = $id;
        }

        foreach (GV_DEFAULT_SCORING_RULES as $i => $rule) {
            db_exec(
                'INSERT INTO scoring_rules (id, organization_id, name, kind, field, value, points, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
                [gv_id(), $orgId, $rule['name'], $rule['kind'], $rule['field'] ?? null, $rule['value'] ?? null, $rule['points'], $i]
            );
        }

        foreach (GV_DEFAULT_INTEGRATIONS as $provider) {
            $label = ucwords(str_replace('_', ' ', $provider));
            db_exec(
                'INSERT INTO integrations (id, organization_id, provider, name, config, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())',
                [gv_id(), $orgId, $provider, $label, '{}']
            );
        }

        // Communication scaffolding. Both channels start switched OFF with empty
        // credentials, which is exactly the RED state - a brand new tenant must
        // not be able to message anybody until a human configures and tests it.
        db_exec('INSERT INTO whatsapp_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())', [gv_id(), $orgId]);
        db_exec('INSERT INTO email_settings (id, organization_id, created_at, updated_at) VALUES (?,?,NOW(),NOW())', [gv_id(), $orgId]);

        $templateIds = [];
        foreach (GV_DEFAULT_TEMPLATES as $t) {
            $id = gv_id();
            db_exec(
                'INSERT INTO message_templates
                   (id, organization_id, channel, name, `key`, provider_name, language, subject, body,
                    variables, category, status, is_active, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())',
                [
                    $id, $orgId, $t['channel'], $t['name'], $t['key'], $t['providerName'] ?? null,
                    'en', $t['subject'] ?? null, $t['body'],
                    to_json(template_variables($t['body'] . ' ' . ($t['subject'] ?? ''))),
                    $t['category'], 'DRAFT',
                ]
            );
            $templateIds[$t['key']] = $id;
        }

        return [
            'organizationId' => $orgId, 'roleIds' => $roleIds, 'statusIds' => $statusIds,
            'sourceIds' => $sourceIds, 'templateIds' => $templateIds,
        ];
    });
}

/**
 * One-click client onboarding (spec section 4).
 *
 * The Super Admin fills in a company and one admin contact; everything a
 * working CRM needs - roles, statuses, sources, scoring, the
 * integration placeholders, both communication channels and the template
 * library - is created here so nothing has to be wired by hand per client.
 *
 * Returns the new organisation id and the admin user id.
 */
function provision_client(array $input, ?array $actor = null): array
{
    $name = trim((string) ($input['companyName'] ?? ''));
    $companyType = strtoupper(trim((string) ($input['companyType'] ?? 'DEVELOPER')));
    $adminEmail = normalize_email((string) ($input['adminEmail'] ?? ''));

    if ($name === '') {
        throw bad_request('Company name is required.');
    }
    if (!is_valid_company_type($companyType)) {
        throw bad_request('Choose a valid company type.');
    }
    if (!$adminEmail) {
        throw bad_request('A valid admin email address is required.');
    }
    $adminFirst = trim((string) ($input['adminName'] ?? ''));
    if ($adminFirst === '') {
        throw bad_request('Admin name is required.');
    }

    $password = (string) ($input['password'] ?? '');
    if ($password === '') {
        throw bad_request('Set a password for the admin account.');
    }
    $strength = check_password_strength($password);
    if (!$strength['ok']) {
        throw bad_request($strength['message']);
    }

    $slug = slugify((string) ($input['slug'] ?? $name)) ?: slugify($name);
    if (db_scalar('SELECT id FROM organizations WHERE slug = ?', [$slug])) {
        // Slugs are global (they may become login subdomains), so collisions
        // are resolved here rather than surfaced to the Super Admin.
        $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    }

    return db_transaction(function () use ($input, $name, $companyType, $slug, $adminEmail, $adminFirst, $password, $actor) {
        $prov = provision_organization(
            $name,
            $slug,
            (string) ($input['currency'] ?? 'INR'),
            (string) ($input['timezone'] ?? 'Asia/Kolkata'),
            [
                'companyType' => $companyType,
                'legalName' => $input['legalName'] ?? null,
                'website' => $input['website'] ?? null,
                'email' => $input['companyEmail'] ?? null,
                'phone' => $input['companyPhone'] ?? null,
                'address' => $input['address'] ?? null,
                'city' => $input['city'] ?? null,
                'state' => $input['state'] ?? null,
                'country' => $input['country'] ?? 'India',
                'pincode' => $input['pincode'] ?? null,
                'logoUrl' => $input['logoUrl'] ?? null,
                'brandPrimary' => $input['brandPrimary'] ?? null,
                'brandAccent' => $input['brandAccent'] ?? null,
                'createdById' => $actor['id'] ?? null,
            ]
        );

        $parts = preg_split('/\s+/', $adminFirst, 2);
        $userId = gv_id();
        db_exec(
            'INSERT INTO users
               (id, organization_id, role_id, first_name, last_name, email, phone, password_hash,
                job_title, designation, is_active, email_verified_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW(),NOW())',
            [
                $userId, $prov['organizationId'], $prov['roleIds']['admin'],
                $parts[0], $parts[1] ?? null, $adminEmail,
                $input['adminPhone'] ?? null, hash_password($password),
                'Administrator', $input['adminDesignation'] ?? 'Administrator',
            ]
        );

        audit_record($prov['organizationId'], $actor, 'client.created', [
            'entityType' => 'ORGANIZATION',
            'entityId' => $prov['organizationId'],
            'entityLabel' => $name,
            'changes' => ['companyType' => $companyType, 'adminEmail' => $adminEmail],
        ]);

        return array_merge($prov, ['adminUserId' => $userId, 'slug' => $slug]);
    });
}
