<?php
declare(strict_types=1);

/**
 * Multi-tenancy: which company's data does this request operate on?
 *
 * The design goal was that the 26 existing route handlers should not have to
 * change to become tenant-aware. They all scope their queries with
 * $actor['organizationId'], so that key is redefined to mean "the tenant this
 * request is acting inside" rather than "the tenant this user belongs to":
 *
 *   normal user    -> always their own organisation, full stop
 *   platform admin -> the tenant selected on their session, else the platform
 *                     organisation (which owns no leads, so an unselected
 *                     session sees nothing rather than everything)
 *
 * The user's real home is kept as homeOrganizationId, which is what user and
 * profile lookups use. A tenant id arriving in a request body or URL is never
 * trusted: it is checked against the active context by tenant_guard().
 */

function platform_organization_id(): ?string
{
    static $id = false;
    if ($id === false) {
        $id = db_scalar('SELECT id FROM organizations WHERE is_platform = 1 LIMIT 1') ?: null;
    }
    return $id;
}

function is_platform_admin(?array $actor): bool
{
    return (bool) ($actor['isPlatformAdmin'] ?? false);
}

/**
 * Platform routes are gated twice: the `clients`/`global_projects` permission
 * must be granted AND the user must actually be a platform admin. A tenant
 * admin who edits their own role to grant `clients:read` still gets nothing.
 */
function require_platform_admin(): array
{
    $a = require_auth();
    if (!is_platform_admin($a)) {
        throw forbidden('This area is restricted to platform administrators.');
    }
    return $a;
}

/** The organisation whose data the current request reads and writes. */
function active_org_id(): string
{
    $a = require_auth();
    return $a['organizationId'];
}

/**
 * Asserts that a tenant id supplied by the client is the one already in
 * context. Call this anywhere a route accepts an organizationId, so a crafted
 * id cannot redirect a write into another company.
 */
function tenant_guard(?string $orgId): string
{
    $active = active_org_id();
    if ($orgId !== null && $orgId !== '' && $orgId !== $active) {
        throw forbidden('You do not have access to that company.');
    }
    return $active;
}

/**
 * Resolves the tenant a platform admin is asking to act inside. Tenant users
 * never reach the second branch: their own organisation is the only answer.
 */
function resolve_tenant_param(?string $requested): string
{
    $a = require_auth();
    if (!$requested || $requested === $a['organizationId']) {
        return $a['organizationId'];
    }
    if (!is_platform_admin($a)) {
        throw forbidden('You do not have access to that company.');
    }
    $org = db_one('SELECT id FROM organizations WHERE id = ?', [$requested]);
    if (!$org) {
        throw not_found('Company not found.');
    }
    return $org['id'];
}

/** Points a platform admin's session at a tenant (or back at the platform). */
function switch_active_tenant(string $sessionId, ?string $orgId): void
{
    db_exec('UPDATE sessions SET active_organization_id = ? WHERE id = ?', [$orgId, $sessionId]);
}

/**
 * Branding for the tenant, falling back to the platform's own Growvanta theme
 * when a client has not supplied their own. Returned to the browser on every
 * /auth/me so the shell can repaint per tenant.
 */
function org_branding(array $org): array
{
    return [
        'name' => $org['name'],
        'legalName' => $org['legal_name'] ?? null,
        'companyType' => $org['company_type'] ?? null,
        'logoUrl' => $org['logo_url'] ?: null,
        'faviconUrl' => $org['favicon_url'] ?: null,
        'primary' => $org['brand_primary'] ?: null,
        'accent' => $org['brand_accent'] ?: null,
        'loginHeadline' => $org['login_headline'] ?: null,
    ];
}

const GV_COMPANY_TYPES = [
    ['key' => 'DEVELOPER', 'label' => 'Developer', 'description' => 'Builds and sells its own projects.'],
    ['key' => 'BROKER', 'label' => 'Broker', 'description' => 'Sells other developers projects; works from the global project master.'],
    ['key' => 'CHANNEL_PARTNER', 'label' => 'Channel Partner', 'description' => 'Authorised sales partner for selected projects.'],
    ['key' => 'AGENCY', 'label' => 'Agency', 'description' => 'Marketing agency running campaigns and routing leads.'],
];

function is_valid_company_type(string $type): bool
{
    foreach (GV_COMPANY_TYPES as $t) {
        if ($t['key'] === $type) {
            return true;
        }
    }
    return false;
}

/**
 * Developers work their own stock; brokers, channel partners and agencies sell
 * other people's, so they are the ones the global project master exists for.
 */
