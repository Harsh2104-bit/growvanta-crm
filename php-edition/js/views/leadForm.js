/**
 * Create / edit lead drawer.
 *
 * Runs a live duplicate check as soon as an email or phone is entered, so the
 * user is warned *before* submitting rather than being rejected afterwards.
 * Custom fields are fetched from the org's definitions and rendered inline.
 */
import { h, mount, clear, debounce } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api, ApiError } from '../lib/api.js';
import { reference, can } from '../lib/store.js';
import { drawer, field, input, textarea, select, toast, reportError } from '../lib/ui.js';
import { inputDate, inputDateTime, titleCase } from '../lib/format.js';
import { navigate } from '../router.js';

const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => ({ value: p, label: titleCase(p) }));

const PURPOSES = [
  { value: 'END_USE', label: 'End use' },
  { value: 'INVESTMENT', label: 'Investment' },
];
const FUNDING = [
  { value: 'SELF', label: 'Self funded' },
  { value: 'HOME_LOAN', label: 'Home loan' },
  { value: 'MIXED', label: 'Part loan' },
];
const POSSESSION = [
  { value: 'IMMEDIATE', label: 'Immediate' },
  { value: 'WITHIN_6_MONTHS', label: 'Within 6 months' },
  { value: 'WITHIN_1_YEAR', label: 'Within a year' },
  { value: 'WITHIN_2_YEARS', label: 'Within 2 years' },
  { value: 'FLEXIBLE', label: 'Flexible' },
];

