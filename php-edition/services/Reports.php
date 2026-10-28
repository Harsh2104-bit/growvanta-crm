<?php
declare(strict_types=1);

/**
 * Reporting and analytics — ported from src/services/reports.js.
 *
 * Everything here is aggregate-only and scope-aware: the same WHERE-clause
 * builders that power the list endpoints (services/Filters.php) power the
 * reports, so a Team Leader's dashboard totals always reconcile with the rows
 * they can actually open. Aggregation is pushed into MySQL (COUNT/SUM/GROUP BY)
 * — no report pulls a full row set into PHP just to count it.
 */

function gv_user_label(?array $u): string
{
    return $u ? trim($u['first_name'] . ' ' . ($u['last_name'] ?? '')) : 'Unassigned';
}

/** Small per-request lookup tables so grouped ids can be turned into labels. */
function gv_label_maps(string $orgId): array
{
    $statuses = db_all('SELECT * FROM lead_statuses WHERE organization_id = ? ORDER BY sort_order ASC', [$orgId]);
    $sources = db_all('SELECT * FROM lead_sources WHERE organization_id = ? ORDER BY sort_order ASC', [$orgId]);
    $users = db_all('SELECT id, first_name, last_name, email, avatar_url FROM users WHERE organization_id = ?', [$orgId]);
    $teams = db_all('SELECT id, name FROM teams WHERE organization_id = ?', [$orgId]);
    // Any project this tenant's leads point at - including shared ones, which
    // is why this is not filtered by projects.organization_id.
    $projects = db_all(
        'SELECT DISTINCT projects.id, projects.name, projects.location
           FROM projects JOIN leads ON leads.project_id = projects.id
          WHERE leads.organization_id = ?',
        [$orgId]
    );

    return [
        'statuses' => array_column($statuses, null, 'id'),
        'statusList' => $statuses,
        'sources' => array_column($sources, null, 'id'),
        'sourceList' => $sources,
        'users' => array_column($users, null, 'id'),
        'teams' => array_column($teams, null, 'id'),
        'projects' => array_column($projects, null, 'id'),
        'projectList' => $projects,
    ];
}

function gv_leaderboard_row_init($id, array $maps): array
{
    $u = $maps['users'][$id] ?? null;
    return [
        'userId' => $id, 'name' => gv_user_label($u), 'email' => $u['email'] ?? '', 'avatarUrl' => $u['avatar_url'] ?? null,
        'leads' => 0, 'converted' => 0, 'siteVisits' => 0, 'revenue' => 0.0, 'deals' => 0, 'activities' => 0, 'conversionRate' => 0.0,
    ];
}

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

/**
 * The dashboard, built on the real-estate funnel rather than a sales pipeline.
 *
 * There is no separate deal object here: an enquiry becomes a site visit,
 * a site visit becomes a booking, and a booking is a lead status carrying
 * `is_converted`. Everything below is therefore a query over `leads`.
 */
