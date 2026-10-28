/**
 * Super Admin: the client companies on the platform.
 *
 * Creating a client is deliberately one screen and one button. Everything a
 * working CRM needs - roles, statuses, sources, pipeline, both communication
 * channels, the template library - is provisioned server-side, so onboarding a
 * developer or broker is company details plus one admin contact.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { store, loadSession } from '../lib/store.js';
import {
  dataTable, searchBox, toast, reportError, drawer, field, input, select,
  confirmDialog, dropdown, menuItem, avatar,
} from '../lib/ui.js';
import { number, titleCase, dateTime } from '../lib/format.js';
import { pageHead, noAccess } from './shared.js';
import { navigate } from '../router.js';

export const COMPANY_TYPES = [
  { value: 'DEVELOPER', label: 'Developer', hint: 'Builds and sells its own projects.' },
  { value: 'BROKER', label: 'Broker', hint: 'Sells other developers projects.' },
  { value: 'CHANNEL_PARTNER', label: 'Channel Partner', hint: 'Authorised partner for selected projects.' },
  { value: 'AGENCY', label: 'Agency', hint: 'Runs campaigns and routes leads.' },
];

const STATUS_TONE = { ACTIVE: 'badge-success', INACTIVE: '', SUSPENDED: 'badge-danger' };

// Mirrors GV_BILLING_STATUSES in lib/tenancy.php.
export const BILLING_STATUSES = [
  { value: 'ACTIVE', label: 'Active', tone: 'badge-success' },
  { value: 'TRIAL', label: 'Trial', tone: 'badge-info' },
  { value: 'PAST_DUE', label: 'Payment overdue', tone: 'badge-warning' },
  { value: 'CANCELLED', label: 'Cancelled', tone: 'badge-danger' },
];

/** Billing state as a badge, with the sign-in block taking visual priority. */
export function billingBadge(billing) {
  if (!billing) return h('span', { class: 'muted' }, '—');
  if (billing.accessBlocked) {
    return h('span', {
      class: 'badge badge-danger',
      title: billing.accessBlockedReason || 'Sign-in blocked',
    }, 'Sign-in blocked');
  }
  const found = BILLING_STATUSES.find((s) => s.value === billing.status);
  return h('span', {
    class: `badge ${found?.tone || ''}`,
    title: billing.renewsAt ? `Renews ${billing.renewsAt}` : undefined,
  }, found?.label || billing.status);
}

/** The RED/ORANGE/GREEN dot used across the platform screens. */
export function channelDot(status, label) {
  const tone = { GREEN: 'var(--success)', ORANGE: 'var(--warning)', RED: 'var(--danger)' }[status] || 'var(--ink-3)';
  const text = { GREEN: 'Active', ORANGE: 'Incomplete', RED: 'Off' }[status] || 'Unknown';
  return h('span', {
    class: 'row',
    style: { gap: '6px', alignItems: 'center' },
    title: `${label}: ${text}`,
  },
  h('span', {
    style: {
      width: '8px', height: '8px', borderRadius: '50%',
      background: tone, flexShrink: 0, display: 'inline-block',
    },
  }),
  h('span', { class: 'tiny muted' }, label));
}

export function isPlatformAdmin() {
  return Boolean(store.user?.isPlatformAdmin);
}

