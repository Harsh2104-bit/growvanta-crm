/** Team management: hierarchy, membership, territory and performance snapshot. */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, refreshReference } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, avatar, drawer, field, input, textarea,
  select, confirmDialog, dropdown, menuItem, kpiCard,
} from '../lib/ui.js';
import { money, number } from '../lib/format.js';
import { PALETTE } from '../lib/charts.js';
import { pageHead, noAccess, panel } from './shared.js';

export default async function render() {
  if (!can('teams', 'read') && !can('users', 'read')) return { node: noAccess('teams') };

  const body = h('div', null, pageLoader('Loading teams'));
  const node = h('div', null,
    pageHead({
      title: 'Teams',
      subtitle: 'Team structure drives lead routing and how much data each manager can see.',
      actions: can('teams', 'create')
        ? [h('button', { class: 'btn btn-primary', onClick: () => openTeamForm({ onSaved: load }) }, icon('plus', { size: 15 }), 'New team')]
        : [],
    }),
    body);

  async function load() {
    try {
      const tree = await api.data('/api/teams/tree');
      if (!tree.length) {
        mount(body, h('div', { class: 'card' }, emptyState({
          iconName: 'teams',
          title: 'No teams yet',
          message: 'Teams let you route leads by territory and give managers visibility over their people.',
          action: can('teams', 'create')
            ? h('button', { class: 'btn btn-primary', onClick: () => openTeamForm({ onSaved: load }) }, 'Create a team')
            : null,
        })));
        return;
      }
      mount(body, h('div', { class: 'col', style: { gap: '14px' } }, ...tree.map((team) => renderTeam(team, 0))));
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load teams', message: err.message })));
    }
  }

  function renderTeam(team, depth) {
    const territory = team.territory || {};
    const territoryLabel = [
      ...(territory.states || []), ...(territory.cities || []), ...(territory.countries || []),
    ].slice(0, 5).join(', ');

    return h('div', { style: { marginLeft: depth ? '28px' : '0' } },
      h('section', { class: 'card mb-8' },
        h('div', { class: 'card-head' },
          h('div', { class: 'row', style: { gap: '11px', minWidth: 0 } },
            h('span', {
              style: {
                width: '34px', height: '34px', borderRadius: '10px', background: 'var(--brand-50)',
                color: 'var(--brand-700)', display: 'grid', placeItems: 'center', flex: '0 0 34px',
              },
            }, icon('teams', { size: 17 })),
            h('div', { style: { minWidth: 0 } },
              h('h3', { class: 'truncate' }, team.name),
              h('div', { class: 'sub truncate' },
                [team.region ? `${team.region} region` : null,
                  team.manager ? `Managed by ${team.manager.firstName} ${team.manager.lastName || ''}`.trim() : 'No manager',
                  territoryLabel || null].filter(Boolean).join(' · ')))),
          h('div', { class: 'row', style: { gap: '8px' } },
            h('span', { class: 'badge' }, `${number(team.memberCount)} member${team.memberCount === 1 ? '' : 's'}`),
            h('span', { class: 'badge badge-brand' }, `${number(team.leadCount)} leads`),
            can('teams', 'update')
              ? dropdown(
                h('button', { class: 'icon-btn', 'aria-label': 'Team actions' }, icon('more', { size: 16 })),
                (close) => h('div', null,
                  menuItem('Edit team', { iconName: 'edit', close, onClick: () => openTeamForm({ team, onSaved: load }) }),
                  menuItem('View performance', {
                    iconName: 'trending', close,
                    onClick: () => showTeamStats(team),
                  }),
                  can('teams', 'create')
                    ? menuItem('Add sub-team', {
                      iconName: 'plus', close,
                      onClick: () => openTeamForm({ parentTeamId: team.id, onSaved: load }),
                    })
                    : null,
                  can('teams', 'delete')
                    ? menuItem('Delete team', {
                      iconName: 'trash', close, danger: true,
                      onClick: async () => {
                        const yes = await confirmDialog({
                          title: 'Delete this team?',
                          message: `${team.name}. Members stay, but leads routed to this team lose the link.`,
                          danger: true, confirmLabel: 'Delete',
                        });
                        if (!yes) return;
                        try {
                          await api.del(`/api/teams/${team.id}`);
                          toast.success('Team deleted');
                          refreshReference();
                          load();
                        } catch (err) { reportError(err); }
                      },
                    })
                    : null),
              )
              : null)),
        team.members.length
          ? h('div', { class: 'card-body' },
            h('div', { class: 'row wrap', style: { gap: '8px' } },
              ...team.members.map((m) => h('div', {
                class: 'row',
                style: {
                  gap: '8px', padding: '5px 10px 5px 5px', border: '1px solid var(--line)',
                  borderRadius: '20px', background: 'var(--surface-2)',
                },
              },
                avatar(`${m.user.firstName} ${m.user.lastName || ''}`, { size: 'sm', url: m.user.avatarUrl }),
                h('span', { class: 'small' }, `${m.user.firstName} ${m.user.lastName || ''}`.trim()),
                m.teamRole === 'LEADER' ? h('span', { class: 'badge badge-brand tiny' }, 'Lead') : null))))
          : h('div', { class: 'card-body' }, h('p', { class: 'muted small' }, 'No members yet.'))),
      ...(team.children || []).map((child) => renderTeam(child, depth + 1)));
  }

  async function showTeamStats(team) {
    const bodyEl = h('div', null, pageLoader());
    drawer({ title: `${team.name} performance`, body: bodyEl });
    try {
      const data = await api.data(`/api/teams/${team.id}`);
      mount(bodyEl,
        h('div', { class: 'kpi-grid mb-16' },
          kpiCard({ label: 'Leads', value: number(data.stats.leads), accent: PALETTE[0] }),
          kpiCard({ label: 'Converted', value: number(data.stats.converted), accent: PALETTE[2] }),
          kpiCard({ label: 'Revenue', value: money(data.stats.revenue, { compact: true }), accent: PALETTE[1] }),
          kpiCard({ label: 'Open tasks', value: number(data.stats.openTasks), accent: PALETTE[3] })),
        panel('Members', h('div', { class: 'col', style: { gap: '8px' } },
          ...data.members.map((m) => h('div', { class: 'row', style: { gap: '10px' } },
            avatar(`${m.user.firstName} ${m.user.lastName || ''}`, { size: 'sm' }),
            h('div', { class: 'grow' },
              h('div', { class: 'strong small' }, `${m.user.firstName} ${m.user.lastName || ''}`.trim()),
              h('div', { class: 'tiny muted' }, m.user.role?.name || '')),
            h('span', { class: 'badge' }, m.teamRole))))));
    } catch (err) {
      mount(bodyEl, emptyState({ iconName: 'alert', title: 'Could not load', message: err.message }));
    }
  }

  await load();
  return { node };
}

