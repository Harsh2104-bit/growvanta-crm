<?php
declare(strict_types=1);

/**
 * Projects - the central real-estate entity.
 *
 * A project row is owned by exactly one organisation. What makes it shareable
 * is `visibility`:
 *
 *   PRIVATE   only the owning tenant
 *   SELECTED  the owner plus every tenant listed in project_access
 *   GLOBAL    the owner plus every tenant on the platform
 *
 * The rule that matters most (spec section 24) is what this file deliberately
 * does NOT do: nothing here ever touches leads. A broker who can see a global
 * project still reads leads through leads.organization_id, so two brokers
 * working the same tower never see each other's buyers.
 */

const GV_PROJECT_TYPES = ['RESIDENTIAL', 'COMMERCIAL', 'MIXED_USE', 'PLOTTED', 'VILLA'];
const GV_PROJECT_STATUSES = ['PRE_LAUNCH', 'NEW_LAUNCH', 'ONGOING', 'READY_TO_MOVE', 'COMPLETED', 'SOLD_OUT', 'ON_HOLD'];
const GV_PROJECT_VISIBILITY = ['PRIVATE', 'SELECTED', 'GLOBAL'];

/**
 * The visibility predicate, as a WHERE fragment against an aliased `projects`.
 *
 * Business type - not a generic "industry" - decides what a company can see:
 *
 *   DEVELOPER                    their own stock, plus anything explicitly
 *                                shared with them. A developer builds and sells
 *                                what it owns; it has no business browsing the
 *                                global master of other developers' towers.
 *   BROKER / CHANNEL_PARTNER     their own, anything shared with them, AND the
 *   / AGENCY                     global project master - which is the whole
 *                                reason the global master exists.
 *
 * Table-qualified on purpose: this fragment is interpolated into queries that
 * join organizations and users, and all three carry organization_id.
 */
function project_visible_where(string $orgId, string $alias = 'projects'): array
{
    // The platform owner, working at platform level, sees every project on the
    // platform. They are the one who has to fix a client's half-filled listing,
    // and they cannot fix what they cannot see.
    //
    // The moment they switch INTO a client this stops applying: inside a tenant
    // they see exactly what that tenant sees, which is what keeps "open their
    // CRM" an honest view rather than a privileged one.
    if (platform_admin_at_platform_level()) {
        return ['sql' => '1 = 1', 'params' => []];
    }

    $clauses = ["$alias.organization_id = ?"];
    $params = [$orgId];

    if (org_uses_global_projects(org_company_type($orgId))) {
        $clauses[] = "($alias.visibility = 'GLOBAL' AND $alias.is_active = 1)";
    }

    $clauses[] = "EXISTS (SELECT 1 FROM project_access pa WHERE pa.project_id = $alias.id AND pa.organization_id = ?)";
    $params[] = $orgId;

    return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
}

/** Cached per request: the visibility predicate is built many times per page. */
function org_company_type(string $orgId): string
{
    static $cache = [];
    if (!isset($cache[$orgId])) {
        $cache[$orgId] = (string) (db_scalar('SELECT company_type FROM organizations WHERE id = ?', [$orgId]) ?: 'DEVELOPER');
    }
    return $cache[$orgId];
}

/**
 * Loads a project the tenant is allowed to see, or throws. Every read route
 * goes through this rather than a bare SELECT by id, so a guessed project id
 * from another tenant returns 404 instead of data.
 */
function project_load_visible(string $orgId, string $projectId): array
{
    $vis = project_visible_where($orgId);
    $row = db_one(
        "SELECT projects.* FROM projects WHERE projects.id = ? AND {$vis['sql']}",
        array_merge([$projectId], $vis['params'])
    );
    if (!$row) {
        throw not_found('Project not found, or it is not shared with your company.');
    }
    return $row;
}

/**
 * Editing is narrower than viewing: a broker may sell a global project but may
 * not rename it. Only the owning tenant (or a platform admin inside the
 * platform tenant) can write.
 */
function project_assert_writable(array $actor, array $project): void
{
    if ($project['organization_id'] === $actor['organizationId']) {
        return;
    }
    // A platform admin may correct any project's details wherever it lives -
    // the global master is theirs to curate, and a client's listing with a
    // missing RERA number or price band is theirs to complete.
    if (is_platform_admin($actor)) {
        return;
    }
    throw forbidden('This project belongs to another company. You can use it, but not edit it.');
}