function reports_dashboard(string $orgId, array $scopeInfo, array $query): array
{
    $leadWhere = build_lead_where($query, $scopeInfo, $orgId);
    $maps = gv_label_maps($orgId);

    $statusIdsWhere = function (string $flag) use ($maps) {
        return array_column(array_filter($maps['statusList'], fn($s) => gv_bool($s[$flag])), 'id');
    };
    $qualifiedStatusIds = $statusIdsWhere('is_qualified');
    $lostStatusIds = $statusIdsWhere('is_lost');
    $junkStatusIds = $statusIdsWhere('is_junk');
    $newStatusIds = $statusIdsWhere('is_default');

    /** Counts leads matching the base filter plus one extra clause. */
    $countLeads = function (string $extra = '', array $extraParams = []) use ($leadWhere) {
        return (int) db_scalar(
            "SELECT COUNT(*) FROM leads {$leadWhere['sql']}" . ($extra ? " AND $extra" : ''),
            array_merge($leadWhere['params'], $extraParams)
        );
    };
    $countByStatusIds = function (array $ids) use ($leadWhere, $countLeads) {
        if (!$ids) {
            return 0;
        }
        [$in, $p] = db_in_clause($ids);
        return $countLeads("status_id IN $in", $p);
    };

    $totalLeads = $countLeads();
    $newLeads = $countByStatusIds($newStatusIds);
    $assignedLeads = $countLeads('owner_id IS NOT NULL');
    $unassignedLeads = $countLeads('owner_id IS NULL');
    $lostLeads = $countByStatusIds($lostStatusIds);
    $junkLeads = $countByStatusIds($junkStatusIds);

    if ($qualifiedStatusIds) {
        [$in, $p] = db_in_clause($qualifiedStatusIds);
        $qualifiedLeads = $countLeads("(status_id IN $in OR qualified_at IS NOT NULL)", $p);
    } else {
        $qualifiedLeads = $countLeads('qualified_at IS NOT NULL');
    }

    $convertedLeads = $countLeads('is_converted = 1');
    $contacted = $countLeads('last_contacted_at IS NOT NULL');

    // The two metrics a real-estate sales head actually watches.
    $siteVisitsBooked = $countLeads('site_visit_at IS NOT NULL');
    $siteVisitsDone = $countLeads('site_visit_at IS NOT NULL AND site_visit_at <= ?', [date('Y-m-d H:i:s')]);
    $siteVisitsUpcoming = $countLeads('site_visit_at > ?', [date('Y-m-d H:i:s')]);

    $followUpsDue = $countLeads(
        'is_converted = 0 AND next_follow_up_at >= ? AND next_follow_up_at <= ?',
        [start_of_day(), end_of_day()]
    );
    $followUpsOverdue = $countLeads('is_converted = 0 AND next_follow_up_at < ?', [start_of_day()]);

    // Booking value comes off the lead's own budget, since there is no deal
    // record to carry an amount.
    $bookedValue = (float) (db_scalar(
        "SELECT SUM(COALESCE(expected_value, budget_max)) FROM leads {$leadWhere['sql']} AND is_converted = 1",
        $leadWhere['params']
    ) ?? 0);
    $openValue = (float) (db_scalar(
        "SELECT SUM(COALESCE(expected_value, budget_max)) FROM leads {$leadWhere['sql']} AND is_converted = 0"
        . ($lostStatusIds ? '' : ''),
        $leadWhere['params']
    ) ?? 0);

    $trend = reports_lead_trend($orgId, $scopeInfo, $query);
    $byStatusRows = db_all("SELECT status_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} GROUP BY status_id", $leadWhere['params']);
    $bySourceRows = db_all("SELECT source_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} GROUP BY source_id", $leadWhere['params']);
    $byOwnerRows = db_all("SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} GROUP BY owner_id", $leadWhere['params']);
    $byProjectRows = db_all(
        "SELECT project_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND project_id IS NOT NULL GROUP BY project_id",
        $leadWhere['params']
    );

    // Qualified because users and leads are joined in below, and both also
    // carry an organization_id.
    if ($scopeInfo['unrestricted']) {
        $actSql = 'activities.organization_id = ?';
        $actParams = [$orgId];
    } else {
        [$in, $p] = db_in_clause($scopeInfo['userIds']);
        $actSql = "activities.organization_id = ? AND (activities.user_id IN $in OR activities.user_id IS NULL)";
        $actParams = array_merge([$orgId], $p);
    }
    $activityRows = db_all(
        "SELECT activities.type, activities.subject, activities.occurred_at, activities.user_id, activities.lead_id,
                u.first_name AS u_first_name, u.last_name AS u_last_name, u.avatar_url AS u_avatar_url,
                l.code AS l_code, l.first_name AS l_first_name, l.last_name AS l_last_name
         FROM activities LEFT JOIN users u ON u.id = activities.user_id LEFT JOIN leads l ON l.id = activities.lead_id
         WHERE $actSql ORDER BY activities.occurred_at DESC LIMIT 12",
        $actParams
    );
    $recentActivities = array_map(fn($r) => [
        'type' => $r['type'], 'subject' => $r['subject'], 'occurredAt' => $r['occurred_at'],
        'lead' => $r['lead_id'] ? ['id' => $r['lead_id'], 'code' => $r['l_code'], 'firstName' => $r['l_first_name'], 'lastName' => $r['l_last_name']] : null,
        'user' => $r['user_id'] ? ['id' => $r['user_id'], 'firstName' => $r['u_first_name'], 'lastName' => $r['u_last_name'], 'avatarUrl' => $r['u_avatar_url']] : null,
    ], $activityRows);

    // Replaces the old task list: the work queue is leads that are due, which
    // is what "follow-up" means now that tasks are gone.
    $followUpRows = db_all(
        "SELECT leads.id, leads.code, leads.first_name, leads.last_name, leads.phone,
                leads.next_follow_up_at, leads.site_visit_at, leads.owner_id,
                projects.name AS project_name,
                u.first_name AS o_first_name, u.last_name AS o_last_name
           FROM leads
           LEFT JOIN projects ON projects.id = leads.project_id
           LEFT JOIN users u ON u.id = leads.owner_id
           {$leadWhere['sql']} AND leads.is_converted = 0 AND leads.next_follow_up_at IS NOT NULL
          ORDER BY leads.next_follow_up_at ASC LIMIT 8",
        $leadWhere['params']
    );
    $upcomingFollowUps = array_map(fn($r) => [
        'id' => $r['id'],
        'code' => $r['code'],
        'name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')),
        'phone' => $r['phone'],
        'dueAt' => $r['next_follow_up_at'],
        'siteVisitAt' => $r['site_visit_at'],
        'projectName' => $r['project_name'],
        'owner' => $r['owner_id'] ? ['id' => $r['owner_id'], 'firstName' => $r['o_first_name'], 'lastName' => $r['o_last_name']] : null,
    ], $followUpRows);

    $leaderboard = reports_team_leaderboard($orgId, $scopeInfo, $query, $maps);

    $byStatus = array_map(fn($r) => [
        'id' => $r['status_id'], 'label' => $maps['statuses'][$r['status_id']]['name'] ?? 'Unknown',
        'color' => $maps['statuses'][$r['status_id']]['color'] ?? '#94a3b8',
        'order' => $maps['statuses'][$r['status_id']]['sort_order'] ?? 99, 'count' => (int) $r['cnt'],
    ], $byStatusRows);
    usort($byStatus, fn($a, $b) => $a['order'] <=> $b['order']);

    $bySource = array_map(fn($r) => [
        'id' => $r['source_id'], 'label' => $r['source_id'] ? ($maps['sources'][$r['source_id']]['name'] ?? 'Unknown') : 'Direct / Unknown',
        'count' => (int) $r['cnt'],
    ], $bySourceRows);
    usort($bySource, fn($a, $b) => $b['count'] <=> $a['count']);
    $bySource = array_slice($bySource, 0, 10);

    $byOwner = array_map(fn($r) => ['id' => $r['owner_id'], 'label' => gv_user_label($maps['users'][$r['owner_id']] ?? null), 'count' => (int) $r['cnt']], $byOwnerRows);
    usort($byOwner, fn($a, $b) => $b['count'] <=> $a['count']);
    $byOwner = array_slice($byOwner, 0, 10);

    $byProject = array_map(fn($r) => [
        'id' => $r['project_id'],
        'label' => $maps['projects'][$r['project_id']]['name'] ?? 'Unknown',
        'count' => (int) $r['cnt'],
    ], $byProjectRows);
    usort($byProject, fn($a, $b) => $b['count'] <=> $a['count']);
    $byProject = array_slice($byProject, 0, 10);

    return [
        'kpis' => [
            'totalLeads' => $totalLeads, 'newLeads' => $newLeads,
            'assignedLeads' => $assignedLeads, 'unassignedLeads' => $unassignedLeads,
            'qualifiedLeads' => $qualifiedLeads, 'convertedLeads' => $convertedLeads,
            'lostLeads' => $lostLeads, 'junkLeads' => $junkLeads,
            'followUpsDue' => $followUpsDue, 'followUpsOverdue' => $followUpsOverdue,
            'siteVisitsBooked' => $siteVisitsBooked,
            'siteVisitsDone' => $siteVisitsDone,
            'siteVisitsUpcoming' => $siteVisitsUpcoming,
            'bookings' => $convertedLeads,
            'bookedValue' => round2($bookedValue),
            'openValue' => round2($openValue),
            'conversionRate' => pct($convertedLeads, $totalLeads),
            'qualificationRate' => pct($qualifiedLeads, $totalLeads),
            'siteVisitRate' => pct($siteVisitsBooked, $totalLeads),
        ],
        'trend' => $trend,
        'byStatus' => $byStatus,
        'bySource' => $bySource,
        'byOwner' => $byOwner,
        'byProject' => $byProject,
        'funnel' => [
            ['label' => 'Enquiries', 'value' => $totalLeads],
            ['label' => 'Contacted', 'value' => $contacted],
            ['label' => 'Qualified', 'value' => $qualifiedLeads],
            ['label' => 'Site visits', 'value' => $siteVisitsBooked],
            ['label' => 'Booked', 'value' => $convertedLeads],
        ],
        'leaderboard' => $leaderboard,
        'recentActivities' => $recentActivities,
        'upcomingFollowUps' => $upcomingFollowUps,
    ];
}

