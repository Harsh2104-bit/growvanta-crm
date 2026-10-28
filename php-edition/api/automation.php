<?php
declare(strict_types=1);

/** /api/automation/* — assignment rules, scoring rules, workflows and follow-up sequences. Ported from src/routes/automation.js. */

function automation_validate_assignment_payload(array $input): array
{
    $group = validate_condition_group($input['conditions'] ?? ['match' => 'ALL', 'conditions' => []]);
    if (!$group['ok']) {
        throw bad_request($group['message']);
    }
    $strategy = $input['strategy'] ?? '';
    $targetUserIds = $input['targetUserIds'] ?? [];
    $targetTeamId = $input['targetTeamId'] ?? null;

    if ($strategy === 'USER' && !$targetUserIds) {
        throw bad_request('Choose the user this rule assigns to.');
    }
    if (in_array($strategy, ['TEAM', 'TEAM_ROUND_ROBIN', 'MANAGER'], true) && !$targetTeamId) {
        throw bad_request('Choose the team this rule routes to.');
    }
    if ($strategy === 'ROUND_ROBIN' && !$targetUserIds && !$targetTeamId) {
        throw bad_request('Round robin needs either a list of users or a team.');
    }
    return $group['value'];
}

function automation_shape_rule(array $r): array
{
    $out = camelize($r);
    $out['conditions'] = safe_json($r['conditions'] ?? '{}', ['match' => 'ALL', 'conditions' => []]);
    $out['targetUserIds'] = safe_json($r['target_user_ids'] ?? '[]', []);
    return $out;
}

function automation_shape_workflow(array $r): array
{
    $out = camelize($r);
    $out['conditions'] = safe_json($r['conditions'] ?? '{}', ['match' => 'ALL', 'conditions' => []]);
    $out['actions'] = safe_json($r['actions'] ?? '[]', []);
    // The column is trigger_key (TRIGGER is reserved in MySQL); the API keeps the Node field name.
    $out['trigger'] = $r['trigger_key'];
    unset($out['triggerKey']);
    return $out;
}

function automation_load_sequence(string $id): array
{
    $seq = db_one('SELECT * FROM followup_sequences WHERE id = ?', [$id]);
    $steps = db_all('SELECT * FROM sequence_steps WHERE sequence_id = ? ORDER BY day_offset ASC, sort_order ASC', [$id]);
    return array_merge(camelize($seq), ['steps' => array_map('camelize', $steps)]);
}

