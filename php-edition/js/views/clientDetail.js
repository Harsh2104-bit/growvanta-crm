/**
 * Super Admin: one client company.
 *
 * Read-only windows into their users, teams, projects and leads, so support
 * questions can be answered without switching context - plus branding, the
 * communication configuration, and the lifecycle controls.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import {
  toast, reportError, pageLoader, emptyState, tabs, dataTable, field, input,
  select, confirmDialog, avatar, kpiCard, textarea, modal,
} from '../lib/ui.js';
import { number, titleCase, dateTime, date } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';
import { navigate } from '../router.js';
import { COMPANY_TYPES, channelDot, isPlatformAdmin, switchToClient, BILLING_STATUSES } from './clients.js';
import { configurationCard } from './communication.js';

export default async function render({ params, query }) {
  if (!isPlatformAdmin()) return { node: noAccess('the platform administration area') };

  const node = h('div', null, pageLoader('Loading client'));
  let client;

  async function load() {
    try {
      client = (await api.get(`/api/platform/clients/${params.id}`)).data;
    } catch (err) {
      mount(node, h('div', { class: 'card' }, emptyState({
        iconName: 'building',
        title: 'Client not found',
        message: err.message,
        action: h('a', { class: 'btn btn-primary', href: '#/clients' }, 'Back to clients'),
      })));
      return;
    }

    clear(node);
    node.appendChild(pageHead({
      title: client.name,
      subtitle: `${titleCase(client.companyType)} · ${number(client.stats.users)} users · ${number(client.stats.leads)} leads`,
      breadcrumbs: [{ label: 'Clients', href: '#/clients' }, { label: client.name }],
      actions: [
        h('button', {
          class: 'btn btn-primary',
          onClick: () => switchToClient(client.id, client.name),
        }, icon('eye', { size: 15 }), 'Open their CRM'),
      ],
    }));

    const tabHost = h('div');
    const active = query.tab || 'overview';
    node.appendChild(tabs([
      { key: 'overview', label: 'Overview' },
      { key: 'branding', label: 'Branding' },
      { key: 'billing', label: 'Billing & access' },
      { key: 'messaging', label: 'Messaging' },
      { key: 'users', label: 'Users' },
      { key: 'teams', label: 'Teams' },
      { key: 'projects', label: 'Projects' },
      { key: 'leads', label: 'Leads' },
      { key: 'danger', label: 'Lifecycle' },
    ], active, (key) => navigate(`/clients/${params.id}?tab=${key}`)));
    node.appendChild(tabHost);

    const panels = {
      overview: () => overviewTab(client),
      branding: () => brandingTab(client, load),
      billing: () => billingTab(client, load),
      messaging: () => messagingTab(client),
      users: () => listTab(client.id, 'users'),
      teams: () => listTab(client.id, 'teams'),
      projects: () => listTab(client.id, 'projects'),
      leads: () => listTab(client.id, 'leads'),
      danger: () => lifecycleTab(client, load),
    };
    mount(tabHost, (panels[active] || panels.overview)());
  }

  await load();
  return { node };
}

function overviewTab(client) {
  const stats = [
    ['Users', client.stats.users], ['Teams', client.stats.teams],
    ['Projects', client.stats.projects], ['Leads', client.stats.leads],
    ['Leads today', client.stats.leadsToday], ['Leads this month', client.stats.leadsThisMonth],
  ];
  const details = [
    ['Company type', titleCase(client.companyType)],
    ['Uses global projects', client.usesGlobalProjects ? 'Yes' : 'No'],
    ['Status', titleCase(client.status)],
    ['Website', client.website || '—'],
    ['Email', client.email || '—'],
    ['Phone', client.phone || '—'],
    ['City', client.city || '—'],
    ['Currency', client.currency],
    ['Timezone', client.timezone],
    ['Created', dateTime(client.createdAt)],
  ];

  return h('div', { class: 'detail-layout' },
    h('div', null,
      panel('At a glance', h('div', { class: 'kpi-grid' },
        ...stats.map(([label, value]) => kpiCard({ label, value: number(value) })))),
      panel('Administrators', h('div', null,
        ...(client.admins?.length
          ? client.admins.map((u) => h('div', { class: 'row', style: { gap: '10px', padding: '8px 0' } },
            avatar(`${u.firstName} ${u.lastName || ''}`, { size: 'sm' }),
            h('div', { class: 'grow' },
              h('div', { class: 'strong' }, `${u.firstName} ${u.lastName || ''}`.trim()),
              h('div', { class: 'cell-sub' }, u.email)),
            h('div', { class: 'tiny muted' },
              u.lastLoginAt ? `Last in ${dateTime(u.lastLoginAt)}` : 'Never signed in')))
          : [h('div', { class: 'muted small' }, 'No administrators found.')])))),
    h('div', null,
      panel('Details', h('div', { class: 'info-list' },
        ...details.map(([label, value]) => h('div', { class: 'info-row' },
          h('span', { class: 'muted' }, label),
          h('span', { class: 'strong' }, String(value))))))));
}

function brandingTab(client, reload) {
  const errorHost = h('div');
  const nameInput = input({ value: client.name });
  const legalInput = input({ value: client.legalName || '' });
  const typeSelect = select(COMPANY_TYPES.map((t) => ({ value: t.value, label: t.label })),
    { value: client.companyType });
  const primaryInput = input({ type: 'color', value: client.brandPrimary || '#35e06f' });
  const accentInput = input({ type: 'color', value: client.brandAccent || '#0d1b2a' });
  const headlineInput = input({
    value: client.loginHeadline || '',
    placeholder: 'Shown on their sign-in screen',
  });

  const logoPreview = h('div', {
    class: 'center',
    style: {
      width: '80px', height: '80px', borderRadius: '12px', background: 'var(--bg-2)',
      display: 'flex', color: 'var(--ink-3)', overflow: 'hidden', flexShrink: 0,
    },
  }, client.logoUrl
    ? h('img', {
      onError: (e) => { e.target.style.display = 'none'; },
      src: client.logoUrl, alt: '',
      style: { width: '100%', height: '100%', objectFit: 'contain' },
    })
    : icon('building', { size: 32 }));

  const logoFile = h('input', {
    type: 'file',
    accept: 'image/png,image/jpeg,image/webp,image/svg+xml',
    onChange: async () => {
      const file = logoFile.files?.[0];
      if (!file) return;
      const form = new FormData();
      form.append('file', file);
      try {
        await api.upload(`/api/platform/clients/${client.id}/logo`, form);
        toast.success('Logo updated');
        reload();
      } catch (err) { reportError(err); }
    },
  });

  const save = h('button', { class: 'btn btn-primary' }, 'Save branding');
  save.addEventListener('click', async () => {
    save.disabled = true;
    try {
      await api.patch(`/api/platform/clients/${client.id}`, {
        name: nameInput.value.trim(),
        legalName: legalInput.value.trim() || null,
        companyType: typeSelect.value,
        brandPrimary: primaryInput.value,
        brandAccent: accentInput.value,
        loginHeadline: headlineInput.value.trim() || null,
      });
      toast.success('Branding saved');
      reload();
    } catch (err) {
      save.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });

  return panel('Company branding', h('div', null,
    errorHost,
    h('p', { class: 'muted small', style: { marginTop: 0 } },
      'The logo and name appear on this client’s login screen, sidebar, reports and exports.'),
    h('div', { class: 'row', style: { gap: '16px', alignItems: 'flex-start' } },
      logoPreview,
      h('div', { class: 'grow' }, field('Logo', logoFile))),
    h('div', { class: 'form-grid' },
      field('Company name', nameInput),
      field('Legal name', legalInput),
      field('Company type', typeSelect),
      field('Primary colour', primaryInput),
      field('Accent colour', accentInput)),
    field('Login headline', headlineInput),
    save));
}

/**
 * Subscription and sign-in access for one client.
 *
 * The block switch is deliberately its own control rather than a side effect of
 * the billing status: being a day late should not lock out a sales floor, so a
 * human decides when non-payment becomes a lockout. Blocking ends every open
 * session at once, and the reason typed here is what the customer reads on the
 * sign-in screen — so it should name the invoice, not just say "blocked".
 */