/** Daily lead / conversion counts for the trend chart. */
function reports_lead_trend(string $orgId, array $scopeInfo, array $query): array
{
    $range = date_range($query);
    $to = $range['to'] ?: end_of_day();
    $from = $range['from'] ?: start_of_day(date('Y-m-d', strtotime($to) - 29 * 86400));
    $days = min(120, max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1));

    $leadQuery = $query;
    unset($leadQuery['range'], $leadQuery['from'], $leadQuery['to']);
    $where = build_lead_where($leadQuery, $scopeInfo, $orgId);
    $rows = db_all(
        "SELECT created_at, converted_at, qualified_at FROM leads {$where['sql']} AND created_at >= ? AND created_at <= ?",
        array_merge($where['params'], [$from, $to])
    );

    $buckets = [];
    for ($i = 0; $i < $days; $i++) {
        $day = date('Y-m-d', strtotime($from) + $i * 86400);
        $buckets[$day] = ['date' => $day, 'leads' => 0, 'qualified' => 0, 'converted' => 0];
    }
    foreach ($rows as $row) {
        $key = date('Y-m-d', strtotime($row['created_at']));
        if (!isset($buckets[$key])) {
            continue;
        }
        $buckets[$key]['leads']++;
        if ($row['qualified_at']) {
            $buckets[$key]['qualified']++;
        }
        if ($row['converted_at']) {
            $buckets[$key]['converted']++;
        }
    }
    return array_values($buckets);
}

