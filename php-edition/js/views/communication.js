/**
 * WhatsApp and Email welcome automation.
 *
 * The rule this screen exists to make visible (spec sections 31 and 35) is
 * that turning the switch on is not the same as being ready to send:
 *
 *   RED     off
 *   ORANGE  on, but something required is missing or unproven
 *   GREEN   on, complete, and proven by a successful test send
 *
 * The verdict and the checklist both come from the server, which re-derives
 * them before every send. Nothing here computes its own colour, because a
 * screen that decided its own status could disagree with the thing that
 * actually sends the message.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import {
  toast, reportError, drawer, field, input, select, textarea, tabs, dataTable,
  pageLoader, switchToggle, confirmDialog, modal,
} from '../lib/ui.js';
import { titleCase, dateTime } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';
import { navigate } from '../router.js';

const TONE = {
  GREEN: { color: 'var(--success)', label: 'ACTIVE', emoji: '🟢' },
  ORANGE: { color: 'var(--warning)', label: 'CONFIGURATION INCOMPLETE', emoji: '🟠' },
  RED: { color: 'var(--danger)', label: 'OFF', emoji: '🔴' },
};

const MESSAGE = {
  WhatsApp: {
    GREEN: 'WhatsApp welcome messages are active and ready to send.',
    ORANGE: 'WhatsApp welcome messages are turned ON, but the configuration is incomplete. Complete the required configuration to activate automatic WhatsApp messages.',
    RED: 'WhatsApp welcome messages are disabled.',
  },
  Email: {
    GREEN: 'Welcome emails are active and ready to send.',
    ORANGE: 'Welcome emails are turned ON, but the configuration is incomplete. Complete the required configuration to activate automatic emails.',
    RED: 'Welcome emails are disabled.',
  },
};

/**
 * The status card. Shared with the Super Admin client screen, which renders it
 * read-only, so one component is the single visual definition of the states.
 */
/**
 * Alerts that go to your own team, not to the buyer.
 *
 * Kept apart from the welcome-message cards above because they answer a
 * different question: those decide what a buyer hears, these decide whether the
 * salesperson finds out at all. A company usually wants the second long before
 * it has agreed the wording of the first.
 *
 * They do NOT ride on the channel above. Staff alerts leave from Growvanta's
 * own mailbox, so they work on a company's first day, before anyone has opened
 * this screen. The card says so, because the obvious assumption is the opposite.
 */