$section = $rest[0] ?? null;
$id = $rest[1] ?? null;
$sub = $rest[2] ?? null;
$body = request_body();
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $section === 'metadata') {
    // Everything the rule-builder UI needs to render itself.
    require_auth();
    json_ok([
        'operators' => GV_OPERATORS,
        'leadFields' => GV_LEAD_FIELDS,
        'strategies' => GV_ASSIGNMENT_STRATEGIES,
        'triggers' => GV_AUTOMATION_TRIGGERS,
        'actions' => GV_AUTOMATION_ACTIONS,
        'scoringKinds' => GV_SCORING_KINDS,
        'scoreBands' => GV_SCORE_BANDS,
        'options' => [
            'sources' => array_map('camelize', db_all('SELECT id, `key`, name FROM lead_sources WHERE organization_id = ?', [$orgId])),
            'statuses' => array_map('camelize', db_all('SELECT id, `key`, name, color FROM lead_statuses WHERE organization_id = ?', [$orgId])),
            'teams' => array_map('camelize', db_all('SELECT id, name FROM teams WHERE organization_id = ?', [$orgId])),
            'users' => array_map('camelize', db_all('SELECT id, first_name, last_name, email FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY first_name ASC', [$orgId])),
            'sequences' => array_map('camelize', db_all('SELECT id, name FROM followup_sequences WHERE organization_id = ?', [$orgId])),
            // Includes shared and global projects, because a broker routes
            // leads for stock they do not own.
            'projects' => array_map('camelize', (function () use ($orgId) {
                $vis = project_visible_where($orgId);
                return db_all(
                    "SELECT projects.id, projects.name, projects.location FROM projects
                      WHERE {$vis['sql']} AND projects.is_active = 1 AND projects.is_archived = 0
                      ORDER BY projects.name ASC",
                    $vis['params']
                );
            })()),
        ],
    ]);

// ---------------------------------------------------------------------------
// Assignment rules
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'assignment-rules') {
    require_permission('automation', 'read');
    $rows = db_all('SELECT * FROM assignment_rules WHERE organization_id = ? ORDER BY priority ASC, created_at ASC', [$orgId]);
    json_ok(array_map('automation_shape_rule', $rows));
} elseif ($method === 'POST' && $section === 'assignment-rules' && $id === 'test') {
    // Dry run: shows which rule a hypothetical lead would match, changing nothing.
    require_permission('automation', 'read');
    $sample = $body['lead'] ?? [];
    $candidate = array_merge($sample, ['organizationId' => $orgId]);
    if (!empty($sample['sourceId'])) {
        $candidate['source'] = camelize(db_one('SELECT * FROM lead_sources WHERE organization_id = ? AND id = ?', [$orgId, $sample['sourceId']]) ?? []);
    }
    if (!empty($sample['statusId'])) {
        $candidate['status'] = camelize(db_one('SELECT * FROM lead_statuses WHERE organization_id = ? AND id = ?', [$orgId, $sample['statusId']]) ?? []);
    }
    $rules = db_all('SELECT * FROM assignment_rules WHERE organization_id = ? AND is_active = 1 ORDER BY priority ASC, created_at ASC', [$orgId]);
    $evaluation = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'priority' => (int) $r['priority'], 'strategy' => $r['strategy'],
        'matched' => evaluate_group($candidate, $r['conditions']),
    ], $rules);
    $firstMatch = null;
    foreach ($evaluation as $e) {
        if ($e['matched']) {
            $firstMatch = $e;
            break;
        }
    }
    json_ok(['evaluation' => $evaluation, 'firstMatch' => $firstMatch, 'note' => 'Dry run only - no rotation counters were advanced.']);
} elseif ($method === 'POST' && $section === 'assignment-rules' && $id === null) {
    require_permission('automation', 'create');
    $v = validate($body);
    $v->required('name', 'Rule name')->maxLength('name', 120);
    $v->required('strategy', 'Strategy')->in('strategy', array_column(GV_ASSIGNMENT_STRATEGIES, 'key'));
    $v->validate();

    $conditions = automation_validate_assignment_payload($body);
    $ruleId = gv_id();
    db_exec(
        'INSERT INTO assignment_rules (id, organization_id, name, description, priority, is_active, conditions, strategy, target_user_ids, target_team_id, stop_on_match, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $ruleId, $orgId, $body['name'], $body['description'] ?? null, (int) ($body['priority'] ?? 10),
            array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1,
            to_json($conditions), $body['strategy'], to_json($body['targetUserIds'] ?? []), $body['targetTeamId'] ?? null,
            array_key_exists('stopOnMatch', $body) ? (bool_val($body['stopOnMatch']) ? 1 : 0) : 1,
        ]
    );
    $row = db_one('SELECT * FROM assignment_rules WHERE id = ?', [$ruleId]);
    audit_record($orgId, $a, 'assignment_rule.created', ['entityType' => 'ASSIGNMENT_RULE', 'entityId' => $ruleId, 'entityLabel' => $row['name']]);
    json_created(automation_shape_rule($row));
} elseif ($method === 'PATCH' && $section === 'assignment-rules' && $id) {
    require_permission('automation', 'update');
    $before = db_one('SELECT * FROM assignment_rules WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Rule not found');
    }
    $merged = [
        'strategy' => $body['strategy'] ?? $before['strategy'],
        'targetUserIds' => $body['targetUserIds'] ?? safe_json($before['target_user_ids'], []),
        'targetTeamId' => array_key_exists('targetTeamId', $body) ? $body['targetTeamId'] : $before['target_team_id'],
        'conditions' => $body['conditions'] ?? safe_json($before['conditions'], []),
    ];
    $conditions = automation_validate_assignment_payload($merged);

    $data = ['conditions' => to_json($conditions), 'strategy' => $merged['strategy'], 'target_user_ids' => to_json($merged['targetUserIds']), 'target_team_id' => $merged['targetTeamId'] ?: null];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('description', $body)) {
        $data['description'] = $body['description'] ?: null;
    }
    if (array_key_exists('priority', $body)) {
        $data['priority'] = (int) $body['priority'];
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('stopOnMatch', $body)) {
        $data['stop_on_match'] = bool_val($body['stopOnMatch']) ? 1 : 0;
    }
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
    db_exec("UPDATE assignment_rules SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));

    $row = db_one('SELECT * FROM assignment_rules WHERE id = ?', [$before['id']]);
    audit_record($orgId, $a, 'assignment_rule.updated', ['entityType' => 'ASSIGNMENT_RULE', 'entityId' => $row['id'], 'entityLabel' => $row['name'], 'changes' => gv_diff($before, $row, ['name', 'priority', 'is_active', 'strategy', 'target_team_id'])]);
    json_ok(automation_shape_rule($row));
} elseif ($method === 'DELETE' && $section === 'assignment-rules' && $id) {
    require_permission('automation', 'delete');
    $row = db_one('SELECT * FROM assignment_rules WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Rule not found');
    }
    db_exec('DELETE FROM assignment_rules WHERE id = ?', [$row['id']]);
    audit_record($orgId, $a, 'assignment_rule.deleted', ['entityType' => 'ASSIGNMENT_RULE', 'entityId' => $row['id'], 'entityLabel' => $row['name']]);
    json_ok(['deleted' => true]);