function billingTab(client, reload) {
  const b = client.billing || {};
  const errorHost = h('div');

  const statusSelect = select(BILLING_STATUSES.map((s) => ({ value: s.value, label: s.label })),
    { value: b.status || 'ACTIVE' });
  const planInput = input({ value: b.planName || '', placeholder: 'Growth' });
  const seatsInput = input({ type: 'number', min: '0', value: b.seats ?? '' });
  const feeInput = input({ type: 'number', min: '0', step: '100', value: b.monthlyFee ?? '', placeholder: '15000' });
  const trialInput = input({ type: 'date', value: b.trialEndsAt || '' });
  const renewsInput = input({ type: 'date', value: b.renewsAt || '' });
  const paidInput = input({ type: 'date', value: (b.lastPaymentAt || '').slice(0, 10) });
  const notesInput = textarea({ value: b.notes || '', rows: 2, placeholder: 'PO number, billing contact, anything worth remembering.' });

  const save = h('button', { class: 'btn btn-primary' }, 'Save subscription');
  save.addEventListener('click', async () => {
    save.disabled = true;
    try {
      await api.patch(`/api/platform/clients/${client.id}`, {
        billingStatus: statusSelect.value,
        planName: planInput.value.trim() || null,
        seats: seatsInput.value === '' ? null : Number(seatsInput.value),
        monthlyFee: feeInput.value === '' ? null : Number(feeInput.value),
        trialEndsAt: trialInput.value || null,
        renewsAt: renewsInput.value || null,
        lastPaymentAt: paidInput.value || null,
        billingNotes: notesInput.value.trim() || null,
      });
      toast.success('Subscription updated');
      reload();
    } catch (err) {
      save.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });

  const setAccess = async (blocked, reason) => {
    try {
      const res = await api.post(`/api/platform/clients/${client.id}/access`, { blocked, reason });
      const ended = res.data?.sessionsEnded || 0;
      toast.success(
        blocked ? 'Sign-in blocked for this company' : 'Sign-in restored',
        blocked
          ? `${ended} open session${ended === 1 ? '' : 's'} ended. Their people see the reason on the sign-in screen.`
          : 'Their people can sign in again straight away.',
      );
      reload();
    } catch (err) {
      reportError(err);
    }
  };

  const blockButton = h('button', { class: 'btn btn-danger' },
    icon('alert', { size: 15 }), 'Block sign-in for non-payment');
  blockButton.addEventListener('click', async () => {
    const reasonInput = input({
      value: b.accessBlockedReason
        || `Subscription payment outstanding${b.planName ? ` on the ${b.planName} plan` : ''}.`,
    });
    const confirmBtn = h('button', { class: 'btn btn-danger' }, 'Block sign-in now');
    const dialog = modal({
      title: `Block sign-in for ${client.name}?`,
      body: h('div', null,
        h('div', { class: 'alert warning mb-16' },
          `Everyone at this company is signed out immediately and cannot sign back in. `
          + `Their data is untouched, and you can restore access in one click.`),
        field('Reason shown on their sign-in screen', reasonInput, {
          hint: 'Name the invoice if you can — it saves a support call.',
          required: true,
        })),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        confirmBtn),
    });
    confirmBtn.addEventListener('click', () => {
      dialog.close();
      setAccess(true, reasonInput.value.trim());
    });
  });

  const restoreButton = h('button', {
    class: 'btn btn-primary',
    onClick: () => setAccess(false, null),
  }, icon('check', { size: 15 }), 'Restore sign-in');

  const blockedBanner = b.accessBlocked
    ? h('div', { class: 'alert danger mb-16' },
      h('div', null,
        h('strong', null, 'Sign-in is blocked for this company. '),
        b.accessBlockedReason || 'No reason recorded.',
        b.accessBlockedAt ? h('div', { class: 'tiny mt-8' }, `Blocked ${dateTime(b.accessBlockedAt)}`) : null))
    : null;

  const overdueBanner = !b.accessBlocked && b.overdue
    ? h('div', { class: 'alert warning mb-16' },
      h('div', null,
        h('strong', null, 'Payment is overdue. '),
        b.renewsAt ? `Renewal date was ${date(b.renewsAt)}. ` : '',
        'They can still sign in — block access below if you want that to stop.'))
    : null;

  const renewalLine = b.renewsAt
    ? (b.daysUntilRenewal >= 0
      ? `Renews in ${b.daysUntilRenewal} day${b.daysUntilRenewal === 1 ? '' : 's'} (${date(b.renewsAt)})`
      : `Renewal lapsed ${Math.abs(b.daysUntilRenewal)} day${Math.abs(b.daysUntilRenewal) === 1 ? '' : 's'} ago`)
    : 'No renewal date set';

  return h('div', null,
    blockedBanner,
    overdueBanner,
    panel('Sign-in access', h('div', null,
      h('p', { class: 'muted small', style: { marginTop: 0 } },
        b.accessBlocked
          ? 'Nobody at this company can sign in right now.'
          : 'Everyone at this company can sign in normally.'),
      h('div', { class: 'row', style: { gap: '8px' } },
        b.accessBlocked ? restoreButton : blockButton)),
    { subtitle: 'Blocking takes effect immediately and ends open sessions.' }),

    panel('Subscription', h('div', null,
      errorHost,
      h('div', { class: 'form-grid' },
        field('Billing status', statusSelect),
        field('Plan', planInput),
        field('Seats', seatsInput),
        field('Monthly fee', feeInput, { hint: client.currency || 'INR' }),
        field('Trial ends', trialInput),
        field('Renews on', renewsInput, { hint: renewalLine }),
        field('Last payment', paidInput)),
      field('Billing notes', notesInput),
      save)));
}