function reports_team_leaderboard(string $orgId, array $scopeInfo, array $query, ?array $maps = null): array
{
    $maps = $maps ?? gv_label_maps($orgId);
    $leadWhere = build_lead_where($query, $scopeInfo, $orgId);

    $leadCounts = db_all("SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND owner_id IS NOT NULL GROUP BY owner_id", $leadWhere['params']);
    $convertedCounts = db_all("SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND owner_id IS NOT NULL AND is_converted = 1 GROUP BY owner_id", $leadWhere['params']);
    // A booking is the deal. Its value is the lead's own budget, since there
    // is no separate deal record to carry an amount.
    $wonAgg = db_all(
        "SELECT owner_id, SUM(COALESCE(expected_value, budget_max)) AS amt, COUNT(*) AS cnt
           FROM leads {$leadWhere['sql']} AND owner_id IS NOT NULL AND is_converted = 1 GROUP BY owner_id",
        $leadWhere['params']
    );
    $siteVisitCounts = db_all(
        "SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND owner_id IS NOT NULL AND site_visit_at IS NOT NULL GROUP BY owner_id",
        $leadWhere['params']
    );

    $activityParams = [$orgId];
    $activitySql = "organization_id = ? AND type IN ('CALL','MEETING','EMAIL','WHATSAPP') AND user_id IS NOT NULL";
    if (!$scopeInfo['unrestricted']) {
        [$in, $p] = db_in_clause($scopeInfo['userIds']);
        $activitySql .= " AND user_id IN $in";
        $activityParams = array_merge($activityParams, $p);
    }
    $activityCounts = db_all("SELECT user_id, COUNT(*) AS cnt FROM activities WHERE $activitySql GROUP BY user_id", $activityParams);

    $byUser = [];
    foreach ($leadCounts as $r) {
        $id = $r['owner_id'];
        $byUser[$id] = $byUser[$id] ?? gv_leaderboard_row_init($id, $maps);
        $byUser[$id]['leads'] = (int) $r['cnt'];
    }
    foreach ($convertedCounts as $r) {
        $id = $r['owner_id'];
        $byUser[$id] = $byUser[$id] ?? gv_leaderboard_row_init($id, $maps);
        $byUser[$id]['converted'] = (int) $r['cnt'];
    }
    foreach ($wonAgg as $r) {
        if (!$r['owner_id']) {
            continue;
        }
        $id = $r['owner_id'];
        $byUser[$id] = $byUser[$id] ?? gv_leaderboard_row_init($id, $maps);
        $byUser[$id]['revenue'] = round2((float) ($r['amt'] ?? 0));
        $byUser[$id]['deals'] = (int) $r['cnt'];
    }
    foreach ($siteVisitCounts as $r) {
        $id = $r['owner_id'];
        $byUser[$id] = $byUser[$id] ?? gv_leaderboard_row_init($id, $maps);
        $byUser[$id]['siteVisits'] = (int) $r['cnt'];
    }
    foreach ($activityCounts as $r) {
        if (!$r['user_id']) {
            continue;
        }
        $id = $r['user_id'];
        $byUser[$id] = $byUser[$id] ?? gv_leaderboard_row_init($id, $maps);
        $byUser[$id]['activities'] = (int) $r['cnt'];
    }

    $rows = [];
    foreach ($byUser as $row) {
        $row['conversionRate'] = pct($row['converted'], $row['leads']);
        $rows[] = $row;
    }
    usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: ($b['converted'] <=> $a['converted'] ?: $b['leads'] <=> $a['leads']));
    return array_slice($rows, 0, 15);
}

