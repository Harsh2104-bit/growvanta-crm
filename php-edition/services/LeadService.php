<?php
declare(strict_types=1);


const GV_LEAD_WRITABLE_FIELDS = [
    'firstName' => 'first_name', 'lastName' => 'last_name', 'email' => 'email', 'phone' => 'phone',
    'altPhone' => 'alt_phone', 'company' => 'company', 'jobTitle' => 'job_title', 'website' => 'website',
    'country' => 'country', 'state' => 'state', 'city' => 'city', 'address' => 'address', 'pincode' => 'pincode',
    'medium' => 'medium', 'landingPage' => 'landing_page', 'referrerUrl' => 'referrer_url',
    'utmSource' => 'utm_source', 'utmMedium' => 'utm_medium', 'utmCampaign' => 'utm_campaign',
    'utmTerm' => 'utm_term', 'utmContent' => 'utm_content', 'gclid' => 'gclid', 'fbclid' => 'fbclid',
    'priority' => 'priority', 'expectedValue' => 'expected_value', 'notes' => 'notes',
    'rating' => 'rating', 'lostReason' => 'lost_reason',
    // Real-estate qualification. These are the fields a site visit is booked
    // on, so they travel with the lead from the very first capture.
    'projectId' => 'project_id', 'budgetMin' => 'budget_min', 'budgetMax' => 'budget_max',
    'configuration' => 'configuration', 'possessionTimeline' => 'possession_timeline',
    'purpose' => 'purpose', 'funding' => 'funding',
];

/**
 * Resolves a status by id or key, falling back to the organisation's default
 * and then to whatever sorts first. A lead always has a status, so this never
 * returns null.
 */
function lead_resolve_status(string $orgId, $statusIdOrKey): array
{
    if ($statusIdOrKey) {
        $found = db_one(
            'SELECT * FROM lead_statuses WHERE organization_id = ? AND (id = ? OR `key` = ?) LIMIT 1',
            [$orgId, $statusIdOrKey, $statusIdOrKey]
        );
        if ($found) {
            return $found;
        }
    }
    $fallback = db_one('SELECT * FROM lead_statuses WHERE organization_id = ? AND is_default = 1 LIMIT 1', [$orgId])
        ?: db_one('SELECT * FROM lead_statuses WHERE organization_id = ? ORDER BY sort_order ASC LIMIT 1', [$orgId]);
    if (!$fallback) {
        throw bad_request('No lead statuses configured for this organisation.');
    }
    return $fallback;
}

/** Resolves a source by id or key. Unlike status, a lead may have none. */
function lead_resolve_source(string $orgId, $sourceIdOrKey): ?array
{
    if (!$sourceIdOrKey) {
        return null;
    }
    return db_one(
        'SELECT * FROM lead_sources WHERE organization_id = ? AND (id = ? OR `key` = ?) LIMIT 1',
        [$orgId, $sourceIdOrKey, strtolower((string) $sourceIdOrKey)]
    );
}

/** Closed vocabularies for the real-estate qualification fields. */
const GV_LEAD_PURPOSES = ['END_USE', 'INVESTMENT'];
const GV_LEAD_FUNDING = ['SELF', 'HOME_LOAN', 'MIXED'];
const GV_LEAD_POSSESSION = ['IMMEDIATE', 'WITHIN_6_MONTHS', 'WITHIN_1_YEAR', 'WITHIN_2_YEARS', 'FLEXIBLE'];

function lead_scalar_patch(array $input): array
{
    foreach (['purpose' => GV_LEAD_PURPOSES, 'funding' => GV_LEAD_FUNDING,
              'possessionTimeline' => GV_LEAD_POSSESSION] as $field => $allowed) {
        if (!empty($input[$field]) && !in_array($input[$field], $allowed, true)) {
            throw bad_request(ucfirst($field) . ' must be one of: ' . implode(', ', $allowed) . '.');
        }
    }
    $data = [];
    foreach (GV_LEAD_WRITABLE_FIELDS as $camel => $column) {
        if (!array_key_exists($camel, $input)) {
            continue;
        }
        $v = $input[$camel];
        if ($camel === 'expectedValue') {
            $data[$column] = ($v === null || $v === '') ? null : (float) $v;
        } elseif (is_string($v)) {
            $trimmed = trim($v);
            $data[$column] = $trimmed === '' ? null : $trimmed;
        } else {
            $data[$column] = $v;
        }
    }
    if (array_key_exists('first_name', $data) && $data['first_name'] === null) {
        unset($data['first_name']);
    }
    return $data;
}

