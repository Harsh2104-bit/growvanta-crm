/** Activity feed and communication mix across every record in scope. */
import { h, mount } from '../lib/dom.js';
import { icon, ACTIVITY_ICONS } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can } from '../lib/store.js';
import { dataTable, pageLoader, emptyState, reportError, avatar, select, kpiCard } from '../lib/ui.js';
import { donutChart, barList, PALETTE } from '../lib/charts.js';
import { relative, dateTime, titleCase, duration, number } from '../lib/format.js';
import { pageHead, filterBar, noAccess, panel, linkTo } from './shared.js';

const TYPES = ['CALL', 'EMAIL', 'MEETING', 'WHATSAPP', 'SMS', 'NOTE', 'STATUS_CHANGE', 'ASSIGNMENT', 'CONVERSION', 'SYSTEM'];

export default async function render({ query }) {
  if (!can('activities', 'read')) return { node: noAccess('activities') };

  const state = { filters: { range: query.range || 'last_30_days', type: query.type || '', userId: query.userId || '' } };
  const summaryHost = h('div', { class: 'mb-16' });
  const filterHost = h('div', { class: 'toolbar' });
  let table;

  const node = h('div', null,
    pageHead({
      title: 'Activity Feed',
      subtitle: 'Every call, email, meeting and system event you are allowed to see.',
    }),
    summaryHost);

  async function buildToolbar() {
    const controls = await filterBar(['range', 'owner'], {
      range: state.filters.range, ownerId: state.filters.userId,
    }, async (patch) => {
      if (patch.range !== undefined) state.filters.range = patch.range;
      if (patch.ownerId !== undefined) state.filters.userId = patch.ownerId;
      await buildToolbar();
      loadSummary();
      table?.setParams({ range: state.filters.range, userId: state.filters.userId });
    });
    mount(filterHost,
      select([{ value: '', label: 'All activity types' }, ...TYPES.map((t) => ({ value: t, label: titleCase(t) }))], {
        value: state.filters.type,
        onChange: (e) => { state.filters.type = e.target.value; table?.setParams({ type: e.target.value }); },
      }),
      ...controls);
  }

  async function loadSummary() {
    try {
      const data = await api.data('/api/activities/communication-summary', { range: state.filters.range });
      const total = data.byChannel.reduce((sum, c) => sum + c.count, 0);
      mount(summaryHost,
        h('div', { class: 'kpi-grid mb-16' },
          kpiCard({ label: 'Interactions', value: number(total), foot: 'Calls, emails, meetings and messages', accent: PALETTE[0] }),
          kpiCard({ label: 'Call time', value: duration(data.totalCallSeconds), foot: `Average ${duration(data.averageCallSeconds)}`, accent: PALETTE[1] }),
          kpiCard({
            label: 'Outbound',
            value: number(data.byDirection.find((d) => d.direction === 'OUTBOUND')?.count || 0),
            foot: `${number(data.byDirection.find((d) => d.direction === 'INBOUND')?.count || 0)} inbound`,
            accent: PALETTE[3],
          }),
          kpiCard({
            label: 'Connected calls',
            value: number(data.callOutcomes.find((o) => o.outcome === 'CONNECTED')?.count || 0),
            foot: `${number(data.callOutcomes.find((o) => o.outcome === 'NO_ANSWER')?.count || 0)} no answer`,
            accent: PALETTE[2],
          })),
        h('div', { class: 'chart-grid two' },
          panel('Channel mix', data.byChannel.length
            ? donutChart(data.byChannel.map((c) => ({ label: titleCase(c.channel), value: c.count })), { centerLabel: 'Interactions' })
            : emptyState({ title: 'No interactions logged' })),
          panel('Call outcomes', data.callOutcomes.length
            ? barList(data.callOutcomes.map((o) => ({ label: titleCase(o.outcome), value: o.count })))
            : emptyState({ title: 'No calls logged' }))));
    } catch (err) { reportError(err); }
  }

  table = dataTable({
    toolbar: filterHost,
    initialParams: state.filters,
    load: (params) => api.get('/api/activities', params),
    emptyTitle: 'No activity in this window',
    emptyMessage: 'Widen the date range or clear the type filter.',
    columns: [
      {
        label: 'Activity',
        render: (row) => h('div', { class: 'row', style: { gap: '10px' } },
          h('span', {
            style: {
              width: '28px', height: '28px', borderRadius: '8px', display: 'grid', placeItems: 'center',
              background: 'var(--surface-3)', color: 'var(--ink-2)', flex: '0 0 28px',
            },
          }, icon(ACTIVITY_ICONS[row.type] || 'info', { size: 14 })),
          h('div', { style: { minWidth: 0 } },
            h('div', { class: 'cell-main truncate' }, row.subject || titleCase(row.type)),
            row.body ? h('div', { class: 'cell-sub truncate' }, row.body) : null)),
      },
      { label: 'Type', render: (row) => h('span', { class: 'badge' }, titleCase(row.type)) },
      {
        label: 'Related to',
        render: (row) => {
          if (row.lead) return linkTo(`/leads/${row.lead.id}`, `${row.lead.firstName} ${row.lead.lastName || ''}`.trim());
          if (row.contact) return linkTo(`/contacts/${row.contact.id}`, `${row.contact.firstName} ${row.contact.lastName || ''}`.trim());
          return h('span', { class: 'muted' }, '—');
        },
      },
      {
        label: 'By',
        cellClass: 'hide-sm',
        render: (row) => (row.user
          ? h('div', { class: 'row', style: { gap: '7px' } },
            avatar(`${row.user.firstName} ${row.user.lastName || ''}`, { size: 'sm', url: row.user.avatarUrl }),
            h('span', { class: 'truncate' }, `${row.user.firstName} ${row.user.lastName || ''}`.trim()))
          : h('span', { class: 'badge' }, 'System')),
      },
      { label: 'Outcome', cellClass: 'hide-sm', render: (row) => (row.outcome ? h('span', { class: 'badge badge-info' }, titleCase(row.outcome)) : '—') },
      { label: 'Duration', cellClass: 'right hide-sm', render: (row) => (row.durationSec ? duration(row.durationSec) : '—') },
      { label: 'When', render: (row) => h('span', { class: 'small muted', title: dateTime(row.occurredAt) }, relative(row.occurredAt)) },
    ],
  });

  node.appendChild(table.root);
  await buildToolbar();
  loadSummary();
  return { node };
}