function org_uses_global_projects(string $companyType): bool
{
    return in_array($companyType, ['BROKER', 'CHANNEL_PARTNER', 'AGENCY'], true);
}

/**
 * Deletes a tenant and everything it owns.
 *
 * Most tables fall away through ON DELETE CASCADE, but `users.role_id`
 * references `roles` with the default RESTRICT - correct on its own, since
 * deleting a role must not orphan the people holding it. The consequence is
 * that cascading an organisation delete can hit that restriction before the
 * users are gone, depending on the order the engine chooses. So users go
 * first, explicitly, and the rest cascades from the organisation.
 */
function delete_organization(string $orgId): void
{
    db_transaction(function () use ($orgId) {
        // Self-referencing manager_id would restrict too, so break the chain
        // before removing the rows.
        db_exec('UPDATE users SET manager_id = NULL WHERE organization_id = ?', [$orgId]);
        db_exec('UPDATE teams SET manager_id = NULL WHERE organization_id = ?', [$orgId]);
        db_exec('DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE organization_id = ?)', [$orgId]);
        db_exec('DELETE FROM users WHERE organization_id = ?', [$orgId]);
        db_exec('DELETE FROM organizations WHERE id = ?', [$orgId]);
    });
}

const GV_BILLING_STATUSES = [
    ['key' => 'ACTIVE', 'label' => 'Active', 'tone' => 'success'],
    ['key' => 'TRIAL', 'label' => 'Trial', 'tone' => 'info'],
    ['key' => 'PAST_DUE', 'label' => 'Payment overdue', 'tone' => 'warning'],
    ['key' => 'CANCELLED', 'label' => 'Cancelled', 'tone' => 'danger'],
];

function is_valid_billing_status(string $status): bool
{
    return in_array($status, array_column(GV_BILLING_STATUSES, 'key'), true);
}

/**
 * Why a company cannot sign in, or null if it can.
 *
 * Three separate reasons, kept separate because they are undone by different
 * people: an operational suspension, a deactivated record, and a billing block.
 * The message is shown to the person trying to sign in, so it says what to do
 * rather than just refusing.
 */
function org_access_denial(array $org): ?string
{
    if (gv_bool($org['access_blocked'] ?? 0)) {
        $reason = trim((string) ($org['access_blocked_reason'] ?? ''));
        return $reason !== ''
            ? "Access to this workspace is paused: $reason"
            : 'Access to this workspace is paused pending payment. Please contact your account manager.';
    }
    if (($org['status'] ?? 'ACTIVE') === 'SUSPENDED') {
        return 'This company account is suspended. Please contact your account manager.';
    }
    if (!gv_bool($org['is_active'] ?? 1)) {
        return 'This company account is inactive. Please contact your account manager.';
    }
    return null;
}

/**
 * Billing summary for one company, including whether the period has lapsed.
 * `overdue` is derived, so a renewal date that slipped past shows up without
 * anyone having to remember to change the status by hand.
 */
function org_billing(array $org): array
{
    $renewsAt = $org['renews_at'] ?? null;
    $trialEndsAt = $org['trial_ends_at'] ?? null;
    $today = date('Y-m-d');

    $lapsed = ($renewsAt && $renewsAt < $today) || ($trialEndsAt && $trialEndsAt < $today
        && ($org['billing_status'] ?? '') === 'TRIAL');

    return [
        'status' => $org['billing_status'] ?? 'ACTIVE',
        'planName' => $org['plan_name'] ?? null,
        'seats' => $org['seats'] !== null ? (int) $org['seats'] : null,
        'monthlyFee' => $org['monthly_fee'] !== null ? (float) $org['monthly_fee'] : null,
        'trialEndsAt' => $trialEndsAt,
        'renewsAt' => $renewsAt,
        'lastPaymentAt' => $org['last_payment_at'] ?? null,
        'accessBlocked' => gv_bool($org['access_blocked'] ?? 0),
        'accessBlockedAt' => $org['access_blocked_at'] ?? null,
        'accessBlockedReason' => $org['access_blocked_reason'] ?? null,
        'notes' => $org['billing_notes'] ?? null,
        // True when money is late, whether or not anyone has blocked access yet.
        'overdue' => ($org['billing_status'] ?? '') === 'PAST_DUE' || $lapsed,
        'daysUntilRenewal' => $renewsAt
            ? (int) floor((strtotime($renewsAt) - strtotime($today)) / 86400)
            : null,
    ];
}