// ---------------------------------------------------------------------------
// Lead reports
// ---------------------------------------------------------------------------

const GV_LEAD_DIMENSIONS = [
    'project' => ['field' => 'project_id', 'label' => 'Project'],
    'source' => ['field' => 'source_id', 'label' => 'Lead source'],
    'status' => ['field' => 'status_id', 'label' => 'Lead status'],
    'owner' => ['field' => 'owner_id', 'label' => 'Owner'],
    'team' => ['field' => 'team_id', 'label' => 'Team'],
    'city' => ['field' => 'city', 'label' => 'City'],
    'state' => ['field' => 'state', 'label' => 'State'],
    'country' => ['field' => 'country', 'label' => 'Country'],
    'priority' => ['field' => 'priority', 'label' => 'Priority'],
    'scoreBand' => ['field' => 'score_band', 'label' => 'Score band'],
];

function lead_report(string $orgId, array $scopeInfo, array $query): array
{
    $dimKey = (isset($query['dimension']) && isset(GV_LEAD_DIMENSIONS[$query['dimension']])) ? $query['dimension'] : 'source';
    $dimension = GV_LEAD_DIMENSIONS[$dimKey];
    $field = $dimension['field'];
    $where = build_lead_where($query, $scopeInfo, $orgId);
    $maps = gv_label_maps($orgId);

    $total = (int) db_scalar("SELECT COUNT(*) FROM leads {$where['sql']}", $where['params']);
    $grouped = db_all("SELECT `$field` AS k, COUNT(*) AS cnt FROM leads {$where['sql']} GROUP BY `$field`", $where['params']);
    $converted = db_all("SELECT `$field` AS k, COUNT(*) AS cnt FROM leads {$where['sql']} AND is_converted = 1 GROUP BY `$field`", $where['params']);
    $qualified = db_all("SELECT `$field` AS k, COUNT(*) AS cnt FROM leads {$where['sql']} AND qualified_at IS NOT NULL GROUP BY `$field`", $where['params']);
    $values = db_all("SELECT `$field` AS k, SUM(expected_value) AS val FROM leads {$where['sql']} GROUP BY `$field`", $where['params']);
    $siteVisits = db_all("SELECT `$field` AS k, COUNT(*) AS cnt FROM leads {$where['sql']} AND site_visit_at IS NOT NULL GROUP BY `$field`", $where['params']);

    $convertedMap = array_column($converted, 'cnt', 'k');
    $qualifiedMap = array_column($qualified, 'cnt', 'k');
    $valueMap = array_column($values, 'val', 'k');
    $siteVisitMap = array_column($siteVisits, 'cnt', 'k');

    $resolveLabel = function ($key) use ($field, $maps) {
        if ($key === null || $key === '') {
            return 'Not set';
        }
        switch ($field) {
            case 'source_id': return $maps['sources'][$key]['name'] ?? 'Unknown';
            case 'status_id': return $maps['statuses'][$key]['name'] ?? 'Unknown';
            case 'owner_id': return gv_user_label($maps['users'][$key] ?? null);
            case 'team_id': return $maps['teams'][$key]['name'] ?? 'Unknown';
            case 'project_id': return $maps['projects'][$key]['name'] ?? 'Unknown';
            default: return (string) $key;
        }
    };

    $rows = array_map(function ($r) use ($convertedMap, $qualifiedMap, $valueMap, $siteVisitMap, $resolveLabel, $total) {
        $key = $r['k'];
        $leads = (int) $r['cnt'];
        $convertedCount = (int) ($convertedMap[$key] ?? 0);
        $qualifiedCount = (int) ($qualifiedMap[$key] ?? 0);
        return [
            'key' => $key,
            'label' => $resolveLabel($key),
            'leads' => $leads,
            'qualified' => $qualifiedCount,
            'converted' => $convertedCount,
            'siteVisits' => (int) ($siteVisitMap[$key] ?? 0),
            'qualificationRate' => pct($qualifiedCount, $leads),
            'conversionRate' => pct($convertedCount, $leads),
            'siteVisitRate' => pct((int) ($siteVisitMap[$key] ?? 0), $leads),
            'expectedValue' => round2((float) ($valueMap[$key] ?? 0)),
            'share' => pct($leads, $total),
        ];
    }, $grouped);
    usort($rows, fn($a, $b) => $b['leads'] <=> $a['leads']);

    return ['dimension' => $dimKey, 'dimensionLabel' => $dimension['label'], 'total' => $total, 'rows' => $rows];
}


