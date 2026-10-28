/**
 * Performance & targets: activity volume, conversion and revenue per person,
 * compared against the targets a manager has set.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, reference } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, avatar, progressBar, modal,
  field, input, select, confirmDialog, tabs,
} from '../lib/ui.js';
import { columnChart, barList, PALETTE } from '../lib/charts.js';
import { money, number, percent, date, titleCase } from '../lib/format.js';
import { pageHead, filterBar, noAccess, panel } from './shared.js';

const METRICS = ['LEADS', 'CALLS', 'MEETINGS', 'QUALIFIED', 'OPPORTUNITIES', 'CONVERSIONS', 'REVENUE'];

export default async function render({ query }) {
  if (!can('reports', 'read') && !can('targets', 'read')) return { node: noAccess('performance reports') };

  const state = {
    tab: query.tab === 'targets' ? 'targets' : 'people',
    filters: { range: query.range || 'this_month', teamId: query.teamId || '' },
  };

  const filterHost = h('div', { class: 'row wrap filter-row', style: { gap: '8px' } });
  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div', null, pageLoader());

  const node = h('div', null,
    pageHead({
      title: 'Performance',
      subtitle: 'Individual and team output against target.',
      actions: [
        filterHost,
        can('targets', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: openTargetForm }, icon('target', { size: 15 }), 'Set targets')
          : null,
        can('reports', 'export')
          ? h('button', {
            class: 'btn',
            onClick: () => api.download('/api/reports/export/performance', state.filters)
              .then(() => toast.success('Exported')).catch(reportError),
          }, icon('download', { size: 15 }), 'Export')
          : null,
      ].filter(Boolean),
    }),
    tabHost, body);

  async function buildFilters() {
    const controls = await filterBar(['range', 'team'], state.filters, async (patch) => {
      Object.assign(state.filters, patch);
      await buildFilters();
      load();
    });
    mount(filterHost, ...controls);
  }

  function buildTabs() {
    mount(tabHost, tabs([
      { key: 'people', label: 'By person' },
      { key: 'targets', label: 'Targets' },
    ], state.tab, (key) => { state.tab = key; buildTabs(); load(); }));
  }

  async function load() {
    mount(body, pageLoader());
    try {
      mount(body, state.tab === 'targets' ? await renderTargets() : await renderPeople());
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Unavailable', message: err.message })));
    }
  }

  async function renderPeople() {
    const rows = await api.data('/api/reports/performance', state.filters);
    if (!rows.length) {
      return h('div', { class: 'card' }, emptyState({
        iconName: 'users', title: 'No performance data', message: 'Nobody in your scope has recorded activity in this period.',
      }));
    }

    const chartGroups = rows.slice(0, 10).map((r) => ({
      label: r.name.split(' ')[0],
      calls: r.calls,
      meetings: r.meetings,
      converted: r.converted,
    }));

    return h('div', null,
      h('div', { class: 'chart-grid two mb-16' },
        panel('Activity volume', columnChart(chartGroups, [
          { key: 'calls', label: 'Calls', color: PALETTE[0] },
          { key: 'meetings', label: 'Meetings', color: PALETTE[1] },
          { key: 'converted', label: 'Conversions', color: PALETTE[2] },
        ], { formatValue: number })),
        panel('Revenue by person', barList(
          rows.filter((r) => r.revenue > 0).slice(0, 10).map((r) => ({ label: r.name, value: r.revenue })),
          { formatValue: (v) => money(v, { compact: true }) },
        ))),

      panel('Detail', h('div', { class: 'table-wrap' },
        h('table', { class: 'data' },
          h('thead', null, h('tr', null,
            h('th', null, 'Person'),
            h('th', { class: 'right' }, 'Leads'),
            h('th', { class: 'right' }, 'Calls'),
            h('th', { class: 'right hide-sm' }, 'Emails'),
            h('th', { class: 'right hide-sm' }, 'Meetings'),
            h('th', { class: 'right' }, 'Converted'),
            h('th', { class: 'right hide-sm' }, 'Conv. rate'),
            h('th', { class: 'right hide-sm' }, 'Site visits'),
            h('th', { class: 'right' }, 'Revenue'),
            h('th', { style: { width: '180px' } }, 'Target attainment'))),
          h('tbody', null, ...rows.map((r) => h('tr', null,
            h('td', null, h('div', { class: 'row', style: { gap: '8px' } },
              avatar(r.name, { size: 'sm' }),
              h('div', null, h('div', { class: 'cell-main' }, r.name), h('div', { class: 'cell-sub truncate' }, r.email)))),
            h('td', { class: 'right' }, number(r.leads)),
            h('td', { class: 'right' }, number(r.calls)),
            h('td', { class: 'right hide-sm' }, number(r.emails)),
            h('td', { class: 'right hide-sm' }, number(r.meetings)),
            h('td', { class: 'right' }, number(r.converted)),
            h('td', { class: 'right hide-sm' }, percent(r.conversionRate)),
            h('td', { class: 'right hide-sm' }, number(r.siteVisits)),
            h('td', { class: 'right strong' }, money(r.revenue, { compact: true })),
            h('td', null, renderAttainment(r.targets)))))),
      ), { flush: true }));
  }

  function renderAttainment(targets) {
    const entries = Object.entries(targets || {});
    if (!entries.length) return h('span', { class: 'muted tiny' }, 'No targets set');
    return h('div', { class: 'col', style: { gap: '6px' } }, ...entries.slice(0, 3).map(([metric, t]) => h('div', null,
      h('div', { class: 'row-between tiny mb-8' },
        h('span', { class: 'muted' }, titleCase(metric)),
        h('span', { class: t.attainment >= 100 ? 'text-success strong' : '' }, percent(t.attainment, 0))),
      progressBar(t.actual, t.target || 1, t.attainment >= 100 ? 'success' : (t.attainment < 50 ? 'danger' : 'warning')))));
  }

  async function renderTargets() {
    if (!can('targets', 'read')) return h('div', { class: 'card' }, emptyState({ iconName: 'shield', title: 'No access to targets' }));
    const rows = await api.data('/api/targets', state.filters);
    if (!rows.length) {
      return h('div', { class: 'card' }, emptyState({
        iconName: 'target',
        title: 'No targets set',
        message: 'Set monthly or quarterly targets so attainment shows up on the dashboard and performance report.',
        action: can('targets', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: openTargetForm }, 'Set targets')
          : null,
      }));
    }

    return panel('Targets', h('div', { class: 'table-wrap' },
      h('table', { class: 'data' },
        h('thead', null, h('tr', null,
          h('th', null, 'Owner'), h('th', null, 'Metric'), h('th', null, 'Period'),
          h('th', { class: 'right' }, 'Target'), h('th', { class: 'right' }, 'Actual'),
          h('th', { style: { width: '200px' } }, 'Attainment'), h('th', { style: { width: '48px' } }, ''))),
        h('tbody', null, ...rows.map((t) => h('tr', null,
          h('td', { class: 'cell-main' }, t.ownerLabel),
          h('td', null, h('span', { class: 'badge' }, titleCase(t.metric))),
          h('td', { class: 'small muted' }, `${titleCase(t.period)} · ${date(t.periodStart)}`),
          h('td', { class: 'right' }, t.metric === 'REVENUE' ? money(t.targetValue, { compact: true }) : number(t.targetValue)),
          h('td', { class: 'right strong' }, t.metric === 'REVENUE' ? money(t.actual, { compact: true }) : number(t.actual)),
          h('td', null, h('div', null,
            h('div', { class: 'row-between tiny mb-8' },
              h('span', { class: t.attainment >= 100 ? 'text-success strong' : '' }, percent(t.attainment, 0)),
              t.attainment >= 100 ? h('span', { class: 'badge badge-success' }, 'Met') : null),
            progressBar(t.actual, t.targetValue || 1, t.attainment >= 100 ? 'success' : (t.attainment < 50 ? 'danger' : 'warning')))),
          h('td', null, can('targets', 'delete')
            ? h('button', {
              class: 'icon-btn',
              'aria-label': 'Delete target',
              onClick: async () => {
                const yes = await confirmDialog({ title: 'Remove this target?', danger: true, confirmLabel: 'Remove' });
                if (!yes) return;
                try { await api.del(`/api/targets/${t.id}`); toast.success('Target removed'); load(); } catch (err) { reportError(err); }
              },
            }, icon('trash', { size: 15 }))
            : null))))),
    ), { flush: true });
  }

  async function openTargetForm() {
    const ref = await reference();
    const metricSelect = select(METRICS.map((m) => ({ value: m, label: titleCase(m) })), { value: 'REVENUE' });
    const periodSelect = select([
      { value: 'MONTH', label: 'This month' }, { value: 'QUARTER', label: 'This quarter' }, { value: 'YEAR', label: 'This year' },
    ], { value: 'MONTH' });
    const valueInput = input({ type: 'number', min: '0', placeholder: '500000' });
    const usersBox = h('div', {
      style: { maxHeight: '220px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '8px', padding: '8px' },
    }, ...ref.users.map((u) => h('label', { class: 'checkbox', style: { display: 'flex', padding: '4px 0' } },
      h('input', { type: 'checkbox', value: u.id }), u.fullName || u.name)));

    const dialog = modal({
      title: 'Set targets',
      subtitle: 'Applies to everyone you select. Existing targets for the same metric and period are replaced.',
      body: h('div', null,
        h('div', { class: 'form-grid' },
          field('Metric', metricSelect),
          field('Period', periodSelect),
          field('Target value', valueInput, { required: true })),
        field('People', usersBox, { required: true })),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            const userIds = [...usersBox.querySelectorAll('input:checked')].map((el) => el.value);
            if (!userIds.length) { toast.warn('Select at least one person'); return; }
            if (!valueInput.value) { toast.warn('Enter a target value'); return; }
            try {
              const res = await api.post('/api/targets/bulk', {
                userIds,
                metric: metricSelect.value,
                period: periodSelect.value,
                periodStart: new Date().toISOString(),
                targetValue: Number(valueInput.value),
              });
              toast.success(`Targets set for ${res.data.updated} people`);
              dialog.close();
              state.tab = 'targets';
              buildTabs();
              load();
            } catch (err) { reportError(err); }
          },
        }, 'Save targets')),
    });
  }

  await buildFilters();
  buildTabs();
  await load();
  return { node };
}
