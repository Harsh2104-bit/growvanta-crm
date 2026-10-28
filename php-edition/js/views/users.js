/** User administration: list, invite, edit, deactivate, plus the org chart. */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store, refreshReference } from '../lib/store.js';
import {
  dataTable, searchBox, toast, reportError, avatar, dropdown, menuItem, confirmDialog,
  drawer, modal, field, input, select, switchToggle, emptyState, pageLoader, copyToClipboard, tabs,
} from '../lib/ui.js';
import { relative, date, number, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

export default async function render({ query }) {
  if (!can('users', 'read')) return { node: noAccess('users') };

  const state = { tab: query.tab === 'chart' ? 'chart' : 'list' };
  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div');
  let table = null;

  const node = h('div', null,
    pageHead({
      title: 'Users',
      subtitle: 'People, their roles and who they report to.',
      actions: can('users', 'create')
        ? [h('button', { class: 'btn btn-primary', onClick: () => openUserForm({ onSaved: refresh }) }, icon('plus', { size: 15 }), 'Invite user')]
        : [],
    }),
    tabHost, body);

  function buildTabs() {
    mount(tabHost, tabs([
      { key: 'list', label: 'All users' },
      { key: 'chart', label: 'Org chart' },
    ], state.tab, (key) => { state.tab = key; buildTabs(); draw(); }));
  }

  function refresh() {
    refreshReference();
    draw();
  }

  async function draw() {
    if (state.tab === 'chart') {
      mount(body, pageLoader());
      mount(body, await buildChart());
      return;
    }
    mount(body, buildTable());
  }

  function buildTable() {
    table = dataTable({
      toolbar: h('div', { class: 'row wrap grow', style: { gap: '10px' } },
        searchBox('Search users…', (v) => table.setParams({ q: v })),
        select([
          { value: '', label: 'Active and inactive' },
          { value: 'true', label: 'Active only' },
          { value: 'false', label: 'Deactivated only' },
        ], { onChange: (e) => table.setParams({ isActive: e.target.value }) })),
      load: (params) => api.get('/api/users', params),
      emptyTitle: 'No users found',
      columns: [
        {
          label: 'User',
          sortKey: 'firstName',
          render: (row) => h('div', { class: 'row', style: { gap: '10px' } },
            avatar(row.fullName, { url: row.avatarUrl }),
            h('div', { style: { minWidth: 0 } },
              h('div', { class: 'cell-main truncate' }, row.fullName,
                row.id === store.user.id ? h('span', { class: 'badge badge-brand', style: { marginLeft: '6px' } }, 'You') : null),
              h('div', { class: 'cell-sub truncate' }, row.email))),
        },
        {
          label: 'Role',
          render: (row) => h('div', null,
            h('span', { class: 'badge badge-brand' }, row.role?.name || '—'),
            h('div', { class: 'cell-sub' }, titleCase(row.role?.dataScope || ''))),
        },
        { label: 'Job title', cellClass: 'hide-sm', render: (row) => row.jobTitle || '—' },
        {
          label: 'Reports to',
          cellClass: 'hide-sm',
          render: (row) => (row.manager ? `${row.manager.firstName} ${row.manager.lastName || ''}`.trim() : h('span', { class: 'muted' }, '—')),
        },
        {
          label: 'Teams',
          cellClass: 'hide-sm',
          render: (row) => (row.teamMemberships?.length
            ? h('div', { class: 'row wrap', style: { gap: '4px' } },
              ...row.teamMemberships.slice(0, 2).map((m) => h('span', { class: 'badge' }, m.team.name)),
              row.teamMemberships.length > 2 ? h('span', { class: 'badge' }, `+${row.teamMemberships.length - 2}`) : null)
            : h('span', { class: 'muted' }, '—')),
        },
        {
          label: 'Status',
          render: (row) => h('div', null,
            h('span', { class: `badge ${row.isActive ? 'badge-success' : 'badge-danger'}` }, row.isActive ? 'Active' : 'Deactivated'),
            row.lastLoginAt ? h('div', { class: 'cell-sub' }, `Seen ${relative(row.lastLoginAt)}`) : h('div', { class: 'cell-sub' }, 'Never signed in')),
        },
        {
          label: '',
          width: '48px',
          render: (row) => dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'User actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              can('users', 'update')
                ? menuItem('Edit', { iconName: 'edit', close, onClick: () => openUserForm({ user: row, onSaved: refresh }) })
                : null,
              can('users', 'manage')
                ? menuItem('Special access', {
                  iconName: 'shield', close,
                  onClick: async () => {
                    const { openPermissionsDrawer } = await import('./userPermissions.js');
                    openPermissionsDrawer({ user: row, onSaved: refresh });
                  },
                })
                : null,
              can('users', 'manage')
                ? menuItem('Send password reset', {
                  iconName: 'key', close,
                  onClick: async () => {
                    try {
                      const res = await api.post(`/api/users/${row.id}/reset-password`);
                      showLink('Password reset link', res.data.resetUrl,
                        'Send this to the user. It expires in 24 hours and can be used once.');
                    } catch (err) { reportError(err); }
                  },
                })
                : null,
              // For the person standing at your desk who cannot open the reset
              // email. Your own password is deliberately absent - that goes
              // through Profile, which asks for the current one first.
              can('users', 'manage') && row.id !== store.user.id
                ? menuItem('Set a new password', {
                  iconName: 'shield', close,
                  onClick: () => openSetPassword(row),
                })
                : null,
              can('users', 'update') && row.id !== store.user.id
                ? menuItem(row.isActive ? 'Deactivate' : 'Reactivate', {
                  iconName: row.isActive ? 'close' : 'check', close,
                  onClick: async () => {
                    if (row.isActive) {
                      const yes = await confirmDialog({
                        title: `Deactivate ${row.fullName}?`,
                        message: 'They are signed out immediately and can no longer sign in. Their leads and history are kept.',
                        confirmLabel: 'Deactivate', danger: true,
                      });
                      if (!yes) return;
                    }
                    try {
                      await api.patch(`/api/users/${row.id}`, { isActive: !row.isActive });
                      toast.success(row.isActive ? 'User deactivated' : 'User reactivated');
                      refresh();
                    } catch (err) { reportError(err); }
                  },
                })
                : null),
          ),
        },
      ],
    });
    return table.root;
  }

  async function buildChart() {
    try {
      const roots = await api.data('/api/users/hierarchy');
      if (!roots.length) return h('div', { class: 'card' }, emptyState({ iconName: 'users', title: 'No reporting structure yet' }));
      return panel('Reporting structure',
        h('div', null, ...roots.map((root) => renderNode(root, 0))),
        { subtitle: 'Who reports to whom. Data visibility follows this tree plus team membership.' });
    } catch (err) {
      return h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load the org chart', message: err.message }));
    }
  }

  function renderNode(user, depth) {
    return h('div', { style: { marginLeft: depth ? '24px' : '0', borderLeft: depth ? '1px solid var(--line)' : 'none', paddingLeft: depth ? '14px' : '0' } },
      h('div', {
        class: 'row',
        style: {
          gap: '10px', padding: '9px 11px', border: '1px solid var(--line)',
          borderRadius: '10px', marginBottom: '8px', background: 'var(--surface)',
        },
      },
        avatar(user.fullName, { size: 'sm', url: user.avatarUrl }),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'strong truncate' }, user.fullName),
          h('div', { class: 'tiny muted truncate' }, user.jobTitle || user.role?.name)),
        h('span', { class: 'badge' }, user.role?.name || ''),
        user.children.length ? h('span', { class: 'badge' }, `${number(user.children.length)} report${user.children.length === 1 ? '' : 's'}`) : null),
      ...user.children.map((child) => renderNode(child, depth + 1)));
  }

  /**
   * Set someone's password outright.
   *
   * Every session that person has open is signed out by the server, because a
   * forced password change that leaves the old session working has not actually
   * taken the account back. The dialog says so before the button is pressed.
   */
  function openSetPassword(row) {
    const pw = input({ type: 'password', id: 'gv-setpw', autocomplete: 'new-password' });
    const confirm = input({ type: 'password', id: 'gv-setpw2', autocomplete: 'new-password' });
    const errorHost = h('div');

    const dialog = modal({
      title: `Set a new password for ${row.fullName || row.email}`,
      subtitle: 'They can sign in with it immediately. Tell it to them yourself — it is not emailed.',
      body: h('div', null,
        errorHost,
        field('New password', pw, { required: true, hint: 'At least 8 characters, with an uppercase letter and a number.' }),
        field('Repeat it', confirm, { required: true }),
        h('div', { class: 'alert warning mt-8' },
          'Everywhere this person is currently signed in will be signed out.')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            if (pw.value !== confirm.value) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Those two passwords do not match.'));
              confirm.focus();
              return;
            }
            if (!pw.value) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Enter a password.'));
              pw.focus();
              return;
            }
            try {
              const res = await api.post(`/api/users/${row.id}/set-password`, { password: pw.value });
              dialog.close();
              toast.success(res.data.message || 'Password updated');
            } catch (err) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
            }
          },
        }, 'Set password')),
    });
  }

  function showLink(title, url, message) {
    const dialog = modal({
      title,
      subtitle: message,
      body: h('div', null,
        h('div', { class: 'code-block', style: { wordBreak: 'break-all', whiteSpace: 'pre-wrap' } }, url)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
        h('button', { class: 'btn btn-primary', onClick: () => copyToClipboard(url, 'Link copied') }, icon('copy', { size: 15 }), 'Copy link')),
    });
  }

  buildTabs();
  await draw();
  return { node };
}

