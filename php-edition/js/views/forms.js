/**
 * Web form builder.
 *
 * Admins compose the field list, map each field onto a lead column, choose the
 * routing, and copy either the script embed or the iframe. The same definition
 * drives the hosted page, the embed script and server-side validation.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, reference } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, drawer, modal, field, input, textarea,
  select, confirmDialog, copyToClipboard, dropdown, menuItem, tabs, switchToggle,
} from '../lib/ui.js';
import { number, relative, date, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

const FIELD_TYPES = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox', 'hidden'];
const MAPPABLE = [
  '', 'firstName', 'lastName', 'email', 'phone', 'altPhone', 'company', 'jobTitle', 'website',
  'country', 'state', 'city', 'address', 'pincode', 'notes', 'expectedValue',
];

export default async function render() {
  if (!can('forms', 'read')) return { node: noAccess('web forms') };

  const listHost = h('div', null, pageLoader('Loading forms'));
  const node = h('div', null,
    pageHead({
      title: 'Web Forms',
      subtitle: 'Embeddable capture forms. Every submission becomes a lead, routed by your rules.',
      actions: can('forms', 'create')
        ? [h('button', { class: 'btn btn-primary', onClick: () => openFormBuilder({ onSaved: load }) }, icon('plus', { size: 15 }), 'New form')]
        : [],
    }),
    listHost);

  async function load() {
    try {
      const forms = await api.data('/api/forms');
      if (!forms.length) {
        mount(listHost, h('div', { class: 'card' }, emptyState({
          iconName: 'forms',
          title: 'No forms yet',
          message: 'Build a form, paste one line of HTML into your website, and leads land here automatically.',
          action: can('forms', 'create')
            ? h('button', { class: 'btn btn-primary', onClick: () => openFormBuilder({ onSaved: load }) }, 'Create your first form')
            : null,
        })));
        return;
      }
      mount(listHost, h('div', { class: 'chart-grid half' }, ...forms.map(renderCard)));
    } catch (err) {
      reportError(err);
      mount(listHost, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load forms', message: err.message })));
    }
  }

  function renderCard(form) {
    return h('section', { class: 'card' },
      h('div', { class: 'card-head' },
        h('div', { style: { minWidth: 0 } },
          h('h3', { class: 'truncate' }, form.name),
          h('div', { class: 'sub truncate' }, form.description || 'No description')),
        h('div', { class: 'row', style: { gap: '6px' } },
          h('span', { class: `badge ${form.isActive ? 'badge-success' : ''}` }, form.isActive ? 'Live' : 'Paused'),
          dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'Form actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              menuItem('Preview hosted page', {
                iconName: 'eye', close, onClick: () => window.open(form.hostedUrl, '_blank', 'noopener'),
              }),
              menuItem('Embed code', { iconName: 'copy', close, onClick: () => showEmbed(form) }),
              menuItem('Submissions', { iconName: 'list', close, onClick: () => showSubmissions(form) }),
              can('forms', 'update')
                ? menuItem('Edit', { iconName: 'edit', close, onClick: () => openFormBuilder({ form, onSaved: load }) })
                : null,
              can('forms', 'update')
                ? menuItem(form.isActive ? 'Pause' : 'Activate', {
                  iconName: form.isActive ? 'close' : 'check', close,
                  onClick: async () => {
                    try {
                      await api.patch(`/api/forms/${form.id}`, { isActive: !form.isActive });
                      toast.success(form.isActive ? 'Form paused' : 'Form activated');
                      load();
                    } catch (err) { reportError(err); }
                  },
                })
                : null,
              can('forms', 'delete')
                ? menuItem('Delete', {
                  iconName: 'trash', close, danger: true,
                  onClick: async () => {
                    const yes = await confirmDialog({
                      title: 'Delete this form?',
                      message: `${form.name} will stop accepting submissions immediately. Existing leads are kept.`,
                      danger: true, confirmLabel: 'Delete', requireText: 'DELETE',
                    });
                    if (!yes) return;
                    try { await api.del(`/api/forms/${form.id}`); toast.success('Form deleted'); load(); } catch (err) { reportError(err); }
                  },
                })
                : null),
          ))),
      h('div', { class: 'card-body' },
        h('div', { class: 'row wrap mb-16', style: { gap: '18px' } },
          stat('Submissions', number(form.submissionCount)),
          stat('Fields', number(form.fields.length)),
          stat('Created', date(form.createdAt))),
        h('div', { class: 'row wrap', style: { gap: '6px' } },
          h('button', { class: 'btn btn-sm', onClick: () => showEmbed(form) }, icon('copy', { size: 14 }), 'Embed code'),
          h('a', { class: 'btn btn-sm', href: form.hostedUrl, target: '_blank', rel: 'noopener' }, icon('link', { size: 14 }), 'Open hosted page'),
          h('button', { class: 'btn btn-sm', onClick: () => showSubmissions(form) }, icon('list', { size: 14 }), 'Submissions'))));
  }

  const stat = (label, value) => h('div', null,
    h('div', { class: 'tiny muted' }, label),
    h('div', { class: 'strong' }, value));

  function showEmbed(form) {
    let mode = 'script';
    const codeHost = h('pre', { class: 'code-block' });
    const draw = () => {
      codeHost.textContent = mode === 'script' ? form.embedScript
        : mode === 'iframe' ? form.iframeEmbed
          : `POST ${form.apiEndpoint}\nContent-Type: application/json\n\n${JSON.stringify(
            Object.fromEntries(form.fields.map((f) => [f.key, `<${f.label}>`])), null, 2,
          )}`;
    };
    draw();

    const dialog = modal({
      title: 'Embed this form',
      subtitle: 'Paste it wherever the form should appear. It works on any site.',
      size: 'wide',
      body: h('div', null,
        h('div', { class: 'btn-group mb-16' },
          ...[['script', 'Script embed'], ['iframe', 'iframe'], ['api', 'Direct API']].map(([key, label]) => h('button', {
            class: mode === key ? 'active' : '',
            onClick: (e) => {
              mode = key;
              [...e.currentTarget.parentElement.children].forEach((b) => b.classList.remove('active'));
              e.currentTarget.classList.add('active');
              draw();
            },
          }, label))),
        codeHost,
        h('div', { class: 'alert info mt-16' },
          icon('info', { size: 16 }),
          h('div', null,
            h('strong', null, 'Origin restrictions'),
            form.allowedOrigins
              ? `Only accepted from: ${form.allowedOrigins}`
              : 'This form accepts submissions from any website. Add allowed origins when you edit it to lock that down.'))),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
        h('button', {
          class: 'btn btn-primary',
          onClick: () => copyToClipboard(codeHost.textContent, 'Embed code copied'),
        }, icon('copy', { size: 15 }), 'Copy')),
    });
  }

  async function showSubmissions(form) {
    const body = h('div', null, pageLoader());
    const dialog = drawer({
      title: `${form.name} submissions`,
      subtitle: `${number(form.submissionCount)} total`,
      wide: true,
      body,
    });
    try {
      const res = await api.get(`/api/forms/${form.id}/submissions`, { limit: 50 });
      const rows = res.data;
      if (!rows.length) {
        mount(body, emptyState({ iconName: 'forms', title: 'No submissions yet', message: 'Nothing has been submitted through this form.' }));
        return;
      }
      mount(body, h('div', { class: 'col', style: { gap: '10px' } }, ...rows.map((sub) => h('div', {
        style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px' },
      },
        h('div', { class: 'row-between mb-8' },
          h('span', {
            class: `badge ${{ ACCEPTED: 'badge-success', DUPLICATE: 'badge-warning', REJECTED: 'badge-danger' }[sub.status] || ''}`,
          }, titleCase(sub.status)),
          h('span', { class: 'tiny muted' }, relative(sub.createdAt))),
        sub.message ? h('div', { class: 'tiny muted mb-8' }, sub.message) : null,
        h('div', { class: 'col tiny', style: { gap: '2px' } },
          ...Object.entries(sub.payload)
            .filter(([key]) => !key.startsWith('_'))
            .map(([key, value]) => h('div', { class: 'row', style: { gap: '8px' } },
              h('span', { class: 'muted', style: { minWidth: '110px' } }, key),
              h('span', { class: 'truncate' }, String(value)))))))));
    } catch (err) {
      mount(body, emptyState({ iconName: 'alert', title: 'Could not load submissions', message: err.message }));
    }
  }

  await load();
  return { node };
}

// ---------------------------------------------------------------------------
// Builder
// ---------------------------------------------------------------------------
async function openFormBuilder({ form = null, onSaved } = {}) {
  const isEdit = Boolean(form?.id);
  const ref = await reference();

  const nameInput = input({ value: form?.name || '', placeholder: 'Request a Demo' });
  const descInput = textarea({ value: form?.description || '', rows: 2, placeholder: 'Shown above the form.' });
  const submitLabelInput = input({ value: form?.submitLabel || 'Submit' });
  const successInput = input({ value: form?.successMessage || 'Thanks! Our team will contact you shortly.' });
  const redirectInput = input({ value: form?.redirectUrl || '', placeholder: 'https://yoursite.com/thank-you' });
  const originsInput = input({ value: form?.allowedOrigins || '', placeholder: 'https://yoursite.com, https://www.yoursite.com' });
  const sourceSelect = select(ref.sources.map((s) => ({ value: s.id, label: s.name })), {
    value: form?.sourceId || ref.sources.find((s) => s.key === 'website')?.id || '', placeholder: 'No source',
  });
  const projectSelect = select((ref.projects || []).map((p) => ({ value: p.id, label: p.name })), {
    value: form?.projectId || '', placeholder: 'No specific project',
  });
  const userSelect = select(ref.users.map((u) => ({ value: u.id, label: u.fullName || u.name })), {
    value: form?.assignToUserId || '', placeholder: 'Use the assignment rules',
  });
  const teamSelect = select(ref.teams.map((t) => ({ value: t.id, label: t.name })), {
    value: form?.assignToTeamId || '', placeholder: 'No specific team',
  });

  let useRules = form ? form.useAssignmentRules : true;
  const fieldsHost = h('div', { class: 'col', style: { gap: '10px' } });

  let fields = form?.fields ? structuredClone(form.fields) : [
    { key: 'firstName', label: 'First name', type: 'text', required: true, mapTo: 'firstName' },
    { key: 'lastName', label: 'Last name', type: 'text', required: false, mapTo: 'lastName' },
    { key: 'email', label: 'Work email', type: 'email', required: true, mapTo: 'email' },
    { key: 'phone', label: 'Phone', type: 'tel', required: true, mapTo: 'phone' },
    { key: 'company', label: 'Company', type: 'text', required: false, mapTo: 'company' },
    { key: 'notes', label: 'How can we help?', type: 'textarea', required: false, mapTo: 'notes' },
  ];

  function renderFields() {
    mount(fieldsHost, ...fields.map((f, index) => h('div', {
      style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px', background: 'var(--surface-2)' },
    },
      h('div', { class: 'row-between mb-8' },
        h('span', { class: 'strong small' }, `Field ${index + 1}`),
        h('div', { class: 'row', style: { gap: '4px' } },
          h('button', {
            class: 'icon-btn', title: 'Move up', disabled: index === 0,
            onClick: () => { [fields[index - 1], fields[index]] = [fields[index], fields[index - 1]]; renderFields(); },
          }, icon('arrowUp', { size: 14 })),
          h('button', {
            class: 'icon-btn', title: 'Move down', disabled: index === fields.length - 1,
            onClick: () => { [fields[index + 1], fields[index]] = [fields[index], fields[index + 1]]; renderFields(); },
          }, icon('arrowDown', { size: 14 })),
          h('button', {
            class: 'icon-btn', title: 'Remove',
            onClick: () => { fields.splice(index, 1); renderFields(); },
          }, icon('trash', { size: 14 })))),
      h('div', { class: 'form-grid' },
        field('Label', input({
          value: f.label,
          onInput: (e) => {
            f.label = e.target.value;
            if (!f.mapTo) f.key = e.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || f.key;
          },
        })),
        field('Type', select(FIELD_TYPES.map((t) => ({ value: t, label: titleCase(t) })), {
          value: f.type, onChange: (e) => { f.type = e.target.value; renderFields(); },
        })),
        field('Maps to lead field', select(MAPPABLE.map((m) => ({ value: m, label: m || 'Custom field (stored as-is)' })), {
          value: f.mapTo || '',
          onChange: (e) => { f.mapTo = e.target.value || null; if (f.mapTo) f.key = f.mapTo; },
        })),
        field('Placeholder', input({ value: f.placeholder || '', onInput: (e) => { f.placeholder = e.target.value; } }))),
      f.type === 'select'
        ? field('Options (one per line)', textarea({
          rows: 3,
          value: (f.options || []).join('\n'),
          onInput: (e) => { f.options = e.target.value.split('\n').map((v) => v.trim()).filter(Boolean); },
        }))
        : null,
      h('label', { class: 'checkbox' },
        h('input', { type: 'checkbox', checked: f.required, onChange: (e) => { f.required = e.target.checked; } }),
        'Required'))));
  }
  renderFields();

  const errorHost = h('div');
  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save form' : 'Create form');

  const ref_ = drawer({
    title: isEdit ? 'Edit form' : 'New web form',
    subtitle: 'Fields, routing and the thank-you behaviour.',
    wide: true,
    body: h('div', null,
      errorHost,
      field('Form name', nameInput, { required: true }),
      field('Description', descInput),

      h('h4', { class: 'mt-16 mb-8' }, 'Fields'),
      h('div', { class: 'alert info mb-16' },
        icon('info', { size: 16 }),
        h('div', null, 'One field must map to First name, and at least one to Email or Phone, so leads can be de-duplicated.')),
      fieldsHost,
      h('button', {
        class: 'btn btn-sm mt-8',
        onClick: () => {
          fields.push({ key: `field_${fields.length + 1}`, label: 'New field', type: 'text', required: false, mapTo: null });
          renderFields();
        },
      }, icon('plus', { size: 14 }), 'Add field'),

      h('h4', { class: 'mt-16 mb-8' }, 'Attribution & routing'),
      h('div', { class: 'form-grid' },
        field('Lead source', sourceSelect),
        field('Project', projectSelect)),
      h('div', { class: 'mb-16' },
        switchToggle('Run the assignment rules on each submission', useRules, (value) => { useRules = value; })),
      h('div', { class: 'form-grid' },
        field('Or always assign to', userSelect, { hint: 'Overrides the rules when set.' }),
        field('Route to team', teamSelect)),

      h('h4', { class: 'mt-16 mb-8' }, 'After submission'),
      h('div', { class: 'form-grid' },
        field('Submit button label', submitLabelInput),
        field('Success message', successInput),
        field('Redirect URL', redirectInput, { hint: 'Optional. Sent after the success message.' }),
        field('Allowed origins', originsInput, { hint: 'Comma separated. Leave blank to accept any website.' }))),
    footer: h('div', { class: 'row' },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      saveButton),
  });

  saveButton.addEventListener('click', async () => {
    clear(errorHost);
    const payload = {
      name: nameInput.value.trim(),
      description: descInput.value.trim() || null,
      fields: fields.map((f) => ({
        key: f.key || f.label.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
        label: f.label,
        type: f.type,
        required: Boolean(f.required),
        placeholder: f.placeholder || null,
        options: f.options || [],
        mapTo: f.mapTo || null,
      })),
      sourceId: sourceSelect.value || null,
      projectId: projectSelect.value || null,
      assignToUserId: userSelect.value || null,
      assignToTeamId: teamSelect.value || null,
      useAssignmentRules: useRules,
      submitLabel: submitLabelInput.value.trim() || 'Submit',
      successMessage: successInput.value.trim() || undefined,
      redirectUrl: redirectInput.value.trim() || null,
      allowedOrigins: originsInput.value.trim(),
    };
    if (!payload.name) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'The form needs a name.'));
      return;
    }
    saveButton.disabled = true;
    try {
      const res = isEdit
        ? await api.patch(`/api/forms/${form.id}`, payload)
        : await api.post('/api/forms', payload);
      toast.success(isEdit ? 'Form saved' : 'Form created', 'Copy the embed code to put it on your site.');
      ref_.close();
      onSaved?.(res.data);
    } catch (err) {
      saveButton.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
}
