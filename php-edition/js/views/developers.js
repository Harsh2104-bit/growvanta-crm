/**
 * Developer settings: API keys, webhooks and the in-app API reference.
 *
 * The API docs live here rather than in a README so an integrator working
 * inside the CRM can copy a working curl command with their own endpoint
 * already filled in.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, modal, drawer, field, input,
  select, confirmDialog, dropdown, menuItem, tabs, copyToClipboard,
} from '../lib/ui.js';
import { number, dateTime, relative, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

export default async function render({ query }) {
  if (!can('apikeys', 'read') && !can('webhooks', 'read')) return { node: noAccess('developer settings') };

  const TABS = [
    can('apikeys', 'read') ? { key: 'keys', label: 'API keys' } : null,
    can('webhooks', 'read') ? { key: 'webhooks', label: 'Webhooks' } : null,
    { key: 'docs', label: 'API reference' },
  ].filter(Boolean);

  const state = { tab: TABS.some((t) => t.key === query.tab) ? query.tab : TABS[0].key };
  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div', null, pageLoader());
  const actionHost = h('div', { class: 'row wrap', style: { gap: '8px' } });

  const node = h('div', null,
    pageHead({
      title: 'Developers',
      subtitle: 'Push leads in over the API, and get notified when things change.',
      actions: actionHost,
    }),
    tabHost, body);

  function buildTabs() {
    mount(tabHost, tabs(TABS, state.tab, (key) => {
      state.tab = key;
      buildTabs();
      load();
    }));
  }

  async function load() {
    mount(body, pageLoader());
    clear(actionHost);
    try {
      if (state.tab === 'keys') await renderKeys();
      else if (state.tab === 'webhooks') await renderWebhooks();
      else renderDocs();
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load', message: err.message })));
    }
  }

  // =========================================================================
  async function renderKeys() {
    const keys = await api.data('/api/api-keys');
    if (can('apikeys', 'create')) {
      mount(actionHost, h('button', { class: 'btn btn-primary', onClick: createKey }, icon('plus', { size: 15 }), 'New API key'));
    }

    if (!keys.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'key',
        title: 'No API keys',
        message: 'Create a key so your website, landing pages or ad platform can post leads straight into the CRM.',
        action: can('apikeys', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: createKey }, 'Create a key')
          : null,
      })));
      return;
    }

    mount(body,
      h('div', { class: 'alert info mb-16' },
        icon('shield', { size: 16 }),
        h('div', null,
          h('strong', null, 'Keys are stored hashed'),
          'The full key is shown once, at creation. If it is lost, revoke it and issue a new one.')),
      panel('API keys', h('div', { class: 'table-wrap' },
        h('table', { class: 'data' },
          h('thead', null, h('tr', null,
            h('th', null, 'Name'), h('th', null, 'Key'), h('th', null, 'Scopes'),
            h('th', { class: 'right' }, 'Calls'), h('th', null, 'Last used'),
            h('th', null, 'Status'), h('th', { style: { width: '48px' } }, ''))),
          h('tbody', null, ...keys.map((key) => h('tr', null,
            h('td', { class: 'cell-main' }, key.name),
            h('td', { class: 'mono small' }, `${key.prefix}••••••••`),
            h('td', null, h('div', { class: 'row wrap', style: { gap: '4px' } },
              ...key.scopes.map((s) => h('span', { class: 'badge' }, s)))),
            h('td', { class: 'right' }, number(key.useCount)),
            h('td', { class: 'small muted' }, key.lastUsedAt ? relative(key.lastUsedAt) : 'Never'),
            h('td', null, h('span', {
              class: `badge ${{ ACTIVE: 'badge-success', REVOKED: 'badge-danger', EXPIRED: 'badge-warning' }[key.status]}`,
            }, titleCase(key.status))),
            h('td', null, can('apikeys', 'delete') && key.status === 'ACTIVE'
              ? dropdown(
                h('button', { class: 'icon-btn', 'aria-label': 'Key actions' }, icon('more', { size: 16 })),
                (close) => h('div', null,
                  menuItem('Revoke', {
                    iconName: 'close', close, danger: true,
                    onClick: async () => {
                      const yes = await confirmDialog({
                        title: 'Revoke this key?',
                        message: `${key.name} stops working immediately. Anything using it will start failing.`,
                        danger: true, confirmLabel: 'Revoke', requireText: 'REVOKE',
                      });
                      if (!yes) return;
                      try { await api.post(`/api/api-keys/${key.id}/revoke`); toast.success('Key revoked'); load(); } catch (err) { reportError(err); }
                    },
                  })),
              )
              : null))))),
      ), { flush: true }));
  }

  async function createKey() {
    const nameInput = input({ placeholder: 'Website lead capture' });
    const scopesBox = h('div', { class: 'col', style: { gap: '6px' } },
      ...[
        ['leads:create', 'Create leads'],
        ['leads:read', 'Read leads'],
      ].map(([value, label]) => h('label', { class: 'checkbox' },
        h('input', { type: 'checkbox', value, checked: value === 'leads:create' }), label)));

    const dialog = modal({
      title: 'New API key',
      subtitle: 'Give it only the scopes it needs.',
      body: h('div', null,
        field('Key name', nameInput, { required: true, hint: 'Where will this key be used?' }),
        field('Scopes', scopesBox)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            const scopes = [...scopesBox.querySelectorAll('input:checked')].map((el) => el.value);
            if (!nameInput.value.trim()) { nameInput.focus(); return; }
            if (!scopes.length) { toast.warn('Pick at least one scope'); return; }
            e.currentTarget.disabled = true;
            try {
              const res = await api.post('/api/api-keys', { name: nameInput.value.trim(), scopes });
              dialog.close();
              showKeyOnce(res.data);
              load();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, 'Create key')),
    });
  }

  function showKeyOnce(key) {
    const dialog = modal({
      title: 'Copy your API key now',
      subtitle: 'This is the only time it will be shown.',
      size: 'wide',
      body: h('div', null,
        h('div', { class: 'alert warning mb-16' },
          icon('alert', { size: 16 }),
          h('div', null, key.warning)),
        h('div', { class: 'code-block mb-16', style: { wordBreak: 'break-all', whiteSpace: 'pre-wrap' } }, key.key),
        h('h4', { class: 'mb-8' }, 'Try it'),
        h('pre', { class: 'code-block' },
          `curl -X POST ${window.location.origin}/api/v1/leads \\\n`
          + `  -H "X-API-Key: ${key.key}" \\\n`
          + '  -H "Content-Type: application/json" \\\n'
          + '  -d \'{\n'
          + '    "firstName": "Aarav",\n'
          + '    "lastName": "Sharma",\n'
          + '    "email": "aarav@example.com",\n'
          + '    "phone": "9876543210",\n'
          + '    "company": "Northwind Traders",\n'
          + '    "utm_source": "google",\n'
          + '    "utm_campaign": "q4_search"\n'
          + '  }\'')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'I have copied it'),
        h('button', { class: 'btn btn-primary', onClick: () => copyToClipboard(key.key, 'API key copied') }, icon('copy', { size: 15 }), 'Copy key')),
      closeOnBackdrop: false,
    });
  }

  // =========================================================================
  async function renderWebhooks() {
    const [hooks, events] = await Promise.all([
      api.data('/api/webhooks'),
      api.data('/api/webhooks/events'),
    ]);

    if (can('webhooks', 'create')) {
      mount(actionHost, h('button', {
        class: 'btn btn-primary', onClick: () => openWebhookForm({ events, onSaved: load }),
      }, icon('plus', { size: 15 }), 'New webhook'));
    }

    if (!hooks.length) {
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'webhook',
        title: 'No webhooks',
        message: 'Get an HTTPS call whenever a lead is created, assigned, converted, or a deal is won.',
        action: can('webhooks', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openWebhookForm({ events, onSaved: load }) }, 'Add a webhook')
          : null,
      })));
      return;
    }

    mount(body,
      h('div', { class: 'alert info mb-16' },
        icon('shield', { size: 16 }),
        h('div', null,
          h('strong', null, 'Every payload is signed'),
          'We send an X-Growvanta-Signature header: sha256=HMAC(secret, rawBody). Verify it before trusting the call.')),
      h('div', { class: 'col', style: { gap: '10px' } }, ...hooks.map((hook) => h('section', { class: 'card' },
        h('div', { class: 'card-head' },
          h('div', { style: { minWidth: 0 } },
            h('h3', { class: 'row', style: { gap: '8px' } },
              hook.name,
              hook.isActive ? h('span', { class: 'badge badge-success' }, 'Active') : h('span', { class: 'badge' }, 'Paused'),
              hook.failureCount > 0 ? h('span', { class: 'badge badge-danger' }, `${hook.failureCount} failures`) : null),
            h('div', { class: 'sub mono truncate' }, hook.url)),
          can('webhooks', 'update')
            ? dropdown(
              h('button', { class: 'icon-btn', 'aria-label': 'Webhook actions' }, icon('more', { size: 16 })),
              (close) => h('div', null,
                menuItem('Send a test event', {
                  iconName: 'play', close,
                  onClick: async () => {
                    toast('Sending test event…');
                    try {
                      const res = await api.post(`/api/webhooks/${hook.id}/test`);
                      if (res.data.ok) toast.success(`Endpoint replied ${res.data.statusCode}`);
                      else toast.error('Delivery failed', res.data.error);
                      load();
                    } catch (err) { reportError(err); }
                  },
                }),
                menuItem('Delivery log', { iconName: 'list', close, onClick: () => showDeliveries(hook) }),
                menuItem('Edit', { iconName: 'edit', close, onClick: () => openWebhookForm({ webhook: hook, events, onSaved: load }) }),
                menuItem('Rotate secret', {
                  iconName: 'refresh', close,
                  onClick: async () => {
                    const yes = await confirmDialog({
                      title: 'Rotate the signing secret?',
                      message: 'The old secret stops working immediately. Update your receiver first.',
                      danger: true, confirmLabel: 'Rotate',
                    });
                    if (!yes) return;
                    try {
                      const res = await api.post(`/api/webhooks/${hook.id}/rotate-secret`);
                      copyToClipboard(res.data.secret, 'New secret copied to clipboard');
                      load();
                    } catch (err) { reportError(err); }
                  },
                }),
                can('webhooks', 'delete')
                  ? menuItem('Delete', {
                    iconName: 'trash', close, danger: true,
                    onClick: async () => {
                      const yes = await confirmDialog({ title: 'Delete this webhook?', message: hook.name, danger: true, confirmLabel: 'Delete' });
                      if (!yes) return;
                      try { await api.del(`/api/webhooks/${hook.id}`); toast.success('Deleted'); load(); } catch (err) { reportError(err); }
                    },
                  })
                  : null),
            )
            : null),
        h('div', { class: 'card-body' },
          h('div', { class: 'row wrap mb-16', style: { gap: '5px' } },
            ...hook.events.map((e) => h('span', { class: 'badge badge-brand' }, e))),
          h('div', { class: 'row wrap', style: { gap: '18px' } },
            h('div', null,
              h('div', { class: 'tiny muted' }, 'Signing secret'),
              h('div', { class: 'row', style: { gap: '6px' } },
                h('span', { class: 'mono small' }, `${hook.secret.slice(0, 14)}••••`),
                h('button', {
                  class: 'icon-btn', 'aria-label': 'Copy secret',
                  onClick: () => copyToClipboard(hook.secret, 'Signing secret copied'),
                }, icon('copy', { size: 14 })))),
            h('div', null,
              h('div', { class: 'tiny muted' }, 'Last delivery'),
              h('div', { class: 'small' },
                hook.lastFiredAt ? `${relative(hook.lastFiredAt)} · HTTP ${hook.lastStatus ?? '—'}` : 'Never')),
            h('div', null,
              h('div', { class: 'tiny muted' }, 'Deliveries'),
              h('div', { class: 'small' }, number(hook.deliveryCount)))))))));
  }

  async function showDeliveries(hook) {
    const bodyEl = h('div', null, pageLoader());
    drawer({ title: `${hook.name} deliveries`, subtitle: hook.url, wide: true, body: bodyEl });
    try {
      const rows = await api.data(`/api/webhooks/${hook.id}/deliveries`);
      if (!rows.length) {
        mount(bodyEl, emptyState({ iconName: 'webhook', title: 'No deliveries yet' }));
        return;
      }
      mount(bodyEl, h('div', { class: 'col', style: { gap: '8px' } }, ...rows.map((d) => h('div', {
        style: { padding: '11px', border: '1px solid var(--line)', borderRadius: '10px' },
      },
        h('div', { class: 'row-between mb-8' },
          h('div', { class: 'row', style: { gap: '8px' } },
            h('span', { class: 'badge badge-brand' }, d.event),
            h('span', {
              class: `badge ${d.statusCode >= 200 && d.statusCode < 300 ? 'badge-success' : 'badge-danger'}`,
            }, d.statusCode ? `HTTP ${d.statusCode}` : 'Failed'),
            d.attempt > 1 ? h('span', { class: 'badge' }, `attempt ${d.attempt}`) : null),
          h('span', { class: 'tiny muted' }, `${dateTime(d.createdAt)}${d.durationMs ? ` · ${d.durationMs}ms` : ''}`)),
        d.error ? h('div', { class: 'small text-danger' }, d.error) : null,
        d.response ? h('div', { class: 'mono tiny muted truncate' }, d.response) : null))));
    } catch (err) {
      mount(bodyEl, emptyState({ iconName: 'alert', title: 'Could not load deliveries', message: err.message }));
    }
  }

  function openWebhookForm({ webhook = null, events, onSaved } = {}) {
    const isEdit = Boolean(webhook?.id);
    const nameInput = input({ value: webhook?.name || '', placeholder: 'Zapier - new leads' });
    const urlInput = input({ value: webhook?.url || '', type: 'url', placeholder: 'https://hooks.example.com/growvanta' });
    const eventsBox = h('div', {
      style: { maxHeight: '240px', overflowY: 'auto', border: '1px solid var(--line)', borderRadius: '8px', padding: '8px' },
    }, ...events.map((e) => h('label', { class: 'checkbox', style: { display: 'flex', padding: '3px 0' } },
      h('input', { type: 'checkbox', value: e, checked: webhook?.events?.includes(e) || false }),
      h('span', { class: 'mono small' }, e))));

    const dialog = modal({
      title: isEdit ? 'Edit webhook' : 'New webhook',
      subtitle: 'We POST a signed JSON body to your URL when the selected events happen.',
      body: h('div', null,
        field('Name', nameInput, { required: true }),
        field('Endpoint URL', urlInput, { required: true, hint: 'Must be https in production, and cannot point at a private address.' }),
        field('Events', eventsBox, { required: true })),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            const selected = [...eventsBox.querySelectorAll('input:checked')].map((el) => el.value);
            if (!nameInput.value.trim() || !urlInput.value.trim()) { toast.warn('Name and URL are required'); return; }
            if (!selected.length) { toast.warn('Select at least one event'); return; }
            e.currentTarget.disabled = true;
            try {
              const payload = { name: nameInput.value.trim(), url: urlInput.value.trim(), events: selected };
              if (isEdit) await api.patch(`/api/webhooks/${webhook.id}`, payload);
              else await api.post('/api/webhooks', payload);
              toast.success(isEdit ? 'Webhook saved' : 'Webhook created');
              dialog.close();
              onSaved?.();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, isEdit ? 'Save' : 'Create')),
    });
  }

  // =========================================================================
  function renderDocs() {
    const base = window.location.origin;
    mount(body, h('div', { class: 'col', style: { gap: '14px' } },
      panel('Lead ingestion API', h('div', null,
        h('p', { class: 'muted mb-16' },
          'Authenticate with an API key in the X-API-Key header. Field names accept camelCase or snake_case, so most webhook senders work without a translation layer.'),
        docBlock('POST /api/v1/leads', `curl -X POST ${base}/api/v1/leads \\
  -H "X-API-Key: gv_your_key_here" \\
  -H "Content-Type: application/json" \\
  -d '{
    "first_name": "Aarav",
    "last_name": "Sharma",
    "email": "aarav@northwind.example",
    "phone": "+91 98765 43210",
    "company": "Northwind Traders",
    "city": "Gurugram",
    "state": "Haryana",
    "lead_source": "google_ads",
    "campaign": "GADS_CRM_Q3",
    "utm_source": "google",
    "utm_campaign": "q3_search",
    "message": "Looking for a CRM for 40 reps"
  }'`),
        h('p', { class: 'small muted mt-8' },
          'Add ?duplicates=reject to have the API return 409 instead of creating a possible duplicate. The default is to create it and return a duplicateWarnings array.'),

        docBlock('POST /api/v1/leads/batch', `{
  "leads": [
    { "name": "Priya Nair", "phone": "9876500001", "company": "Zenith Healthcare" },
    { "name": "Rahul Verma", "email": "rahul@example.com" }
  ]
}`),
        docBlock('GET /api/v1/leads?since=2026-01-01', `curl "${base}/api/v1/leads?since=2026-01-01&limit=50" \\
  -H "X-API-Key: gv_your_key_here"`),
        docBlock('GET /api/v1/metadata', `curl ${base}/api/v1/metadata -H "X-API-Key: gv_your_key_here"
# Returns the source keys, status keys and campaign codes you can send.`))),

      panel('Response shape', h('div', null,
        h('p', { class: 'muted mb-16' }, 'Every endpoint returns the same envelope.'),
        docBlock('Success', `{
  "data": { "id": "clx...", "code": "LD-1042", "status": "New", "owner": "Rohan Gupta", "score": 45 },
  "meta": { "page": 1, "limit": 25, "total": 190, "totalPages": 8 }
}`),
        docBlock('Error', `{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": [{ "field": "email", "message": "Enter a valid email" }]
  }
}`))),

      panel('Webhook verification', h('div', null,
        h('p', { class: 'muted mb-16' },
          'Compute the HMAC over the raw request body and compare it in constant time. Reject anything that does not match.'),
        docBlock('Node.js', `const crypto = require('crypto');

app.post('/growvanta', express.raw({ type: 'application/json' }), (req, res) => {
  const signature = req.get('x-growvanta-signature');       // "sha256=<hex>"
  const expected = 'sha256=' + crypto
    .createHmac('sha256', process.env.GROWVANTA_WEBHOOK_SECRET)
    .update(req.body)
    .digest('hex');

  if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
    return res.sendStatus(401);
  }

  const { event, data } = JSON.parse(req.body);
  // handle lead.created, lead.assigned, lead.converted, ...
  res.sendStatus(200);
});`))),

      panel('Session API', h('div', null,
        h('p', { class: 'muted mb-16' },
          'The CRM UI uses the same REST API under /api. It authenticates with an httpOnly session cookie plus an X-CSRF-Token header, so it cannot be driven with an API key — use /api/v1 for machine access.'),
        h('div', { class: 'table-wrap' },
          h('table', { class: 'data' },
            h('thead', null, h('tr', null, h('th', null, 'Resource'), h('th', null, 'Endpoints'))),
            h('tbody', null, ...[
              ['Auth', '/api/auth/login · logout · me · forgot-password · reset-password · 2fa'],
              ['Leads', '/api/leads · /board · /export · /:id · /:id/convert · /bulk/*'],
              ['Projects', '/api/projects · /options · /:id · /:id/images'],
              ['Reports', '/api/reports/dashboard · leads · sales · marketing · performance'],
              ['Admin', '/api/users · /api/roles · /api/teams · /api/settings · /api/automation'],
              ['Audit', '/api/audit-logs · /export'],
            ].map(([resource, endpoints]) => h('tr', null,
              h('td', { class: 'cell-main' }, resource),
              h('td', { class: 'mono small' }, endpoints)))))))),
    ));
  }

  function docBlock(title, code) {
    const pre = h('pre', { class: 'code-block' }, code);
    return h('div', { class: 'mb-16' },
      h('div', { class: 'row-between mb-8' },
        h('span', { class: 'strong small mono' }, title),
        h('button', { class: 'btn btn-sm', onClick: () => copyToClipboard(code, 'Copied') }, icon('copy', { size: 13 }))),
      pre);
  }

  buildTabs();
  await load();
  return { node };
}