function staffAlertsCard(em, qs, reload) {
  // /api/email returns the settings flat, with `configuration` nested inside.
  const s = em || {};

  const save = async (payload) => {
    try {
      await api.patch(`/api/email${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`, payload);
      toast.success('Alert settings saved');
      reload();
    } catch (err) { reportError(err); }
  };

  const minutes = select(
    [15, 30, 60, 120, 240].map((m) => ({ value: String(m), label: m >= 60 ? `${m / 60} hour${m === 60 ? '' : 's'} before` : `${m} minutes before` })),
    { value: String(s.followupLeadMinutes || 60), onChange: (e) => save({ followupLeadMinutes: Number(e.target.value) }) },
  );

  // Whole hours only: the digest is a "what is on me today" email, not an
  // appointment, and an odd minute would only imply a precision the sweep does
  // not have.
  const summaryHour = select(
    Array.from({ length: 24 }, (_, i) => ({
      value: String(i),
      label: `${((i + 11) % 12) + 1}:00 ${i < 12 ? 'am' : 'pm'}`,
    })),
    {
      value: String(s.dailySummaryHour ?? 8),
      onChange: (e) => save({ dailySummaryHour: Number(e.target.value) }),
    },
  );

  const cc = input({
    type: 'email',
    id: 'gv-notify-cc',
    value: s.notifyCcEmail || '',
    placeholder: 'sales.head@yourcompany.com',
    onChange: (e) => save({ notifyCcEmail: e.target.value.trim() }),
  });

  return h('div', { class: 'card mt-16' },
    h('div', { class: 'card-body' },
      h('div', { class: 'row-between', style: { alignItems: 'flex-start' } },
        h('div', null,
          h('strong', null, 'Alerts to your team'),
          h('div', { class: 'tiny muted', style: { marginTop: '3px' } },
            'Emails sent to the person who owns the lead.')),
        h('span', { class: 'badge badge-info' }, 'Sent by Growvanta')),

      h('div', { class: 'row-between mt-16', style: { alignItems: 'center', gap: '12px' } },
        h('div', null,
          h('div', null, 'New lead arrives'),
          h('div', { class: 'tiny muted' }, 'The owner is emailed the moment a lead lands or is handed to them.')),
        switchToggle('', Boolean(s.notifyNewLead), (checked) => save({ notifyNewLead: checked }))),

      h('div', { class: 'row-between mt-16', style: { alignItems: 'center', gap: '12px' } },
        h('div', null,
          h('div', null, 'Follow-up is coming up'),
          h('div', { class: 'tiny muted' }, 'A warning before the follow-up time, not after it has been missed.')),
        switchToggle('', Boolean(s.notifyFollowup), (checked) => save({ notifyFollowup: checked }))),

      s.notifyFollowup
        ? h('div', { class: 'mt-16' }, field('Warn me', minutes, {
          hint: 'The reminder runs every 15 minutes, so anything shorter would arrive late.',
        }))
        : null,

      h('div', { class: 'row-between mt-16', style: { alignItems: 'center', gap: '12px' } },
        h('div', null,
          h('div', null, 'Morning summary'),
          h('div', { class: 'tiny muted' }, 'One email listing today’s follow-ups and site visits.')),
        switchToggle('', Boolean(s.notifyDailySummary), (checked) => save({ notifyDailySummary: checked }))),

      s.notifyDailySummary
        ? h('div', { class: 'mt-16' }, field('Send it at', summaryHour, {
          hint: 'Nobody is emailed an empty list — a digest that always says "nothing today" stops being read.',
        }))
        : null,

      h('div', { class: 'mt-16' }, field('Also copy every new-lead alert to', cc, {
        hint: 'Optional — for a sales head who wants to see the flow without owning any of it.',
      })),

      h('div', { class: 'alert info mt-16' },
        h('strong', null, 'No setup needed. '),
        'These go out from Growvanta’s own mailbox, so they work whether or not you have '
        + 'configured the email channel above. That one is what your buyers hear from; this is '
        + 'what your team hears from.')));
}

export function configurationCard(channel, verdict, { readOnly = false, onToggle, onManage, onTest } = {}) {
  const status = verdict?.status || 'RED';
  const tone = TONE[status] || TONE.RED;
  const checklist = verdict?.checklist || [];

  return h('div', { class: 'card', style: { borderTop: `3px solid ${tone.color}` } },
    h('div', { class: 'card-body' },
      h('div', { class: 'row-between', style: { alignItems: 'flex-start' } },
        h('div', null,
          h('div', { class: 'row', style: { gap: '8px', alignItems: 'center' } },
            h('span', { style: { fontSize: '15px' } }, tone.emoji),
            h('strong', null, `${channel} welcome message`)),
          h('div', {
            class: 'tiny',
            style: { color: tone.color, fontWeight: '700', letterSpacing: '.04em', marginTop: '3px' },
          }, tone.label)),
        readOnly
          ? null
          : switchToggle('', Boolean(verdict?.enabled), (checked) => onToggle?.(checked))),

      h('p', { class: 'small muted', style: { marginTop: '10px' } },
        MESSAGE[channel]?.[status] || ''),

      // The missing items are always itemised. "Something is wrong" is not an
      // actionable message for a sales admin.
      status === 'ORANGE' && verdict?.missing?.length
        ? h('div', { class: 'field' },
          h('label', null, 'Still needed'),
          h('div', null, ...checklist.map((item) => h('div', {
            class: 'row',
            style: { gap: '7px', alignItems: 'center', padding: '2px 0' },
          },
          h('span', {
            style: { color: item.ok ? 'var(--success)' : 'var(--danger)', fontWeight: '700', width: '12px' },
          }, item.ok ? '✓' : '✗'),
          h('span', { class: 'small', style: { color: item.ok ? 'var(--ink-2)' : 'var(--ink)' } }, item.label),
          item.detail ? h('span', { class: 'tiny muted' }, `— ${item.detail}`) : null))))
        : null,

      status === 'GREEN' && verdict?.lastTestAt
        ? h('div', { class: 'tiny muted mt-8' }, `Last verified ${dateTime(verdict.lastTestAt)}`)
        : null,

      readOnly
        ? null
        : h('div', { class: 'row wrap mt-16', style: { gap: '8px' } },
          h('button', {
            class: status === 'GREEN' ? 'btn' : 'btn btn-primary',
            onClick: () => onManage?.(),
          }, status === 'GREEN' ? 'Manage configuration' : 'Complete configuration'),
          h('button', { class: 'btn', onClick: () => onTest?.() },
            icon('play', { size: 14 }), `Test ${channel.toLowerCase()}`))));
}

