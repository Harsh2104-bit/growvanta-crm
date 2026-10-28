/** Organisation settings: profile, lead statuses, lead sources, custom fields, tags. */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, refreshReference, loadSession } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, modal, drawer, field, input, textarea,
  select, confirmDialog, dropdown, menuItem, tabs, switchToggle,
} from '../lib/ui.js';
import { number, titleCase, date } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

const TABS = [
  { key: 'organization', label: 'Organisation' },
  { key: 'statuses', label: 'Lead statuses' },
  { key: 'sources', label: 'Lead sources' },
  { key: 'fields', label: 'Custom fields' },
  { key: 'tags', label: 'Tags' },
];

export default async function render({ query }) {
  if (!can('settings', 'read')) return { node: noAccess('settings') };

  const state = { tab: TABS.some((t) => t.key === query.tab) ? query.tab : 'organization' };
  const editable = can('settings', 'update');

  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div', null, pageLoader());
  const actionHost = h('div', { class: 'row wrap', style: { gap: '8px' } });

  const node = h('div', null,
    pageHead({
      title: 'Settings',
      subtitle: 'How the CRM behaves for everyone in your organisation.',
      actions: actionHost,
    }),
    tabHost, body);

  function buildTabs() {
    mount(tabHost, tabs(TABS, state.tab, (key) => {
      state.tab = key;
      window.history.replaceState(null, '', `#/settings?tab=${key}`);
      buildTabs();
      load();
    }));
  }

  async function load() {
    mount(body, pageLoader());
    clear(actionHost);
    try {
      const renderers = {
        organization: renderOrganization,
        statuses: renderStatuses,
        sources: renderSources,
        fields: renderCustomFields,
        tags: renderTags,
      };
      await renderers[state.tab]();
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load', message: err.message })));
    }
  }

  // =========================================================================
  async function renderOrganization() {
    const org = await api.data('/api/settings/organization');
    const settings = org.settings || {};

    const nameInput = input({ value: org.name, disabled: !editable });
    const domainInput = input({ value: org.domain || '', placeholder: 'growvanta.com', disabled: !editable });
    const currencySelect = select(
      ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD'].map((c) => ({ value: c, label: c })),
      { value: org.currency, disabled: !editable },
    );
    const timezoneInput = input({ value: org.timezone, disabled: !editable });
    const localeSelect = select(
      ['en-IN', 'en-US', 'en-GB', 'en-AE', 'en-SG'].map((l) => ({ value: l, label: l })),
      { value: org.locale, disabled: !editable },
    );
    const duplicateSelect = select([
      { value: 'FLAG', label: 'Warn, but allow the lead through' },
      { value: 'REJECT', label: 'Reject duplicates outright' },
      { value: 'ALLOW', label: 'Do not check at all' },
    ], { value: settings.duplicatePolicy || 'FLAG', disabled: !editable });

    let autoAssign = settings.autoAssignFallback !== false;

    mount(body,
      h('div', { class: 'chart-grid two' },
        panel('Organisation profile', h('div', null,
          h('div', { class: 'form-grid' },
            field('Name', nameInput),
            field('Primary domain', domainInput),
            field('Currency', currencySelect),
            field('Locale', localeSelect, { hint: 'Controls number, date and currency formatting.' }),
            field('Timezone', timezoneInput)),
          editable
            ? h('button', {
              class: 'btn btn-primary mt-8',
              onClick: async (e) => {
                const button = e.currentTarget;
                button.disabled = true;
                try {
                  await api.patch('/api/settings/organization', {
                    name: nameInput.value.trim(),
                    domain: domainInput.value.trim() || null,
                    currency: currencySelect.value,
                    locale: localeSelect.value,
                    timezone: timezoneInput.value.trim(),
                  });
                  toast.success('Organisation updated');
                  await loadSession();
                } catch (err) { reportError(err); } finally { button.disabled = false; }
              },
            }, 'Save profile')
            : null)),

        panel('Lead handling', h('div', null,
          field('Duplicate policy', duplicateSelect, {
            hint: 'Applies to manual entry, forms, the API and imports.',
          }),
          h('div', { class: 'mb-16' },
            switchToggle('Fall back to load-balanced round robin when no assignment rule matches',
              autoAssign, (v) => { autoAssign = v; })),
          editable
            ? h('button', {
              class: 'btn btn-primary',
              onClick: async (e) => {
                const button = e.currentTarget;
                button.disabled = true;
                try {
                  await api.patch('/api/settings/organization', {
                    settings: { duplicatePolicy: duplicateSelect.value, autoAssignFallback: autoAssign },
                  });
                  toast.success('Lead handling updated');
                } catch (err) { reportError(err); } finally { button.disabled = false; }
              },
            }, 'Save lead handling')
            : null,
          h('div', { class: 'info-list mt-16' },
            row('Users', number(org.stats.users)),
            row('Leads', number(org.stats.leads)),
            row('Workspace created', date(org.createdAt)),
            row('Plan', titleCase(org.plan)))))));
  }

  const row = (label, value) => h('div', { class: 'info-row' },
    h('div', { class: 'i-label' }, label), h('div', { class: 'i-value' }, value));

  // =========================================================================
  async function renderStatuses() {
    const statuses = await api.data('/api/settings/lead-statuses');
    if (editable) {
      mount(actionHost, h('button', {
        class: 'btn btn-primary',
        onClick: () => openStatusForm({ onSaved: load }),
      }, icon('plus', { size: 15 }), 'New status'));
    }

    mount(body,
      h('div', { class: 'alert info mb-16' },
        icon('info', { size: 16 }),
        h('div', null, 'Statuses are the columns of the lead Kanban board. Order decides the column order; probability feeds the weighted value estimate.')),
      panel('Lead statuses', h('div', { class: 'table-wrap' },
        h('table', { class: 'data' },
          h('thead', null, h('tr', null,
            h('th', { style: { width: '40px' } }, ''), h('th', null, 'Status'),
            h('th', { class: 'right' }, 'Probability'), h('th', { class: 'right' }, 'Leads'),
            h('th', null, 'Flags'), h('th', { style: { width: '48px' } }, ''))),
          h('tbody', null, ...statuses.map((status, index) => h('tr', null,
            h('td', null, h('span', {
              style: { display: 'block', width: '12px', height: '12px', borderRadius: '4px', background: status.color },
            })),
            h('td', null,
              h('div', { class: 'cell-main' }, status.name),
              h('div', { class: 'cell-sub mono' }, status.key)),
            h('td', { class: 'right' }, `${status.probability}%`),
            h('td', { class: 'right' }, number(status.leadCount)),
            h('td', null, h('div', { class: 'row wrap', style: { gap: '4px' } },
              status.isDefault ? h('span', { class: 'badge badge-brand' }, 'Default') : null,
              status.isQualified ? h('span', { class: 'badge badge-info' }, 'Qualified') : null,
              status.isConverted ? h('span', { class: 'badge badge-success' }, 'Converted') : null,
              status.isLost ? h('span', { class: 'badge badge-danger' }, 'Lost') : null,
              status.isJunk ? h('span', { class: 'badge' }, 'Junk') : null,
              status.isActive ? null : h('span', { class: 'badge badge-warning' }, 'Inactive'))),
            h('td', null, editable
              ? dropdown(
                h('button', { class: 'icon-btn', 'aria-label': 'Status actions' }, icon('more', { size: 16 })),
                (close) => h('div', null,
                  menuItem('Edit', { iconName: 'edit', close, onClick: () => openStatusForm({ status, onSaved: load }) }),
                  index > 0
                    ? menuItem('Move up', {
                      iconName: 'arrowUp', close,
                      onClick: () => reorderStatuses(statuses, index, index - 1),
                    })
                    : null,
                  index < statuses.length - 1
                    ? menuItem('Move down', {
                      iconName: 'arrowDown', close,
                      onClick: () => reorderStatuses(statuses, index, index + 1),
                    })
                    : null,
                  menuItem('Delete', {
                    iconName: 'trash', close, danger: true,
                    onClick: async () => {
                      const yes = await confirmDialog({
                        title: 'Delete this status?',
                        message: status.leadCount > 0
                          ? `${status.leadCount} lead(s) still use it — move them first.`
                          : status.name,
                        danger: true, confirmLabel: 'Delete',
                      });
                      if (!yes) return;
                      try {
                        await api.del(`/api/settings/lead-statuses/${status.id}`);
                        toast.success('Status deleted');
                        refreshReference();
                        load();
                      } catch (err) { reportError(err); }
                    },
                  })),
              )
              : null))))),
      ), { flush: true }));
  }

  async function reorderStatuses(statuses, from, to) {
    const order = statuses.map((s) => s.id);
    [order[from], order[to]] = [order[to], order[from]];
    try {
      await api.post('/api/settings/lead-statuses/reorder', { order });
      refreshReference();
      load();
    } catch (err) { reportError(err); }
  }

  function openStatusForm({ status = null, onSaved } = {}) {
    const isEdit = Boolean(status?.id);
    const nameInput = input({ value: status?.name || '' });
    const colorInput = h('input', { type: 'color', class: 'input', value: status?.color || '#35e06f', style: { padding: '4px', height: '38px' } });
    const probInput = input({ type: 'number', min: '0', max: '100', value: status?.probability ?? 0 });
    const flags = {};
    const flagBox = h('div', { class: 'col', style: { gap: '6px' } },
      ...[
        ['isDefault', 'Default status for new leads'],
        ['isQualified', 'Counts as qualified'],
        ['isConverted', 'Marks the lead converted'],
        ['isLost', 'Counts as lost'],
        ['isJunk', 'Counts as junk'],
        ['isActive', 'Active (shown on the board)'],
      ].map(([key, label]) => {
        const cb = h('input', { type: 'checkbox', checked: status ? status[key] : key === 'isActive' });
        flags[key] = cb;
        return h('label', { class: 'checkbox' }, cb, label);
      }));

    const dialog = modal({
      title: isEdit ? 'Edit lead status' : 'New lead status',
      body: h('div', null,
        h('div', { class: 'form-grid' },
          field('Name', nameInput, { required: true }),
          field('Colour', colorInput),
          field('Probability %', probInput, { hint: 'Used for weighted value estimates.' })),
        field('Behaviour', flagBox)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            if (!nameInput.value.trim()) { nameInput.focus(); return; }
            const payload = {
              name: nameInput.value.trim(),
              color: colorInput.value,
              probability: Number(probInput.value) || 0,
              ...Object.fromEntries(Object.entries(flags).map(([k, cb]) => [k, cb.checked])),
            };
            e.currentTarget.disabled = true;
            try {
              if (isEdit) await api.patch(`/api/settings/lead-statuses/${status.id}`, payload);
              else await api.post('/api/settings/lead-statuses', payload);
              toast.success(isEdit ? 'Status updated' : 'Status created');
              refreshReference();
              dialog.close();
              onSaved?.();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, isEdit ? 'Save' : 'Create')),
    });
  }

  // =========================================================================
  async function renderSources() {
    const sources = await api.data('/api/settings/lead-sources');
    if (editable) {
      mount(actionHost, h('button', {
        class: 'btn btn-primary', onClick: () => openSourceForm({ onSaved: load }),
      }, icon('plus', { size: 15 }), 'New source'));
    }

    mount(body, panel('Lead sources', h('div', { class: 'table-wrap' },
      h('table', { class: 'data' },
        h('thead', null, h('tr', null,
          h('th', null, 'Source'), h('th', null, 'Category'),
          h('th', { class: 'right' }, 'Cost per lead'), h('th', { class: 'right' }, 'Leads'),
          h('th', null, 'Status'), h('th', { style: { width: '48px' } }, ''))),
        h('tbody', null, ...sources.map((source) => h('tr', null,
          h('td', null, h('div', { class: 'cell-main' }, source.name), h('div', { class: 'cell-sub mono' }, source.key)),
          h('td', null, h('span', { class: 'badge' }, titleCase(source.category))),
          h('td', { class: 'right' }, source.costPerLead ? String(source.costPerLead) : '—'),
          h('td', { class: 'right' }, number(source.leadCount)),
          h('td', null, h('span', { class: `badge ${source.isActive ? 'badge-success' : ''}` }, source.isActive ? 'Active' : 'Inactive')),
          h('td', null, editable
            ? dropdown(
              h('button', { class: 'icon-btn', 'aria-label': 'Source actions' }, icon('more', { size: 16 })),
              (close) => h('div', null,
                menuItem('Edit', { iconName: 'edit', close, onClick: () => openSourceForm({ source, onSaved: load }) }),
                menuItem('Delete', {
                  iconName: 'trash', close, danger: true,
                  onClick: async () => {
                    const yes = await confirmDialog({
                      title: 'Delete this source?',
                      message: source.leadCount > 0 ? `${source.leadCount} lead(s) reference it — deactivate it instead.` : source.name,
                      danger: true, confirmLabel: 'Delete',
                    });
                    if (!yes) return;
                    try {
                      await api.del(`/api/settings/lead-sources/${source.id}`);
                      toast.success('Source deleted');
                      refreshReference();
                      load();
                    } catch (err) { reportError(err); }
                  },
                }),
              ))
            : null))))),
    ), { flush: true, subtitle: 'Cost per lead feeds the marketing ROI report.' }));
  }

  function openSourceForm({ source = null, onSaved } = {}) {
    const isEdit = Boolean(source?.id);
    const nameInput = input({ value: source?.name || '' });
    const categorySelect = select(
      ['PAID', 'ORGANIC', 'SOCIAL', 'REFERRAL', 'DIRECT', 'OTHER'].map((c) => ({ value: c, label: titleCase(c) })),
      { value: source?.category || 'OTHER' },
    );
    const cplInput = input({ type: 'number', min: '0', value: source?.costPerLead ?? '' });
    const activeCb = h('input', { type: 'checkbox', checked: source ? source.isActive : true });

    const dialog = modal({
      title: isEdit ? 'Edit lead source' : 'New lead source',
      body: h('div', null,
        field('Name', nameInput, { required: true }),
        h('div', { class: 'form-grid' },
          field('Category', categorySelect),
          field('Cost per lead', cplInput, { hint: 'Optional. Used for source-level ROI.' })),
        h('label', { class: 'checkbox' }, activeCb, 'Active')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            if (!nameInput.value.trim()) { nameInput.focus(); return; }
            e.currentTarget.disabled = true;
            try {
              const payload = {
                name: nameInput.value.trim(),
                category: categorySelect.value,
                costPerLead: cplInput.value === '' ? null : Number(cplInput.value),
                isActive: activeCb.checked,
              };
              if (isEdit) await api.patch(`/api/settings/lead-sources/${source.id}`, payload);
              else await api.post('/api/settings/lead-sources', payload);
              toast.success(isEdit ? 'Source updated' : 'Source created');
              refreshReference();
              dialog.close();
              onSaved?.();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, isEdit ? 'Save' : 'Create')),
    });
  }

  // =========================================================================
  async function renderCustomFields() {
    const fields = await api.data('/api/settings/custom-fields');
    if (editable) {
      mount(actionHost, h('button', {
        class: 'btn btn-primary', onClick: () => openFieldForm({ onSaved: load }),
      }, icon('plus', { size: 15 }), 'New field'));
    }

    if (!fields.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'forms',
        title: 'No custom fields',
        message: 'Add fields specific to your business — industry, team size, budget band — without a schema change.',
        action: editable ? h('button', { class: 'btn btn-primary', onClick: () => openFieldForm({ onSaved: load }) }, 'Add a field') : null,
      })));
      return;
    }

    const byEntity = new Map();
    for (const f of fields) {
      if (!byEntity.has(f.entity)) byEntity.set(f.entity, []);
      byEntity.get(f.entity).push(f);
    }

    mount(body, h('div', { class: 'col', style: { gap: '14px' } },
      ...[...byEntity.entries()].map(([entity, list]) => panel(`${titleCase(entity)} fields`,
        h('div', { class: 'table-wrap' },
          h('table', { class: 'data' },
            h('thead', null, h('tr', null,
              h('th', null, 'Label'), h('th', null, 'Key'), h('th', null, 'Type'),
              h('th', null, 'Required'), h('th', { style: { width: '48px' } }, ''))),
            h('tbody', null, ...list.map((f) => h('tr', null,
              h('td', { class: 'cell-main' }, f.label),
              h('td', { class: 'mono small' }, f.key),
              h('td', null, h('span', { class: 'badge' }, titleCase(f.type))),
              h('td', null, f.isRequired ? h('span', { class: 'badge badge-warning' }, 'Required') : h('span', { class: 'muted' }, '—')),
              h('td', null, editable
                ? dropdown(
                  h('button', { class: 'icon-btn', 'aria-label': 'Field actions' }, icon('more', { size: 16 })),
                  (close) => h('div', null,
                    menuItem('Edit', { iconName: 'edit', close, onClick: () => openFieldForm({ customField: f, onSaved: load }) }),
                    menuItem('Delete', {
                      iconName: 'trash', close, danger: true,
                      onClick: async () => {
                        const yes = await confirmDialog({
                          title: 'Delete this field?',
                          message: `${f.label}. Stored values are dropped the next time each record is saved.`,
                          danger: true, confirmLabel: 'Delete',
                        });
                        if (!yes) return;
                        try { await api.del(`/api/settings/custom-fields/${f.id}`); toast.success('Field deleted'); load(); } catch (err) { reportError(err); }
                      },
                    }),
                  ))
                : null))))),
        ), { flush: true }))));
  }

  function openFieldForm({ customField = null, onSaved } = {}) {
    const isEdit = Boolean(customField?.id);
    const labelInput = input({ value: customField?.label || '', placeholder: 'Budget band' });
    const entitySelect = select(
      ['LEAD', 'CONTACT', 'ACCOUNT', 'OPPORTUNITY'].map((e) => ({ value: e, label: titleCase(e) })),
      { value: customField?.entity || 'LEAD', disabled: isEdit },
    );
    const typeSelect = select(
      ['TEXT', 'TEXTAREA', 'NUMBER', 'DECIMAL', 'DATE', 'BOOLEAN', 'SELECT', 'MULTISELECT', 'EMAIL', 'PHONE', 'URL']
        .map((t) => ({ value: t, label: titleCase(t) })),
      { value: customField?.type || 'TEXT', onChange: () => updateOptions() },
    );
    const optionsInput = textarea({
      rows: 4, value: (customField?.options || []).join('\n'), placeholder: 'One option per line',
    });
    const optionsField = field('Options', optionsInput);
    const helpInput = input({ value: customField?.helpText || '' });
    const requiredCb = h('input', { type: 'checkbox', checked: customField?.isRequired || false });
    const listCb = h('input', { type: 'checkbox', checked: customField?.showInList || false });

    function updateOptions() {
      optionsField.classList.toggle('hidden', !['SELECT', 'MULTISELECT'].includes(typeSelect.value));
    }
    updateOptions();

    const dialog = modal({
      title: isEdit ? 'Edit custom field' : 'New custom field',
      body: h('div', null,
        h('div', { class: 'form-grid' },
          field('Label', labelInput, { required: true }),
          field('Applies to', entitySelect, { hint: isEdit ? 'Cannot be changed after creation.' : undefined }),
          field('Type', typeSelect)),
        optionsField,
        field('Help text', helpInput),
        h('label', { class: 'checkbox' }, requiredCb, 'Required'),
        h('label', { class: 'checkbox' }, listCb, 'Show in list views')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            if (!labelInput.value.trim()) { labelInput.focus(); return; }
            e.currentTarget.disabled = true;
            try {
              const payload = {
                label: labelInput.value.trim(),
                entity: entitySelect.value,
                type: typeSelect.value,
                options: optionsInput.value.split('\n').map((v) => v.trim()).filter(Boolean),
                helpText: helpInput.value.trim() || null,
                isRequired: requiredCb.checked,
                showInList: listCb.checked,
              };
              if (isEdit) await api.patch(`/api/settings/custom-fields/${customField.id}`, payload);
              else await api.post('/api/settings/custom-fields', payload);
              toast.success(isEdit ? 'Field updated' : 'Field created');
              dialog.close();
              onSaved?.();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, isEdit ? 'Save' : 'Create')),
    });
  }

  // =========================================================================
  async function renderTags() {
    const tags = await api.data('/api/settings/tags');
    if (!tags.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'star', title: 'No tags yet', message: 'Tags are created as you use them on leads.',
      })));
      return;
    }
    mount(body, panel('Tags', h('div', { class: 'row wrap', style: { gap: '8px' } },
      ...tags.map((tag) => h('span', {
        class: 'chip',
        style: { background: `${tag.color}1f`, color: tag.color, borderColor: `${tag.color}44` },
      },
        tag.name,
        h('span', { class: 'tiny muted' }, `${number(tag.usageCount)}`),
        editable
          ? h('button', {
            'aria-label': `Delete ${tag.name}`,
            onClick: async () => {
              const yes = await confirmDialog({
                title: 'Delete this tag?',
                message: `“${tag.name}” is used on ${tag.usageCount} lead(s).`,
                danger: true, confirmLabel: 'Delete',
              });
              if (!yes) return;
              try { await api.del(`/api/settings/tags/${tag.id}`); toast.success('Tag deleted'); load(); } catch (err) { reportError(err); }
            },
          }, '×')
          : null))),
    { subtitle: 'The number shows how many leads carry each tag.' }));
  }

  buildTabs();
  await load();
  return { node };
}