function lead_apply_tags(string $leadId, string $orgId, array $tagNames): void
{
    $names = array_values(array_unique(array_filter(array_map(fn($t) => trim((string) $t), $tagNames))));
    $names = array_slice($names, 0, 25);
    $tagIds = [];
    foreach ($names as $name) {
        $tag = db_one('SELECT * FROM tags WHERE organization_id = ? AND name = ?', [$orgId, $name]);
        if (!$tag) {
            $tagId = gv_id();
            db_exec('INSERT INTO tags (id, organization_id, name, created_at) VALUES (?,?,?,NOW())', [$tagId, $orgId, $name]);
        } else {
            $tagId = $tag['id'];
        }
        $tagIds[] = $tagId;
    }
    if ($tagIds) {
        [$in, $p] = db_in_clause($tagIds);
        db_exec("DELETE FROM lead_tags WHERE lead_id = ? AND tag_id NOT IN $in", array_merge([$leadId], $p));
    } else {
        db_exec('DELETE FROM lead_tags WHERE lead_id = ?', [$leadId]);
    }
    foreach ($tagIds as $tagId) {
        $existing = db_one('SELECT id FROM lead_tags WHERE lead_id = ? AND tag_id = ?', [$leadId, $tagId]);
        if (!$existing) {
            db_exec('INSERT INTO lead_tags (id, lead_id, tag_id) VALUES (?,?,?)', [gv_id(), $leadId, $tagId]);
        }
    }
}

/**
 * Builds the camelCase context Conditions/Scoring/Assignment expect, from a
 * freshly loaded snake_case lead row.
 */
function lead_build_context(array $leadRow): array
{
    $ctx = camelize($leadRow);
    $ctx['organizationId'] = $leadRow['organization_id'];
    if ($leadRow['source_id']) {
        $ctx['source'] = camelize(db_one('SELECT * FROM lead_sources WHERE id = ?', [$leadRow['source_id']]));
    }
    if ($leadRow['status_id']) {
        $ctx['status'] = camelize(db_one('SELECT * FROM lead_statuses WHERE id = ?', [$leadRow['status_id']]));
    }
    if ($leadRow['project_id']) {
        // Assignment and automation rules route on the project, so it has to be
        // in the context the condition engine sees.
        $ctx['project'] = camelize(db_one('SELECT * FROM projects WHERE id = ?', [$leadRow['project_id']]));
    }
    return $ctx;
}

/**
 * @param array $input raw, untrusted lead fields (camelCase)
 * @param array $ctx organizationId, actor?, origin ('MANUAL'|'FORM'|'API'|'IMPORT'|'META'),
 *   duplicatePolicy ('FLAG'|'REJECT'|'ALLOW'), skipAssignment?, skipAutomation?, ownerId?, teamId?
 * @return array{lead: array, duplicates: array, assignment: ?array}
 */
/**
 * Resolves a project reference to an id this tenant may actually use.
 *
 * This is the last line of defence on the section 24 rule: every ingestion
 * path (UI, webhook, API key, import, Meta) lands here, so a project id
 * belonging to another company's private stock can never be written onto a
 * lead even if the route-level check were bypassed.
 */
function lead_resolve_project(string $orgId, $reference): ?string
{
    if ($reference === null || $reference === '') {
        return null;
    }
    $vis = project_visible_where($orgId);
    $byId = db_scalar(
        "SELECT projects.id FROM projects WHERE projects.id = ? AND {$vis['sql']}",
        array_merge([$reference], $vis['params'])
    );
    if ($byId) {
        return (string) $byId;
    }
    $byName = db_scalar(
        "SELECT projects.id FROM projects
          WHERE (projects.name = ? OR projects.slug = ? OR projects.code = ?)
            AND projects.is_active = 1 AND {$vis['sql']}
          LIMIT 1",
        array_merge([$reference, slugify((string) $reference), $reference], $vis['params'])
    );
    return $byName ? (string) $byName : null;
}