// ---------------------------------------------------------------------------
// Scoring rules
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'scoring-rules') {
    require_permission('automation', 'read');
    json_ok(array_map('camelize', db_all('SELECT * FROM scoring_rules WHERE organization_id = ? ORDER BY sort_order ASC, created_at ASC', [$orgId])));
} elseif ($method === 'POST' && $section === 'scoring-rules' && $id === 'recalculate') {
    // Recomputes every lead's score, batched so a large org does not blow memory.
    require_permission('automation', 'update');
    $processed = 0;
    $changed = 0;
    $cursor = '';
    for (;;) {
        $batch = db_all('SELECT * FROM leads WHERE organization_id = ? AND is_duplicate = 0 AND id > ? ORDER BY id ASC LIMIT 200', [$orgId, $cursor]);
        if (!$batch) {
            break;
        }
        foreach ($batch as $lead) {
            $scored = compute_score($orgId, lead_build_context($lead));
            if ($scored['score'] !== (int) $lead['score']) {
                db_exec('UPDATE leads SET score = ?, score_band = ? WHERE id = ?', [$scored['score'], $scored['band'], $lead['id']]);
                $changed++;
            }
            $processed++;
        }
        $cursor = $batch[count($batch) - 1]['id'];
        if (count($batch) < 200) {
            break;
        }
    }
    audit_record($orgId, $a, 'scoring.recalculated', ['changes' => ['processed' => $processed, 'changed' => $changed]]);
    json_ok(['processed' => $processed, 'changed' => $changed]);
} elseif ($method === 'POST' && $section === 'scoring-rules' && $id === null) {
    require_permission('automation', 'create');
    $v = validate($body);
    $v->required('name', 'Rule name')->maxLength('name', 120);
    $v->required('kind', 'Kind')->in('kind', array_column(GV_SCORING_KINDS, 'key'));
    $v->validate();

    $ruleId = gv_id();
    db_exec(
        'INSERT INTO scoring_rules (id, organization_id, name, kind, field, operator, value, points, is_active, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
        [
            $ruleId, $orgId, $body['name'], $body['kind'], $body['field'] ?? null, $body['operator'] ?? null, $body['value'] ?? null,
            (int) ($body['points'] ?? 0), array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1, (int) ($body['sortOrder'] ?? 0),
        ]
    );
    json_created(camelize(db_one('SELECT * FROM scoring_rules WHERE id = ?', [$ruleId])));
} elseif ($method === 'PATCH' && $section === 'scoring-rules' && $id) {
    require_permission('automation', 'update');
    $before = db_one('SELECT * FROM scoring_rules WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Scoring rule not found');
    }
    $data = [];
    foreach (['name' => 'name', 'kind' => 'kind', 'field' => 'field', 'operator' => 'operator', 'value' => 'value'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = $body[$camel] ?: null;
        }
    }
    foreach (['points' => 'points', 'sortOrder' => 'sort_order'] as $camel => $col) {
        if (array_key_exists($camel, $body)) {
            $data[$col] = (int) $body[$camel];
        }
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE scoring_rules SET $sets WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    json_ok(camelize(db_one('SELECT * FROM scoring_rules WHERE id = ?', [$before['id']])));
} elseif ($method === 'DELETE' && $section === 'scoring-rules' && $id) {
    require_permission('automation', 'delete');
    $row = db_one('SELECT * FROM scoring_rules WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Scoring rule not found');
    }
    db_exec('DELETE FROM scoring_rules WHERE id = ?', [$row['id']]);
    json_ok(['deleted' => true]);

