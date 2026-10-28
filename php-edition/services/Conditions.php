<?php
declare(strict_types=1);

/**
 * Shared condition evaluator for assignment rules and automation workflows —
 * ported from src/services/conditions.js. Stored shape is unchanged:
 * { match: "ALL"|"ANY", conditions: [{ field, operator, value }] }, so rules
 * created under the Node backend evaluate identically here.
 */

const GV_OPERATORS = [
    ['key' => 'eq', 'label' => 'is'],
    ['key' => 'neq', 'label' => 'is not'],
    ['key' => 'contains', 'label' => 'contains'],
    ['key' => 'not_contains', 'label' => 'does not contain'],
    ['key' => 'starts_with', 'label' => 'starts with'],
    ['key' => 'in', 'label' => 'is any of'],
    ['key' => 'not_in', 'label' => 'is none of'],
    ['key' => 'gt', 'label' => 'greater than'],
    ['key' => 'gte', 'label' => 'greater or equal'],
    ['key' => 'lt', 'label' => 'less than'],
    ['key' => 'lte', 'label' => 'less or equal'],
    ['key' => 'is_empty', 'label' => 'is empty'],
    ['key' => 'is_not_empty', 'label' => 'is not empty'],
    ['key' => 'changed', 'label' => 'changed'],
];

const GV_LEAD_FIELDS = [
    ['key' => 'projectId', 'label' => 'Project', 'type' => 'project'],
    ['key' => 'project.city', 'label' => 'Project City', 'type' => 'text'],
    ['key' => 'project.projectType', 'label' => 'Project Type', 'type' => 'enum',
        'options' => ['RESIDENTIAL', 'COMMERCIAL', 'MIXED_USE', 'PLOTTED', 'VILLA']],
    ['key' => 'configuration', 'label' => 'Configuration', 'type' => 'text'],
    ['key' => 'budgetMax', 'label' => 'Budget (max)', 'type' => 'number'],
    ['key' => 'purpose', 'label' => 'Purpose', 'type' => 'enum', 'options' => ['END_USE', 'INVESTMENT']],
    ['key' => 'source.key', 'label' => 'Lead Source', 'type' => 'source'],
    ['key' => 'utmCampaign', 'label' => 'UTM Campaign', 'type' => 'text'],
    ['key' => 'status.key', 'label' => 'Lead Status', 'type' => 'status'],
    ['key' => 'country', 'label' => 'Country', 'type' => 'text'],
    ['key' => 'state', 'label' => 'State', 'type' => 'text'],
    ['key' => 'city', 'label' => 'City', 'type' => 'text'],
    ['key' => 'pincode', 'label' => 'Pincode', 'type' => 'text'],
    ['key' => 'company', 'label' => 'Company', 'type' => 'text'],
    ['key' => 'jobTitle', 'label' => 'Job Title', 'type' => 'text'],
    ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
    ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
    ['key' => 'medium', 'label' => 'Medium', 'type' => 'text'],
    ['key' => 'utmSource', 'label' => 'UTM Source', 'type' => 'text'],
    ['key' => 'utmCampaign', 'label' => 'UTM Campaign', 'type' => 'text'],
    ['key' => 'utmMedium', 'label' => 'UTM Medium', 'type' => 'text'],
    ['key' => 'landingPage', 'label' => 'Landing Page', 'type' => 'text'],
    ['key' => 'score', 'label' => 'Lead Score', 'type' => 'number'],
    ['key' => 'scoreBand', 'label' => 'Score Band', 'type' => 'enum', 'options' => ['COLD', 'WARM', 'HOT', 'VERY_HOT']],
    ['key' => 'priority', 'label' => 'Priority', 'type' => 'enum', 'options' => ['LOW', 'MEDIUM', 'HIGH', 'URGENT']],
    ['key' => 'expectedValue', 'label' => 'Expected Value', 'type' => 'number'],
    ['key' => 'product', 'label' => 'Product / Interest', 'type' => 'text'],
    ['key' => 'ownerId', 'label' => 'Owner', 'type' => 'user'],
    ['key' => 'teamId', 'label' => 'Team', 'type' => 'team'],
];