/**
 * True only for a platform admin who is NOT currently inside a client.
 *
 * Returns false with no actor at all, so CLI scripts (seed, cron) keep the
 * ordinary tenant-scoped behaviour rather than silently gaining global reach.
 */
function platform_admin_at_platform_level(): bool
{
    $a = actor();
    return $a !== null && is_platform_admin($a) && empty($a['isImpersonating']);
}

function project_next_code(string $orgId): string
{
    // Same atomic-increment trick the lead sequence uses: bump and read in one
    // statement so two concurrent creates cannot collide on a code.
    db_exec('UPDATE organizations SET project_seq = project_seq + 1 WHERE id = ?', [$orgId]);
    $seq = (int) db_scalar('SELECT project_seq FROM organizations WHERE id = ?', [$orgId]);
    return 'PRJ-' . $seq;
}

function project_unique_slug(string $orgId, string $name): string
{
    $base = slugify($name) ?: 'project';
    $slug = $base;
    $n = 1;
    while (db_scalar('SELECT id FROM projects WHERE organization_id = ? AND slug = ?', [$orgId, $slug])) {
        $n++;
        $slug = $base . '-' . $n;
    }
    return $slug;
}

/** Shapes a row for the API, adding the flags the UI needs to decide affordances. */
function project_present(array $row, string $viewerOrgId, array $extra = []): array
{
    $isOwner = $row['organization_id'] === $viewerOrgId;
    $a = actor();
    return camelize(array_merge($row, [
        'configurations' => safe_json($row['configurations'], []),
        'amenities' => safe_json($row['amenities'], []),
        'is_owner' => $isOwner,
        'is_shared' => !$isOwner,
        'can_edit' => $isOwner || ($a !== null && is_platform_admin($a)),
    ], $extra));
}

/**
 * Replaces the tenant list a SELECTED project is shared with.
 * Platform-admin only - it is the lever behind spec section 23.
 */
function project_set_access(string $projectId, array $orgIds, ?string $grantedById): void
{
    db_transaction(function () use ($projectId, $orgIds, $grantedById) {
        db_exec('DELETE FROM project_access WHERE project_id = ?', [$projectId]);
        foreach (array_unique($orgIds) as $orgId) {
            if (!$orgId) {
                continue;
            }
            // A tenant that no longer exists is skipped rather than failing the
            // whole grant - the UI list can lag behind a deleted client.
            if (!db_scalar('SELECT id FROM organizations WHERE id = ?', [$orgId])) {
                continue;
            }
            db_exec(
                'INSERT INTO project_access (id, project_id, organization_id, granted_by_id, created_at) VALUES (?,?,?,?,NOW())',
                [gv_id(), $projectId, $orgId, $grantedById]
            );
        }
    });
}

function project_access_org_ids(string $projectId): array
{
    return array_column(
        db_all('SELECT organization_id FROM project_access WHERE project_id = ?', [$projectId]),
        'organization_id'
    );
}

/** Cover image, falling back to the first gallery image if none is flagged. */
function project_sync_cover(string $projectId): void
{
    $cover = db_one(
        'SELECT url FROM project_images WHERE project_id = ? ORDER BY is_cover DESC, sort_order ASC, created_at ASC LIMIT 1',
        [$projectId]
    );
    db_exec('UPDATE projects SET cover_image_url = ? WHERE id = ?', [$cover['url'] ?? null, $projectId]);
}

function project_images(string $projectId): array
{
    return camelize(db_all(
        'SELECT id, url, caption, is_cover, sort_order, mime_type, file_size, created_at
           FROM project_images WHERE project_id = ? ORDER BY is_cover DESC, sort_order ASC, created_at ASC',
        [$projectId]
    ));
}

/**
 * Lead counts per project, scoped to the viewing tenant only. This is the
 * query that proves the global-project rule: the project id may be shared, the
 * COUNT never is.
 */
function project_lead_counts(string $orgId, array $projectIds): array
{
    if (!$projectIds) {
        return [];
    }
    [$in, $params] = db_in_clause($projectIds);
    $rows = db_all(
        "SELECT project_id, COUNT(*) AS total
           FROM leads
          WHERE leads.organization_id = ? AND leads.is_duplicate = 0 AND leads.project_id IN $in
          GROUP BY project_id",
        array_merge([$orgId], $params)
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['project_id']] = (int) $r['total'];
    }
    return $out;
}