// ---------------------------------------------------------------------------
// Workflows
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'workflows' && $id === null) {
    require_permission('automation', 'read');
    json_ok(array_map('automation_shape_workflow', db_all('SELECT * FROM workflows WHERE organization_id = ? ORDER BY priority ASC, created_at DESC', [$orgId])));
} elseif ($method === 'GET' && $section === 'workflows' && $id && $sub === 'runs') {
    require_permission('automation', 'read');
    $workflow = db_one('SELECT * FROM workflows WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$workflow) {
        throw not_found('Workflow not found');
    }
    $runs = db_all('SELECT * FROM workflow_runs WHERE workflow_id = ? ORDER BY created_at DESC LIMIT 100', [$workflow['id']]);
    json_ok(array_map(fn($r) => array_merge(camelize($r), ['log' => safe_json($r['log'] ?? '[]', [])]), $runs));
} elseif ($method === 'POST' && $section === 'workflows' && $id === null) {
    require_permission('automation', 'create');
    $v = validate($body);
    $v->required('name', 'Workflow name')->maxLength('name', 120);
    $v->required('trigger', 'Trigger')->in('trigger', array_column(GV_AUTOMATION_TRIGGERS, 'key'));
    $v->validate();

    $group = validate_condition_group($body['conditions'] ?? ['match' => 'ALL', 'conditions' => []]);
    if (!$group['ok']) {
        throw bad_request($group['message']);
    }
    $actions = validate_actions($body['actions'] ?? []);
    if (!$actions['ok']) {
        throw bad_request($actions['message']);
    }
    if (!$actions['value']) {
        throw bad_request('A workflow needs at least one action.');
    }

    $workflowId = gv_id();
    db_exec(
        'INSERT INTO workflows (id, organization_id, name, description, trigger_key, conditions, actions, is_active, priority, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $workflowId, $orgId, $body['name'], $body['description'] ?? null, $body['trigger'], to_json($group['value']), to_json($actions['value']),
            array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1, (int) ($body['priority'] ?? 10),
        ]
    );
    $row = db_one('SELECT * FROM workflows WHERE id = ?', [$workflowId]);
    audit_record($orgId, $a, 'workflow.created', ['entityType' => 'WORKFLOW', 'entityId' => $workflowId, 'entityLabel' => $row['name']]);
    json_created(automation_shape_workflow($row));
} elseif ($method === 'PATCH' && $section === 'workflows' && $id) {
    require_permission('automation', 'update');
    $before = db_one('SELECT * FROM workflows WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Workflow not found');
    }
    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('description', $body)) {
        $data['description'] = $body['description'] ?: null;
    }
    if (array_key_exists('trigger', $body)) {
        $data['trigger_key'] = $body['trigger'];
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('priority', $body)) {
        $data['priority'] = (int) $body['priority'];
    }
    if (array_key_exists('conditions', $body)) {
        $group = validate_condition_group($body['conditions']);
        if (!$group['ok']) {
            throw bad_request($group['message']);
        }
        $data['conditions'] = to_json($group['value']);
    }
    if (array_key_exists('actions', $body)) {
        $actions = validate_actions($body['actions']);
        if (!$actions['ok']) {
            throw bad_request($actions['message']);
        }
        $data['actions'] = to_json($actions['value']);
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE workflows SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    $row = db_one('SELECT * FROM workflows WHERE id = ?', [$before['id']]);
    audit_record($orgId, $a, 'workflow.updated', ['entityType' => 'WORKFLOW', 'entityId' => $row['id'], 'entityLabel' => $row['name'], 'changes' => gv_diff($before, $row, ['name', 'trigger_key', 'is_active', 'priority', 'conditions', 'actions'])]);
    json_ok(automation_shape_workflow($row));
} elseif ($method === 'DELETE' && $section === 'workflows' && $id) {
    require_permission('automation', 'delete');
    $row = db_one('SELECT * FROM workflows WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Workflow not found');
    }
    db_exec('DELETE FROM workflows WHERE id = ?', [$row['id']]);
    audit_record($orgId, $a, 'workflow.deleted', ['entityType' => 'WORKFLOW', 'entityId' => $row['id'], 'entityLabel' => $row['name']]);
    json_ok(['deleted' => true]);

