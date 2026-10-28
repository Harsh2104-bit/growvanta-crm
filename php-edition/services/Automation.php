<?php
declare(strict_types=1);

/**
 * Workflow automation engine — ported from src/services/automation.js.
 *
 * A workflow is: trigger (a domain event) + condition group + ordered action
 * list. Anything that calls emit_domain_event() gets automation for free once
 * flush_domain_events() runs.
 *
 * Two safety properties carried over from the Node version:
 *  - Actions run one at a time and every run is logged, so a partial run is
 *    visible in workflow_runs rather than silently half-applied.
 *  - `depth` guards re-entrancy (an action that eventually re-emits an event
 *    could otherwise loop forever); MAX_DEPTH mirrors the original.
 */

const GV_AUTOMATION_MAX_DEPTH = 3;

const GV_AUTOMATION_TRIGGERS = [
    ['key' => 'lead.created', 'label' => 'Lead is created', 'entity' => 'LEAD'],
    ['key' => 'lead.updated', 'label' => 'Lead is updated', 'entity' => 'LEAD'],
    ['key' => 'lead.status_changed', 'label' => 'Lead status changes', 'entity' => 'LEAD'],
    ['key' => 'lead.assigned', 'label' => 'Lead is assigned', 'entity' => 'LEAD'],
    ['key' => 'lead.converted', 'label' => 'Lead is converted', 'entity' => 'LEAD'],
];

const GV_AUTOMATION_ACTIONS = [
    ['key' => 'assign_owner', 'label' => 'Assign to a specific user', 'params' => [['key' => 'userId', 'type' => 'user', 'required' => true]]],
    ['key' => 'assign_team', 'label' => 'Assign to a team', 'params' => [['key' => 'teamId', 'type' => 'team', 'required' => true]]],
    ['key' => 'run_assignment_rules', 'label' => 'Run the assignment rule chain', 'params' => []],
    ['key' => 'set_status', 'label' => 'Set lead status', 'params' => [['key' => 'statusKey', 'type' => 'status', 'required' => true]]],
    ['key' => 'set_priority', 'label' => 'Set priority', 'params' => [['key' => 'priority', 'type' => 'enum', 'options' => ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], 'required' => true]]],
    ['key' => 'set_field', 'label' => 'Set a field value', 'params' => [['key' => 'field', 'type' => 'text', 'required' => true], ['key' => 'value', 'type' => 'text']]],
    ['key' => 'add_tag', 'label' => 'Add a tag', 'params' => [['key' => 'tag', 'type' => 'text', 'required' => true]]],
    ['key' => 'adjust_score', 'label' => 'Adjust lead score', 'params' => [['key' => 'points', 'type' => 'number', 'required' => true]]],
    ['key' => 'set_follow_up', 'label' => 'Schedule next follow-up', 'params' => [['key' => 'inDays', 'type' => 'number', 'required' => true]]],
    ['key' => 'notify', 'label' => 'Send a notification', 'params' => [
        ['key' => 'target', 'type' => 'enum', 'options' => ['OWNER', 'MANAGER', 'TEAM_MANAGER', 'USER'], 'required' => true],
        ['key' => 'userId', 'type' => 'user'],
        ['key' => 'title', 'type' => 'text', 'required' => true],
        ['key' => 'body', 'type' => 'text'],
    ]],
    ['key' => 'log_activity', 'label' => 'Log a timeline note', 'params' => [['key' => 'subject', 'type' => 'text', 'required' => true], ['key' => 'body', 'type' => 'text']]],
];

function gv_automation_action_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (GV_AUTOMATION_ACTIONS as $a) {
            $map[$a['key']] = $a;
        }
    }
    return $map;
}

function validate_actions($actions): array
{
    $parsed = is_array($actions) ? $actions : safe_json($actions, null);
    if (!is_array($parsed) || !array_is_list($parsed)) {
        return ['ok' => false, 'message' => 'Actions must be an array'];
    }
    $map = gv_automation_action_map();
    foreach ($parsed as $action) {
        if (!is_array($action) || !isset($map[$action['type'] ?? ''])) {
            return ['ok' => false, 'message' => 'Unsupported action: ' . ($action['type'] ?? '')];
        }
        $spec = $map[$action['type']];
        foreach ($spec['params'] as $p) {
            if (!empty($p['required']) && ($action['params'][$p['key']] ?? '') === '') {
                return ['ok' => false, 'message' => "Action \"{$spec['label']}\" needs \"{$p['key']}\""];
            }
        }
    }
    return ['ok' => true, 'value' => $parsed];
}

