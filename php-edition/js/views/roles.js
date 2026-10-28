/**
 * Roles & permissions.
 *
 * The permission matrix is generated from the server's catalogue, so the UI can
 * never offer a grant the API would reject. Data scope is chosen separately
 * because "what you can do" and "whose records you can do it to" are different
 * questions.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, drawer, field, input, textarea,
  select, confirmDialog, dropdown, menuItem, tabs, dataTable, searchBox, avatar,
} from '../lib/ui.js';
import { number, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

export default async function render() {
  if (!can('roles', 'read')) return { node: noAccess('roles and permissions') };

  const body = h('div', null, pageLoader('Loading roles'));
  let catalogue = null;

  const node = h('div', null,
    pageHead({
      title: 'Roles & Permissions',
      subtitle: 'What each role can do, and whose records they can see.',
      actions: can('roles', 'create')
        ? [h('button', { class: 'btn btn-primary', onClick: () => openRoleForm({ catalogue, onSaved: load }) }, icon('plus', { size: 15 }), 'New role')]
        : [],
    }),
    body);

  let tab = 'roles';

  async function load() {
    try {
      [catalogue] = await Promise.all([api.data('/api/roles/catalogue')]);
      const roles = await api.data('/api/roles');
      const tabBar = tabs([
        { key: 'roles', label: 'Roles', count: roles.length },
        { key: 'people', label: 'People & roles' },
      ], tab, (key) => { tab = key; load(); });

      mount(body, tabBar, tab === 'people'
        ? renderPeopleTab(roles, catalogue, load)
        : h('div', null,
          h('div', { class: 'alert info mb-16 mt-16' },
            icon('shield', { size: 16 }),
            h('div', null,
              h('strong', null, 'Permissions are enforced on the server'),
              'Hiding a button in the UI is a convenience. Every request is independently checked against the role, and data scope is applied to every query.')),
          h('div', { class: 'chart-grid half' }, ...roles.map((r) => renderRoleCard(r, () => { tab = 'people'; load(); })))));
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load roles', message: err.message })));
    }
  }

  function renderRoleCard(role, onShowPeople) {
    const grantedModules = Object.keys(role.permissions || {});
    const totalActions = Object.values(role.permissions || {}).reduce((sum, a) => sum + a.length, 0);
    const scopeLabel = catalogue.dataScopes.find((s) => s.key === role.dataScope)?.label || role.dataScope;

    return h('section', { class: 'card' },
      h('div', { class: 'card-head' },
        h('div', { style: { minWidth: 0 } },
          h('h3', { class: 'row', style: { gap: '8px' } },
            role.name,
            role.isSystem ? h('span', { class: 'badge' }, 'Built-in') : null,
            role.id === store.user.role?.id ? h('span', { class: 'badge badge-brand' }, 'Your role') : null),
          h('div', { class: 'sub' }, role.description || `Level ${role.level}`)),
        can('roles', 'update')
          ? dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'Role actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              menuItem('Edit', {
                iconName: 'edit', close,
                onClick: () => openRoleForm({ role, catalogue, onSaved: load }),
              }),
              menuItem('Duplicate', {
                iconName: 'copy', close,
                onClick: () => openRoleForm({
                  role: { ...role, id: null, name: `${role.name} (copy)`, isSystem: false },
                  catalogue,
                  onSaved: load,
                }),
              }),
              !role.isSystem && can('roles', 'delete')
                ? menuItem('Delete', {
                  iconName: 'trash', close, danger: true,
                  onClick: async () => {
                    const yes = await confirmDialog({
                      title: 'Delete this role?',
                      message: role.userCount > 0
                        ? `${role.userCount} user(s) still hold it - move them first.`
                        : `${role.name} will be removed.`,
                      danger: true, confirmLabel: 'Delete',
                    });
                    if (!yes) return;
                    try { await api.del(`/api/roles/${role.id}`); toast.success('Role deleted'); load(); } catch (err) { reportError(err); }
                  },
                })
                : null),
          )
          : null),
      h('div', { class: 'card-body' },
        h('div', { class: 'row wrap mb-16', style: { gap: '18px' } },
          h('div', null,
            h('div', { class: 'tiny muted' }, 'Users'),
            role.userCount > 0 && onShowPeople
              ? h('button', {
                class: 'btn btn-sm btn-ghost',
                style: { padding: '0', height: 'auto', fontWeight: '700' },
                onClick: onShowPeople,
                title: 'See who holds this role',
              }, number(role.userCount))
              : h('div', { class: 'strong' }, number(role.userCount))),
          h('div', null,
            h('div', { class: 'tiny muted' }, 'Data scope'),
            h('div', { class: 'strong' }, scopeLabel)),
          h('div', null,
            h('div', { class: 'tiny muted' }, 'Modules'),
            h('div', { class: 'strong' }, `${number(grantedModules.length)} · ${number(totalActions)} permissions`))),
        h('div', { class: 'row wrap', style: { gap: '5px' } },
          ...grantedModules.slice(0, 10).map((key) => {
            const mod = catalogue.modules.find((m) => m.key === key);
            return h('span', { class: 'badge', title: (role.permissions[key] || []).join(', ') }, mod?.label || key);
          }),
          grantedModules.length > 10 ? h('span', { class: 'badge' }, `+${grantedModules.length - 10}`) : null)));
  }

  await load();
  return { node };
}

/**
 * Who holds which role.
 *
 * The role cards answer "what can this role do"; this answers "and who is it?",
 * which is the question you actually have when someone changes job. The role
 * can be changed inline, because making people hop to the Users screen to do
 * one dropdown is how permissions end up stale.
 */