export default async function render({ query }) {
  if (!isPlatformAdmin()) return { node: noAccess('the platform administration area') };

  let table;
  const node = h('div', null,
    pageHead({
      title: 'Client companies',
      subtitle: 'Every developer, broker, channel partner and agency on the platform.',
      actions: [
        h('button', {
          class: 'btn btn-primary',
          onClick: () => openClientWizard({ onSaved: () => table.refresh() }),
        }, icon('plus', { size: 15 }), 'Create new client'),
      ],
    }));

  const typeFilter = select(
    [{ value: '', label: 'All company types' }, ...COMPANY_TYPES.map((t) => ({ value: t.value, label: t.label }))],
    { value: query.companyType || '', onChange: (e) => table.setParams({ companyType: e.target.value }) },
  );
  const statusFilter = select([
    { value: '', label: 'All statuses' },
    { value: 'ACTIVE', label: 'Active' },
    { value: 'INACTIVE', label: 'Inactive' },
    { value: 'SUSPENDED', label: 'Suspended' },
  ], { value: query.status || '', onChange: (e) => table.setParams({ status: e.target.value }) });

  table = dataTable({
    toolbar: h('div', { class: 'row wrap grow', style: { gap: '10px' } },
      searchBox('Search companies…', (v) => table.setParams({ q: v }), { value: query.q || '' }),
      typeFilter, statusFilter),
    initialParams: { q: query.q || '', companyType: query.companyType || '', status: query.status || '' },
    onRowClick: (row) => navigate(`/clients/${row.id}`),
    load: (params) => api.get('/api/platform/clients', params),
    emptyTitle: 'No client companies yet',
    emptyMessage: 'Create your first client to give them their own branded CRM workspace.',
    emptyAction: h('button', {
      class: 'btn btn-primary',
      onClick: () => openClientWizard({ onSaved: () => table.refresh() }),
    }, 'Create new client'),
    columns: [
      {
        label: 'Company',
        render: (row) => h('div', { class: 'row', style: { gap: '10px' } },
          row.logoUrl
            ? h('img', {
              onError: (e) => { e.target.style.display = 'none'; },
              src: row.logoUrl,
              alt: '',
              style: { width: '32px', height: '32px', borderRadius: '7px', objectFit: 'contain', background: 'var(--bg-2)' },
            })
            : avatar(row.name, { size: 'sm' }),
          h('div', { style: { minWidth: 0 } },
            h('div', { class: 'cell-main truncate' }, row.name),
            h('div', { class: 'cell-sub truncate' }, row.email || row.slug))),
      },
      { label: 'Type', render: (row) => h('span', { class: 'badge' }, titleCase(row.companyType)) },
      { label: 'Billing', render: (row) => billingBadge(row.billing) },
      {
        label: 'Status',
        render: (row) => h('span', { class: `badge ${STATUS_TONE[row.status] || ''}` }, titleCase(row.status)),
      },
      { label: 'Users', cellClass: 'right hide-sm', render: (row) => number(row.stats?.users) },
      { label: 'Projects', cellClass: 'right hide-sm', render: (row) => number(row.stats?.projects) },
      { label: 'Leads', cellClass: 'right', render: (row) => number(row.stats?.leads) },
      {
        label: 'Messaging',
        render: (row) => h('div', { style: { display: 'grid', gap: '3px' } },
          channelDot(row.messaging?.whatsapp?.status, 'WhatsApp'),
          channelDot(row.messaging?.email?.status, 'Email')),
      },
      {
        label: '',
        width: '48px',
        render: (row) => dropdown(
          h('button', { class: 'icon-btn', 'aria-label': 'Actions' }, icon('more', { size: 16 })),
          // dropdown() mounts whatever this returns, so it has to be a single
          // node - an array silently breaks the whole menu.
          (close) => h('div', null,
            menuItem('Open their CRM', {
              iconName: 'eye', close, onClick: () => switchToClient(row.id, row.name),
            }),
            menuItem('View details', {
              iconName: 'building', close, onClick: () => navigate(`/clients/${row.id}`),
            }),
            row.status === 'SUSPENDED'
              ? menuItem('Reactivate', {
                iconName: 'play', close, onClick: () => setStatus(row, 'ACTIVE', () => table.refresh()),
              })
              : menuItem('Suspend', {
                iconName: 'alert', danger: true, close,
                onClick: () => setStatus(row, 'SUSPENDED', () => table.refresh()),
              }),
          ),
        ),
      },
    ],
  });

  node.appendChild(table.root);
  return { node };
}

// ---------------------------------------------------------------------------
export async function switchToClient(organizationId, name) {
  try {
    await api.post('/api/platform/switch', { organizationId });
    // Reload the session so the shell repaints with the client's branding,
    // and land on their dashboard rather than leaving the admin on a
    // platform-only screen that no longer applies.
    await loadSession();
    toast.success(`Now working inside ${name}`);
    navigate('/');
    window.location.reload();
  } catch (err) {
    reportError(err);
  }
}

export async function exitClientContext() {
  try {
    await api.post('/api/platform/switch', { organizationId: null });
    await loadSession();
    navigate('/platform');
    window.location.reload();
  } catch (err) {
    reportError(err);
  }
}

async function setStatus(client, status, onDone) {
  if (status === 'SUSPENDED') {
    const ok = await confirmDialog({
      title: `Suspend ${client.name}?`,
      message: 'Everyone at this company will be signed out immediately and will not be able to sign in until you reactivate them. Their data is kept.',
      confirmLabel: 'Suspend company',
      danger: true,
    });
    if (!ok) return;
  }
  try {
    await api.patch(`/api/platform/clients/${client.id}`, { status });
    toast.success(status === 'ACTIVE' ? 'Company reactivated' : 'Company suspended');
    onDone?.();
  } catch (err) {
    reportError(err);
  }
}

