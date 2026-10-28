<?php
declare(strict_types=1);

/**
 * WHERE-clause builders — ported from src/services/filters.js.
 *
 * The Node version built Prisma `where` object trees; there is no Prisma
 * here, so the equivalent is a builder that returns raw SQL (with `?`
 * placeholders) plus its bound params: ['sql' => 'WHERE ...', 'params' => [...]].
 * Every list, export and report funnels through here so a filter behaves
 * identically wherever it's applied, and so the visibility clause from
 * lib/scope.php is never accidentally left off.
 */

function gv_query_array($value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn($v) => $v !== ''));
}

/** @return array{from: ?string, to: ?string} Y-m-d H:i:s bounds, or nulls for "no filter". */
function date_range(array $query): array
{
    $from = !empty($query['from']) ? parse_date($query['from']) : null;
    $to = !empty($query['to']) ? parse_date($query['to']) : null;
    if ($from || $to) {
        return ['from' => $from ? start_of_day($from) : null, 'to' => $to ? end_of_day($to) : null];
    }

    switch ($query['range'] ?? null) {
        case 'today':
            return ['from' => start_of_day(), 'to' => end_of_day()];
        case 'yesterday':
            $y = date('Y-m-d', strtotime('-1 day'));
            return ['from' => start_of_day($y), 'to' => end_of_day($y)];
        case 'last_7_days':
            return ['from' => start_of_day(date('Y-m-d', strtotime('-6 days'))), 'to' => end_of_day()];
        case 'last_30_days':
            return ['from' => start_of_day(date('Y-m-d', strtotime('-29 days'))), 'to' => end_of_day()];
        case 'last_90_days':
            return ['from' => start_of_day(date('Y-m-d', strtotime('-89 days'))), 'to' => end_of_day()];
        case 'this_month':
            return ['from' => date('Y-m-01 00:00:00'), 'to' => end_of_day()];
        case 'last_month':
            return ['from' => date('Y-m-01 00:00:00', strtotime('first day of last month')), 'to' => date('Y-m-t 23:59:59', strtotime('last day of last month'))];
        case 'this_quarter':
            $month = (int) date('n');
            $startMonth = intdiv($month - 1, 3) * 3 + 1;
            return ['from' => sprintf('%s-%02d-01 00:00:00', date('Y'), $startMonth), 'to' => end_of_day()];
        case 'this_year':
            return ['from' => date('Y-01-01 00:00:00'), 'to' => end_of_day()];
        default:
            return ['from' => null, 'to' => null];
    }
}

/** @return array{0: ?string, 1: array} a SQL fragment (or null) and its params */
function date_filter_clause(string $column, array $range): array
{
    $parts = [];
    $params = [];
    if ($range['from']) {
        $parts[] = "$column >= ?";
        $params[] = $range['from'];
    }
    if ($range['to']) {
        $parts[] = "$column <= ?";
        $params[] = $range['to'];
    }
    return [$parts ? implode(' AND ', $parts) : null, $params];
}

const GV_LEAD_DATE_FIELDS = [
    'createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'convertedAt' => 'converted_at',
    'lastContactedAt' => 'last_contacted_at', 'nextFollowUpAt' => 'next_follow_up_at', 'qualifiedAt' => 'qualified_at',
];
const GV_OPP_DATE_FIELDS = [
    'createdAt' => 'created_at', 'expectedCloseDate' => 'expected_close_date', 'actualCloseDate' => 'actual_close_date', 'updatedAt' => 'updated_at',
];
const GV_TASK_DATE_FIELDS = ['dueAt' => 'due_at', 'createdAt' => 'created_at', 'completedAt' => 'completed_at'];

/**
 * Lead list / report filter. Supported query keys: q, statusId, sourceId,
 * projectId, purpose, configuration,
 * ownerId, teamId, priority, scoreBand, country, state, city,
 * tag, converted, unassigned, followUp, range/from/to, dateField, minScore, maxScore.
 *
 * Every column is qualified with `leads.` so this stays valid whether the
 * caller queries `leads` alone or joins in owner/status/source/etc. labels —
 * an unqualified `organization_id` would be ambiguous the moment a joined
 * table (they all have one) is added to the query.
 *
 * @return array{sql: string, params: array}
 */