function renderPeopleTab(roles, catalogue, reload) {
  const host = h('div');
  const rolesById = Object.fromEntries(roles.map((r) => [r.id, r]));
  const scopeLabelFor = (role) => catalogue.dataScopes.find((s) => s.key === role?.dataScope)?.label || role?.dataScope || '—';

  let table;
  const roleFilter = select(
    [{ value: '', label: 'All roles' }, ...roles.map((r) => ({ value: r.id, label: `${r.name} (${r.userCount})` }))],
    { value: '', onChange: (e) => table.setParams({ roleId: e.target.value }) },
  );

  const changeRole = async (user, roleId, selectEl) => {
    const previous = user.roleId;
    selectEl.disabled = true;
    try {
      await api.patch(`/api/users/${user.id}`, { roleId });
      toast.success(
        `${user.fullName} is now ${rolesById[roleId]?.name}`,
        'Their permissions change the next time they load the app.',
      );
      reload();
    } catch (err) {
      selectEl.value = previous;
      selectEl.disabled = false;
      reportError(err);
    }
  };

  table = dataTable({
    toolbar: h('div', { class: 'row wrap grow', style: { gap: '10px' } },
      searchBox('Search people…', (v) => table.setParams({ q: v })),
      roleFilter),
    load: (params) => api.get('/api/users', { ...params, scoped: 'false' }),
    emptyTitle: 'Nobody here yet',
    emptyMessage: 'Invite users and they will appear with their role.',
    columns: [
      {
        label: 'Person',
        render: (row) => h('div', { class: 'row', style: { gap: '10px' } },
          avatar(row.fullName, { size: 'sm', url: row.avatarUrl }),
          h('div', { style: { minWidth: 0 } },
            h('div', { class: 'cell-main truncate' }, row.fullName),
            h('div', { class: 'cell-sub truncate' }, row.email))),
      },
      {
        label: 'Role',
        width: '210px',
        render: (row) => {
          if (!can('users', 'update')) {
            return h('span', { class: 'badge' }, rolesById[row.roleId]?.name || '—');
          }
          const sel = select(roles.map((r) => ({ value: r.id, label: r.name })), { value: row.roleId });
          sel.addEventListener('change', () => changeRole(row, sel.value, sel));
          return sel;
        },
      },
      {
        label: 'Sees',
        cellClass: 'hide-sm',
        render: (row) => h('span', { class: 'small muted' }, scopeLabelFor(rolesById[row.roleId])),
      },
      {
        label: 'Reports to',
        cellClass: 'hide-sm',
        render: (row) => h('span', { class: 'small' }, row.managerName || 'Nobody'),
      },
      {
        label: 'Status',
        render: (row) => h('span', { class: `badge ${row.isActive ? 'badge-success' : ''}` },
          row.isActive ? 'Active' : 'Inactive'),
      },
      {
        label: '',
        width: '150px',
        render: (row) => (can('users', 'manage')
          ? h('button', {
            class: 'btn btn-sm',
            onClick: async () => {
              const { openPermissionsDrawer } = await import('./userPermissions.js');
              openPermissionsDrawer({ user: row, onSaved: reload });
            },
          }, icon('shield', { size: 13 }), 'Special access')
          : null),
      },
    ],
  });

  mount(host,
    h('div', { class: 'alert info mb-16' },
      icon('users', { size: 16 }),
      h('div', null,
        h('strong', null, 'Change a role here and it applies everywhere. '),
        'The role decides what someone can do; "Sees" is how much of the company their role lets them read. '
        + 'Use Special access for one-off exceptions rather than inventing a new role.')),
    table.root);

  return host;
}