export async function openLeadForm({ lead = null, onSaved } = {}) {
  const isEdit = Boolean(lead?.id);
  const ref = await reference();
  const customFields = await api.data('/api/settings/custom-fields', { entity: 'LEAD' }).catch(() => []);
  const projects = can('projects', 'read')
    ? await api.data('/api/projects/options').catch(() => [])
    : [];
  const values = { ...(lead || {}) };
  const customValues = { ...(lead?.customFields || {}) };

  const controls = {};
  const mk = (name, props = {}) => {
    const el = input({ value: values[name] ?? '', name, ...props });
    controls[name] = el;
    return el;
  };

  const dupHost = h('div');
  let allowDuplicate = false;

  const checkDuplicates = debounce(async () => {
    const email = controls.email.value.trim();
    const phone = controls.phone.value.trim();
    if (!email && !phone) { clear(dupHost); return; }
    try {
      const matches = await api.post('/api/leads/check-duplicates', {
        email, phone, firstName: controls.firstName.value.trim(), company: controls.company.value.trim(),
        excludeId: lead?.id,
      });
      const rows = matches.data;
      if (!rows.length) { clear(dupHost); return; }
      mount(dupHost, h('div', { class: 'alert warning mb-16' },
        icon('alert', { size: 16 }),
        h('div', { class: 'grow' },
          h('strong', null, `${rows.length} possible duplicate${rows.length === 1 ? '' : 's'}`),
          h('div', { class: 'col', style: { gap: '5px', marginTop: '6px' } },
            ...rows.map((row) => h('div', { class: 'row-between' },
              h('span', { class: 'small' },
                `${row.firstName} ${row.lastName || ''} · ${row.code} · matched on ${row.matchedOn.join(', ').toLowerCase()}`),
              h('button', {
                class: 'btn btn-sm',
                onClick: () => { ref_.close(); navigate(`/leads/${row.id}`); },
              }, 'Open')))),
          h('label', { class: 'checkbox mt-8' },
            h('input', {
              type: 'checkbox',
              onChange: (e) => { allowDuplicate = e.target.checked; },
            }),
            'Create anyway'))));
    } catch { /* the duplicate hint is advisory; a failure must not block typing */ }
  }, 450);

  const customFieldControls = {};
  function renderCustomField(def) {
    const value = customValues[def.key];
    let control;
    if (def.type === 'TEXTAREA') {
      control = textarea({ value: value ?? '', rows: 3 });
    } else if (def.type === 'SELECT') {
      control = select(def.options.map((o) => ({ value: o, label: o })), { value, placeholder: 'Select…' });
    } else if (def.type === 'MULTISELECT') {
      control = h('div', { class: 'row wrap', style: { gap: '10px' } },
        ...def.options.map((option) => h('label', { class: 'checkbox' },
          h('input', {
            type: 'checkbox', value: option,
            checked: Array.isArray(value) && value.includes(option),
          }), option)));
    } else if (def.type === 'BOOLEAN') {
      control = h('label', { class: 'checkbox' }, h('input', { type: 'checkbox', checked: Boolean(value) }), def.label);
    } else if (def.type === 'DATE') {
      control = input({ type: 'date', value: value ?? '' });
    } else if (def.type === 'NUMBER' || def.type === 'DECIMAL') {
      control = input({ type: 'number', value: value ?? '', step: def.type === 'DECIMAL' ? '0.01' : '1' });
    } else {
      control = input({ value: value ?? '', type: { EMAIL: 'email', PHONE: 'tel', URL: 'url' }[def.type] || 'text' });
    }
    customFieldControls[def.key] = { def, control };
    return field(def.label, control, { hint: def.helpText, required: def.isRequired });
  }

  function collectCustomFields() {
    const out = {};
    for (const [key, { def, control }] of Object.entries(customFieldControls)) {
      if (def.type === 'MULTISELECT') {
        out[key] = [...control.querySelectorAll('input:checked')].map((el) => el.value);
      } else if (def.type === 'BOOLEAN') {
        out[key] = control.querySelector('input').checked;
      } else {
        out[key] = control.value;
      }
    }
    return out;
  }

  const tagsInput = input({
    value: (lead?.tags || []).map((t) => t.name).join(', '),
    placeholder: 'enterprise, warm, referral',
  });

  const errorHost = h('div');

  // Configurations vary per project, so the suggestion list follows the
  // project picker rather than being a fixed dropdown.
  const configListId = `cfg-${Math.random().toString(36).slice(2, 8)}`;
  const configDatalist = h('datalist', { id: configListId });
  const configurationControl = h('div', null,
    input({ value: values.configuration ?? '', list: configListId, placeholder: '3 BHK' }),
    configDatalist);
  const configInputEl = configurationControl.firstChild;

  const body = h('div', null,
    errorHost,
    dupHost,

    h('h4', { class: 'mb-8' }, 'Contact'),
    h('div', { class: 'form-grid' },
      field('First name', mk('firstName', { placeholder: 'Aarav', required: true }), { required: true }),
      field('Last name', mk('lastName', { placeholder: 'Sharma' })),
      field('Email', mk('email', { type: 'email', placeholder: 'aarav@company.com', onInput: checkDuplicates })),
      field('Phone', mk('phone', { type: 'tel', placeholder: '9876543210', onInput: checkDuplicates })),
      field('Alternate phone', mk('altPhone', { type: 'tel' })),
      field('Job title', mk('jobTitle', { placeholder: 'Procurement Head' }))),

    h('h4', { class: 'mb-8 mt-16' }, 'Company'),
    h('div', { class: 'form-grid' },
      field('Company', mk('company', { placeholder: 'Northwind Traders', onInput: checkDuplicates })),
      field('Website', mk('website', { type: 'url', placeholder: 'https://…' })),
      field('City', mk('city')),
      field('State', mk('state')),
      field('Country', mk('country', { value: values.country ?? 'India' })),
      field('Pincode', mk('pincode'))),
    field('Address', (controls.address = textarea({ value: values.address ?? '', rows: 2 }))),

    h('h4', { class: 'mb-8 mt-16' }, 'Requirement'),
    h('div', { class: 'form-grid' },
      // Required on a lead someone types in: every enquiry is about something,
      // and the server refuses a manual lead without it.
      field('Project', (controls.projectId = select(
        projects.map((p) => ({ value: p.id, label: `${p.name} — ${p.location}` })),
        { value: values.projectId || '', placeholder: 'Which project are they asking about?' },
      )), {
        required: !isEdit,
        hint: projects.length
          ? undefined
          : 'No projects available to your company yet — add one before entering leads.',
      }),
      field('Configuration', configurationControl),
      field('Budget from', mk('budgetMin', { type: 'number', min: '0', step: '100000', placeholder: '7500000' })),
      field('Budget to', mk('budgetMax', { type: 'number', min: '0', step: '100000', placeholder: '15000000' })),
      field('Purpose', (controls.purpose = select(PURPOSES, {
        value: values.purpose || '', placeholder: 'Not asked yet',
      }))),
      field('Funding', (controls.funding = select(FUNDING, {
        value: values.funding || '', placeholder: 'Not asked yet',
      }))),
      field('Possession timeline', (controls.possessionTimeline = select(POSSESSION, {
        value: values.possessionTimeline || '', placeholder: 'Not asked yet',
      }))),
      field('Site visit', (controls.siteVisitAt = input({
        type: 'datetime-local', value: inputDateTime(values.siteVisitAt),
      })))),

    h('h4', { class: 'mb-8 mt-16' }, 'Qualification'),
    h('div', { class: 'form-grid' },
      field('Lead source', (controls.sourceId = select(
        ref.sources.map((s) => ({ value: s.id, label: s.name })),
        { value: values.sourceId || '', placeholder: 'Select a source' },
      ))),
      // Choosing the opening status is part of creating a lead; changing it
      // afterwards is a separate grant, so on an edit the control is shown but
      // inert rather than failing at save time.
      field('Status', (controls.statusId = select(
        ref.statuses.map((s) => ({ value: s.id, label: s.name })),
        {
          value: values.statusId || '',
          placeholder: 'Default status',
          ...(isEdit && !can('leads', 'change_status') ? { disabled: true } : {}),
        },
      )), isEdit && !can('leads', 'change_status')
        ? { hint: 'You do not have permission to change the status of a lead.' }
        : undefined),
      field('Priority', (controls.priority = select(PRIORITIES, { value: values.priority || 'MEDIUM' }))),
      field('Expected value', mk('expectedValue', { type: 'number', min: '0', step: '1000', placeholder: '250000' })),
      field('Next follow-up', (controls.nextFollowUpAt = input({
        type: 'date', value: inputDate(values.nextFollowUpAt),
      })))),

    can('leads', 'assign')
      ? h('div', { class: 'form-grid' },
        field('Owner', (controls.ownerId = select(
          ref.users.map((u) => ({ value: u.id, label: u.fullName || u.name })),
          { value: values.ownerId || '', placeholder: 'Let the assignment rules decide' },
        )), { hint: 'Leave blank to run the routing rules.' }),
        field('Team', (controls.teamId = select(
          ref.teams.map((t) => ({ value: t.id, label: t.name })),
          { value: values.teamId || '', placeholder: 'No specific team' },
        ))))
      : null,

    field('Tags', tagsInput, { hint: 'Comma separated.' }),
    field('Notes', (controls.notes = textarea({ value: values.notes ?? '', rows: 3, placeholder: 'What did they ask for?' }))),

    customFields.length
      ? h('div', null,
        h('h4', { class: 'mb-8 mt-16' }, 'Additional details'),
        ...customFields.map(renderCustomField))
      : null);

  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save changes' : 'Create lead');

  const ref_ = drawer({
    title: isEdit ? `Edit ${lead.firstName} ${lead.lastName || ''}`.trim() : 'New lead',
    subtitle: isEdit ? lead.code : 'Capture a lead manually. Routing, scoring and follow-ups run automatically.',
    wide: true,
    body,
    footer: h('div', { class: 'row' },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      saveButton),
  });

  const syncConfigOptions = () => {
    const chosen = projects.find((p) => p.id === controls.projectId.value);
    clear(configDatalist);
    for (const cfg of chosen?.configurations || []) {
      configDatalist.appendChild(h('option', { value: cfg }));
    }
  };
  controls.projectId.addEventListener('change', syncConfigOptions);
  syncConfigOptions();

  saveButton.addEventListener('click', async () => {
    clear(errorHost);
    const payload = {
      firstName: controls.firstName.value.trim(),
      lastName: controls.lastName.value.trim() || null,
      email: controls.email.value.trim() || null,
      phone: controls.phone.value.trim() || null,
      altPhone: controls.altPhone.value.trim() || null,
      company: controls.company.value.trim() || null,
      jobTitle: controls.jobTitle.value.trim() || null,
      website: controls.website.value.trim() || null,
      city: controls.city.value.trim() || null,
      state: controls.state.value.trim() || null,
      country: controls.country.value.trim() || null,
      pincode: controls.pincode.value.trim() || null,
      address: controls.address.value.trim() || null,
      sourceId: controls.sourceId.value || null,
      statusId: controls.statusId.value || null,
      priority: controls.priority.value,
      expectedValue: controls.expectedValue.value ? Number(controls.expectedValue.value) : null,
      nextFollowUpAt: controls.nextFollowUpAt.value || null,
      projectId: controls.projectId.value || null,
      configuration: configInputEl.value.trim() || null,
      budgetMin: controls.budgetMin.value ? Number(controls.budgetMin.value) : null,
      budgetMax: controls.budgetMax.value ? Number(controls.budgetMax.value) : null,
      purpose: controls.purpose.value || null,
      funding: controls.funding.value || null,
      possessionTimeline: controls.possessionTimeline.value || null,
      siteVisitAt: controls.siteVisitAt.value || null,
      notes: controls.notes.value.trim() || null,
      tags: tagsInput.value.split(',').map((t) => t.trim()).filter(Boolean),
      customFields: collectCustomFields(),
      allowDuplicate,
    };
    if (controls.ownerId) payload.ownerId = controls.ownerId.value || null;
    if (controls.teamId) payload.teamId = controls.teamId.value || null;

    if (!payload.firstName) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'First name is required.'));
      controls.firstName.focus();
      return;
    }
    if (!payload.email && !payload.phone) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Enter at least an email address or a phone number.'));
      controls.email.focus();
      return;
    }
    // Caught here as well as on the server, so the answer arrives without a
    // round trip and with the cursor already in the field that needs it.
    if (!isEdit && !payload.projectId) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' },
        projects.length
          ? 'Choose the project this enquiry is about.'
          : 'Add a project first — every enquiry has to be attached to one.'));
      controls.projectId.focus();
      return;
    }

    saveButton.disabled = true;
    saveButton.textContent = 'Saving…';
    try {
      const res = isEdit
        ? await api.patch(`/api/leads/${lead.id}`, payload)
        : await api.post('/api/leads', payload);
      const saved = res.data;
      toast.success(isEdit ? 'Lead updated' : `Lead ${saved.code} created`,
        saved.assignment?.ruleName ? `Routed by “${saved.assignment.ruleName}”.` : undefined);
      ref_.close();
      onSaved?.(saved);
      window.dispatchEvent(new CustomEvent('gv:data-changed'));
    } catch (err) {
      saveButton.disabled = false;
      saveButton.textContent = isEdit ? 'Save changes' : 'Create lead';
      if (err instanceof ApiError && err.status === 409) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' },
          h('div', null,
            h('strong', null, 'Duplicate lead'),
            err.message,
            h('div', { class: 'mt-8' },
              h('button', {
                class: 'btn btn-sm',
                onClick: () => { allowDuplicate = true; saveButton.click(); },
              }, 'Create anyway')))));
        return;
      }
      const fieldErrors = err instanceof ApiError ? err.fieldErrors : {};
      mount(errorHost, h('div', { class: 'alert danger mb-16' },
        Object.entries(fieldErrors).map(([k, v]) => `${k}: ${v}`).join(' · ') || err.message));
      reportError(err);
    }
  });

  return ref_;
}

export default { openLeadForm };