function messagingTab(client) {
  return h('div', null,
    h('p', { class: 'muted small' },
      'Green means this client could send a message right now. Orange means the switch is on but the setup is unfinished.'),
    h('div', { class: 'form-grid' },
      configurationCard('WhatsApp', client.messaging?.whatsapp, { readOnly: true }),
      configurationCard('Email', client.messaging?.email, { readOnly: true })),
    h('a', {
      class: 'btn mt-16',
      href: `#/communication?organizationId=${client.id}`,
    }, icon('settings', { size: 15 }), `Configure messaging for ${client.name}`));
}

const LIST_CONFIG = {
  users: {
    title: 'Users',
    columns: [
      {
        label: 'Name',
        render: (r) => h('div', null,
          h('div', { class: 'cell-main' }, `${r.firstName} ${r.lastName || ''}`.trim()),
          h('div', { class: 'cell-sub' }, r.email)),
      },
      { label: 'Role', render: (r) => r.roleName || '—' },
      { label: 'Team', render: (r) => r.teamName || '—' },
      { label: 'Designation', cellClass: 'hide-sm', render: (r) => r.designation || '—' },
      {
        label: 'Status',
        render: (r) => h('span', { class: `badge ${r.isActive ? 'badge-success' : ''}` }, r.isActive ? 'Active' : 'Inactive'),
      },
      { label: 'Last seen', cellClass: 'hide-sm', render: (r) => (r.lastLoginAt ? dateTime(r.lastLoginAt) : 'Never') },
    ],
  },
  teams: {
    title: 'Teams',
    columns: [
      { label: 'Team', render: (r) => h('div', { class: 'cell-main' }, r.name) },
      { label: 'Region', render: (r) => r.region || '—' },
      { label: 'Members', cellClass: 'right', render: (r) => number(r.memberCount) },
    ],
  },
  projects: {
    title: 'Projects',
    columns: [
      {
        label: 'Project',
        render: (r) => h('div', null,
          h('div', { class: 'cell-main' }, r.name),
          h('div', { class: 'cell-sub' }, `${r.code} · ${r.developerName}`)),
      },
      { label: 'Location', render: (r) => r.location },
      { label: 'Type', render: (r) => titleCase(r.projectType) },
      { label: 'Visibility', render: (r) => h('span', { class: 'badge' }, titleCase(r.visibility)) },
    ],
  },
  leads: {
    title: 'Leads',
    columns: [
      {
        label: 'Lead',
        render: (r) => h('div', null,
          h('div', { class: 'cell-main' }, `${r.firstName} ${r.lastName || ''}`.trim()),
          h('div', { class: 'cell-sub' }, r.code)),
      },
      { label: 'Phone', render: (r) => r.phone || '—' },
      { label: 'Project', render: (r) => r.projectName || '—' },
      { label: 'Source', cellClass: 'hide-sm', render: (r) => r.sourceName || '—' },
      { label: 'Status', render: (r) => r.statusName || '—' },
      {
        label: 'Owner',
        render: (r) => (r.ownerFirstName ? `${r.ownerFirstName} ${r.ownerLastName || ''}`.trim() : 'Unassigned'),
      },
      { label: 'Created', cellClass: 'hide-sm', render: (r) => date(r.createdAt) },
    ],
  },
};

