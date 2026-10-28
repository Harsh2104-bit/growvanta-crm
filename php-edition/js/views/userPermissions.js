/**
 * Per-user special access (spec sections 11 and 12).
 *
 * Roles decide what a job title can do. This screen handles the exceptions
 * every real sales floor has: the executive covering for a manager on leave who
 * needs export for a fortnight, or the rep whose lead creation is paused during
 * a data-quality review.
 *
 * Each row shows the three values the spec asks for side by side -
 *
 *     inherited from the role  +  special override  =  effective
 *
 * - so the person granting access can see exactly what they are changing and
 * what it was before.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { drawer, toast, reportError, select, input, pageLoader } from '../lib/ui.js';
import { titleCase } from '../lib/format.js';

const EFFECT_NONE = '';

export async function openPermissionsDrawer({ user, onSaved } = {}) {
  const body = h('div', null, pageLoader('Loading permissions'));
  const saveButton = h('button', { class: 'btn btn-primary' }, 'Save special access');
  saveButton.disabled = true;

  const ref_ = drawer({
    title: 'Special access',
    subtitle: `${user.fullName || user.email} — overrides on top of their role`,
    wide: true,
    body,
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      saveButton),
  });

  let data;
  try {
    data = await api.data(`/api/users/${user.id}/permissions`);
  } catch (err) {
    mount(body, h('div', { class: 'alert danger' }, err.message));
    return ref_;
  }

  // Working copy, keyed "module:action".
  const overrides = new Map();
  for (const s of data.special || []) {
    overrides.set(`${s.module}:${s.action}`, {
      effect: s.effect, dataScope: s.dataScope || '', reason: s.reason || '',
    });
  }

  const scopeOptions = [
    { value: '', label: 'Same as role' },
    ...data.dataScopes.map((s) => ({ value: s.key, label: s.label })),
  ];

  const groups = {};
  for (const mod of data.catalogue) {
    (groups[mod.group] ||= []).push(mod);
  }

  const reasonInput = input({
    placeholder: 'Why does this person need the exception?',
    value: (data.special || [])[0]?.reason || '',
  });

  clear(body);
  body.appendChild(h('div', { class: 'alert info mb-16' },
    h('strong', null, `Role: ${data.role.name}. `),
    `Everything below is inherited from that role unless you override it here. `
    + `Their role sees ${(data.dataScopes.find((s) => s.key === data.role.dataScope) || {}).label?.toLowerCase() || 'their own records'}.`));

  const rowsHost = h('div');
  body.appendChild(rowsHost);

  const renderRows = () => {
    clear(rowsHost);
    for (const [groupName, modules] of Object.entries(groups)) {
      // Platform modules are not a tenant's to grant; the server refuses them
      // anyway, so showing them here would only be misleading.
      if (groupName === 'Platform') continue;

      rowsHost.appendChild(h('h4', { class: 'mt-16 mb-8' }, groupName));

      for (const mod of modules) {
        const actionRows = mod.actions.map((action) => {
          const key = `${mod.key}:${action}`;
          const inherited = (data.inherited[mod.key] || []).includes(action);
          const override = overrides.get(key);
          const effective = override
            ? override.effect === 'GRANT'
            : inherited;

          const effectSelect = select([
            { value: EFFECT_NONE, label: inherited ? 'Inherited (allowed)' : 'Inherited (not allowed)' },
            { value: 'GRANT', label: 'Grant to this user' },
            { value: 'REVOKE', label: 'Revoke from this user' },
          ], { value: override?.effect || EFFECT_NONE });

          const scopeSelect = select(scopeOptions, { value: override?.dataScope || '' });
          scopeSelect.disabled = !override || override.effect !== 'GRANT';

          const resultChip = h('span', {
            class: `badge ${effective ? 'badge-success' : ''}`,
          }, effective ? 'Allowed' : 'Not allowed');

          const repaint = () => {
            const value = effectSelect.value;
            if (value === EFFECT_NONE) {
              overrides.delete(key);
            } else {
              overrides.set(key, {
                effect: value,
                dataScope: scopeSelect.value || '',
                reason: reasonInput.value,
              });
            }
            const now = overrides.get(key);
            const isAllowed = now ? now.effect === 'GRANT' : inherited;
            clear(resultChip);
            resultChip.className = `badge ${isAllowed ? 'badge-success' : ''}`;
            resultChip.textContent = isAllowed ? 'Allowed' : 'Not allowed';
            scopeSelect.disabled = !now || now.effect !== 'GRANT';
            saveButton.disabled = false;
          };
          effectSelect.addEventListener('change', repaint);
          scopeSelect.addEventListener('change', repaint);

          return h('div', {
            class: 'row',
            style: {
              gap: '10px', alignItems: 'center', padding: '6px 0',
              borderBottom: '1px solid var(--line)',
            },
          },
          h('div', { style: { width: '130px', flexShrink: 0 } },
            h('span', { class: 'small' }, titleCase(action))),
          h('div', { style: { width: '90px', flexShrink: 0 } },
            h('span', { class: 'tiny muted' }, inherited ? 'Role: yes' : 'Role: no')),
          h('div', { style: { flex: '1 1 190px' } }, effectSelect),
          h('div', { style: { flex: '1 1 170px' } }, scopeSelect),
          h('div', { style: { width: '100px', flexShrink: 0, textAlign: 'right' } }, resultChip));
        });

        rowsHost.appendChild(h('div', { class: 'card mb-16' }, h('div', { class: 'card-body' },
          h('div', { class: 'row-between mb-8' },
            h('strong', null, mod.label),
            h('span', { class: 'tiny muted' }, 'role → override → effective')),
          ...actionRows)));
      }
    }
  };
  renderRows();

  body.appendChild(h('div', { class: 'field mt-16' },
    h('label', null, 'Reason (recorded in the audit log)'),
    reasonInput));

  saveButton.addEventListener('click', async () => {
    const payload = [...overrides.entries()].map(([key, value]) => {
      const [module, action] = key.split(':');
      return {
        module,
        action,
        effect: value.effect,
        dataScope: value.dataScope || null,
        reason: reasonInput.value.trim() || value.reason || null,
      };
    });
    saveButton.disabled = true;
    try {
      await api.put(`/api/users/${user.id}/permissions`, { permissions: payload });
      toast.success(
        payload.length ? `${payload.length} special permission${payload.length === 1 ? '' : 's'} saved` : 'Special access cleared',
        'It applies the next time they load the app.',
      );
      ref_.close();
      onSaved?.();
    } catch (err) {
      saveButton.disabled = false;
      reportError(err);
    }
  });

  return ref_;
}
