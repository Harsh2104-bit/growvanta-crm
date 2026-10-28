/**
 * Automation: assignment rules, lead scoring, workflows and follow-up sequences.
 *
 * The condition builder is shared by assignment rules and workflows - both send
 * the same `{ match, conditions[] }` shape to the server, which is also what the
 * evaluator reads, so what you build is literally what runs.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, drawer, modal, field, input, textarea,
  select, confirmDialog, dropdown, menuItem, tabs, switchToggle,
} from '../lib/ui.js';
import { number, relative, titleCase, dateTime } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

const TABS = [
  { key: 'assignment', label: 'Lead assignment' },
  { key: 'scoring', label: 'Lead scoring' },
  { key: 'workflows', label: 'Workflows' },
  { key: 'sequences', label: 'Follow-up sequences' },
];

export default async function render({ query }) {
  if (!can('automation', 'read')) return { node: noAccess('automation') };

  const state = { tab: TABS.some((t) => t.key === query.tab) ? query.tab : 'assignment' };
  let meta = null;

  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div', null, pageLoader());
  const actionHost = h('div', { class: 'row wrap', style: { gap: '8px' } });

  const node = h('div', null,
    pageHead({
      title: 'Automation',
      subtitle: 'Route leads, score them, and run workflows without anyone touching a keyboard.',
      actions: actionHost,
    }),
    tabHost, body);

  function buildTabs() {
    mount(tabHost, tabs(TABS, state.tab, (key) => {
      state.tab = key;
      window.history.replaceState(null, '', `#/automation?tab=${key}`);
      buildTabs();
      load();
    }));
  }

  async function load() {
    mount(body, pageLoader());
    clear(actionHost);
    try {
      if (!meta) meta = await api.data('/api/automation/metadata');
      if (state.tab === 'assignment') await renderAssignment();
      else if (state.tab === 'scoring') await renderScoring();
      else if (state.tab === 'workflows') await renderWorkflows();
      else await renderSequences();
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load', message: err.message })));
    }
  }

  // =========================================================================
  // Assignment rules
  // =========================================================================
  async function renderAssignment() {
    const rules = await api.data('/api/automation/assignment-rules');

    if (can('automation', 'create')) {
      mount(actionHost,
        h('button', { class: 'btn', onClick: testRules }, icon('play', { size: 15 }), 'Test a lead'),
        h('button', { class: 'btn btn-primary', onClick: () => openAssignmentForm({ onSaved: load }) }, icon('plus', { size: 15 }), 'New rule'));
    }

    if (!rules.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'automation',
        title: 'No assignment rules',
        message: 'Without rules, new leads fall back to load-balanced round robin across everyone who can take leads.',
        action: can('automation', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openAssignmentForm({ onSaved: load }) }, 'Create a rule')
          : null,
      })));
      return;
    }

    mount(body,
      h('div', { class: 'alert info mb-16' },
        icon('info', { size: 16 }),
        h('div', null, 'Rules run in priority order (lowest first). The first rule that matches wins, unless it is set to keep going.')),
      h('div', { class: 'col', style: { gap: '10px' } }, ...rules.map(renderRuleCard)));
  }

  function renderRuleCard(rule) {
    const conditions = rule.conditions?.conditions || [];
    return h('section', { class: 'card' },
      h('div', { class: 'card-head' },
        h('div', { style: { minWidth: 0 } },
          h('h3', { class: 'row', style: { gap: '8px' } },
            h('span', { class: 'badge' }, `#${rule.priority}`),
            rule.name,
            rule.isActive ? null : h('span', { class: 'badge badge-warning' }, 'Paused')),
          h('div', { class: 'sub' }, rule.description || describeStrategy(rule))),
        h('div', { class: 'row', style: { gap: '8px' } },
          h('span', { class: 'badge badge-brand' }, `${number(rule.assignedCount)} assigned`),
          can('automation', 'update')
            ? dropdown(
              h('button', { class: 'icon-btn', 'aria-label': 'Rule actions' }, icon('more', { size: 16 })),
              (close) => h('div', null,
                menuItem('Edit', { iconName: 'edit', close, onClick: () => openAssignmentForm({ rule, onSaved: load }) }),
                menuItem(rule.isActive ? 'Pause' : 'Activate', {
                  iconName: rule.isActive ? 'close' : 'check', close,
                  onClick: async () => {
                    try {
                      await api.patch(`/api/automation/assignment-rules/${rule.id}`, { isActive: !rule.isActive });
                      toast.success(rule.isActive ? 'Rule paused' : 'Rule activated');
                      load();
                    } catch (err) { reportError(err); }
                  },
                }),
                can('automation', 'delete')
                  ? menuItem('Delete', {
                    iconName: 'trash', close, danger: true,
                    onClick: async () => {
                      const yes = await confirmDialog({ title: 'Delete this rule?', message: rule.name, danger: true, confirmLabel: 'Delete' });
                      if (!yes) return;
                      try { await api.del(`/api/automation/assignment-rules/${rule.id}`); toast.success('Rule deleted'); load(); } catch (err) { reportError(err); }
                    },
                  })
                  : null),
            )
            : null)),
      h('div', { class: 'card-body' },
        h('div', { class: 'row wrap', style: { gap: '8px', alignItems: 'flex-start' } },
          h('span', { class: 'badge badge-info', style: { flex: '0 0 auto' } }, 'IF'),
          conditions.length
            ? h('div', { class: 'row wrap', style: { gap: '6px' } },
              ...conditions.flatMap((c, i) => [
                i > 0 ? h('span', { class: 'tiny muted strong' }, rule.conditions.match === 'ANY' ? 'OR' : 'AND') : null,
                h('span', { class: 'chip' }, `${labelForField(c.field)} ${labelForOperator(c.operator)} ${formatValue(c.field, c.value)}`),
              ].filter(Boolean)))
            : h('span', { class: 'muted small' }, 'any lead')),
        h('div', { class: 'row wrap mt-8', style: { gap: '8px' } },
          h('span', { class: 'badge badge-success', style: { flex: '0 0 auto' } }, 'THEN'),
          h('span', { class: 'small' }, describeStrategy(rule)))));
  }

  function describeStrategy(rule) {
    const strategy = meta.strategies.find((s) => s.key === rule.strategy)?.label || rule.strategy;
    const team = meta.options.teams.find((t) => t.id === rule.targetTeamId);
    const users = (rule.targetUserIds || [])
      .map((id) => meta.options.users.find((u) => u.id === id))
      .filter(Boolean)
      .map((u) => `${u.firstName} ${u.lastName || ''}`.trim());
    const parts = [strategy];
    if (team) parts.push(`team ${team.name}`);
    if (users.length) parts.push(users.slice(0, 3).join(', ') + (users.length > 3 ? ` +${users.length - 3}` : ''));
    return parts.join(' · ');
  }

  const labelForField = (key) => meta.leadFields.find((f) => f.key === key)?.label || key;
  const labelForOperator = (key) => meta.operators.find((o) => o.key === key)?.label || key;
  function formatValue(fieldKey, value) {
    if (value === undefined || value === null || value === '') return '';
    const def = meta.leadFields.find((f) => f.key === fieldKey);
    if (def?.type === 'user') {
      const u = meta.options.users.find((x) => x.id === value);
      return u ? `${u.firstName} ${u.lastName || ''}`.trim() : value;
    }
    if (def?.type === 'team') return meta.options.teams.find((t) => t.id === value)?.name || value;
    if (def?.type === 'project') return (meta.options.projects || []).find((p) => p.id === value)?.name || value;
    return String(value);
  }

  async function testRules() {
    const stateSelect = input({ placeholder: 'Haryana' });
    const sourceSelect = select(meta.options.sources.map((s) => ({ value: s.id, label: s.name })), { placeholder: 'Any source' });
    const valueInput = input({ type: 'number', placeholder: '500000' });
    const resultHost = h('div');

    const dialog = modal({
      title: 'Test the assignment rules',
      subtitle: 'A dry run. Nothing is created and no rotation counter moves.',
      body: h('div', null,
        h('div', { class: 'form-grid' },
          field('Lead source', sourceSelect),
          field('State', stateSelect),
          field('Expected value', valueInput)),
        resultHost),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            try {
              const res = await api.post('/api/automation/assignment-rules/test', {
                lead: {
                  sourceId: sourceSelect.value || null,
                  state: stateSelect.value || null,
                  expectedValue: valueInput.value ? Number(valueInput.value) : null,
                },
              });
              const { evaluation, firstMatch } = res.data;
              mount(resultHost,
                h('div', { class: 'mt-16' },
                  firstMatch
                    ? h('div', { class: 'alert success mb-16' },
                      icon('check', { size: 16 }),
                      h('div', null, h('strong', null, 'Matched'), `“${firstMatch.name}” would assign this lead.`))
                    : h('div', { class: 'alert warning mb-16' },
                      icon('alert', { size: 16 }),
                      h('div', null, h('strong', null, 'No rule matched'), 'The default load-balanced fallback would be used.')),
                  h('div', { class: 'col', style: { gap: '5px' } },
                    ...evaluation.map((r) => h('div', { class: 'row-between small' },
                      h('span', null, `#${r.priority} ${r.name}`),
                      h('span', { class: r.matched ? 'badge badge-success' : 'badge' }, r.matched ? 'match' : 'no match'))))));
            } catch (err) { reportError(err); }
          },
        }, 'Run test')),
    });
  }

  // =========================================================================
  // Scoring
  // =========================================================================
  async function renderScoring() {
    const rules = await api.data('/api/automation/scoring-rules');

    if (can('automation', 'create')) {
      mount(actionHost,
        h('button', {
          class: 'btn',
          onClick: async () => {
            const yes = await confirmDialog({
              title: 'Recalculate every lead score?',
              message: 'Runs the current rules over every lead in the organisation. On a large database this takes a moment.',
              confirmLabel: 'Recalculate',
            });
            if (!yes) return;
            try {
              const res = await api.post('/api/automation/scoring-rules/recalculate');
              toast.success(`${res.data.changed} of ${res.data.processed} lead scores updated`);
            } catch (err) { reportError(err); }
          },
        }, icon('refresh', { size: 15 }), 'Recalculate all'),
        h('button', { class: 'btn btn-primary', onClick: () => openScoringForm({ onSaved: load }) }, icon('plus', { size: 15 }), 'New rule'));
    }

    const bands = panel('Score bands', h('div', { class: 'row wrap', style: { gap: '10px' } },
      ...meta.scoreBands.map((band) => h('div', {
        style: {
          flex: '1 1 130px', padding: '12px', borderRadius: '10px',
          border: `1px solid ${band.color}44`, background: `${band.color}14`,
        },
      },
        h('div', { class: 'strong', style: { color: band.color } }, band.label),
        h('div', { class: 'tiny muted' }, `${band.min} points and above`)))),
    { subtitle: 'Scores are clamped to 0-100 and mapped to these bands.' });

    if (!rules.length) {
      mount(body, bands, h('div', { class: 'card mt-16' }, emptyState({
        iconName: 'trending', title: 'No scoring rules', message: 'Without rules every lead scores zero.',
        action: can('automation', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openScoringForm({ onSaved: load }) }, 'Add a rule')
          : null,
      })));
      return;
    }

    mount(body, bands,
      h('div', { class: 'mt-16' },
        panel('Scoring rules', h('div', { class: 'table-wrap' },
          h('table', { class: 'data' },
            h('thead', null, h('tr', null,
              h('th', null, 'Rule'), h('th', null, 'Condition'),
              h('th', { class: 'right' }, 'Points'), h('th', null, 'Status'), h('th', { style: { width: '48px' } }, ''))),
            h('tbody', null, ...rules.map((rule) => h('tr', null,
              h('td', { class: 'cell-main' }, rule.name),
              h('td', { class: 'small muted' }, describeScoringRule(rule)),
              h('td', { class: 'right' }, h('span', {
                class: rule.points >= 0 ? 'badge badge-success' : 'badge badge-danger',
              }, `${rule.points > 0 ? '+' : ''}${rule.points}`)),
              h('td', null, h('span', { class: `badge ${rule.isActive ? 'badge-success' : ''}` }, rule.isActive ? 'Active' : 'Paused')),
              h('td', null, can('automation', 'update')
                ? dropdown(
                  h('button', { class: 'icon-btn', 'aria-label': 'Actions' }, icon('more', { size: 16 })),
                  (close) => h('div', null,
                    menuItem('Edit', { iconName: 'edit', close, onClick: () => openScoringForm({ rule, onSaved: load }) }),
                    menuItem(rule.isActive ? 'Pause' : 'Activate', {
                      iconName: rule.isActive ? 'close' : 'check', close,
                      onClick: async () => {
                        try {
                          await api.patch(`/api/automation/scoring-rules/${rule.id}`, { isActive: !rule.isActive });
                          load();
                        } catch (err) { reportError(err); }
                      },
                    }),
                    can('automation', 'delete')
                      ? menuItem('Delete', {
                        iconName: 'trash', close, danger: true,
                        onClick: async () => {
                          const yes = await confirmDialog({ title: 'Delete this rule?', message: rule.name, danger: true, confirmLabel: 'Delete' });
                          if (!yes) return;
                          try { await api.del(`/api/automation/scoring-rules/${rule.id}`); toast.success('Deleted'); load(); } catch (err) { reportError(err); }
                        },
                      })
                      : null),
                )
                : null)))))),
        { flush: true })));
  }

  function describeScoringRule(rule) {
    const kind = meta.scoringKinds.find((k) => k.key === rule.kind)?.label || rule.kind;
    return [kind, rule.field, rule.value].filter(Boolean).join(' · ');
  }

  // =========================================================================
  // Workflows
  // =========================================================================
  async function renderWorkflows() {
    const workflows = await api.data('/api/automation/workflows');

    if (can('automation', 'create')) {
      mount(actionHost,
        h('button', { class: 'btn btn-primary', onClick: () => openWorkflowForm({ onSaved: load }) }, icon('plus', { size: 15 }), 'New workflow'));
    }

    if (!workflows.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'automation',
        title: 'No workflows yet',
        message: 'A workflow reacts to an event — a lead is created, a status changes, a deal is won — and runs actions for you.',
        action: can('automation', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openWorkflowForm({ onSaved: load }) }, 'Create a workflow')
          : null,
      })));
      return;
    }

    mount(body, h('div', { class: 'col', style: { gap: '10px' } }, ...workflows.map((wf) => {
      const trigger = meta.triggers.find((t) => t.key === wf.trigger);
      const conditions = wf.conditions?.conditions || [];
      return h('section', { class: 'card' },
        h('div', { class: 'card-head' },
          h('div', { style: { minWidth: 0 } },
            h('h3', { class: 'row', style: { gap: '8px' } },
              wf.name,
              wf.isActive ? null : h('span', { class: 'badge badge-warning' }, 'Paused')),
            h('div', { class: 'sub' }, wf.description || `Runs when: ${trigger?.label || wf.trigger}`)),
          h('div', { class: 'row', style: { gap: '8px' } },
            h('span', { class: 'badge' }, `${number(wf.runCount)} runs`),
            wf.lastRunAt ? h('span', { class: 'badge' }, `Last ${relative(wf.lastRunAt)}`) : null,
            can('automation', 'update')
              ? dropdown(
                h('button', { class: 'icon-btn', 'aria-label': 'Workflow actions' }, icon('more', { size: 16 })),
                (close) => h('div', null,
                  menuItem('Edit', { iconName: 'edit', close, onClick: () => openWorkflowForm({ workflow: wf, onSaved: load }) }),
                  menuItem('Run history', { iconName: 'list', close, onClick: () => showRuns(wf) }),
                  menuItem(wf.isActive ? 'Pause' : 'Activate', {
                    iconName: wf.isActive ? 'close' : 'check', close,
                    onClick: async () => {
                      try {
                        await api.patch(`/api/automation/workflows/${wf.id}`, { isActive: !wf.isActive });
                        toast.success(wf.isActive ? 'Workflow paused' : 'Workflow activated');
                        load();
                      } catch (err) { reportError(err); }
                    },
                  }),
                  can('automation', 'delete')
                    ? menuItem('Delete', {
                      iconName: 'trash', close, danger: true,
                      onClick: async () => {
                        const yes = await confirmDialog({ title: 'Delete this workflow?', message: wf.name, danger: true, confirmLabel: 'Delete' });
                        if (!yes) return;
                        try { await api.del(`/api/automation/workflows/${wf.id}`); toast.success('Deleted'); load(); } catch (err) { reportError(err); }
                      },
                    })
                    : null),
              )
              : null)),
        h('div', { class: 'card-body' },
          h('div', { class: 'row wrap mb-8', style: { gap: '8px' } },
            h('span', { class: 'badge badge-brand' }, 'WHEN'),
            h('span', { class: 'small strong' }, trigger?.label || wf.trigger)),
          conditions.length
            ? h('div', { class: 'row wrap mb-8', style: { gap: '6px' } },
              h('span', { class: 'badge badge-info' }, 'IF'),
              ...conditions.map((c) => h('span', { class: 'chip' },
                `${labelForField(c.field)} ${labelForOperator(c.operator)} ${formatValue(c.field, c.value)}`)))
            : null,
          h('div', { class: 'row wrap', style: { gap: '6px' } },
            h('span', { class: 'badge badge-success' }, 'THEN'),
            ...(wf.actions || []).map((a) => h('span', { class: 'chip' },
              meta.actions.find((x) => x.key === a.type)?.label || a.type)))));
    })));
  }

  async function showRuns(workflow) {
    const bodyEl = h('div', null, pageLoader());
    drawer({ title: `${workflow.name} runs`, subtitle: 'Most recent first', wide: true, body: bodyEl });
    try {
      const runs = await api.data(`/api/automation/workflows/${workflow.id}/runs`);
      if (!runs.length) {
        mount(bodyEl, emptyState({ iconName: 'automation', title: 'No runs yet', message: 'This workflow has not been triggered.' }));
        return;
      }
      mount(bodyEl, h('div', { class: 'col', style: { gap: '8px' } }, ...runs.map((run) => h('div', {
        style: { padding: '11px', border: '1px solid var(--line)', borderRadius: '10px' },
      },
        h('div', { class: 'row-between mb-8' },
          h('span', {
            class: `badge ${{ SUCCESS: 'badge-success', FAILED: 'badge-danger', SKIPPED: '' }[run.status] || ''}`,
          }, titleCase(run.status)),
          h('span', { class: 'tiny muted' }, dateTime(run.createdAt))),
        run.error ? h('div', { class: 'small text-danger mb-8' }, run.error) : null,
        run.log?.length
          ? h('ul', { class: 'small muted', style: { margin: 0, paddingLeft: '18px' } },
            ...run.log.map((line) => h('li', null, line)))
          : h('div', { class: 'tiny muted' }, 'No actions logged.')))));
    } catch (err) {
      mount(bodyEl, emptyState({ iconName: 'alert', title: 'Could not load runs', message: err.message }));
    }
  }

  // =========================================================================
  // Sequences
  // =========================================================================
  async function renderSequences() {
    const sequences = await api.data('/api/automation/sequences');

    if (can('automation', 'create')) {
      mount(actionHost,
        h('button', { class: 'btn btn-primary', onClick: () => openSequenceForm({ onSaved: load }) }, icon('plus', { size: 15 }), 'New sequence'));
    }

    if (!sequences.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'clock',
        title: 'No follow-up sequences',
        message: 'A sequence schedules the next follow-up date on the lead as it moves through the funnel.',
        action: can('automation', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openSequenceForm({ onSaved: load }) }, 'Create a sequence')
          : null,
      })));
      return;
    }

    mount(body, h('div', { class: 'chart-grid half' }, ...sequences.map((seq) => h('section', { class: 'card' },
      h('div', { class: 'card-head' },
        h('div', null,
          h('h3', { class: 'row', style: { gap: '8px' } },
            seq.name,
            seq.isActive ? null : h('span', { class: 'badge badge-warning' }, 'Paused')),
          h('div', { class: 'sub' }, seq.description
            || (seq.applyOnCreate ? 'Applied to every new lead' : (seq.triggerStatusKey ? `Applied when status becomes ${seq.triggerStatusKey}` : 'Applied manually')))),
        can('automation', 'update')
          ? dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'Sequence actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              menuItem('Edit', { iconName: 'edit', close, onClick: () => openSequenceForm({ sequence: seq, onSaved: load }) }),
              can('automation', 'delete')
                ? menuItem('Delete', {
                  iconName: 'trash', close, danger: true,
                  onClick: async () => {
                    const yes = await confirmDialog({ title: 'Delete this sequence?', message: seq.name, danger: true, confirmLabel: 'Delete' });
                    if (!yes) return;
                    try { await api.del(`/api/automation/sequences/${seq.id}`); toast.success('Deleted'); load(); } catch (err) { reportError(err); }
                  },
                })
                : null),
          )
          : null),
      h('div', { class: 'card-body' },
        h('div', { class: 'timeline' }, ...seq.steps.map((step) => h('div', { class: 'tl-item' },
          h('div', { class: 'tl-dot' }, icon('clock', { size: 11 })),
          h('div', { class: 'tl-head' },
            h('span', { class: 'tl-title' }, step.title),
            h('span', { class: 'tl-time' }, `Day ${step.dayOffset}`)),
          h('div', { class: 'tl-meta' },
            h('span', { class: 'badge' }, titleCase(step.taskType)),
            h('span', { class: 'badge' }, titleCase(step.priority)))))))))));
  }

  // =========================================================================
  // Condition builder (shared)
  // =========================================================================
  function conditionBuilder(initial) {
    const stateGroup = {
      match: initial?.match || 'ALL',
      conditions: structuredClone(initial?.conditions || []),
    };
    const rowsHost = h('div', { class: 'col', style: { gap: '8px' } });

    function draw() {
      mount(rowsHost, ...stateGroup.conditions.map((cond, index) => {
        const fieldSelect = select(meta.leadFields.map((f) => ({ value: f.key, label: f.label })), {
          value: cond.field,
          onChange: (e) => { cond.field = e.target.value; cond.value = ''; draw(); },
        });
        const opSelect = select(meta.operators.map((o) => ({ value: o.key, label: o.label })), {
          value: cond.operator,
          onChange: (e) => { cond.operator = e.target.value; draw(); },
        });

        const def = meta.leadFields.find((f) => f.key === cond.field);
        let valueControl;
        if (['is_empty', 'is_not_empty', 'changed'].includes(cond.operator)) {
          valueControl = h('span', { class: 'muted small' }, 'no value needed');
        } else if (def?.type === 'source') {
          valueControl = select(meta.options.sources.map((s) => ({ value: s.key, label: s.name })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'status') {
          valueControl = select(meta.options.statuses.map((s) => ({ value: s.key, label: s.name })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'campaign') {
          valueControl = select(meta.options.pipelines.map((p) => ({ value: p.key, label: p.name })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'project') {
          valueControl = select((meta.options.projects || []).map((p) => ({ value: p.id, label: `${p.name} — ${p.location}` })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'user') {
          valueControl = select(meta.options.users.map((u) => ({ value: u.id, label: `${u.firstName} ${u.lastName || ''}`.trim() })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'team') {
          valueControl = select(meta.options.teams.map((t) => ({ value: t.id, label: t.name })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else if (def?.type === 'enum') {
          valueControl = select(def.options.map((o) => ({ value: o, label: titleCase(o) })), {
            value: cond.value, onChange: (e) => { cond.value = e.target.value; },
          });
        } else {
          valueControl = input({
            value: cond.value ?? '',
            type: def?.type === 'number' ? 'number' : 'text',
            placeholder: ['in', 'not_in'].includes(cond.operator) ? 'comma, separated, values' : 'value',
            onInput: (e) => { cond.value = e.target.value; },
          });
        }

        return h('div', {
          style: {
            display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: '8px',
            alignItems: 'center', padding: '10px', border: '1px solid var(--line)',
            borderRadius: '10px', background: 'var(--surface-2)',
          },
        },
          fieldSelect, opSelect, valueControl,
          h('button', {
            class: 'icon-btn', 'aria-label': 'Remove condition',
            onClick: () => { stateGroup.conditions.splice(index, 1); draw(); },
          }, icon('trash', { size: 15 })));
      }));
    }
    draw();

    const node_ = h('div', null,
      h('div', { class: 'row mb-8', style: { gap: '8px' } },
        h('span', { class: 'small muted' }, 'Match'),
        select([{ value: 'ALL', label: 'all conditions' }, { value: 'ANY', label: 'any condition' }], {
          value: stateGroup.match,
          onChange: (e) => { stateGroup.match = e.target.value; },
        })),
      rowsHost,
      h('button', {
        class: 'btn btn-sm mt-8',
        onClick: () => {
          stateGroup.conditions.push({ field: meta.leadFields[0].key, operator: 'eq', value: '' });
          draw();
        },
      }, icon('plus', { size: 14 }), 'Add condition'),
      h('p', { class: 'tiny muted mt-8' }, 'No conditions means the rule applies to every lead.'));

    return { node: node_, get value() { return stateGroup; } };
  }

  // =========================================================================
  // Forms
  // =========================================================================
  function openAssignmentForm({ rule = null, onSaved } = {}) {
    const isEdit = Boolean(rule?.id);
    const nameInput = input({ value: rule?.name || '', placeholder: 'Delhi NCR territory' });
    const descInput = input({ value: rule?.description || '', placeholder: 'What is this rule for?' });
    const priorityInput = input({ type: 'number', min: '0', max: '999', value: rule?.priority ?? 10 });
    const strategySelect = select(meta.strategies.map((s) => ({ value: s.key, label: s.label })), {
      value: rule?.strategy || 'ROUND_ROBIN',
      onChange: () => updateTargets(),
    });
    const teamSelect = select(meta.options.teams.map((t) => ({ value: t.id, label: t.name })), {
      value: rule?.targetTeamId || '', placeholder: 'Choose a team',
    });
    const usersBox = h('div', {
      style: { maxHeight: '180px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '8px', padding: '8px' },
    }, ...meta.options.users.map((u) => h('label', { class: 'checkbox', style: { display: 'flex', padding: '3px 0' } },
      h('input', {
        type: 'checkbox', value: u.id,
        checked: (rule?.targetUserIds || []).includes(u.id),
      }), `${u.firstName} ${u.lastName || ''}`.trim())));

    const teamField = field('Target team', teamSelect);
    const usersField = field('Target users', usersBox, { hint: 'Leads rotate through the people you tick.' });
    const targetsHost = h('div', null, teamField, usersField);

    function updateTargets() {
      const strategy = strategySelect.value;
      teamField.classList.toggle('hidden', !['TEAM', 'TEAM_ROUND_ROBIN', 'MANAGER', 'ROUND_ROBIN', 'LOAD_BALANCED'].includes(strategy));
      usersField.classList.toggle('hidden', !['USER', 'ROUND_ROBIN', 'LOAD_BALANCED'].includes(strategy));
    }
    updateTargets();

    let stopOnMatch = rule ? rule.stopOnMatch : true;
    const builder = conditionBuilder(rule?.conditions);
    const errorHost = h('div');
    const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save rule' : 'Create rule');

    const ref = drawer({
      title: isEdit ? `Edit ${rule.name}` : 'New assignment rule',
      subtitle: 'Conditions decide which leads match; the strategy decides who gets them.',
      wide: true,
      body: h('div', null,
        errorHost,
        h('div', { class: 'form-grid' },
          field('Rule name', nameInput, { required: true }),
          field('Priority', priorityInput, { hint: 'Lower numbers run first.' })),
        field('Description', descInput),
        h('h4', { class: 'mt-16 mb-8' }, 'Conditions'),
        builder.node,
        h('h4', { class: 'mt-16 mb-8' }, 'Assignment'),
        field('Strategy', strategySelect),
        targetsHost,
        h('div', { class: 'mt-8' },
          switchToggle('Stop evaluating further rules when this one matches', stopOnMatch, (v) => { stopOnMatch = v; }))),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        saveButton),
    });

    saveButton.addEventListener('click', async () => {
      clear(errorHost);
      const payload = {
        name: nameInput.value.trim(),
        description: descInput.value.trim() || null,
        priority: Number(priorityInput.value) || 10,
        conditions: builder.value,
        strategy: strategySelect.value,
        targetTeamId: teamSelect.value || null,
        targetUserIds: [...usersBox.querySelectorAll('input:checked')].map((el) => el.value),
        stopOnMatch,
      };
      if (!payload.name) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The rule needs a name.'));
        return;
      }
      saveButton.disabled = true;
      try {
        if (isEdit) await api.patch(`/api/automation/assignment-rules/${rule.id}`, payload);
        else await api.post('/api/automation/assignment-rules', payload);
        toast.success(isEdit ? 'Rule saved' : 'Rule created');
        ref.close();
        onSaved?.();
      } catch (err) {
        saveButton.disabled = false;
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      }
    });
  }

  function openScoringForm({ rule = null, onSaved } = {}) {
    const isEdit = Boolean(rule?.id);
    const nameInput = input({ value: rule?.name || '', placeholder: 'Visited the pricing page' });
    const kindSelect = select(meta.scoringKinds.map((k) => ({ value: k.key, label: k.label })), {
      value: rule?.kind || 'FIELD_PRESENT',
      onChange: () => updateHints(),
    });
    const fieldInput = input({ value: rule?.field || '', placeholder: 'email / phone / CALL' });
    const valueInput = input({ value: rule?.value || '', placeholder: 'google_ads / 500000 / 1' });
    const pointsInput = input({ type: 'number', min: '-100', max: '100', value: rule?.points ?? 5 });
    const hint = h('div', { class: 'hint' });

    function updateHints() {
      const kind = meta.scoringKinds.find((k) => k.key === kindSelect.value);
      hint.textContent = kind ? `Needs: ${kind.needs.join(', ') || 'nothing else'}` : '';
    }
    updateHints();

    const errorHost = h('div');
    const dialog = modal({
      title: isEdit ? 'Edit scoring rule' : 'New scoring rule',
      body: h('div', null,
        errorHost,
        field('Rule name', nameInput, { required: true }),
        field('Rule type', kindSelect),
        hint,
        h('div', { class: 'form-grid' },
          field('Field', fieldInput, { hint: 'Lead field name, or activity type for activity counts.' }),
          field('Value', valueInput),
          field('Points', pointsInput, { hint: 'Negative values subtract, e.g. for stale leads.' }))),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            const button = e.currentTarget;
            clear(errorHost);
            const payload = {
              name: nameInput.value.trim(),
              kind: kindSelect.value,
              field: fieldInput.value.trim() || null,
              value: valueInput.value.trim() || null,
              points: Number(pointsInput.value) || 0,
            };
            if (!payload.name) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The rule needs a name.'));
              return;
            }
            button.disabled = true;
            try {
              if (isEdit) await api.patch(`/api/automation/scoring-rules/${rule.id}`, payload);
              else await api.post('/api/automation/scoring-rules', payload);
              toast.success(isEdit ? 'Rule saved' : 'Rule created');
              dialog.close();
              onSaved?.();
            } catch (err) {
              button.disabled = false;
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
            }
          },
        }, isEdit ? 'Save' : 'Create')),
    });
  }

  function openWorkflowForm({ workflow = null, onSaved } = {}) {
    const isEdit = Boolean(workflow?.id);
    const nameInput = input({ value: workflow?.name || '', placeholder: 'Escalate hot website leads' });
    const descInput = input({ value: workflow?.description || '' });
    const triggerSelect = select(meta.triggers.map((t) => ({ value: t.key, label: t.label })), {
      value: workflow?.trigger || 'lead.created',
    });
    const priorityInput = input({ type: 'number', min: '0', value: workflow?.priority ?? 10 });
    const builder = conditionBuilder(workflow?.conditions);

    const actionsState = structuredClone(workflow?.actions || []);
    const actionsHost = h('div', { class: 'col', style: { gap: '8px' } });

    function drawActions() {
      mount(actionsHost, ...actionsState.map((action, index) => {
        const spec = meta.actions.find((a) => a.key === action.type);
        const paramControls = (spec?.params || []).map((p) => {
          let control;
          if (p.type === 'user') {
            control = select(meta.options.users.map((u) => ({ value: u.id, label: `${u.firstName} ${u.lastName || ''}`.trim() })), {
              value: action.params?.[p.key] || '', placeholder: 'Choose a user',
              onChange: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          } else if (p.type === 'team') {
            control = select(meta.options.teams.map((t) => ({ value: t.id, label: t.name })), {
              value: action.params?.[p.key] || '', placeholder: 'Choose a team',
              onChange: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          } else if (p.type === 'status') {
            control = select(meta.options.statuses.map((s) => ({ value: s.key, label: s.name })), {
              value: action.params?.[p.key] || '', placeholder: 'Choose a status',
              onChange: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          } else if (p.type === 'sequence') {
            control = select(meta.options.sequences.map((s) => ({ value: s.id, label: s.name })), {
              value: action.params?.[p.key] || '', placeholder: 'Choose a sequence',
              onChange: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          } else if (p.type === 'enum') {
            control = select(p.options.map((o) => ({ value: o, label: titleCase(o) })), {
              value: action.params?.[p.key] || p.options[0],
              onChange: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          } else {
            control = input({
              type: p.type === 'number' ? 'number' : 'text',
              value: action.params?.[p.key] ?? '',
              onInput: (e) => { action.params = { ...action.params, [p.key]: e.target.value }; },
            });
          }
          return field(titleCase(p.key), control, { required: p.required });
        });

        return h('div', {
          style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px', background: 'var(--surface-2)' },
        },
          h('div', { class: 'row-between mb-8' },
            select(meta.actions.map((a) => ({ value: a.key, label: a.label })), {
              value: action.type,
              onChange: (e) => { actionsState[index] = { type: e.target.value, params: {} }; drawActions(); },
            }),
            h('button', {
              class: 'icon-btn', 'aria-label': 'Remove action',
              onClick: () => { actionsState.splice(index, 1); drawActions(); },
            }, icon('trash', { size: 15 }))),
          paramControls.length ? h('div', { class: 'form-grid' }, ...paramControls) : null);
      }));
    }
    drawActions();

    const errorHost = h('div');
    const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save workflow' : 'Create workflow');

    const ref = drawer({
      title: isEdit ? `Edit ${workflow.name}` : 'New workflow',
      subtitle: 'When something happens, and the conditions hold, run these actions.',
      wide: true,
      body: h('div', null,
        errorHost,
        h('div', { class: 'form-grid' },
          field('Workflow name', nameInput, { required: true }),
          field('Priority', priorityInput, { hint: 'Lower runs first.' })),
        field('Description', descInput),
        h('h4', { class: 'mt-16 mb-8' }, 'When'),
        field('Trigger', triggerSelect),
        h('h4', { class: 'mt-16 mb-8' }, 'If'),
        builder.node,
        h('h4', { class: 'mt-16 mb-8' }, 'Then'),
        actionsHost,
        h('button', {
          class: 'btn btn-sm mt-8',
          onClick: () => { actionsState.push({ type: meta.actions[0].key, params: {} }); drawActions(); },
        }, icon('plus', { size: 14 }), 'Add action')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        saveButton),
    });

    saveButton.addEventListener('click', async () => {
      clear(errorHost);
      const payload = {
        name: nameInput.value.trim(),
        description: descInput.value.trim() || null,
        trigger: triggerSelect.value,
        priority: Number(priorityInput.value) || 10,
        conditions: builder.value,
        actions: actionsState,
      };
      if (!payload.name) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The workflow needs a name.'));
        return;
      }
      if (!actionsState.length) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Add at least one action.'));
        return;
      }
      saveButton.disabled = true;
      try {
        if (isEdit) await api.patch(`/api/automation/workflows/${workflow.id}`, payload);
        else await api.post('/api/automation/workflows', payload);
        toast.success(isEdit ? 'Workflow saved' : 'Workflow created');
        ref.close();
        onSaved?.();
      } catch (err) {
        saveButton.disabled = false;
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      }
    });
  }

  function openSequenceForm({ sequence = null, onSaved } = {}) {
    const isEdit = Boolean(sequence?.id);
    const nameInput = input({ value: sequence?.name || '', placeholder: 'Standard 4-touch follow-up' });
    const descInput = input({ value: sequence?.description || '' });
    const statusSelect = select(meta.options.statuses.map((s) => ({ value: s.key, label: s.name })), {
      value: sequence?.triggerStatusKey || '', placeholder: 'Apply manually only',
    });
    let applyOnCreate = sequence?.applyOnCreate || false;

    const steps = structuredClone(sequence?.steps || [
      { dayOffset: 1, taskType: 'CALL', title: 'First follow-up call', priority: 'HIGH' },
      { dayOffset: 3, taskType: 'EMAIL', title: 'Send product overview', priority: 'MEDIUM' },
    ]);
    const stepsHost = h('div', { class: 'col', style: { gap: '8px' } });

    function drawSteps() {
      mount(stepsHost, ...steps.map((step, index) => h('div', {
        style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px', background: 'var(--surface-2)' },
      },
        h('div', { class: 'row-between mb-8' },
          h('span', { class: 'strong small' }, `Step ${index + 1}`),
          h('button', {
            class: 'icon-btn', 'aria-label': 'Remove step',
            onClick: () => { steps.splice(index, 1); drawSteps(); },
          }, icon('trash', { size: 15 }))),
        h('div', { class: 'form-grid' },
          field('Task title', input({ value: step.title, onInput: (e) => { step.title = e.target.value; } })),
          field('Day offset', input({
            type: 'number', min: '0', value: step.dayOffset,
            onInput: (e) => { step.dayOffset = Number(e.target.value) || 0; },
          })),
          field('Type', select(['CALL', 'EMAIL', 'MEETING', 'FOLLOW_UP', 'TASK'].map((t) => ({ value: t, label: titleCase(t) })), {
            value: step.taskType, onChange: (e) => { step.taskType = e.target.value; },
          })),
          field('Priority', select(['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => ({ value: p, label: titleCase(p) })), {
            value: step.priority, onChange: (e) => { step.priority = e.target.value; },
          }))))));
    }
    drawSteps();

    const errorHost = h('div');
    const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save sequence' : 'Create sequence');

    const ref = drawer({
      title: isEdit ? `Edit ${sequence.name}` : 'New follow-up sequence',
      subtitle: 'Applying a sequence schedules follow-up dates from the day it is applied.',
      wide: true,
      body: h('div', null,
        errorHost,
        field('Sequence name', nameInput, { required: true }),
        field('Description', descInput),
        field('Apply automatically when status becomes', statusSelect),
        h('div', { class: 'mb-16' },
          switchToggle('Apply to every newly created lead', applyOnCreate, (v) => { applyOnCreate = v; })),
        h('h4', { class: 'mt-16 mb-8' }, 'Steps'),
        stepsHost,
        h('button', {
          class: 'btn btn-sm mt-8',
          onClick: () => {
            steps.push({ dayOffset: (steps[steps.length - 1]?.dayOffset || 0) + 3, taskType: 'FOLLOW_UP', title: 'Follow up', priority: 'MEDIUM' });
            drawSteps();
          },
        }, icon('plus', { size: 14 }), 'Add step')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        saveButton),
    });

    saveButton.addEventListener('click', async () => {
      clear(errorHost);
      const payload = {
        name: nameInput.value.trim(),
        description: descInput.value.trim() || null,
        triggerStatusKey: statusSelect.value || null,
        applyOnCreate,
        steps: steps.filter((s) => s.title.trim()),
      };
      if (!payload.name || !payload.steps.length) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'A sequence needs a name and at least one step.'));
        return;
      }
      saveButton.disabled = true;
      try {
        if (isEdit) await api.patch(`/api/automation/sequences/${sequence.id}`, payload);
        else await api.post('/api/automation/sequences', payload);
        toast.success(isEdit ? 'Sequence saved' : 'Sequence created');
        ref.close();
        onSaved?.();
      } catch (err) {
        saveButton.disabled = false;
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      }
    });
  }

  buildTabs();
  await load();
  return { node };
}