// ----------------------------------------------------------------------------
// Action implementations
// ----------------------------------------------------------------------------

const GV_SETTABLE_LEAD_FIELDS = [
    'priority' => 'priority', 'medium' => 'medium', 'company' => 'company', 'jobTitle' => 'job_title',
    'city' => 'city', 'state' => 'state', 'country' => 'country', 'expectedValue' => 'expected_value',
    'notes' => 'notes', 'lostReason' => 'lost_reason', 'rating' => 'rating',
];

function automation_resolve_notify_target(string $target, array $params, array $record): ?string
{
    switch ($target) {
        case 'USER':
            return $params['userId'] ?? null;
        case 'OWNER':
            return $record['ownerId'] ?? null;
        case 'CREATOR':
            return $record['createdById'] ?? null;
        case 'MANAGER':
            if (empty($record['ownerId'])) {
                return null;
            }
            return db_scalar('SELECT manager_id FROM users WHERE id = ?', [$record['ownerId']]);
        case 'TEAM_MANAGER':
            if (empty($record['teamId'])) {
                return null;
            }
            return db_scalar('SELECT manager_id FROM teams WHERE id = ?', [$record['teamId']]);
        default:
            return null;
    }
}

function automation_run_action(array $action, string $orgId, string $entityType, array $record, array &$log): void
{
    $params = $action['params'] ?? [];
    $type = $action['type'] ?? '';

    switch ($type) {
        case 'assign_owner':
            if ($entityType !== 'LEAD') {
                $log[] = 'assign_owner skipped: not a lead';
                return;
            }
            $user = db_one('SELECT * FROM users WHERE id = ? AND organization_id = ? AND is_active = 1', [$params['userId'] ?? null, $orgId]);
            if (!$user) {
                $log[] = 'assign_owner skipped: user not found';
                return;
            }
            db_exec('UPDATE leads SET owner_id = ?, assigned_at = NOW() WHERE id = ?', [$user['id'], $record['id']]);
            db_exec(
                'INSERT INTO activities (id, organization_id, type, subject, lead_id, occurred_at, created_at) VALUES (?,?,?,?,?,NOW(),NOW())',
                [gv_id(), $orgId, 'ASSIGNMENT', 'Automation assigned to ' . trim($user['first_name'] . ' ' . ($user['last_name'] ?? '')), $record['id']]
            );
            notify_user($orgId, $user['id'], 'LEAD_ASSIGNED',
                'New lead assigned: ' . trim(($record['firstName'] ?? '') . ' ' . ($record['lastName'] ?? '')),
                'Assigned automatically by a workflow.', 'LEAD', $record['id'], "#/leads/{$record['id']}");
            $log[] = "assigned owner {$user['email']}";
            return;

        case 'assign_team':
            if ($entityType !== 'LEAD') {
                $log[] = 'assign_team skipped: not a lead';
                return;
            }
            $team = db_one('SELECT * FROM teams WHERE id = ? AND organization_id = ?', [$params['teamId'] ?? null, $orgId]);
            if (!$team) {
                $log[] = 'assign_team skipped: team not found';
                return;
            }
            db_exec('UPDATE leads SET team_id = ? WHERE id = ?', [$team['id'], $record['id']]);
            $log[] = "assigned team {$team['name']}";
            return;

        case 'run_assignment_rules':
            if ($entityType !== 'LEAD') {
                $log[] = 'run_assignment_rules skipped: not a lead';
                return;
            }
            $full = db_one('SELECT * FROM leads WHERE id = ?', [$record['id']]);
            if (!$full) {
                $log[] = 'run_assignment_rules skipped: lead not found';
                return;
            }
            $target = resolve_assignment(lead_build_context($full));
            if (!$target) {
                $log[] = 'run_assignment_rules: no rule matched';
                return;
            }
            db_exec('UPDATE leads SET owner_id = ?, team_id = ?, assigned_at = NOW() WHERE id = ?', [$target['ownerId'], $target['teamId'], $record['id']]);
            if ($target['ownerId']) {
                notify_user($orgId, $target['ownerId'], 'LEAD_ASSIGNED',
                    'New lead assigned: ' . trim(($record['firstName'] ?? '') . ' ' . ($record['lastName'] ?? '')),
                    "Matched rule \"{$target['ruleName']}\".", 'LEAD', $record['id'], "#/leads/{$record['id']}");
            }
            $log[] = "assignment rules matched \"{$target['ruleName']}\"";
            return;

        case 'set_status':
            if ($entityType !== 'LEAD') {
                $log[] = 'set_status skipped: not a lead';
                return;
            }
            $status = db_one('SELECT * FROM lead_statuses WHERE organization_id = ? AND `key` = ?', [$orgId, $params['statusKey'] ?? null]);
            if (!$status) {
                $log[] = 'set_status skipped: unknown status ' . ($params['statusKey'] ?? '');
                return;
            }
            $extra = [];
            $vals = [$status['id']];
            if (gv_bool($status['is_qualified'])) {
                $extra[] = 'qualified_at = NOW()';
            }
            if (gv_bool($status['is_lost'])) {
                $extra[] = 'lost_at = NOW()';
            }
            $sql = 'UPDATE leads SET status_id = ?' . ($extra ? ', ' . implode(', ', $extra) : '') . ' WHERE id = ?';
            db_exec($sql, array_merge($vals, [$record['id']]));
            db_exec(
                'INSERT INTO activities (id, organization_id, type, subject, lead_id, occurred_at, created_at) VALUES (?,?,?,?,?,NOW(),NOW())',
                [gv_id(), $orgId, 'STATUS_CHANGE', "Automation set status to {$status['name']}", $record['id']]
            );
            $log[] = "status set to {$status['name']}";
            return;

        case 'set_priority':
            if ($entityType !== 'LEAD') {
                $log[] = 'set_priority skipped: not a lead';
                return;
            }
            db_exec('UPDATE leads SET priority = ? WHERE id = ?', [$params['priority'] ?? 'MEDIUM', $record['id']]);
            $log[] = 'priority set to ' . ($params['priority'] ?? 'MEDIUM');
            return;

        case 'set_field':
            if ($entityType !== 'LEAD') {
                $log[] = 'set_field skipped: not a lead';
                return;
            }
            $field = $params['field'] ?? '';
            if (!isset(GV_SETTABLE_LEAD_FIELDS[$field])) {
                $log[] = "set_field refused: $field is not settable";
                return;
            }
            $column = GV_SETTABLE_LEAD_FIELDS[$field];
            $value = $field === 'expectedValue' ? (float) ($params['value'] ?? 0) : ($params['value'] ?? null);
            db_exec("UPDATE leads SET `$column` = ? WHERE id = ?", [$value, $record['id']]);
            $log[] = "$field set";
            return;

        case 'add_tag':
            if ($entityType !== 'LEAD') {
                $log[] = 'add_tag skipped: not a lead';
                return;
            }
            $name = trim((string) ($params['tag'] ?? ''));
            if ($name === '') {
                $log[] = 'add_tag skipped: empty tag';
                return;
            }
            $tag = db_one('SELECT * FROM tags WHERE organization_id = ? AND name = ?', [$orgId, $name]);
            $tagId = $tag['id'] ?? gv_id();
            if (!$tag) {
                db_exec('INSERT INTO tags (id, organization_id, name, created_at) VALUES (?,?,?,NOW())', [$tagId, $orgId, $name]);
            }
            $existing = db_one('SELECT id FROM lead_tags WHERE lead_id = ? AND tag_id = ?', [$record['id'], $tagId]);
            if (!$existing) {
                db_exec('INSERT INTO lead_tags (id, lead_id, tag_id) VALUES (?,?,?)', [gv_id(), $record['id'], $tagId]);
            }
            $log[] = "tagged \"$name\"";
            return;

        case 'adjust_score':
            if ($entityType !== 'LEAD') {
                $log[] = 'adjust_score skipped: not a lead';
                return;
            }
            $current = (int) db_scalar('SELECT score FROM leads WHERE id = ?', [$record['id']]);
            $score = max(0, min(100, $current + (int) ($params['points'] ?? 0)));
            db_exec('UPDATE leads SET score = ?, score_band = ? WHERE id = ?', [$score, score_band_for($score), $record['id']]);
            $log[] = "score adjusted by " . ($params['points'] ?? 0) . " to $score";
            return;

        case 'set_follow_up':
            if ($entityType !== 'LEAD') {
                $log[] = 'set_follow_up skipped: not a lead';
                return;
            }
            $days = (int) ($params['inDays'] ?? 1);
            db_exec('UPDATE leads SET next_follow_up_at = ? WHERE id = ?', [add_days(date('Y-m-d H:i:s'), $days), $record['id']]);
            $log[] = "follow-up scheduled in $days day(s)";
            return;

        case 'notify':
            $userId = automation_resolve_notify_target($params['target'] ?? '', $params, $record);
            if (!$userId) {
                $log[] = 'notify skipped: no ' . ($params['target'] ?? '');
                return;
            }
            notify_user($orgId, $userId, 'SYSTEM_ALERT', $params['title'] ?? '', $params['body'] ?? null, $entityType, $record['id'],
                $entityType === 'LEAD' ? "#/leads/{$record['id']}" : '#/opportunities');
            $log[] = 'notified ' . ($params['target'] ?? '');
            return;

        case 'log_activity':
            db_exec(
                'INSERT INTO activities (id, organization_id, type, subject, body, lead_id, opportunity_id, occurred_at, created_at)
                 VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
                [
                    gv_id(), $orgId, 'SYSTEM', $params['subject'] ?? '', $params['body'] ?? null,
                    $entityType === 'LEAD' ? $record['id'] : ($record['leadId'] ?? null),
                    $entityType === 'OPPORTUNITY' ? $record['id'] : null,
                ]
            );
            $log[] = 'activity logged';
            return;

        default:
            $log[] = "unknown action $type";
    }
}