// ---------------------------------------------------------------------------
export default async function render({ query }) {
  const canSee = can('whatsapp', 'read') || can('email', 'read');
  if (!canSee) return { node: noAccess('messaging settings') };

  // A Super Admin may configure a selected client (spec section 27).
  const orgId = query.organizationId || null;
  const qs = orgId ? { organizationId: orgId } : {};

  const node = h('div', null, pageLoader('Loading messaging settings'));
  const activeTab = query.tab || 'automation';

  async function load() {
    let wa = null;
    let em = null;
    try {
      [wa, em] = await Promise.all([
        can('whatsapp', 'read') ? api.data('/api/whatsapp', qs) : Promise.resolve(null),
        can('email', 'read') ? api.data('/api/email', qs) : Promise.resolve(null),
      ]);
    } catch (err) {
      mount(node, h('div', { class: 'card' }, h('div', { class: 'alert danger' }, err.message)));
      return;
    }

    clear(node);
    node.appendChild(pageHead({
      title: 'Messaging & automation',
      subtitle: orgId
        ? 'Configuring a client company on their behalf.'
        : 'Welcome messages sent automatically when a new lead arrives.',
      breadcrumbs: orgId ? [{ label: 'Clients', href: '#/clients' }, { label: 'Messaging' }] : undefined,
    }));

    const tabHost = h('div');
    node.appendChild(tabs([
      { key: 'automation', label: 'Automation' },
      { key: 'templates', label: 'Templates' },
      { key: 'logs', label: 'Delivery log' },
    ], activeTab, (key) => navigate(`/communication?tab=${key}${orgId ? `&organizationId=${orgId}` : ''}`)));
    node.appendChild(tabHost);

    if (activeTab === 'templates') {
      mount(tabHost, templatesPanel(qs));
    } else if (activeTab === 'logs') {
      mount(tabHost, logsPanel(qs));
    } else {
      mount(tabHost, h('div', null,
        h('div', { class: 'form-grid mt-16' },
          wa ? configurationCard('WhatsApp', wa.configuration, {
            onToggle: (checked) => toggle('whatsapp', { welcomeEnabled: checked }, qs, load),
            onManage: () => openWhatsAppConfig(wa, qs, load),
            onTest: () => openTestDialog('WhatsApp', wa, qs, load),
          }) : null,
          em ? configurationCard('Email', em.configuration, {
            onToggle: (checked) => toggle('email', { welcomeEnabled: checked }, qs, load),
            onManage: () => openEmailConfig(em, qs, load),
            onTest: () => openTestDialog('Email', em, qs, load),
          }) : null),
        em ? staffAlertsCard(em, qs, load) : null,
        h('div', { class: 'alert info mt-16' },
          h('strong', null, 'How this is enforced: '),
          'the server re-checks this configuration before every single message. '
          + 'A channel that is not green will record why it skipped, in the delivery log, rather than failing silently.')));
    }
  }

  await load();
  return { node };
}