// ---------------------------------------------------------------------------
// Create client
// ---------------------------------------------------------------------------
export function openClientWizard({ onSaved } = {}) {
  const errorHost = h('div');

  const companyName = input({ placeholder: 'ABC Realty' });
  const legalName = input({ placeholder: 'ABC Realty Private Limited' });
  const companyType = select(COMPANY_TYPES.map((t) => ({ value: t.value, label: t.label })), { value: 'DEVELOPER' });
  const typeHint = h('div', { class: 'tiny muted' }, COMPANY_TYPES[0].hint);
  companyType.addEventListener('change', () => {
    const found = COMPANY_TYPES.find((t) => t.value === companyType.value);
    typeHint.textContent = found?.hint || '';
  });

  const website = input({ placeholder: 'https://abcrealty.com' });
  const companyEmail = input({ type: 'email', placeholder: 'hello@abcrealty.com' });
  const companyPhone = input({ placeholder: '+91 98765 43210' });
  const address = input({ placeholder: 'Street address' });
  const city = input({ placeholder: 'Gurugram' });
  const stateInput = input({ placeholder: 'Haryana' });
  const pincode = input({ placeholder: '122004' });

  const adminName = input({ placeholder: 'Anita Rao' });
  const adminEmail = input({ type: 'email', placeholder: 'anita@abcrealty.com' });
  const adminPhone = input({ placeholder: '+91 98765 43210' });
  const password = input({ type: 'password', placeholder: 'At least 8 characters' });

  // Logo is uploaded after creation, because the tenant id is what names the
  // stored file. The preview here is local only.
  const logoFile = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/svg+xml' });
  const logoPreview = h('div', {
    class: 'center',
    style: {
      width: '64px', height: '64px', borderRadius: '10px', background: 'var(--bg-2)',
      display: 'flex', color: 'var(--ink-3)', overflow: 'hidden', flexShrink: 0,
    },
  }, icon('building', { size: 26 }));
  logoFile.addEventListener('change', () => {
    const file = logoFile.files?.[0];
    if (!file) return;
    clear(logoPreview);
    logoPreview.appendChild(h('img', {
      src: URL.createObjectURL(file),
      alt: '',
      style: { width: '100%', height: '100%', objectFit: 'contain' },
    }));
  });

  const saveButton = h('button', { class: 'btn btn-primary' }, 'Create client');

  const ref_ = drawer({
    title: 'Create new client',
    subtitle: 'A complete, branded CRM workspace is provisioned automatically.',
    wide: true,
    body: h('div', null,
      errorHost,
      h('h4', { class: 'mt-8' }, 'Company'),
      h('div', { class: 'row', style: { gap: '14px', alignItems: 'flex-start' } },
        logoPreview,
        h('div', { class: 'grow' }, field('Company logo', logoFile, {
          hint: 'PNG, JPG, WEBP or SVG. Appears on their login screen, sidebar and reports.',
        }))),
      h('div', { class: 'form-grid' },
        field('Company name', companyName, { required: true }),
        field('Legal name', legalName),
        field('Company type', h('div', null, companyType, typeHint), { required: true }),
        field('Website', website),
        field('Company email', companyEmail),
        field('Company phone', companyPhone)),
      field('Address', address),
      h('div', { class: 'form-grid' },
        field('City', city),
        field('State', stateInput),
        field('Pincode', pincode)),

      h('h4', { class: 'mt-16' }, 'Administrator'),
      h('p', { class: 'muted small', style: { marginTop: 0 } },
        'This person gets full control of their company workspace: users, teams, projects and settings.'),
      h('div', { class: 'form-grid' },
        field('Admin name', adminName, { required: true }),
        field('Admin email', adminEmail, { required: true, hint: 'This is their login.' }),
        field('Admin phone', adminPhone, { required: true }),
        field('Password', password, { required: true, hint: 'Upper and lower case plus a number.' }))),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      saveButton),
  });

  saveButton.addEventListener('click', async () => {
    const payload = {
      companyName: companyName.value.trim(),
      legalName: legalName.value.trim() || null,
      companyType: companyType.value,
      website: website.value.trim() || null,
      companyEmail: companyEmail.value.trim() || null,
      companyPhone: companyPhone.value.trim() || null,
      address: address.value.trim() || null,
      city: city.value.trim() || null,
      state: stateInput.value.trim() || null,
      pincode: pincode.value.trim() || null,
      adminName: adminName.value.trim(),
      adminEmail: adminEmail.value.trim(),
      adminPhone: adminPhone.value.trim() || null,
      password: password.value,
    };
    if (!payload.companyName || !payload.adminName || !payload.adminEmail || !payload.password) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' },
        'Company name, admin name, admin email and a password are all required.'));
      return;
    }

    saveButton.disabled = true;
    saveButton.textContent = 'Creating workspace…';
    try {
      const res = await api.post('/api/platform/clients', payload);
      const client = res.data;

      if (logoFile.files?.[0]) {
        const form = new FormData();
        form.append('file', logoFile.files[0]);
        try {
          await api.upload(`/api/platform/clients/${client.id}/logo`, form);
        } catch (err) {
          // The workspace exists; a failed logo is a detail they can retry.
          toast.error('Company created, but the logo upload failed', err.message);
        }
      }

      toast.success(`${client.name} is ready`, 'Their admin can sign in now.');
      ref_.close();
      onSaved?.(client);
    } catch (err) {
      saveButton.disabled = false;
      saveButton.textContent = 'Create client';
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });

  return ref_;
}