// ---------------------------------------------------------------------------
async function openRoleForm({ role = null, catalogue, onSaved } = {}) {
  const isEdit = Boolean(role?.id);
  const permissions = structuredClone(role?.permissions || {});

  const nameInput = input({ value: role?.name || '', placeholder: 'Inside Sales Rep' });
  const descInput = textarea({ value: role?.description || '', rows: 2, placeholder: 'What is this role for?' });
  const levelInput = input({ type: 'number', min: '0', max: '100', value: role?.level ?? 50 });
  const scopeSelect = select(
    catalogue.dataScopes.map((s) => ({ value: s.key, label: `${s.key.replace(/_/g, ' ')} — ${s.label}` })),
    { value: role?.dataScope || 'OWN' },
  );

  // Group the matrix by module group so it reads like the sidebar.
  const groups = new Map();
  for (const mod of catalogue.modules) {
    if (!groups.has(mod.group)) groups.set(mod.group, []);
    groups.get(mod.group).push(mod);
  }

  const matrix = h('div', { class: 'col', style: { gap: '16px' } },
    ...[...groups.entries()].map(([groupName, modules]) => h('div', null,
      h('div', { class: 'row-between mb-8' },
        h('h4', null, groupName),
        h('button', {
          class: 'btn btn-sm btn-ghost',
          onClick: (e) => {
            const box = e.currentTarget.closest('div').parentElement;
            const boxes = [...box.querySelectorAll('input[type="checkbox"]')];
            const allOn = boxes.every((b) => b.checked);
            boxes.forEach((b) => { b.checked = !allOn; b.dispatchEvent(new Event('change')); });
          },
        }, 'Toggle all')),
      h('div', { class: 'table-wrap' },
        h('table', { class: 'data' },
          h('thead', null, h('tr', null,
            h('th', null, 'Module'),
            ...['read', 'create', 'update', 'delete', 'export', 'assign', 'manage']
              .map((a) => h('th', { class: 'center' }, titleCase(a))))),
          h('tbody', null, ...modules.map((mod) => h('tr', null,
            h('td', { class: 'cell-main' }, mod.label),
            ...['read', 'create', 'update', 'delete', 'export', 'assign', 'manage'].map((action) => h('td', { class: 'center' },
              mod.actions.includes(action)
                ? h('input', {
                  type: 'checkbox',
                  checked: (permissions[mod.key] || []).includes(action),
                  'aria-label': `${mod.label} ${action}`,
                  onChange: (e) => {
                    const current = new Set(permissions[mod.key] || []);
                    if (e.target.checked) {
                      current.add(action);
                      // Any write implies read; otherwise the row is unusable.
                      if (action !== 'read' && mod.actions.includes('read')) current.add('read');
                    } else {
                      current.delete(action);
                      if (action === 'read') current.clear();
                    }
                    if (current.size) permissions[mod.key] = [...current];
                    else delete permissions[mod.key];
                    // Reflect implied grants back into the checkboxes.
                    const row = e.target.closest('tr');
                    row.querySelectorAll('input[type="checkbox"]').forEach((cb, i) => {
                      const act = ['read', 'create', 'update', 'delete', 'export', 'assign', 'manage']
                        .filter((a) => mod.actions.includes(a))[i];
                      if (act) cb.checked = (permissions[mod.key] || []).includes(act);
                    });
                  },
                })
                : h('span', { class: 'muted tiny' }, '—')))))))))));

  const errorHost = h('div');
  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save role' : 'Create role');

  const ref = drawer({
    title: isEdit ? `Edit ${role.name}` : 'New role',
    subtitle: 'Permissions decide the actions; data scope decides whose records.',
    wide: true,
    body: h('div', null,
      errorHost,
      role?.isSystem
        ? h('div', { class: 'alert warning mb-16' },
          icon('alert', { size: 16 }),
          h('div', null, 'This is a built-in role. Its permissions and scope are locked so the organisation always has a working administrator. Duplicate it to make a custom variant.'))
        : null,
      h('div', { class: 'form-grid' },
        field('Role name', nameInput, { required: true }),
        field('Authority level', levelInput, { hint: '0 is highest. You cannot create a role above your own.' })),
      field('Description', descInput),
      field('Data scope', scopeSelect, { hint: 'How much of the organisation this role can see.' }),
      h('h4', { class: 'mt-16 mb-8' }, 'Permissions'),
      matrix),
    footer: h('div', { class: 'row' },
      h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
      saveButton),
  });

  saveButton.addEventListener('click', async () => {
    clear(errorHost);
    if (!nameInput.value.trim()) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The role needs a name.'));
      return;
    }
    const payload = {
      name: nameInput.value.trim(),
      description: descInput.value.trim() || null,
      level: Number(levelInput.value) || 50,
      dataScope: scopeSelect.value,
      permissions,
    };
    saveButton.disabled = true;
    try {
      if (isEdit) await api.patch(`/api/roles/${role.id}`, payload);
      else await api.post('/api/roles', payload);
      toast.success(isEdit ? 'Role updated' : 'Role created');
      ref.close();
      onSaved?.();
    } catch (err) {
      saveButton.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
}
