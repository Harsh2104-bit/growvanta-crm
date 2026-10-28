<?php
declare(strict_types=1);

/** Configurable lead scoring — ported from src/services/scoring.js. */

const GV_SCORING_KINDS = [
    ['key' => 'FIELD_PRESENT', 'label' => 'Field has a value', 'needs' => ['field']],
    ['key' => 'FIELD_EQUALS', 'label' => 'Field matches a value', 'needs' => ['field', 'value']],
    ['key' => 'FIELD_GT', 'label' => 'Numeric field greater than', 'needs' => ['field', 'value']],
    ['key' => 'SOURCE_IS', 'label' => 'Lead source is', 'needs' => ['value']],
    ['key' => 'CAMPAIGN_IS', 'label' => 'Campaign code is', 'needs' => ['value']],
    ['key' => 'ACTIVITY_COUNT', 'label' => 'Has at least N activities of a type', 'needs' => ['field', 'value']],
    ['key' => 'NO_ACTIVITY_DAYS', 'label' => 'No activity for N days (use negative points)', 'needs' => ['value']],
    ['key' => 'STATUS_IS', 'label' => 'Lead status is', 'needs' => ['value']],
];

const GV_SCORE_BANDS = [
    ['key' => 'VERY_HOT', 'min' => 75, 'label' => 'Very Hot', 'color' => '#dc2626'],
    ['key' => 'HOT', 'min' => 50, 'label' => 'Hot', 'color' => '#ea580c'],
    ['key' => 'WARM', 'min' => 25, 'label' => 'Warm', 'color' => '#d97706'],
    ['key' => 'COLD', 'min' => 0, 'label' => 'Cold', 'color' => '#0284c7'],
];

function score_band_for(int $score): string
{
    foreach (GV_SCORE_BANDS as $b) {
        if ($score >= $b['min']) {
            return $b['key'];
        }
    }
    return 'COLD';
}

function scoring_get_field(array $lead, ?string $field)
{
    if (!$field) {
        return null;
    }
    if (str_starts_with($field, 'customFields.')) {
        return safe_json($lead['customFields'] ?? '{}', [])[substr($field, 13)] ?? null;
    }
    $value = $lead;
    foreach (explode('.', $field) as $seg) {
        if (!is_array($value) || !array_key_exists($seg, $value)) {
            return null;
        }
        $value = $value[$seg];
    }
    return $value;
}

function scoring_is_present($value): bool
{
    return !($value === null || trim((string) $value) === '');
}

/**
 * @param array $lead camelCase lead row, plus optional 'source'/'status' sub-arrays
 * @return array{score:int, band:string, breakdown: array}
 */
function compute_score(string $orgId, array $lead): array
{
    $rules = db_all('SELECT * FROM scoring_rules WHERE organization_id = ? AND is_active = 1 ORDER BY sort_order ASC, created_at ASC', [$orgId]);
    if (!$rules) {
        $score = (int) ($lead['score'] ?? 0);
        return ['score' => $score, 'band' => score_band_for($score), 'breakdown' => []];
    }

    $needsActivity = false;
    foreach ($rules as $r) {
        if (in_array($r['kind'], ['ACTIVITY_COUNT', 'NO_ACTIVITY_DAYS'], true)) {
            $needsActivity = true;
            break;
        }
    }
    $activityCounts = [];
    $lastActivityAt = null;
    if ($needsActivity && !empty($lead['id'])) {
        $rows = db_all(
            'SELECT type, COUNT(*) AS cnt, MAX(occurred_at) AS last_at FROM activities WHERE organization_id = ? AND lead_id = ? GROUP BY type',
            [$orgId, $lead['id']]
        );
        foreach ($rows as $r) {
            $activityCounts[$r['type']] = (int) $r['cnt'];
            if ($r['last_at'] && (!$lastActivityAt || strtotime($r['last_at']) > $lastActivityAt)) {
                $lastActivityAt = strtotime($r['last_at']);
            }
        }
    }

    $breakdown = [];
    $total = 0;
    foreach ($rules as $rule) {
        $hit = false;
        switch ($rule['kind']) {
            case 'FIELD_PRESENT':
                $hit = scoring_is_present(scoring_get_field($lead, $rule['field']));
                break;
            case 'FIELD_EQUALS':
                $hit = strtolower((string) scoring_get_field($lead, $rule['field'])) === strtolower((string) $rule['value']);
                break;
            case 'FIELD_GT':
                $hit = (float) scoring_get_field($lead, $rule['field']) > (float) $rule['value'];
                break;
            case 'SOURCE_IS':
                $hit = strtolower($lead['source']['key'] ?? '') === strtolower((string) $rule['value']);
                break;
            case 'CAMPAIGN_IS':
                $hit = strtolower((string) ($lead['utmCampaign'] ?? '')) === strtolower((string) $rule['value']);
                break;
            case 'STATUS_IS':
                $hit = strtolower($lead['status']['key'] ?? '') === strtolower((string) $rule['value']);
                break;
            case 'ACTIVITY_COUNT':
                $hit = ($activityCounts[strtoupper((string) $rule['field'])] ?? 0) >= (int) ($rule['value'] ?: 1);
                break;
            case 'NO_ACTIVITY_DAYS':
                $days = (int) ($rule['value'] ?: 30);
                $reference = $lastActivityAt ?: (isset($lead['createdAt']) ? strtotime($lead['createdAt']) : time());
                $hit = ((time() - $reference) / 86400) >= $days;
                break;
        }
        if ($hit) {
            $total += (int) $rule['points'];
            $breakdown[] = ['rule' => $rule['name'], 'points' => (int) $rule['points']];
        }
    }

    $score = max(0, min(100, $total));
    return ['score' => $score, 'band' => score_band_for($score), 'breakdown' => $breakdown];
}

/** Recomputes and persists; returns null when nothing changed. */
function rescore_lead(string $orgId, string $leadId, ?string $actorId = null): ?array
{
    $lead = db_one('SELECT * FROM leads WHERE id = ? AND organization_id = ?', [$leadId, $orgId]);
    if (!$lead) {
        return null;
    }
    $enriched = camelize($lead);
    if ($lead['source_id']) {
        $enriched['source'] = camelize(db_one('SELECT `key` FROM lead_sources WHERE id = ?', [$lead['source_id']]));
    }
    if ($lead['status_id']) {
        $enriched['status'] = camelize(db_one('SELECT `key` FROM lead_statuses WHERE id = ?', [$lead['status_id']]));
    }

    ['score' => $score, 'band' => $band] = compute_score($orgId, $enriched);
    if ($score === (int) $lead['score'] && $band === $lead['score_band']) {
        return null;
    }
    db_exec('UPDATE leads SET score = ?, score_band = ? WHERE id = ?', [$score, $band, $leadId]);

    db_exec(
        'INSERT INTO activities (id, organization_id, type, subject, lead_id, user_id, meta, occurred_at, created_at)
         VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
        [
            gv_id(), $orgId, 'SCORE_CHANGE',
            "Lead score {$lead['score']} to $score (" . str_replace('_', ' ', $band) . ')',
            $leadId, $actorId, to_json(['from' => (int) $lead['score'], 'to' => $score, 'band' => $band]),
        ]
    );

    return db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
}
