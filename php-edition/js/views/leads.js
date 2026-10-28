/**
 * Leads: a server-paginated table and a drag-and-drop Kanban board over the same
 * filter set, plus saved views and bulk actions.
 *
 * Filters live in the URL hash, so any view a user builds is a shareable link
 * and survives a refresh.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, reference } from '../lib/store.js';
import {
  dataTable, searchBox, toast, reportError, emptyState, statusBadge, avatar,
  dropdown, menuItem, confirmDialog, modal, select, input, field, pageLoader,
} from '../lib/ui.js';
import { money, relative, date, number, titleCase } from '../lib/format.js';
import { pageHead, filterBar, ownerCell, scoreCell, priorityBadge, leadNameCell, tagList, noAccess } from './shared.js';
import { navigate, parseHash } from '../router.js';
import { openLeadForm } from './leadForm.js';

export default async function render({ query }) {
  if (!can('leads', 'read')) return { node: noAccess('leads') };

  const state = {
    view: query.view === 'board' ? 'board' : 'list',
    filters: stripControlKeys(query),
  };

  const filterHost = h('div', { class: 'toolbar' });
  const viewHost = h('div');
  const savedViewHost = h('div', { class: 'row wrap chip-strip', style: { gap: '6px' } });

  const node = h('div', null,
    pageHead({
      title: 'Leads',
      subtitle: 'Every prospect in your scope, from first touch to conversion.',
      actions: [
        h('div', { class: 'btn-group' },
          h('button', {
            class: state.view === 'list' ? 'active' : '',
            onClick: () => switchView('list'),
          }, 'List'),
          h('button', {
            class: state.view === 'board' ? 'active' : '',
            onClick: () => switchView('board'),
          }, 'Board')),
        can('leads', 'export')
          ? h('button', {
            class: 'btn',
            onClick: () => api.download('/api/leads/export', state.filters)
              .then(() => toast.success('Export downloaded'))
              .catch(reportError),
          }, icon('download', { size: 15 }), 'Export')
          : null,
        can('imports', 'create')
          ? h('button', { class: 'btn', onClick: () => navigate('/imports') }, icon('import', { size: 15 }), 'Import')
          : null,
        can('leads', 'create')
          ? h('button', {
            class: 'btn btn-primary',
            onClick: () => openLeadForm({ onSaved: refreshCurrent }),
          }, icon('plus', { size: 15 }), 'New lead')
          : null,
      ].filter(Boolean),
    }),
    savedViewHost,
    h('div', { class: 'mt-16' }, viewHost));

  let table = null;

  function stripControlKeys(source) {
    const out = { ...source };
    delete out.view;
    delete out.page;
    return out;
  }

  function syncHash() {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(state.filters)) if (value) params.set(key, value);
    if (state.view === 'board') params.set('view', 'board');
    window.history.replaceState(null, '', `#/leads${params.toString() ? `?${params}` : ''}`);
  }

  function switchView(view) {
    if (state.view === view) return;
    state.view = view;
    syncHash();
    node.querySelectorAll('.btn-group button').forEach((b, i) => {
      b.classList.toggle('active', (i === 0) === (view === 'list'));
    });
    renderView();
  }

  const applyFilters = async (patch) => {
    Object.assign(state.filters, patch);
    for (const [key, value] of Object.entries(state.filters)) if (!value) delete state.filters[key];
    syncHash();
    await buildToolbar();
    if (state.view === 'list' && table) table.setParams({ ...emptyFilterPatch(), ...state.filters });
    else renderView();
  };

  // Clearing a filter must actively remove it from the table's params.
  const FILTER_KEYS = ['q', 'statusId', 'sourceId', 'projectId', 'ownerId', 'teamId', 'priority', 'scoreBand', 'range', 'followUp', 'unassigned', 'noProject', 'converted', 'tag'];
  const emptyFilterPatch = () => Object.fromEntries(FILTER_KEYS.map((k) => [k, '']));

  async function buildToolbar() {
    const controls = await filterBar(['range', 'project', 'status', 'source', 'owner', 'team', 'priority', 'scoreBand'], state.filters, applyFilters);
    mount(filterHost,
      searchBox('Search name, email, phone, company…', (value) => applyFilters({ q: value }), { value: state.filters.q || '' }),
      ...controls);
    await buildSavedViews();
  }

  async function buildSavedViews() {
    const quick = [
      { label: 'All', filters: {} },
      { label: 'My leads', filters: { ownerId: 'me' } },
      { label: 'Unassigned', filters: { unassigned: 'true' } },
      // Enquiries with no project attached - from an older form, an import or
      // Meta. Typing one in by hand now requires a project, so this list only
      // ever shrinks.
      { label: 'No project', filters: { noProject: 'true' } },
      { label: 'Follow-up today', filters: { followUp: 'today' } },
      { label: 'Overdue', filters: { followUp: 'overdue' } },
      { label: 'Hot & very hot', filters: { scoreBand: 'HOT,VERY_HOT' } },
      { label: 'Converted', filters: { converted: 'true' } },
    ];
    let saved = [];
    try { saved = await api.data('/api/saved-views', { entity: 'LEAD' }); } catch { saved = []; }

    mount(savedViewHost,
      ...quick.map((view) => h('button', {
        class: `btn btn-sm ${matchesFilters(view.filters) ? 'btn-primary' : ''}`,
        onClick: async () => {
          const next = { ...view.filters };
          if (next.ownerId === 'me') {
            const me = (await import('../lib/store.js')).store.user.id;
            next.ownerId = me;
          }
          state.filters = next;
          syncHash();
          await buildToolbar();
          if (state.view === 'list' && table) table.setParams({ ...emptyFilterPatch(), ...state.filters });
          else renderView();
        },
      }, view.label)),
      ...saved.map((view) => h('button', {
        class: 'btn btn-sm',
        title: view.owned ? 'Your saved view' : 'Shared view',
        onClick: async () => {
          state.filters = { ...view.filters };
          syncHash();
          await buildToolbar();
          if (state.view === 'list' && table) table.setParams({ ...emptyFilterPatch(), ...state.filters });
          else renderView();
        },
      }, icon('star', { size: 12 }), view.name)),
      Object.keys(state.filters).length
        ? h('button', { class: 'btn btn-sm btn-ghost', onClick: saveCurrentView }, icon('save', { size: 13 }), 'Save view')
        : null);
  }

  function matchesFilters(candidate) {
    const keys = Object.keys(candidate);
    if (!keys.length) return !Object.keys(state.filters).length;
    return keys.every((k) => String(state.filters[k] || '') === String(candidate[k]));
  }

  async function saveCurrentView() {
    const nameInput = input({ placeholder: 'My hot leads' });
    const sharedInput = h('input', { type: 'checkbox' });
    const ref = modal({
      title: 'Save this view',
      subtitle: 'Filters, not results - the view stays live.',
      body: h('div', null,
        field('View name', nameInput, { required: true }),
        h('label', { class: 'checkbox' }, sharedInput, 'Share with everyone in the organisation')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            if (!nameInput.value.trim()) { nameInput.focus(); return; }
            try {
              await api.post('/api/saved-views', {
                entity: 'LEAD',
                name: nameInput.value.trim(),
                filters: state.filters,
                isShared: sharedInput.checked,
              });
              toast.success('View saved');
              ref.close();
              buildSavedViews();
            } catch (err) { reportError(err); }
          },
        }, 'Save')),
    });
  }

  function refreshCurrent() {
    if (state.view === 'list' && table) table.refresh();
    else renderView();
  }

  // -------------------------------------------------------------------------
  // List view
  // -------------------------------------------------------------------------
  function buildTable() {
    table = dataTable({
      toolbar: filterHost,
      initialParams: state.filters,
      defaultSort: 'createdAt',
      selectable: can('leads', 'update') || can('leads', 'assign') || can('leads', 'delete'),
      onRowClick: (row) => navigate(`/leads/${row.id}`),
      load: (params) => api.get('/api/leads', params),
      emptyTitle: 'No leads match these filters',
      emptyMessage: 'Try widening the date range or clearing a filter.',
      emptyAction: can('leads', 'create')
        ? h('button', { class: 'btn btn-primary', onClick: () => openLeadForm({ onSaved: refreshCurrent }) }, 'Add a lead')
        : null,
      columns: [
        { label: 'Lead', sortKey: 'firstName', render: leadNameCell },
        {
          label: 'Contact',
          render: (row) => h('div', null,
            h('div', { class: 'truncate' }, row.email || '—'),
            h('div', { class: 'cell-sub' }, row.phone || '')),
        },
        {
          label: 'Project',
          cellClass: 'hide-sm',
          render: (row) => (row.projectName
            ? h('div', null,
              h('div', { class: 'truncate' }, row.projectName),
              row.configuration ? h('div', { class: 'cell-sub' }, row.configuration) : null)
            : h('span', { class: 'muted' }, '—')),
        },
        { label: 'Status', render: (row) => statusBadge(row.status?.name, row.status?.color) },
        { label: 'Score', sortKey: 'score', render: (row) => scoreCell(row.score, row.scoreBand) },
        { label: 'Source', cellClass: 'hide-sm', render: (row) => h('span', { class: 'small' }, row.source?.name || '—') },
        { label: 'Owner', render: (row) => ownerCell(row.owner) },
        {
          label: 'Value',
          sortKey: 'expectedValue',
          cellClass: 'right hide-sm',
          render: (row) => (row.expectedValue ? money(row.expectedValue, { compact: true }) : '—'),
        },
        {
          label: 'Follow-up',
          sortKey: 'nextFollowUpAt',
          cellClass: 'hide-sm',
          render: (row) => {
            if (!row.nextFollowUpAt) return h('span', { class: 'muted' }, '—');
            const overdue = new Date(row.nextFollowUpAt) < new Date();
            return h('span', { class: overdue ? 'badge badge-danger' : 'badge' }, relative(row.nextFollowUpAt));
          },
        },
        { label: 'Created', sortKey: 'createdAt', cellClass: 'hide-sm', render: (row) => h('span', { class: 'small muted' }, date(row.createdAt)) },
        {
          label: '',
          width: '48px',
          render: (row) => dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'Row actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              menuItem('Open', { iconName: 'eye', close, onClick: () => navigate(`/leads/${row.id}`) }),
              can('leads', 'update')
                ? menuItem('Edit', { iconName: 'edit', close, onClick: () => openLeadForm({ lead: row, onSaved: refreshCurrent }) })
                : null,
              can('leads', 'delete')
                ? menuItem('Delete', {
                  iconName: 'trash', close, danger: true,
                  onClick: async () => {
                    const yes = await confirmDialog({
                      title: 'Delete this lead?',
                      message: `${row.fullName} and its entire timeline will be removed. This cannot be undone.`,
                      confirmLabel: 'Delete lead',
                      danger: true,
                    });
                    if (!yes) return;
                    try {
                      await api.del(`/api/leads/${row.id}`);
                      toast.success('Lead deleted');
                      table.refresh();
                    } catch (err) { reportError(err); }
                  },
                })
                : null),
          ),
        },
      ],
      bulkActions: (ids, ctx) => h('div', { class: 'row wrap', style: { gap: '6px' } },
        can('leads', 'assign')
          ? h('button', { class: 'btn btn-sm', onClick: () => bulkAssign(ids, ctx) }, icon('user', { size: 14 }), 'Assign')
          : null,
        can('leads', 'change_status')
          ? h('button', { class: 'btn btn-sm', onClick: () => bulkStatus(ids, ctx) }, icon('refresh', { size: 14 }), 'Change status')
          : null,
        can('leads', 'update')
          ? h('button', { class: 'btn btn-sm', onClick: () => bulkTag(ids, ctx) }, icon('star', { size: 14 }), 'Tag')
          : null,
        can('tasks', 'create')
          ? h('button', { class: 'btn btn-sm', onClick: () => bulkTask(ids, ctx) }, icon('tasks', { size: 14 }), 'Create tasks')
          : null,
        can('leads', 'delete')
          ? h('button', { class: 'btn btn-sm btn-danger', onClick: () => bulkDelete(ids, ctx) }, icon('trash', { size: 14 }), 'Delete')
          : null),
    });
    return table.root;
  }

  async function bulkAssign(ids, ctx) {
    const ref = await reference();
    const userSelect = select(ref.users.map((u) => ({ value: u.id, label: u.fullName || u.name })), { placeholder: 'Choose a user' });
    const teamSelect = select(ref.teams.map((t) => ({ value: t.id, label: t.name })), { placeholder: 'Or a team' });
    const dialog = modal({
      title: `Assign ${ids.length} lead${ids.length === 1 ? '' : 's'}`,
      body: h('div', null,
        field('Assign to user', userSelect),
        field('Or route to team', teamSelect, { hint: 'Choose one. Team routing leaves the owner open for the team to pick up.' })),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            if (!userSelect.value && !teamSelect.value) { toast.warn('Choose a user or a team'); return; }
            try {
              const res = await api.post('/api/leads/bulk/assign', {
                leadIds: ids,
                ownerId: userSelect.value || undefined,
                teamId: teamSelect.value || undefined,
              });
              toast.success(`${res.data.updated} lead(s) assigned`);
              dialog.close();
              ctx.clear();
              table.refresh();
            } catch (err) { reportError(err); }
          },
        }, 'Assign')),
    });
  }

  async function bulkStatus(ids, ctx) {
    const ref = await reference();
    const statusSelect = select(ref.statuses.map((s) => ({ value: s.id, label: s.name })), { placeholder: 'Choose a status' });
    const reasonInput = input({ placeholder: 'Optional reason (used when marking lost)' });
    const dialog = modal({
      title: `Change status for ${ids.length} lead${ids.length === 1 ? '' : 's'}`,
      body: h('div', null, field('New status', statusSelect, { required: true }), field('Reason', reasonInput)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            if (!statusSelect.value) { toast.warn('Pick a status'); return; }
            try {
              const res = await api.post('/api/leads/bulk/status', {
                leadIds: ids, statusId: statusSelect.value, lostReason: reasonInput.value || undefined,
              });
              toast.success(`${res.data.updated} lead(s) updated`);
              dialog.close();
              ctx.clear();
              table.refresh();
            } catch (err) { reportError(err); }
          },
        }, 'Update')),
    });
  }

  function bulkTag(ids, ctx) {
    const tagsInput = input({ placeholder: 'enterprise, high-intent' });
    const dialog = modal({
      title: `Tag ${ids.length} lead${ids.length === 1 ? '' : 's'}`,
      body: field('Tags', tagsInput, { hint: 'Comma separated. Existing tags are kept.' }),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            const tags = tagsInput.value.split(',').map((t) => t.trim()).filter(Boolean);
            if (!tags.length) { toast.warn('Enter at least one tag'); return; }
            try {
              await api.post('/api/leads/bulk/tag', { leadIds: ids, tags });
              toast.success('Tags applied');
              dialog.close();
              ctx.clear();
              table.refresh();
            } catch (err) { reportError(err); }
          },
        }, 'Apply')),
    });
  }

  function bulkTask(ids, ctx) {
    const titleInput = input({ placeholder: 'Call and qualify' });
    const daysInput = input({ type: 'number', value: '1', min: '0', max: '90' });
    const typeSelect = select(['CALL', 'EMAIL', 'MEETING', 'FOLLOW_UP', 'TASK'].map((t) => ({ value: t, label: titleCase(t) })), { value: 'CALL' });
    const prioritySelect = select(['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => ({ value: p, label: titleCase(p) })), { value: 'MEDIUM' });
    const dialog = modal({
      title: `Create a task on ${ids.length} lead${ids.length === 1 ? '' : 's'}`,
      body: h('div', null,
        field('Task title', titleInput, { required: true }),
        h('div', { class: 'form-grid' },
          field('Type', typeSelect),
          field('Priority', prioritySelect),
          field('Due in (days)', daysInput))),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            if (!titleInput.value.trim()) { titleInput.focus(); return; }
            try {
              const res = await api.post('/api/leads/bulk/tasks', {
                leadIds: ids,
                title: titleInput.value.trim(),
                type: typeSelect.value,
                priority: prioritySelect.value,
                dueInDays: Number(daysInput.value) || 1,
              });
              toast.success(`${res.data.created} task(s) created`);
              dialog.close();
              ctx.clear();
              window.dispatchEvent(new CustomEvent('gv:data-changed'));
            } catch (err) { reportError(err); }
          },
        }, 'Create tasks')),
    });
  }

  async function bulkDelete(ids, ctx) {
    const yes = await confirmDialog({
      title: `Delete ${ids.length} lead${ids.length === 1 ? '' : 's'}?`,
      message: 'Their activities, tasks and documents go with them. This cannot be undone.',
      confirmLabel: 'Delete permanently',
      danger: true,
      requireText: 'DELETE',
    });
    if (!yes) return;
    try {
      const res = await api.post('/api/leads/bulk/delete', { leadIds: ids });
      toast.success(`${res.data.deleted} lead(s) deleted`);
      ctx.clear();
      table.refresh();
    } catch (err) { reportError(err); }
  }

  // -------------------------------------------------------------------------
  // Board view
  // -------------------------------------------------------------------------
  async function buildBoard() {
    const boardHost = h('div', null, pageLoader('Loading board'));
    const wrapper = h('div', null, h('div', { class: 'card mb-16' }, filterHost), boardHost);

    async function loadBoard() {
      try {
        const columns = await api.data('/api/leads/board', state.filters);
        if (!columns.length) {
          mount(boardHost, h('div', { class: 'card' }, emptyState({ title: 'No statuses configured' })));
          return;
        }
        mount(boardHost, h('div', { class: 'kanban' }, ...columns.map(renderColumn)));
      } catch (err) {
        reportError(err);
        mount(boardHost, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load the board', message: err.message })));
      }
    }

    function renderColumn(column) {
      const bodyEl = h('div', { class: 'kanban-col-body' },
        ...column.items.map(renderCard),
        column.total > column.items.length
          ? h('div', { class: 'muted tiny center', style: { padding: '6px' } },
            `+${column.total - column.items.length} more`)
          : null);

      const col = h('div', {
        class: 'kanban-col',
        dataset: { statusId: column.status.id },
        onDragover: (e) => { e.preventDefault(); col.classList.add('drag-over'); },
        onDragleave: () => col.classList.remove('drag-over'),
        onDrop: async (e) => {
          e.preventDefault();
          col.classList.remove('drag-over');
          const leadId = e.dataTransfer.getData('text/plain');
          if (!leadId) return;
          if (!can('leads', 'change_status')) { toast.warn('You do not have permission to change lead status.'); return; }
          try {
            await api.patch(`/api/leads/${leadId}`, { statusId: column.status.id });
            toast.success(`Moved to ${column.status.name}`);
            loadBoard();
          } catch (err) { reportError(err); }
        },
      },
        h('div', { class: 'kanban-col-head' },
          h('span', { class: 'k-dot', style: { background: column.status.color } }),
          h('span', { class: 'k-name' }, column.status.name),
          h('span', { class: 'k-count' }, number(column.total))),
        bodyEl);
      return col;
    }

    function renderCard(lead) {
      const card = h('div', {
        class: 'kanban-card',
        draggable: can('leads', 'update') ? 'true' : 'false',
        onDragstart: (e) => {
          e.dataTransfer.setData('text/plain', lead.id);
          e.dataTransfer.effectAllowed = 'move';
          card.classList.add('dragging');
        },
        onDragend: () => card.classList.remove('dragging'),
        onClick: () => navigate(`/leads/${lead.id}`),
      },
        h('div', { class: 'kc-title truncate' }, lead.fullName),
        h('div', { class: 'kc-sub truncate' }, [lead.company, lead.city].filter(Boolean).join(' · ') || lead.email || lead.phone || ''),
        lead.tags?.length ? h('div', { class: 'mb-8' }, tagList(lead.tags)) : null,
        h('div', { class: 'kc-foot' },
          h('div', { class: 'row', style: { gap: '6px' } },
            scoreCell(lead.score, lead.scoreBand)),
          lead.owner
            ? avatar(`${lead.owner.firstName} ${lead.owner.lastName || ''}`, { size: 'sm', url: lead.owner.avatarUrl })
            : h('span', { class: 'badge' }, 'Open')),
        lead.expectedValue
          ? h('div', { class: 'tiny muted mt-8' }, money(lead.expectedValue, { compact: true }))
          : null);
      return card;
    }

    await loadBoard();
    return wrapper;
  }

  async function renderView() {
    mount(viewHost, pageLoader());
    if (state.view === 'board') {
      mount(viewHost, await buildBoard());
    } else {
      mount(viewHost, buildTable());
    }
  }

  await buildToolbar();
  await renderView();

  return {
    node,
    onQuery: (q) => {
      const next = stripControlKeys(q);
      if (JSON.stringify(next) === JSON.stringify(state.filters)) return;
      state.filters = next;
      buildToolbar();
      if (state.view === 'list' && table) table.setParams({ ...emptyFilterPatch(), ...state.filters });
      else renderView();
    },
  };
}