async function toggle(channel, payload, qs, reload) {
  try {
    const res = await api.patch(`/api/${channel}${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`, payload);
    const status = res.data?.status;
    if (payload.welcomeEnabled && status === 'ORANGE') {
      // Being explicit here prevents the "I switched it on, why is nothing
      // sending" support ticket.
      toast('Switched on, but not sending yet', {
        body: 'The configuration is incomplete. Finish the checklist to activate.',
        type: 'warning',
      });
    } else if (payload.welcomeEnabled && status === 'GREEN') {
      toast.success('Welcome messages are now active');
    } else {
      toast.success('Welcome messages disabled');
    }
    reload();
  } catch (err) {
    reportError(err);
    reload();
  }
}

// ---------------------------------------------------------------------------
function openWhatsAppConfig(wa, qs, reload) {
  const errorHost = h('div');
  const tokenInput = input({
    type: 'password',
    placeholder: wa.hasAccessToken ? '•••••••• (leave blank to keep)' : 'Permanent access token',
  });
  const phoneIdInput = input({ value: wa.phoneNumberId || '', placeholder: '1234567890' });
  const wabaInput = input({ value: wa.wabaId || '', placeholder: 'WhatsApp Business Account ID' });
  const senderInput = input({ value: wa.senderNumber || '', placeholder: '+91 90000 00000' });
  const baseInput = input({ value: wa.apiBaseUrl || '', placeholder: 'https://graph.facebook.com/v21.0' });
  const templateSelect = select([
    { value: '', label: 'Select a template…' },
    ...(wa.templates || []).map((t) => ({
      value: t.id,
      label: `${t.name}${t.status === 'APPROVED' ? '' : ` (${titleCase(t.status)})`}`,
    })),
  ], { value: wa.welcomeTemplateId || '' });

  const save = h('button', { class: 'btn btn-primary' }, 'Save configuration');
  const ref_ = drawer({
    title: 'WhatsApp configuration',
    subtitle: 'Meta WhatsApp Cloud API',
    wide: true,
    body: h('div', null,
      errorHost,
      h('div', { class: 'alert info mb-16' },
        'Business-initiated WhatsApp messages must use a template that Meta has approved. '
        + 'Growvanta will not mark this channel active until a live test send succeeds.'),
      field('Access token', tokenInput, {
        required: true,
        hint: 'Stored encrypted and never shown again.',
      }),
      h('div', { class: 'form-grid' },
        field('Phone number ID', phoneIdInput, { required: true }),
        field('Business account ID', wabaInput, { required: true }),
        field('Sender number', senderInput, { required: true }),
        field('API base URL', baseInput, { hint: 'Leave blank for the default.' })),
      field('Welcome template', templateSelect, {
        required: true,
        hint: 'Only approved templates can actually be delivered.',
      })),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      save),
  });

  save.addEventListener('click', async () => {
    const payload = {
      phoneNumberId: phoneIdInput.value.trim(),
      wabaId: wabaInput.value.trim(),
      senderNumber: senderInput.value.trim(),
      apiBaseUrl: baseInput.value.trim() || null,
      welcomeTemplateId: templateSelect.value || null,
    };
    // Only send the token when one was typed, so saving other fields does not
    // wipe a stored credential.
    if (tokenInput.value) payload.accessToken = tokenInput.value;

    save.disabled = true;
    try {
      await api.patch(`/api/whatsapp${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`, payload);
      toast.success('WhatsApp configuration saved', 'Send a test message to activate it.');
      ref_.close();
      reload();
    } catch (err) {
      save.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
  return ref_;
}

function openEmailConfig(em, qs, reload) {
  const errorHost = h('div');
  const modeSelect = select([
    { value: 'SMTP', label: 'SMTP server' },
    { value: 'API', label: 'Email API provider' },
  ], { value: em.mode || 'SMTP' });

  const hostInput = input({ value: em.smtpHost || '', placeholder: 'smtp.yourhost.com' });
  const portInput = input({ type: 'number', value: em.smtpPort || 587 });
  const encSelect = select([
    { value: 'TLS', label: 'STARTTLS (587)' },
    { value: 'SSL', label: 'SSL/TLS (465)' },
    { value: 'NONE', label: 'None' },
  ], { value: em.smtpEncryption || 'TLS' });
  const userInput = input({ value: em.smtpUsername || '' });
  const passInput = input({
    type: 'password',
    placeholder: em.hasSmtpPassword ? '•••••••• (leave blank to keep)' : 'SMTP password',
  });
  const apiKeyInput = input({
    type: 'password',
    placeholder: em.hasApiKey ? '•••••••• (leave blank to keep)' : 'Provider API key',
  });

  const fromEmailInput = input({ type: 'email', value: em.fromEmail || '', placeholder: 'sales@yourcompany.com' });
  const fromNameInput = input({ value: em.fromName || '', placeholder: 'ABC Realty Sales' });
  const replyToInput = input({ type: 'email', value: em.replyTo || '' });
  const templateSelect = select([
    { value: '', label: 'Select a template…' },
    ...(em.templates || []).map((t) => ({ value: t.id, label: t.name })),
  ], { value: em.welcomeTemplateId || '' });

  const smtpBlock = h('div', null,
    h('div', { class: 'form-grid' },
      field('SMTP host', hostInput, { required: true }),
      field('Port', portInput, { required: true }),
      field('Encryption', encSelect),
      field('Username', userInput)),
    field('Password', passInput, { hint: 'Stored encrypted.' }));
  const apiBlock = h('div', null, field('API key', apiKeyInput, { hint: 'Stored encrypted.' }));

  const transportHost = h('div');
  const paintTransport = () => mount(transportHost, modeSelect.value === 'SMTP' ? smtpBlock : apiBlock);
  paintTransport();
  modeSelect.addEventListener('change', paintTransport);

  const save = h('button', { class: 'btn btn-primary' }, 'Save configuration');
  const ref_ = drawer({
    title: 'Email configuration',
    subtitle: 'Outgoing mail for welcome messages and notifications',
    wide: true,
    body: h('div', null,
      errorHost,
      field('Send using', modeSelect),
      transportHost,
      h('div', { class: 'form-grid' },
        field('From email', fromEmailInput, { required: true }),
        field('From name', fromNameInput, { required: true }),
        field('Reply-to', replyToInput)),
      field('Welcome email template', templateSelect, { required: true })),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      save),
  });

  save.addEventListener('click', async () => {
    const payload = {
      mode: modeSelect.value,
      smtpHost: hostInput.value.trim() || null,
      smtpPort: portInput.value ? Number(portInput.value) : null,
      smtpEncryption: encSelect.value,
      smtpUsername: userInput.value.trim() || null,
      fromEmail: fromEmailInput.value.trim() || null,
      fromName: fromNameInput.value.trim() || null,
      replyTo: replyToInput.value.trim() || null,
      welcomeTemplateId: templateSelect.value || null,
    };
    if (passInput.value) payload.smtpPassword = passInput.value;
    if (apiKeyInput.value) payload.apiKey = apiKeyInput.value;

    save.disabled = true;
    try {
      await api.patch(`/api/email${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`, payload);
      toast.success('Email configuration saved', 'Send a test email to activate it.');
      ref_.close();
      reload();
    } catch (err) {
      save.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
  return ref_;
}

// ---------------------------------------------------------------------------
function openTestDialog(channel, settings, qs, reload) {
  const isWhatsApp = channel === 'WhatsApp';
  const target = input({
    type: isWhatsApp ? 'tel' : 'email',
    placeholder: isWhatsApp ? '+91 98765 43210' : 'you@yourcompany.com',
    value: isWhatsApp ? '' : (store.user?.email || ''),
  });
  const resultHost = h('div');
  const send = h('button', { class: 'btn btn-primary' }, 'Send test');

  const ref = modal({
    title: `Test ${channel.toLowerCase()}`,
    body: h('div', null,
      h('p', { class: 'muted small', style: { marginTop: 0 } },
        'A successful test is what marks this channel as verified. Test messages are logged separately and never counted as lead activity.'),
      field(isWhatsApp ? 'Send to WhatsApp number' : 'Send to email address', target, { required: true }),
      resultHost),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref.close() }, 'Close'),
      send),
  });

  send.addEventListener('click', async () => {
    if (!target.value.trim()) return;
    send.disabled = true;
    send.textContent = 'Sending…';
    mount(resultHost, h('div', { class: 'muted small mt-8' }, 'Contacting the provider…'));
    try {
      const path = `/api/${isWhatsApp ? 'whatsapp' : 'email'}/test${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`;
      const res = await api.post(path, isWhatsApp ? { phone: target.value.trim() } : { email: target.value.trim() });
      const data = res.data;
      mount(resultHost, data.ok
        ? h('div', { class: 'alert success mt-8' }, 'Delivered. This channel is now verified.')
        : h('div', { class: 'alert danger mt-8' },
          h('strong', null, 'The provider rejected it: '), data.error || 'Unknown error'));
      reload();
    } catch (err) {
      mount(resultHost, h('div', { class: 'alert danger mt-8' }, err.message));
    } finally {
      send.disabled = false;
      send.textContent = 'Send test';
    }
  });
  return ref;
}

// ---------------------------------------------------------------------------
function templatesPanel(qs) {
  const host = h('div');

  async function paint() {
    mount(host, pageLoader('Loading templates'));
    try {
      const rows = await api.data('/api/templates', qs);
      clear(host);
      if (!rows.length) {
        host.appendChild(h('div', { class: 'muted small' }, 'No templates yet.'));
      }
      for (const t of rows) {
        host.appendChild(h('div', { class: 'card mb-16' }, h('div', { class: 'card-body' },
          h('div', { class: 'row-between' },
            h('div', null,
              h('strong', null, t.name),
              h('div', { class: 'cell-sub' }, `${titleCase(t.channel)} · ${t.key}`)),
            h('div', { class: 'row', style: { gap: '6px' } },
              h('span', {
                class: `badge ${t.status === 'APPROVED' ? 'badge-success' : 'badge-warning'}`,
              }, titleCase(t.status)),
              can('templates', 'update')
                ? h('button', { class: 'btn btn-sm', onClick: () => openTemplateForm(t, qs, paint) }, 'Edit')
                : null)),
          t.subject ? h('div', { class: 'small mt-8' }, h('strong', null, 'Subject: '), t.subject) : null,
          h('pre', {
            class: 'code-block mt-8',
            style: { whiteSpace: 'pre-wrap', maxHeight: '150px', overflow: 'auto' },
          }, t.body),
          t.unknownVariables?.length
            ? h('div', { class: 'alert warning mt-8' },
              `These variables are not recognised and will render blank: ${t.unknownVariables.join(', ')}`)
            : null)));
      }
    } catch (err) {
      mount(host, h('div', { class: 'alert danger' }, err.message));
    }
  }
  paint();

  return panel('Message templates', host, {
    subtitle: 'Use {{customer_name}}, {{project_name}}, {{company_name}} and friends.',
    action: can('templates', 'create')
      ? h('button', { class: 'btn btn-sm', onClick: () => openTemplateForm(null, qs, paint) },
        icon('plus', { size: 14 }), 'New template')
      : null,
  });
}

function openTemplateForm(template, qs, reload) {
  const isEdit = Boolean(template);
  const errorHost = h('div');
  const nameInput = input({ value: template?.name || '' });
  const channelSelect = select([
    { value: 'WHATSAPP', label: 'WhatsApp' },
    { value: 'EMAIL', label: 'Email' },
  ], { value: template?.channel || 'WHATSAPP', disabled: isEdit });
  const providerInput = input({
    value: template?.providerName || '',
    placeholder: 'Name registered at the provider',
  });
  const subjectInput = input({ value: template?.subject || '' });
  const bodyInput = textarea({ value: template?.body || '', rows: 8 });
  const previewHost = h('div');

  const preview = async () => {
    try {
      const res = await api.post(`/api/templates/preview${qs.organizationId ? `?organizationId=${qs.organizationId}` : ''}`, {
        body: bodyInput.value, subject: subjectInput.value,
      });
      mount(previewHost, h('div', { class: 'card mt-8' }, h('div', { class: 'card-body' },
        res.data.subject ? h('div', { class: 'small mb-8' }, h('strong', null, 'Subject: '), res.data.subject) : null,
        h('div', { style: { whiteSpace: 'pre-wrap' }, class: 'small' }, res.data.body))));
    } catch (err) { reportError(err); }
  };

  const save = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save template' : 'Create template');
  const ref_ = drawer({
    title: isEdit ? 'Edit template' : 'New template',
    wide: true,
    body: h('div', null,
      errorHost,
      h('div', { class: 'form-grid' },
        field('Template name', nameInput, { required: true }),
        field('Channel', channelSelect, { required: true }),
        field('Provider template name', providerInput, {
          hint: 'WhatsApp only: the name Meta approved.',
        })),
      field('Subject', subjectInput, { hint: 'Email only.' }),
      field('Message body', bodyInput, {
        required: true,
        hint: 'Available: {{customer_name}} {{first_name}} {{project_name}} {{project_location}} {{company_name}} {{sales_person_name}} {{sales_person_phone}} {{phone}} {{email}} {{budget}} {{configuration}} {{lead_source}} {{lead_code}}',
      }),
      h('button', { class: 'btn btn-sm', onClick: preview }, icon('eye', { size: 14 }), 'Preview with sample data'),
      previewHost),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      save),
  });

  save.addEventListener('click', async () => {
    const payload = {
      name: nameInput.value.trim(),
      channel: channelSelect.value,
      providerName: providerInput.value.trim() || null,
      subject: subjectInput.value.trim() || null,
      body: bodyInput.value,
    };
    save.disabled = true;
    try {
      const suffix = qs.organizationId ? `?organizationId=${qs.organizationId}` : '';
      if (isEdit) await api.patch(`/api/templates/${template.id}${suffix}`, payload);
      else await api.post(`/api/templates${suffix}`, payload);
      toast.success(isEdit ? 'Template saved' : 'Template created');
      ref_.close();
      reload();
    } catch (err) {
      save.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
    }
  });
  return ref_;
}

