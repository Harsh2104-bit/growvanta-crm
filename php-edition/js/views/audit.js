/** Audit log: immutable, filterable, exportable. */
import { h, mount } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can } from '../lib/store.js';
import { dataTable, searchBox, toast, reportError, avatar, select, modal, input } from '../lib/ui.js';
import { dateTime, relative, titleCase, number } from '../lib/format.js';
import { pageHead, noAccess, filterBar } from './shared.js';

export default async function render({ query }) {
  if (!can('audit', 'read')) return { node: noAccess('the audit log') };

  const state = { filters: { action: query.action || '', userId: query.userId || '', q: query.q || '' } };
  const toolbarHost = h('div', { class: 'toolbar' });
  let table;

  const node = h('div', null,
    pageHead({
      title: 'Audit Log',
      subtitle: 'Who did what, when, and from where. Read-only by design.',
      actions: can('audit', 'export')
        ? [h('button', {
          class: 'btn',
          onClick: () => api.download('/api/audit-logs/export', state.filters)
            .then(() => toast.success('Audit log exported'))
            .catch(reportError),
        }, icon('download', { size: 15 }), 'Export')]
        : [],
    }));

  async function buildToolbar() {
    let actions = [];
    try { actions = await api.data('/api/audit-logs/actions'); } catch { actions = []; }
    const controls = await filterBar(['owner'], { ownerId: state.filters.userId }, (patch) => {
      state.filters.userId = patch.ownerId || '';
      table.setParams({ userId: state.filters.userId });
      buildToolbar();
    });
    mount(toolbarHost,
      searchBox('Search actions, records, people…', (v) => {
        state.filters.q = v;
        table.setParams({ q: v });
      }, { value: state.filters.q }),
      select(
        [{ value: '', label: 'All actions' }, ...actions.map((a) => ({ value: a.action, label: `${a.action} (${number(a.count)})` }))],
        {
          value: state.filters.action,
          onChange: (e) => { state.filters.action = e.target.value; table.setParams({ action: e.target.value }); },
        },
      ),
      ...controls);
  }

  table = dataTable({
    toolbar: toolbarHost,
    initialParams: state.filters,
    load: (params) => api.get('/api/audit-logs', params),
    emptyTitle: 'No audit entries',
    emptyMessage: 'Nothing matches these filters.',
    onRowClick: (row) => showEntry(row),
    columns: [
      {
        label: 'When',
        render: (row) => h('div', null,
          h('div', { class: 'cell-main' }, relative(row.createdAt)),
          h('div', { class: 'cell-sub' }, dateTime(row.createdAt))),
      },
      {
        label: 'Who',
        render: (row) => (row.user
          ? h('div', { class: 'row', style: { gap: '8px' } },
            avatar(`${row.user.firstName} ${row.user.lastName || ''}`, { size: 'sm', url: row.user.avatarUrl }),
            h('div', { style: { minWidth: 0 } },
              h('div', { class: 'truncate' }, `${row.user.firstName} ${row.user.lastName || ''}`.trim()),
              h('div', { class: 'cell-sub truncate' }, row.user.email)))
          : h('span', { class: 'badge' }, row.actorLabel || 'System')),
      },
      { label: 'Action', render: (row) => h('span', { class: 'badge badge-brand mono' }, row.action) },
      {
        label: 'Record',
        render: (row) => h('div', null,
          h('div', { class: 'truncate' }, row.entityLabel || '—'),
          row.entityType ? h('div', { class: 'cell-sub' }, titleCase(row.entityType)) : null),
      },
      {
        label: 'Changes',
        cellClass: 'hide-sm',
        render: (row) => {
          const keys = Object.keys(row.changes || {});
          if (!keys.length) return h('span', { class: 'muted' }, '—');
          return h('span', { class: 'small' }, `${keys.length} field${keys.length === 1 ? '' : 's'}: ${keys.slice(0, 3).join(', ')}`);
        },
      },
      { label: 'IP', cellClass: 'hide-sm', render: (row) => h('span', { class: 'mono tiny muted' }, row.ip || '—') },
    ],
  });

  node.appendChild(table.root);

  function showEntry(entry) {
    const changes = Object.entries(entry.changes || {});
    const dialog = modal({
      title: entry.action,
      subtitle: dateTime(entry.createdAt),
      size: 'wide',
      body: h('div', null,
        h('div', { class: 'info-list mb-16' },
          row('Actor', entry.actorLabel || 'System'),
          row('Action', h('span', { class: 'mono' }, entry.action)),
          row('Record', entry.entityLabel || '—'),
          row('Record type', entry.entityType ? titleCase(entry.entityType) : '—'),
          row('Record ID', entry.entityId ? h('span', { class: 'mono small' }, entry.entityId) : '—'),
          row('IP address', entry.ip || '—'),
          row('User agent', h('span', { class: 'tiny' }, entry.userAgent || '—'))),
        changes.length
          ? h('div', null,
            h('h4', { class: 'mb-8' }, 'Changes'),
            h('div', { class: 'table-wrap' },
              h('table', { class: 'data' },
                h('thead', null, h('tr', null, h('th', null, 'Field'), h('th', null, 'Before'), h('th', null, 'After'))),
                h('tbody', null, ...changes.map(([fieldName, change]) => h('tr', null,
                  h('td', { class: 'cell-main' }, titleCase(fieldName)),
                  h('td', { class: 'small muted' }, formatSide(change, 'from')),
                  h('td', { class: 'small' }, formatSide(change, 'to'))))))))
          : h('p', { class: 'muted' }, 'No field-level changes were recorded for this action.')),
      footer: h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
    });
  }

  const row = (label, value) => h('div', { class: 'info-row' },
    h('div', { class: 'i-label' }, label), h('div', { class: 'i-value' }, value));

  function formatSide(change, side) {
    if (change && typeof change === 'object' && side in change) {
      const v = change[side];
      if (v === null || v === undefined || v === '') return '(empty)';
      return typeof v === 'object' ? JSON.stringify(v) : String(v);
    }
    return typeof change === 'object' ? JSON.stringify(change) : String(change);
  }

  await buildToolbar();
  return { node };
}