// ---------------------------------------------------------------------------
// Performance
// ---------------------------------------------------------------------------

function performance_report(string $orgId, array $scopeInfo, array $query): array
{
    $maps = gv_label_maps($orgId);
    $range = date_range($query);

    $activityParams = [$orgId];
    $activitySql = 'organization_id = ?';
    if (!$scopeInfo['unrestricted']) {
        [$in, $p] = db_in_clause($scopeInfo['userIds']);
        $activitySql .= " AND user_id IN $in";
        $activityParams = array_merge($activityParams, $p);
    }
    if ($range['from']) {
        $activitySql .= ' AND occurred_at >= ?';
        $activityParams[] = $range['from'];
    }
    if ($range['to']) {
        $activitySql .= ' AND occurred_at <= ?';
        $activityParams[] = $range['to'];
    }
    $byUserType = db_all("SELECT user_id, type, COUNT(*) AS cnt FROM activities WHERE $activitySql GROUP BY user_id, type", $activityParams);

    $leadWhere = build_lead_where($query, $scopeInfo, $orgId);
    $leadCounts = db_all("SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} GROUP BY owner_id", $leadWhere['params']);
    $convCounts = db_all("SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND is_converted = 1 GROUP BY owner_id", $leadWhere['params']);

    // A booking is the deal, and its value is the lead's own budget.
    $wonAgg = db_all(
        "SELECT owner_id, SUM(COALESCE(expected_value, budget_max)) AS amt, COUNT(*) AS cnt
           FROM leads {$leadWhere['sql']} AND is_converted = 1 GROUP BY owner_id",
        $leadWhere['params']
    );
    $siteVisitAgg = db_all(
        "SELECT owner_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']} AND site_visit_at IS NOT NULL GROUP BY owner_id",
        $leadWhere['params']
    );

    $targetParams = [$orgId];
    $targetSql = 'organization_id = ? AND user_id IS NOT NULL';
    if ($range['from']) {
        $targetSql .= ' AND period_end >= ?';
        $targetParams[] = substr($range['from'], 0, 10);
    }
    if ($range['to']) {
        $targetSql .= ' AND period_start <= ?';
        $targetParams[] = substr($range['to'], 0, 10);
    }
    $targets = db_all("SELECT * FROM targets WHERE $targetSql", $targetParams);

    $rows = [];
    $init = function ($id) use ($maps) {
        $u = $maps['users'][$id] ?? null;
        return [
            'userId' => $id, 'name' => gv_user_label($u), 'email' => $u['email'] ?? '',
            'calls' => 0, 'emails' => 0, 'meetings' => 0, 'whatsapp' => 0, 'notes' => 0,
            'leads' => 0, 'converted' => 0, 'revenue' => 0.0, 'deals' => 0,
            'siteVisits' => 0, 'conversionRate' => 0.0, 'targets' => [],
        ];
    };
    $typeToField = ['CALL' => 'calls', 'EMAIL' => 'emails', 'MEETING' => 'meetings', 'WHATSAPP' => 'whatsapp', 'NOTE' => 'notes'];

    foreach ($byUserType as $r) {
        if (!$r['user_id']) {
            continue;
        }
        $rows[$r['user_id']] = $rows[$r['user_id']] ?? $init($r['user_id']);
        $field = $typeToField[$r['type']] ?? null;
        if ($field) {
            $rows[$r['user_id']][$field] += (int) $r['cnt'];
        }
    }
    foreach ($leadCounts as $r) {
        if (!$r['owner_id']) {
            continue;
        }
        $rows[$r['owner_id']] = $rows[$r['owner_id']] ?? $init($r['owner_id']);
        $rows[$r['owner_id']]['leads'] = (int) $r['cnt'];
    }
    foreach ($convCounts as $r) {
        if (!$r['owner_id']) {
            continue;
        }
        $rows[$r['owner_id']] = $rows[$r['owner_id']] ?? $init($r['owner_id']);
        $rows[$r['owner_id']]['converted'] = (int) $r['cnt'];
    }
    foreach ($wonAgg as $r) {
        if (!$r['owner_id']) {
            continue;
        }
        $rows[$r['owner_id']] = $rows[$r['owner_id']] ?? $init($r['owner_id']);
        $rows[$r['owner_id']]['revenue'] = round2((float) ($r['amt'] ?? 0));
        $rows[$r['owner_id']]['deals'] = (int) $r['cnt'];
    }
    foreach ($siteVisitAgg as $r) {
        if (!$r['owner_id']) {
            continue;
        }
        $rows[$r['owner_id']] = $rows[$r['owner_id']] ?? $init($r['owner_id']);
        $rows[$r['owner_id']]['siteVisits'] = (int) $r['cnt'];
    }

    $targetsByUser = [];
    foreach ($targets as $t) {
        $targetsByUser[$t['user_id']][] = $t;
    }
    $metricField = [
        'LEADS' => 'leads', 'CALLS' => 'calls', 'MEETINGS' => 'meetings',
        'QUALIFIED' => 'converted', 'SITE_VISITS' => 'siteVisits',
        'BOOKINGS' => 'deals', 'CONVERSIONS' => 'converted', 'REVENUE' => 'revenue',
    ];

    foreach ($rows as $uid => &$row) {
        $row['conversionRate'] = pct($row['converted'], $row['leads']);
        foreach ($targetsByUser[$uid] ?? [] as $t) {
            $field = $metricField[$t['metric']] ?? null;
            $actual = $field ? $row[$field] : 0;
            $row['targets'][$t['metric']] = ['target' => (float) $t['target_value'], 'actual' => $actual, 'attainment' => pct($actual, (float) $t['target_value'])];
        }
    }
    unset($row);

    $out = array_values($rows);
    usort($out, fn($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['leads'] <=> $a['leads']);
    return $out;
}

// ---------------------------------------------------------------------------
// Marketing attribution
// ---------------------------------------------------------------------------

/**
 * Marketing performance by lead source and by project.
 *
 * Campaign objects are gone with the campaigns module, but the attribution
 * survives on the lead itself: `source_id` plus the UTM columns plus the Meta
 * ad/adset/campaign ids. Cost per lead comes from the source's own
 * `cost_per_lead`, which is where a real-estate team records what a portal or
 * an ad platform charges them.
 */
function marketing_report(string $orgId, array $scopeInfo, array $query): array
{
    $maps = gv_label_maps($orgId);
    $leadWhere = build_lead_where($query, $scopeInfo, $orgId);

    $agg = function (string $extra = '') use ($leadWhere) {
        return db_all(
            "SELECT source_id, COUNT(*) AS cnt FROM leads {$leadWhere['sql']}" . ($extra ? " AND $extra" : '')
            . ' GROUP BY source_id',
            $leadWhere['params']
        );
    };

    $bySourceRows = $agg();
    $qualifiedMap = array_column($agg('qualified_at IS NOT NULL'), 'cnt', 'source_id');
    $siteVisitMap = array_column($agg('site_visit_at IS NOT NULL'), 'cnt', 'source_id');
    $convertedMap = array_column($agg('is_converted = 1'), 'cnt', 'source_id');
    $valueMap = array_column(
        db_all(
            "SELECT source_id, SUM(COALESCE(expected_value, budget_max)) AS amt
               FROM leads {$leadWhere['sql']} AND is_converted = 1 GROUP BY source_id",
            $leadWhere['params']
        ),
        'amt',
        'source_id'
    );

    $sources = array_map(function ($r) use ($maps, $qualifiedMap, $siteVisitMap, $convertedMap, $valueMap) {
        $key = $r['source_id'];
        $source = $key ? ($maps['sources'][$key] ?? null) : null;
        $leads = (int) $r['cnt'];
        $qualified = (int) ($qualifiedMap[$key] ?? 0);
        $converted = (int) ($convertedMap[$key] ?? 0);
        $revenue = round2((float) ($valueMap[$key] ?? 0));
        $costPerLead = (float) ($source['cost_per_lead'] ?? 0);
        $spend = round2($costPerLead * $leads);
        return [
            'sourceId' => $key,
            'label' => $source['name'] ?? 'Direct / Unknown',
            'leads' => $leads,
            'qualified' => $qualified,
            'siteVisits' => (int) ($siteVisitMap[$key] ?? 0),
            'converted' => $converted,
            'revenue' => $revenue,
            'costPerLead' => round2($costPerLead),
            'spend' => $spend,
            'costPerQualified' => $qualified ? round2($spend / $qualified) : 0,
            'costPerBooking' => $converted ? round2($spend / $converted) : 0,
            'qualificationRate' => pct($qualified, $leads),
            'conversionRate' => pct($converted, $leads),
            'roi' => $spend > 0 ? round2((($revenue - $spend) / $spend) * 100) : null,
        ];
    }, $bySourceRows);
    usort($sources, fn($a, $b) => $b['leads'] <=> $a['leads']);

    // UTM breakdown, which is what actually identifies an ad campaign now.
    $byUtm = db_all(
        "SELECT utm_source, utm_campaign, COUNT(*) AS cnt,
                SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) AS converted
           FROM leads {$leadWhere['sql']} AND utm_campaign IS NOT NULL AND utm_campaign <> ''
          GROUP BY utm_source, utm_campaign ORDER BY cnt DESC LIMIT 25",
        $leadWhere['params']
    );
    $campaigns = array_map(fn($r) => [
        'label' => $r['utm_campaign'],
        'source' => $r['utm_source'] ?: 'Unknown',
        'leads' => (int) $r['cnt'],
        'converted' => (int) $r['converted'],
        'conversionRate' => pct((int) $r['converted'], (int) $r['cnt']),
    ], $byUtm);

    $byProject = db_all(
        "SELECT project_id, COUNT(*) AS cnt,
                SUM(CASE WHEN is_converted = 1 THEN 1 ELSE 0 END) AS converted
           FROM leads {$leadWhere['sql']} AND project_id IS NOT NULL GROUP BY project_id ORDER BY cnt DESC",
        $leadWhere['params']
    );
    $projects = array_map(fn($r) => [
        'projectId' => $r['project_id'],
        'label' => $maps['projects'][$r['project_id']]['name'] ?? 'Unknown',
        'leads' => (int) $r['cnt'],
        'converted' => (int) $r['converted'],
        'conversionRate' => pct((int) $r['converted'], (int) $r['cnt']),
    ], $byProject);

    $totalLeads = array_sum(array_column($sources, 'leads'));
    $totalSpend = array_sum(array_column($sources, 'spend'));
    $totalRevenue = array_sum(array_column($sources, 'revenue'));

    return [
        'sources' => $sources,
        'campaigns' => $campaigns,
        'projects' => $projects,
        'totals' => [
            'leads' => $totalLeads,
            'spend' => round2((float) $totalSpend),
            'revenue' => round2((float) $totalRevenue),
            'costPerLead' => $totalLeads ? round2($totalSpend / $totalLeads) : 0,
            'roi' => $totalSpend > 0 ? round2((($totalRevenue - $totalSpend) / $totalSpend) * 100) : null,
        ],
    ];
}