function gv_get_path(array $record, string $path)
{
    if (str_starts_with($path, 'customFields.')) {
        $cf = safe_json($record['customFields'] ?? [], []);
        return $cf[substr($path, 13)] ?? null;
    }
    $segments = explode('.', $path);
    $value = $record;
    foreach ($segments as $seg) {
        if (!is_array($value) || !array_key_exists($seg, $value)) {
            return null;
        }
        $value = $value[$seg];
    }
    return $value;
}

function gv_norm($value)
{
    if ($value === null) {
        return '';
    }
    return is_string($value) ? strtolower(trim($value)) : $value;
}

function gv_to_list($value): array
{
    if (is_array($value)) {
        return array_map('gv_norm', $value);
    }
    return array_values(array_filter(array_map('gv_norm', array_map('trim', explode(',', (string) $value))), fn($v) => $v !== ''));
}

function evaluate_condition(array $record, array $condition, array $context = []): bool
{
    $field = $condition['field'] ?? null;
    $operator = $condition['operator'] ?? null;
    $value = $condition['value'] ?? null;
    if (!$field || !in_array($operator, array_column(GV_OPERATORS, 'key'), true)) {
        return false;
    }

    $actual = gv_get_path($record, $field);
    $a = gv_norm($actual);
    $b = gv_norm($value);

    switch ($operator) {
        case 'eq': return $a === $b;
        case 'neq': return $a !== $b;
        case 'contains': return $b !== '' && str_contains((string) $a, (string) $b);
        case 'not_contains': return !str_contains((string) $a, (string) $b);
        case 'starts_with': return $b !== '' && str_starts_with((string) $a, (string) $b);
        case 'in': return in_array($a, gv_to_list($value), true);
        case 'not_in': return !in_array($a, gv_to_list($value), true);
        case 'gt': return (float) $actual > (float) $value;
        case 'gte': return (float) $actual >= (float) $value;
        case 'lt': return (float) $actual < (float) $value;
        case 'lte': return (float) $actual <= (float) $value;
        case 'is_empty': return $actual === null || trim((string) $actual) === '';
        case 'is_not_empty': return !($actual === null || trim((string) $actual) === '');
        case 'changed': return array_key_exists($field, $context['changes'] ?? []);
        default: return false;
    }
}

/** An empty group matches everything. */
function evaluate_group(array $record, $group, array $context = []): bool
{
    $parsed = is_array($group) ? $group : safe_json($group, ['match' => 'ALL', 'conditions' => []]);
    $conditions = $parsed['conditions'] ?? [];
    if (!$conditions) {
        return true;
    }
    $results = array_map(fn($c) => evaluate_condition($record, $c, $context), $conditions);
    return strtoupper($parsed['match'] ?? 'ALL') === 'ANY'
        ? in_array(true, $results, true)
        : !in_array(false, $results, true);
}

function validate_condition_group($group): array
{
    $parsed = is_array($group) ? $group : safe_json($group, null);
    if (!is_array($parsed)) {
        return ['ok' => false, 'message' => 'Conditions must be an object'];
    }
    $match = strtoupper($parsed['match'] ?? 'ALL');
    if (!in_array($match, ['ALL', 'ANY'], true)) {
        return ['ok' => false, 'message' => 'match must be ALL or ANY'];
    }
    // Reject anything we do not understand rather than ignoring it. A typo in
    // the payload key would otherwise leave `conditions` empty, and an empty
    // group matches every record - silently converting a targeted rule into a
    // catch-all that misroutes the whole pipeline.
    $unknown = array_diff(array_keys($parsed), ['match', 'conditions']);
    if ($unknown) {
        return ['ok' => false, 'message' => 'Unexpected key in conditions: ' . implode(', ', $unknown)];
    }
    $conditions = $parsed['conditions'] ?? [];
    if (!is_array($conditions)) {
        return ['ok' => false, 'message' => 'conditions must be a list'];
    }
    $operatorKeys = array_column(GV_OPERATORS, 'key');
    foreach ($conditions as $c) {
        if (empty($c['field']) || !is_string($c['field'])) {
            return ['ok' => false, 'message' => 'Every condition needs a field'];
        }
        if (!in_array($c['operator'] ?? null, $operatorKeys, true)) {
            return ['ok' => false, 'message' => 'Unsupported operator: ' . ($c['operator'] ?? '')];
        }
    }
    return ['ok' => true, 'value' => ['match' => $match, 'conditions' => $conditions]];
}