function create_lead(array $input, array $ctx): array
{
    $orgId = $ctx['organizationId'];
    $actor = $ctx['actor'] ?? null;
    $origin = $ctx['origin'] ?? 'MANUAL';
    $duplicatePolicy = $ctx['duplicatePolicy'] ?? 'FLAG';

    if (empty($input['firstName']) || trim((string) $input['firstName']) === '') {
        throw bad_request('First name is required');
    }
    if (empty($input['email']) && empty($input['phone'])) {
        throw bad_request('A lead needs at least an email address or a phone number');
    }

    $status = lead_resolve_status($orgId, $input['statusId'] ?? $input['status'] ?? null);
    $source = lead_resolve_source($orgId, $input['sourceId'] ?? $input['source'] ?? null);
    // Resolve the project by id OR name, because webhooks and portal feeds send
    // a project name, not our id. An unresolvable name leaves project_id null
    // rather than rejecting the lead - losing an enquiry is worse than losing
    // an attribution, and an unassigned project is visible in the UI.
    $input['projectId'] = lead_resolve_project($orgId, $input['projectId'] ?? $input['project'] ?? null);

    $duplicates = find_duplicates($orgId, $input);
    if ($duplicates && $duplicatePolicy === 'REJECT') {
        throw conflict('A lead with the same phone or email already exists', [
            'duplicates' => array_map(fn($d) => ['id' => $d['id'], 'code' => $d['code'], 'name' => trim($d['firstName'] . ' ' . ($d['lastName'] ?? '')), 'matchedOn' => $d['matchedOn']], $duplicates),
        ]);
    }

    $customFields = validate_custom_field_values($orgId, 'LEAD', $input['customFields'] ?? [], '{}');
    $code = next_code($orgId, 'LD');
    $now = date('Y-m-d H:i:s');
    $base = lead_scalar_patch($input);
    $sourceLabel = $source['name'] ?? ($input['utmSource'] ?? $origin);
    $leadId = gv_id();

    $columns = array_merge($base, [
        'id' => $leadId, 'organization_id' => $orgId, 'code' => $code,
        'first_name' => trim($input['firstName']),
        'status_id' => $status['id'], 'source_id' => $source['id'] ?? null,
        'email_norm' => normalize_email($input['email'] ?? null), 'phone_norm' => normalize_phone($input['phone'] ?? null),
        'created_by_id' => $actor['id'] ?? null,
        'owner_id' => $ctx['ownerId'] ?? $input['ownerId'] ?? null,
        'team_id' => $ctx['teamId'] ?? $input['teamId'] ?? null,
        'next_follow_up_at' => parse_date($input['nextFollowUpAt'] ?? null),
        'site_visit_at' => parse_date($input['siteVisitAt'] ?? null),
        'custom_fields' => $customFields,
        'first_touch_source' => $sourceLabel, 'first_touch_campaign' => $input['utmCampaign'] ?? null, 'first_touch_at' => $now,
        'last_touch_source' => $sourceLabel, 'last_touch_campaign' => $input['utmCampaign'] ?? null, 'last_touch_at' => $now,
        'is_duplicate' => ($duplicatePolicy === 'FLAG' && $duplicates && !empty($input['markDuplicate'])) ? 1 : 0,
        // Meta Lead Ads linkage, when present.
        'meta_lead_id' => $input['metaLeadId'] ?? null, 'meta_form_id' => $input['metaFormId'] ?? null,
        'meta_page_id' => $input['metaPageId'] ?? null, 'meta_ad_id' => $input['metaAdId'] ?? null,
        'meta_adset_id' => $input['metaAdsetId'] ?? null, 'meta_campaign_id' => $input['metaCampaignId'] ?? null,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($columns)));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    db_exec("INSERT INTO leads ($cols) VALUES ($placeholders)", array_values($columns));

    if (!empty($input['tags'])) {
        lead_apply_tags($leadId, $orgId, $input['tags']);
    }

    db_exec(
        'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, user_id, meta, occurred_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            gv_id(), $orgId, $origin === 'FORM' ? 'FORM' : ($origin === 'IMPORT' ? 'IMPORT' : ($origin === 'META' ? 'FORM' : 'SYSTEM')),
            'Lead created via ' . strtolower($origin), $sourceLabel ? "Source: $sourceLabel" : null,
            $leadId, $actor['id'] ?? null, to_json(['origin' => $origin, 'duplicateCount' => count($duplicates)]),
        ]
    );

    $lead = db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);

    // ---- assignment ---------------------------------------------------------
    $assignment = null;
    if (empty($ctx['skipAssignment']) && !$lead['owner_id'] && !$lead['team_id']) {
        $assignment = resolve_assignment(lead_build_context($lead)) ?? fallback_round_robin($orgId);
        if ($assignment && ($assignment['ownerId'] || $assignment['teamId'])) {
            db_exec('UPDATE leads SET owner_id = ?, team_id = ?, assigned_at = NOW() WHERE id = ?', [$assignment['ownerId'], $assignment['teamId'], $leadId]);
            $lead = db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
            $ownerName = $assignment['ownerId'] ? db_one('SELECT first_name, last_name FROM users WHERE id = ?', [$assignment['ownerId']]) : null;
            $teamName = $assignment['teamId'] ? db_scalar('SELECT name FROM teams WHERE id = ?', [$assignment['teamId']]) : null;
            db_exec(
                'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, occurred_at, created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())',
                [gv_id(), $orgId, 'ASSIGNMENT', $ownerName ? 'Assigned to ' . trim($ownerName['first_name'] . ' ' . $ownerName['last_name']) : "Routed to team $teamName",
                 !empty($assignment['ruleName']) ? "Rule: {$assignment['ruleName']}" : null, $leadId]
            );
            if ($assignment['ownerId']) {
                notify_user($orgId, $assignment['ownerId'], 'LEAD_ASSIGNED',
                    'New lead assigned: ' . trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? '')),
                    "{$lead['code']} from " . ($sourceLabel ?: 'unknown source'), 'LEAD', $leadId, "#/leads/$leadId");
            }
        }
    } elseif ($lead['owner_id']) {
        db_exec('UPDATE leads SET assigned_at = NOW() WHERE id = ?', [$leadId]);
        notify_user($orgId, $lead['owner_id'], 'LEAD_ASSIGNED',
            'New lead assigned: ' . trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? '')),
            "{$lead['code']} from " . ($sourceLabel ?: 'unknown source'), 'LEAD', $leadId, "#/leads/$leadId");
    }

    // ---- scoring --------------------------------------------------------------
    $scored = compute_score($orgId, lead_build_context($lead));
    if ($scored['score'] !== (int) $lead['score']) {
        db_exec('UPDATE leads SET score = ?, score_band = ? WHERE id = ?', [$scored['score'], $scored['band'], $leadId]);
        $lead = db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    }

    audit_record($orgId, $actor, 'lead.created', [
        'entityType' => 'LEAD', 'entityId' => $leadId, 'entityLabel' => "{$lead['code']} {$lead['first_name']}",
        'changes' => ['origin' => $origin, 'source' => $sourceLabel],
    ]);

    if (empty($ctx['skipAutomation'])) {
        emit_domain_event('lead.created', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => lead_build_context($lead)]);
        if ($lead['owner_id']) {
            emit_domain_event('lead.assigned', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => lead_build_context($lead)]);
        }
    }

    return ['lead' => lead_build_context($lead), 'duplicates' => $duplicates, 'assignment' => $assignment];
}