// ----------------------------------------------------------------------------
// Runner
// ----------------------------------------------------------------------------

function run_workflows_for(string $trigger, array $payload): void
{
    $orgId = $payload['organizationId'] ?? null;
    $data = camelize($payload['data'] ?? null);
    if (!$orgId || empty($data['id'])) {
        return;
    }
    $depth = $payload['depth'] ?? 0;
    if ($depth >= GV_AUTOMATION_MAX_DEPTH) {
        error_log("[automation] depth limit reached for $trigger; not cascading further.");
        return;
    }
    $entityType = $payload['entityType'] ?? 'LEAD';
    $changes = $payload['changes'] ?? [];

    $workflows = db_all(
        'SELECT * FROM workflows WHERE organization_id = ? AND trigger_key = ? AND is_active = 1 ORDER BY priority ASC, created_at ASC',
        [$orgId, $trigger]
    );
    if (!$workflows) {
        return;
    }

    foreach ($workflows as $workflow) {
        $log = [];
        $status = 'SUCCESS';
        $error = null;
        try {
            if (!evaluate_group($data, $workflow['conditions'], ['changes' => $changes])) {
                $status = 'SKIPPED';
                $log[] = 'conditions not met';
            } else {
                $actions = safe_json($workflow['actions'], []);
                foreach ($actions as $action) {
                    automation_run_action($action, $orgId, $entityType, $data, $log);
                }
            }
        } catch (Throwable $e) {
            $status = 'FAILED';
            $error = substr($e->getMessage(), 0, 500);
            error_log("[automation] workflow \"{$workflow['name']}\" failed: " . $e->getMessage());
        }

        try {
            db_exec(
                'INSERT INTO workflow_runs (id, workflow_id, organization_id, entity_type, entity_id, status, log, error, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
                [gv_id(), $workflow['id'], $orgId, $entityType, $data['id'], $status, to_json($log), $error]
            );
            if ($status !== 'SKIPPED') {
                db_exec('UPDATE workflows SET run_count = run_count + 1, last_run_at = NOW() WHERE id = ?', [$workflow['id']]);
            }
        } catch (Throwable $e) {
            error_log('[automation] failed to record workflow run: ' . $e->getMessage());
        }
    }
}

function register_automation_listeners(): void
{
    foreach (GV_AUTOMATION_TRIGGERS as $t) {
        on_domain_event($t['key'], function (array $payload) use ($t) {
            try {
                run_workflows_for($t['key'], array_merge(['entityType' => $t['entity']], $payload));
            } catch (Throwable $e) {
                error_log('[automation] runner error: ' . $e->getMessage());
            }
        });
    }
}