// ---------------------------------------------------------------------------
async function openUserForm({ user = null, onSaved } = {}) {
  const isEdit = Boolean(user?.id);
  const [roles, teams, users] = await Promise.all([
    api.data('/api/roles').catch(() => []),
    api.data('/api/teams').catch(() => []),
    api.data('/api/users/assignable').catch(() => []),
  ]);

  const controls = {};
  const mk = (name, props = {}) => {
    const el = input({ value: user?.[name] ?? '', ...props });
    controls[name] = el;
    return el;
  };

  const roleSelect = select(roles.map((r) => ({ value: r.id, label: `${r.name} · ${titleCase(r.dataScope)}` })), {
    value: user?.role?.id || roles.find((r) => r.key === 'sales_executive')?.id || '',
  });
  const managerSelect = select(users.map((u) => ({ value: u.id, label: u.fullName })), {
    value: user?.managerId || '', placeholder: 'No manager',
  });
  const teamsBox = h('div', {
    style: { maxHeight: '170px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '8px', padding: '8px' },
  }, ...teams.map((t) => h('label', { class: 'checkbox', style: { display: 'flex', padding: '3px 0' } },
    h('input', {
      type: 'checkbox', value: t.id,
      checked: user?.teamMemberships?.some((m) => m.team.id === t.id) || false,
    }), t.name)));

  let sendInvite = !isEdit;
  const passwordInput = input({ type: 'password', placeholder: 'Leave blank to send an invitation instead', autocomplete: 'new-password' });
  const passwordField = h('div', { class: isEdit ? 'hidden' : '' }, field('Initial password', passwordInput, {
    hint: 'Optional. If blank, an invitation link is generated for you to share.',
  }));

  /**
   * Name the company the new user will belong to.
   *
   * A platform admin standing at platform level creates a PLATFORM user, not
   * one inside a client - the account then never appears in that client's list
   * and the whole thing looks like it silently failed. Saying which company is
   * about to gain a user is the cheapest way to stop that.
   */
  function inviteSubtitle() {
    const org = store.organization?.name;
    if (!org) {
      return 'They join your organisation with the role you pick.';
    }
    if (store.user?.isPlatformAdmin && !store.user?.isImpersonating) {
      return `They join ${org} — the platform company itself. To add someone to a client, open that client first.`;
    }
    return `They join ${org} with the role you pick.`;
  }

  const errorHost = h('div');
  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save changes' : 'Create user');

  const ref = drawer({
    title: isEdit ? `Edit ${user.fullName}` : 'Invite a user',
    subtitle: isEdit ? user.email : inviteSubtitle(),
    body: h('div', null,
      errorHost,
      h('div', { class: 'form-grid' },
        field('First name', mk('firstName'), { required: true }),
        field('Last name', mk('lastName')),
        field('Email', mk('email', { type: 'email' }), { required: true }),
        field('Phone', mk('phone', { type: 'tel' })),
        field('Job title', mk('jobTitle')),
        field('Region', mk('region', { placeholder: 'North / South / West' }))),
      field('Role', roleSelect, { required: true, hint: 'The role decides both permissions and how much data they can see.' }),
      field('Reports to', managerSelect, { hint: 'Managers see everything their reports can see.' }),
      field('Teams', teamsBox),
      passwordField,
      isEdit ? null : h('div', { class: 'alert info' },
        icon('info', { size: 16 }),
        h('div', null, 'You can only grant a role at or below your own authority, and never a wider data scope than your own.'))),
    footer: h('div', { class: 'row' },
      h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
      saveButton),
  });

  saveButton.addEventListener('click', async () => {
    clear(errorHost);
    const teamIds = [...teamsBox.querySelectorAll('input:checked')].map((el) => el.value);
    const payload = {
      firstName: controls.firstName.value.trim(),
      lastName: controls.lastName.value.trim() || null,
      email: controls.email.value.trim(),
      phone: controls.phone.value.trim() || null,
      jobTitle: controls.jobTitle.value.trim() || null,
      region: controls.region.value.trim() || null,
      roleId: roleSelect.value,
      managerId: managerSelect.value || null,
      teamIds,
    };
    if (!isEdit && passwordInput.value) payload.password = passwordInput.value;

    if (!payload.firstName || !payload.email) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'First name and email are required.'));
      return;
    }

    saveButton.disabled = true;
    try {
      const res = isEdit
        ? await api.patch(`/api/users/${user.id}`, payload)
        : await api.post('/api/users', payload);
      ref.close();
      onSaved?.(res.data);
      if (!isEdit && res.data.inviteUrl) {
        const dialog = modal({
          title: 'User created',
          subtitle: 'Email is not configured, so share this invitation link directly. It expires in 7 days.',
          body: h('div', { class: 'code-block', style: { wordBreak: 'break-all', whiteSpace: 'pre-wrap' } }, res.data.inviteUrl),
          footer: h('div', { class: 'row' },
            h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
            h('button', {
              class: 'btn btn-primary',
              onClick: () => copyToClipboard(res.data.inviteUrl, 'Invitation link copied'),
            }, 'Copy link')),
        });
      } else {
        toast.success(isEdit ? 'User updated' : 'User created');
      }
    } catch (err) {
      saveButton.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
}