// ---------------------------------------------------------------------------
async function openTeamForm({ team = null, parentTeamId = null, onSaved } = {}) {
  const isEdit = Boolean(team?.id);
  const [teams, users] = await Promise.all([
    api.data('/api/teams').catch(() => []),
    api.data('/api/users/assignable').catch(() => []),
  ]);

  const nameInput = input({ value: team?.name || '', placeholder: 'North India Sales' });
  const descInput = textarea({ value: team?.description || '', rows: 2 });
  const regionInput = input({ value: team?.region || '', placeholder: 'North' });
  const parentSelect = select(
    teams.filter((t) => t.id !== team?.id).map((t) => ({ value: t.id, label: t.name })),
    { value: team?.parentTeamId || parentTeamId || '', placeholder: 'Top level' },
  );
  const managerSelect = select(users.map((u) => ({ value: u.id, label: u.fullName })), {
    value: team?.managerId || '', placeholder: 'No manager',
  });
  const statesInput = input({
    value: (team?.territory?.states || []).join(', '),
    placeholder: 'Haryana, Delhi, Punjab',
  });
  const citiesInput = input({
    value: (team?.territory?.cities || []).join(', '),
    placeholder: 'Gurugram, Noida',
  });
  const membersBox = h('div', {
    style: { maxHeight: '220px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '8px', padding: '8px' },
  }, ...users.map((u) => h('label', { class: 'checkbox', style: { display: 'flex', padding: '3px 0' } },
    h('input', {
      type: 'checkbox', value: u.id,
      checked: team?.members?.some((m) => m.user.id === u.id) || false,
    }), u.fullName)));

  const errorHost = h('div');
  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save team' : 'Create team');

  const ref = drawer({
    title: isEdit ? `Edit ${team.name}` : 'New team',
    subtitle: 'Territory feeds the assignment rules; membership feeds data visibility.',
    body: h('div', null,
      errorHost,
      field('Team name', nameInput, { required: true }),
      field('Description', descInput),
      h('div', { class: 'form-grid' },
        field('Parent team', parentSelect),
        field('Region', regionInput),
        field('Manager', managerSelect, { hint: 'Managers see everything their team can see.' })),
      h('div', { class: 'form-grid' },
        field('Territory states', statesInput, { hint: 'Comma separated.' }),
        field('Territory cities', citiesInput, { hint: 'Comma separated.' })),
      field('Members', membersBox)),
    footer: h('div', { class: 'row' },
      h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
      saveButton),
  });

  saveButton.addEventListener('click', async () => {
    clear(errorHost);
    if (!nameInput.value.trim()) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The team needs a name.'));
      return;
    }
    const payload = {
      name: nameInput.value.trim(),
      description: descInput.value.trim() || null,
      parentTeamId: parentSelect.value || null,
      region: regionInput.value.trim() || null,
      managerId: managerSelect.value || null,
      territory: {
        states: statesInput.value.split(',').map((s) => s.trim()).filter(Boolean),
        cities: citiesInput.value.split(',').map((s) => s.trim()).filter(Boolean),
        countries: [],
      },
      memberIds: [...membersBox.querySelectorAll('input:checked')].map((el) => el.value),
    };
    saveButton.disabled = true;
    try {
      const res = isEdit
        ? await api.patch(`/api/teams/${team.id}`, payload)
        : await api.post('/api/teams', payload);
      toast.success(isEdit ? 'Team saved' : 'Team created');
      refreshReference();
      ref.close();
      onSaved?.(res.data);
    } catch (err) {
      saveButton.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
}