// ---------------------------------------------------------------------------
// Follow-up sequences
// ---------------------------------------------------------------------------
} elseif ($method === 'GET' && $section === 'sequences') {
    require_permission('automation', 'read');
    $rows = db_all('SELECT id FROM followup_sequences WHERE organization_id = ? ORDER BY created_at ASC', [$orgId]);
    json_ok(array_map(fn($r) => automation_load_sequence($r['id']), $rows));
} elseif ($method === 'POST' && $section === 'sequences' && $id === null) {
    require_permission('automation', 'create');
    $v = validate($body);
    $v->required('name', 'Sequence name')->maxLength('name', 120);
    $v->validate();

    $seqId = gv_id();
    db_exec(
        'INSERT INTO followup_sequences (id, organization_id, name, description, is_active, apply_on_create, trigger_status_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
        [
            $seqId, $orgId, $body['name'], $body['description'] ?? null,
            array_key_exists('isActive', $body) ? (bool_val($body['isActive']) ? 1 : 0) : 1,
            !empty($body['applyOnCreate']) ? 1 : 0, $body['triggerStatusKey'] ?? null,
        ]
    );
    foreach ($body['steps'] ?? [] as $i => $s) {
        db_exec(
            'INSERT INTO sequence_steps (id, sequence_id, day_offset, task_type, title, description, priority, sort_order) VALUES (?,?,?,?,?,?,?,?)',
            [gv_id(), $seqId, (int) ($s['dayOffset'] ?? 1), $s['taskType'] ?? 'FOLLOW_UP', $s['title'], $s['description'] ?? null, $s['priority'] ?? 'MEDIUM', $i]
        );
    }
    json_created(automation_load_sequence($seqId));
} elseif ($method === 'PATCH' && $section === 'sequences' && $id) {
    require_permission('automation', 'update');
    $before = db_one('SELECT * FROM followup_sequences WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$before) {
        throw not_found('Sequence not found');
    }
    $data = [];
    if (array_key_exists('name', $body)) {
        $data['name'] = $body['name'];
    }
    if (array_key_exists('description', $body)) {
        $data['description'] = $body['description'] ?: null;
    }
    if (array_key_exists('isActive', $body)) {
        $data['is_active'] = bool_val($body['isActive']) ? 1 : 0;
    }
    if (array_key_exists('applyOnCreate', $body)) {
        $data['apply_on_create'] = bool_val($body['applyOnCreate']) ? 1 : 0;
    }
    if (array_key_exists('triggerStatusKey', $body)) {
        $data['trigger_status_key'] = $body['triggerStatusKey'] ?: null;
    }
    if ($data) {
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        db_exec("UPDATE followup_sequences SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($data), [$before['id']]));
    }
    if (array_key_exists('steps', $body) && is_array($body['steps'])) {
        // Replacing steps wholesale is safe: tasks already created keep their own
        // copy of title/type and only reference the step id for de-duplication.
        db_exec('DELETE FROM sequence_steps WHERE sequence_id = ?', [$before['id']]);
        foreach ($body['steps'] as $i => $s) {
            db_exec(
                'INSERT INTO sequence_steps (id, sequence_id, day_offset, task_type, title, description, priority, sort_order) VALUES (?,?,?,?,?,?,?,?)',
                [gv_id(), $before['id'], (int) ($s['dayOffset'] ?? 1), $s['taskType'] ?? 'FOLLOW_UP', $s['title'], $s['description'] ?? null, $s['priority'] ?? 'MEDIUM', $i]
            );
        }
    }
    json_ok(automation_load_sequence($before['id']));
} elseif ($method === 'DELETE' && $section === 'sequences' && $id) {
    require_permission('automation', 'delete');
    $row = db_one('SELECT * FROM followup_sequences WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$row) {
        throw not_found('Sequence not found');
    }
    db_exec('DELETE FROM followup_sequences WHERE id = ?', [$row['id']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown automation route');
}