function listTab(clientId, kind) {
  const cfg = LIST_CONFIG[kind];
  const table = dataTable({
    columns: cfg.columns,
    load: (params) => api.get(`/api/platform/clients/${clientId}/${kind}`, params),
    emptyTitle: `No ${kind} yet`,
    emptyMessage: `This company has not created any ${kind}.`,
  });
  return panel(cfg.title, table.root, { subtitle: 'Read-only view.', flush: true });
}

function lifecycleTab(client, reload) {
  const setStatus = async (status) => {
    if (status !== 'ACTIVE') {
      const ok = await confirmDialog({
        title: status === 'SUSPENDED' ? `Suspend ${client.name}?` : `Deactivate ${client.name}?`,
        message: 'Everyone at this company is signed out immediately and cannot sign in again until you reactivate them. Their data is untouched.',
        confirmLabel: titleCase(status),
        danger: true,
      });
      if (!ok) return;
    }
    try {
      await api.patch(`/api/platform/clients/${client.id}`, { status });
      toast.success('Status updated');
      reload();
    } catch (err) { reportError(err); }
  };

  const deleteClient = async () => {
    const ok = await confirmDialog({
      title: `Permanently delete ${client.name}?`,
      message: `This removes ${number(client.stats.users)} users, ${number(client.stats.projects)} projects and ${number(client.stats.leads)} leads. It cannot be undone.`,
      confirmLabel: 'Delete this company forever',
      danger: true,
      requireText: client.name,
    });
    if (!ok) return;
    try {
      await api.del(`/api/platform/clients/${client.id}`, { body: { confirmName: client.name } });
      toast.success('Company deleted');
      navigate('/clients');
    } catch (err) { reportError(err); }
  };

  return h('div', null,
    panel('Status', h('div', null,
      h('p', { class: 'muted small', style: { marginTop: 0 } },
        `This company is currently ${titleCase(client.status)}.`),
      h('div', { class: 'row', style: { gap: '8px' } },
        client.status !== 'ACTIVE'
          ? h('button', { class: 'btn btn-primary', onClick: () => setStatus('ACTIVE') }, 'Reactivate')
          : null,
        client.status !== 'SUSPENDED'
          ? h('button', { class: 'btn', onClick: () => setStatus('SUSPENDED') }, 'Suspend')
          : null,
        client.status !== 'INACTIVE'
          ? h('button', { class: 'btn', onClick: () => setStatus('INACTIVE') }, 'Deactivate')
          : null))),
    panel('Delete company', h('div', null,
      h('div', { class: 'alert danger mb-16' },
        'Deleting a company removes every user, team, project, lead, note and report it owns. There is no undo and no backup taken.'),
      h('button', { class: 'btn btn-danger', onClick: deleteClient }, 'Delete this company'))));
}