/** Partial update. Emits status/assignment events only when they really change. */
function update_lead(string $leadId, array $input, array $ctx): array
{
    $orgId = $ctx['organizationId'];
    $actor = $ctx['actor'] ?? null;

    $before = db_one('SELECT * FROM leads WHERE id = ? AND organization_id = ?', [$leadId, $orgId]);
    if (!$before) {
        throw not_found('Lead not found');
    }

    $data = lead_scalar_patch($input);
    if (array_key_exists('email', $input)) {
        $data['email_norm'] = normalize_email($input['email']);
    }
    if (array_key_exists('phone', $input)) {
        $data['phone_norm'] = normalize_phone($input['phone']);
    }
    if (array_key_exists('nextFollowUpAt', $input)) {
        $data['next_follow_up_at'] = parse_date($input['nextFollowUpAt']);
    }
    if (array_key_exists('lastContactedAt', $input)) {
        $data['last_contacted_at'] = parse_date($input['lastContactedAt']);
    }
    if (array_key_exists('siteVisitAt', $input)) {
        $data['site_visit_at'] = parse_date($input['siteVisitAt']);
    }
    if (array_key_exists('projectId', $input) || array_key_exists('project', $input)) {
        // Re-resolved rather than trusted, for the same reason as on create.
        $data['project_id'] = lead_resolve_project($orgId, $input['projectId'] ?? $input['project']);
    }
    if (array_key_exists('customFields', $input)) {
        $data['custom_fields'] = validate_custom_field_values($orgId, 'LEAD', $input['customFields'], $before['custom_fields']);
    }

    $statusChanged = false;
    $newStatus = null;
    if (array_key_exists('statusId', $input) || array_key_exists('status', $input)) {
        $newStatus = lead_resolve_status($orgId, $input['statusId'] ?? $input['status']);
        if ($newStatus['id'] !== $before['status_id']) {
            $statusChanged = true;
            $data['status_id'] = $newStatus['id'];
            if (gv_bool($newStatus['is_qualified']) && !$before['qualified_at']) {
                $data['qualified_at'] = date('Y-m-d H:i:s');
            }
            if (gv_bool($newStatus['is_lost'])) {
                $data['lost_at'] = date('Y-m-d H:i:s');
            } elseif ($before['lost_at']) {
                $data['lost_at'] = null;
                $data['lost_reason'] = null;
            }
        }
    }

    if (array_key_exists('sourceId', $input)) {
        $source = lead_resolve_source($orgId, $input['sourceId']);
        $data['source_id'] = $source['id'] ?? null;
    }

    $ownerChanged = false;
    if (array_key_exists('ownerId', $input) && $input['ownerId'] !== $before['owner_id']) {
        if ($input['ownerId']) {
            $owner = db_one('SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1', [$input['ownerId'], $orgId]);
            if (!$owner) {
                throw bad_request('That user cannot own leads in this organisation');
            }
        }
        $data['owner_id'] = $input['ownerId'] ?: null;
        $data['assigned_at'] = date('Y-m-d H:i:s');
        $ownerChanged = true;
    }
    if (array_key_exists('teamId', $input) && $input['teamId'] !== $before['team_id']) {
        if ($input['teamId']) {
            $team = db_one('SELECT id FROM teams WHERE id = ? AND organization_id = ?', [$input['teamId'], $orgId]);
            if (!$team) {
                throw bad_request('Unknown team');
            }
        }
        $data['team_id'] = $input['teamId'] ?: null;
    }

    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE leads SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$leadId]));
    }

    if (array_key_exists('tags', $input)) {
        lead_apply_tags($leadId, $orgId, $input['tags']);
    }
    $lead = db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);

    $changes = gv_diff($before, $lead, array_merge(array_values(GV_LEAD_WRITABLE_FIELDS), ['status_id', 'owner_id', 'team_id', 'source_id', 'project_id', 'next_follow_up_at', 'site_visit_at']));

    if ($statusChanged) {
        // The subject is read by people in the timeline, so it names the status
        // they knew it by. Without this it prints the previous status's id.
        $fromName = $before['status_id']
            ? (string) db_scalar('SELECT name FROM lead_statuses WHERE id = ?', [$before['status_id']])
            : '';
        $fromName = $fromName !== '' ? $fromName : 'No status';
        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, lead_id, user_id, meta, occurred_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [gv_id(), $orgId, 'STATUS_CHANGE', "Status: $fromName to {$newStatus['name']}", $leadId, $actor['id'] ?? null, to_json(['from' => $before['status_id'], 'to' => $newStatus['key']])]
        );
    }
    if ($ownerChanged) {
        $ownerName = $lead['owner_id'] ? db_one('SELECT first_name, last_name FROM users WHERE id = ?', [$lead['owner_id']]) : null;
        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, lead_id, user_id, meta, occurred_at, created_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [gv_id(), $orgId, 'ASSIGNMENT', $ownerName ? 'Reassigned to ' . trim($ownerName['first_name'] . ' ' . $ownerName['last_name']) : 'Owner cleared',
             $leadId, $actor['id'] ?? null, to_json(['from' => $before['owner_id'], 'to' => $lead['owner_id']])]
        );
        if ($lead['owner_id']) {
            $statusName = db_scalar('SELECT name FROM lead_statuses WHERE id = ?', [$lead['status_id']]);
            notify_user($orgId, $lead['owner_id'], 'LEAD_REASSIGNED',
                'Lead assigned to you: ' . trim($lead['first_name'] . ' ' . ($lead['last_name'] ?? '')),
                "{$lead['code']} - $statusName", 'LEAD', $leadId, "#/leads/$leadId");
        }
    }

    if ($changes) {
        audit_record($orgId, $actor, 'lead.updated', [
            'entityType' => 'LEAD', 'entityId' => $leadId, 'entityLabel' => "{$lead['code']} {$lead['first_name']}", 'changes' => $changes,
        ]);
    }

    $scored = compute_score($orgId, lead_build_context($lead));
    if ($scored['score'] !== (int) $lead['score'] || $scored['band'] !== $lead['score_band']) {
        db_exec('UPDATE leads SET score = ?, score_band = ? WHERE id = ?', [$scored['score'], $scored['band'], $leadId]);
        $lead = db_one('SELECT * FROM leads WHERE id = ?', [$leadId]);
    }

    if (empty($ctx['skipAutomation'])) {
        $context = lead_build_context($lead);
        emit_domain_event('lead.updated', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => $context, 'changes' => $changes]);
        if ($statusChanged) {
            emit_domain_event('lead.status_changed', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => $context, 'changes' => $changes]);
        }
        if ($ownerChanged && $lead['owner_id']) {
            emit_domain_event('lead.assigned', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => $context, 'changes' => $changes]);
        }
    }

    return lead_build_context($lead);
}