// ---------------------------------------------------------------------------
function logsPanel(qs) {
  const channelSelect = select([
    { value: 'whatsapp', label: 'WhatsApp' },
    { value: 'email', label: 'Email' },
  ], { value: 'whatsapp' });

  const host = h('div');
  let table;

  const build = () => {
    table = dataTable({
      load: (params) => api.get(`/api/${channelSelect.value}/logs`, { ...params, ...qs }),
      emptyTitle: 'Nothing sent yet',
      emptyMessage: 'Messages, and the reasons any were skipped, appear here.',
      columns: [
        {
          label: 'Status',
          render: (row) => h('span', {
            class: `badge ${{ SENT: 'badge-success', FAILED: 'badge-danger', SKIPPED: 'badge-warning' }[row.status] || ''}`,
          }, titleCase(row.status)),
        },
        { label: 'Recipient', render: (row) => row.recipient || '—' },
        { label: 'Lead', render: (row) => row.leadCode || (row.isTest ? 'Test message' : '—') },
        {
          label: 'Reason',
          render: (row) => h('span', { class: 'small', title: row.reason || '' },
            row.reason ? String(row.reason).slice(0, 90) : '—'),
        },
        { label: 'When', cellClass: 'hide-sm', render: (row) => dateTime(row.createdAt) },
      ],
    });
    mount(host, table.root);
  };
  build();
  channelSelect.addEventListener('change', build);

  return panel('Delivery log',
    h('div', null,
      h('div', { class: 'row mb-16', style: { gap: '10px' } }, channelSelect),
      host),
    { subtitle: 'Every attempt, including the ones deliberately skipped.' });
}