function build_lead_where(array $query, ?array $scopeInfo, string $orgId, bool $ignoreVisibility = false): array
{
    $clauses = ['leads.organization_id = ?', 'leads.is_duplicate = 0'];
    $params = [$orgId];

    if (!$ignoreVisibility && $scopeInfo) {
        $vis = visibility_where($scopeInfo, 'leads.owner_id', 'leads.team_id');
        if ($vis['sql'] !== '') {
            $clauses[] = $vis['sql'];
            $params = array_merge($params, $vis['params']);
        }
    }

    if (!empty($query['q'])) {
        $term = '%' . trim((string) $query['q']) . '%';
        $clauses[] = '(leads.first_name LIKE ? OR leads.last_name LIKE ? OR leads.email LIKE ? OR leads.phone LIKE ? OR leads.company LIKE ? OR leads.code LIKE ? OR leads.city LIKE ?)';
        array_push($params, $term, $term, $term, $term, $term, $term, $term);
    }

    foreach ([
        'statusId' => 'status_id', 'sourceId' => 'source_id',
        'projectId' => 'project_id',
        'priority' => 'priority', 'scoreBand' => 'score_band', 'ownerId' => 'owner_id', 'teamId' => 'team_id',
        'purpose' => 'purpose', 'configuration' => 'configuration',
    ] as $key => $col) {
        $vals = gv_query_array($query[$key] ?? null);
        if ($vals) {
            [$in, $p] = db_in_clause($vals);
            $clauses[] = "leads.$col IN $in";
            $params = array_merge($params, $p);
        }
    }

    foreach (['country' => 'country', 'state' => 'state', 'city' => 'city'] as $key => $col) {
        if (!empty($query[$key])) {
            $clauses[] = "leads.$col = ?";
            $params[] = (string) $query[$key];
        }
    }

    $unassigned = $query['unassigned'] ?? null;
    if ($unassigned === 'true' || $unassigned === true) {
        $clauses[] = 'leads.owner_id IS NULL';
    }
    // Enquiries that arrived without a project - from an older form, an import,
    // or Meta. Typing a lead in by hand now requires one, but the ones already
    // here still need finding, and an enquiry nobody can attribute to a tower is
    // worth chasing rather than hiding.
    if (($query['noProject'] ?? null) === 'true') {
        $clauses[] = 'leads.project_id IS NULL';
    }
    if (($query['converted'] ?? null) === 'true') {
        $clauses[] = 'leads.is_converted = 1';
    }
    if (($query['converted'] ?? null) === 'false') {
        $clauses[] = 'leads.is_converted = 0';
    }

    if (!empty($query['minScore'])) {
        $clauses[] = 'leads.score >= ?';
        $params[] = (float) $query['minScore'];
    }
    if (!empty($query['maxScore'])) {
        $clauses[] = 'leads.score <= ?';
        $params[] = (float) $query['maxScore'];
    }

    if (!empty($query['tag'])) {
        $tags = gv_query_array($query['tag']);
        if ($tags) {
            [$in, $p] = db_in_clause($tags);
            $clauses[] = "leads.id IN (SELECT lt.lead_id FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id WHERE t.name IN $in)";
            $params = array_merge($params, $p);
        }
    }

    switch ($query['followUp'] ?? null) {
        case 'overdue':
            $clauses[] = 'leads.next_follow_up_at < ?';
            $params[] = start_of_day();
            $clauses[] = 'leads.is_converted = 0';
            break;
        case 'today':
            $clauses[] = 'leads.next_follow_up_at >= ? AND leads.next_follow_up_at <= ?';
            $params[] = start_of_day();
            $params[] = end_of_day();
            break;
        case 'upcoming':
            $clauses[] = 'leads.next_follow_up_at > ?';
            $params[] = end_of_day();
            break;
        case 'due':
            $clauses[] = 'leads.next_follow_up_at <= ?';
            $params[] = end_of_day();
            $clauses[] = 'leads.is_converted = 0';
            break;
    }

    $range = date_range($query);
    $column = 'leads.' . (GV_LEAD_DATE_FIELDS[$query['dateField'] ?? ''] ?? 'created_at');
    [$dateSql, $dateParams] = date_filter_clause($column, $range);
    if ($dateSql) {
        $clauses[] = $dateSql;
        $params = array_merge($params, $dateParams);
    }

    return ['sql' => 'WHERE ' . implode(' AND ', $clauses), 'params' => $params];
}