/** Bulk assignment. Returns the number actually moved. */
function assign_leads(string $orgId, array $leadIds, ?string $ownerId, ?string $teamId, ?array $actor): int
{
    if ($ownerId) {
        $owner = db_one('SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1', [$ownerId, $orgId]);
        if (!$owner) {
            throw bad_request('That user cannot receive leads');
        }
    }
    if ($teamId) {
        $team = db_one('SELECT id FROM teams WHERE id = ? AND organization_id = ?', [$teamId, $orgId]);
        if (!$team) {
            throw bad_request('Unknown team');
        }
    }
    if (!$leadIds) {
        return 0;
    }
    [$in, $p] = db_in_clause($leadIds);
    $leads = db_all("SELECT id, code, first_name, last_name, owner_id FROM leads WHERE id IN $in AND organization_id = ?", array_merge($p, [$orgId]));
    if (!$leads) {
        return 0;
    }
    $ids = array_column($leads, 'id');
    [$in2, $p2] = db_in_clause($ids);

    $sets = [];
    $params = [];
    if ($ownerId !== null) {
        $sets[] = 'owner_id = ?';
        $params[] = $ownerId ?: null;
    }
    if ($teamId !== null) {
        $sets[] = 'team_id = ?';
        $params[] = $teamId ?: null;
    }
    $sets[] = 'assigned_at = NOW()';
    db_exec("UPDATE leads SET " . implode(', ', $sets) . " WHERE id IN $in2", array_merge($params, $p2));

    foreach ($leads as $l) {
        db_exec(
            'INSERT INTO activities (id, organization_id, type, subject, lead_id, user_id, occurred_at, created_at) VALUES (?,?,?,?,?,?,NOW(),NOW())',
            [gv_id(), $orgId, 'ASSIGNMENT', $ownerId ? 'Bulk assigned' : 'Bulk routed to team', $l['id'], $actor['id'] ?? null]
        );
        emit_domain_event('lead.assigned', ['organizationId' => $orgId, 'entityType' => 'LEAD', 'data' => array_merge($l, ['ownerId' => $ownerId ?: null, 'teamId' => $teamId ?: null])]);
    }
    if ($ownerId) {
        notify_user($orgId, $ownerId, 'LEAD_ASSIGNED', count($leads) . ' lead(s) assigned to you',
            implode(', ', array_column(array_slice($leads, 0, 5), 'code')), 'LEAD', $leads[0]['id'] ?? null, '#/leads');
    }

    audit_record($orgId, $actor, 'lead.bulk_assigned', [
        'entityType' => 'LEAD', 'entityLabel' => count($leads) . ' leads', 'changes' => ['ownerId' => $ownerId, 'teamId' => $teamId, 'leadIds' => $ids],
    ]);

    return count($leads);
}
