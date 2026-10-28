/**
 * Integrations.
 *
 * Each provider is a real configuration record. Nothing here claims to be
 * connected until the required credentials exist - the card says exactly what
 * is missing and where to set it.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can } from '../lib/store.js';
import { pageLoader, emptyState, toast, reportError, drawer, field, input, switchToggle } from '../lib/ui.js';
import { relative, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

const PROVIDER_ICON = {
  google_ads: 'campaign', meta_ads: 'message', google_analytics: 'reports',
  whatsapp: 'message', smtp: 'mail', sms: 'phone', zapier: 'integrations',
};

export default async function render({ query } = {}) {
  if (!can('integrations', 'read')) return { node: noAccess('integrations') };

  const body = h('div', null, pageLoader('Loading integrations'));
  const facebookHost = h('div');
  const node = h('div', null,
    pageHead({
      title: 'Integrations',
      subtitle: 'Connect ad platforms, messaging and email. Everything unconfigured stays inert.',
    }),
    facebookHost,
    body);

  async function load() {
    drawFacebook();
    try {
      const data = await api.data('/api/integrations');
      mount(body,
        h('div', { class: 'alert info mb-16' },
          icon('info', { size: 16 }),
          h('div', null, data.note)),
        h('div', { class: 'chart-grid half' }, ...data.integrations.map(renderCard)));
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load integrations', message: err.message })));
    }
  }

  /**
   * Facebook & Instagram Lead Ads.
   *
   * Its own card, above the provider list, because it is the one integration a
   * customer completes by pressing a button instead of pasting credentials:
   * they authorise on facebook.com with their own account and come back here to
   * say which of their pages belongs in this CRM. No page id, no access token,
   * no Graph API Explorer.
   */
  async function drawFacebook() {
    let status;
    let forms = [];
    let projects = [];
    try {
      status = await api.data('/api/meta-forms/connect/status');
      forms = (await api.data('/api/meta-forms')).forms || [];
      // Only needed for the form -> project picker; a missing permission just hides it.
      projects = can('projects', 'read') ? await api.data('/api/projects/options').catch(() => []) : [];
    } catch {
      // A server without the connect flow, or no permission to see it. Leaving
      // the section out entirely is better than showing a broken card.
      clear(facebookHost);
      return;
    }

    // webhooks/meta_oauth.php sends the browser back with the outcome attached.
    const outcome = query?.meta || null;
    const awaiting = status.awaitingPageChoice || outcome === 'choose';
    const editable = can('integrations', 'update');

    // One row per page, however many forms it carries - the page is what the
    // customer recognises and what was actually subscribed.
    const pages = new Map();
    for (const f of forms) {
      const p = pages.get(f.metaPageId) || {
        name: f.pageName || f.metaPageId, forms: 0, live: 0, error: null,
      };
      p.forms += 1;
      if (f.subscribedAt) p.live += 1;
      // Facebook's own words about why the page is not subscribed. Worth more
      // than any message we could invent, so it is shown verbatim.
      if (f.lastError && !p.error) p.error = f.lastError;
      pages.set(f.metaPageId, p);
    }

    const connectButton = h('button', {
      class: 'btn btn-primary',
      onClick: async (e) => {
        e.target.disabled = true;
        try {
          const res = await api.post('/api/meta-forms/connect');
          // A full redirect, not a popup: Facebook refuses to render its login
          // screen inside one, and a blocked popup is invisible to the person
          // who just pressed the button.
          window.location.href = res.data.url;
        } catch (err) {
          e.target.disabled = false;
          reportError(err);
        }
      },
    }, icon('message', { size: 15 }), pages.size ? 'Connect another page' : 'Connect Facebook');

    mount(facebookHost,
      h('section', { class: 'card mb-16' },
        h('div', { class: 'card-head' },
          h('div', { class: 'row', style: { gap: '11px', minWidth: 0 } },
            h('span', {
              style: {
                width: '38px', height: '38px', borderRadius: '11px', flex: '0 0 38px',
                display: 'grid', placeItems: 'center',
                background: pages.size ? 'var(--success-bg)' : 'var(--surface-3)',
                color: pages.size ? 'var(--success)' : 'var(--ink-3)',
              },
            }, icon('message', { size: 19 })),
            h('div', { style: { minWidth: 0 } },
              h('h3', { class: 'truncate' }, 'Facebook & Instagram Lead Ads'),
              h('div', { class: 'sub' },
                'Connect your own Facebook page. Every lead form on it starts arriving here, including forms you publish later.'))),
          h('span', { class: `badge ${pages.size ? 'badge-success' : ''}` },
            pages.size ? `${pages.size} page${pages.size === 1 ? '' : 's'} connected` : 'Not connected')),

        h('div', { class: 'card-body' },
          outcome === 'cancelled' || outcome === 'failed'
            ? h('div', { class: 'alert danger mb-16' },
              icon('alert', { size: 16 }),
              h('div', null,
                h('strong', null, outcome === 'cancelled'
                  ? 'Facebook authorisation was cancelled'
                  : 'Facebook could not be connected'),
                query.reason || 'Nothing was changed. You can start again below.'))
            : null,

          !status.configured
            ? h('div', { class: 'alert warning mb-16' },
              icon('alert', { size: 16 }),
              h('div', null,
                h('strong', null, 'This server has no Facebook app yet'),
                'META_APP_ID and META_APP_SECRET have to be set in the server\'s .env file before anyone can connect.'))
            : null,

          awaiting
            ? h('div', { class: 'alert success mb-16' },
              icon('check', { size: 16 }),
              h('div', null,
                h('strong', null, 'Authorised'),
                'Now choose which of your pages should send leads into this CRM.'))
            : null,

          pages.size
            ? h('div', { class: 'mb-16' },
              h('div', { class: 'tiny muted mb-8' }, 'Connected pages'),
              ...[...pages.entries()].map(([pageId, p]) => h('div', {
                class: 'row-between',
                style: { padding: '8px 0', borderBottom: '1px solid var(--line-2)', gap: '10px' },
              },
              h('div', { style: { minWidth: 0 } },
                h('div', { class: 'truncate', style: { fontWeight: '600' } }, p.name),
                h('div', { class: 'tiny muted' },
                  `${p.forms} form${p.forms === 1 ? '' : 's'} · page ${pageId}`),
                p.error
                  ? h('div', { class: 'tiny', style: { color: 'var(--danger)', marginTop: '3px' } }, p.error)
                  : null),
              // A page that is connected but not subscribed looks perfectly
              // healthy and delivers nothing. Say so where it can be seen.
              h('span', { class: `badge ${p.live ? 'badge-success' : 'badge-warning'}` },
                p.live ? 'Receiving leads' : 'Not subscribed'))))
            : null,

          // A Meta lead form cannot ask which project the buyer means, so it is
          // answered here once per form. Every lead from that form is then filed
          // under the project, which is what the project reports group on.
          forms.length && projects.length
            ? h('div', { class: 'mb-16' },
              h('div', { class: 'tiny muted mb-8' }, 'Lead form → project'),
              ...forms.map((f) => {
                const isFallback = String(f.metaFormId || '').startsWith('page-');
                const choose = h('select', {
                  class: 'select',
                  style: { maxWidth: '240px' },
                  disabled: !editable,
                  onChange: async (e) => {
                    const projectId = e.target.value || null;
                    e.target.disabled = true;
                    try {
                      await api.patch(`/api/meta-forms/${f.id}`, { projectId });
                      f.projectId = projectId;
                      toast.success('Saved', `${f.formName || f.metaFormId} → ${projectId ? e.target.selectedOptions[0].text : 'No project'}`);
                    } catch (err) {
                      e.target.value = f.projectId || '';
                      reportError(err);
                    } finally {
                      e.target.disabled = !editable;
                    }
                  },
                },
                h('option', { value: '' }, 'No project'),
                ...projects.map((pr) => h('option', { value: pr.id, selected: pr.id === f.projectId }, pr.name)));
                return h('div', {
                  class: 'row-between',
                  style: { padding: '8px 0', borderBottom: '1px solid var(--line-2)', gap: '10px' },
                },
                h('div', { style: { minWidth: 0 } },
                  h('div', { class: 'truncate', style: { fontWeight: '600' } },
                    isFallback ? 'Any other form on this page' : (f.formName || f.metaFormId)),
                  h('div', { class: 'tiny muted' },
                    `${f.pageName || f.metaPageId}${isFallback ? '' : ' · form ' + f.metaFormId}`)),
                choose);
              }))
            : null,

          h('div', { class: 'row wrap', style: { gap: '6px' } },
            status.configured
              ? h('button', {
                class: 'btn',
                onClick: async (e) => {
                  e.target.disabled = true;
                  e.target.textContent = 'Asking Facebook...';
                  try { showDiagnosis(await api.data('/api/meta-forms/diagnose')); }
                  catch (err) { reportError(err); }
                  finally { e.target.disabled = false; e.target.textContent = 'Run diagnosis'; }
                },
              }, icon('alert', { size: 15 }), 'Run diagnosis')
              : null,
            editable && status.configured
              ? (awaiting
                ? h('button', { class: 'btn btn-primary', onClick: () => openPagePicker() },
                  icon('check', { size: 15 }), 'Choose your pages')
                : connectButton)
              : null,
            editable && awaiting ? connectButton : null,
            !editable
              ? h('span', { class: 'small muted', style: { alignSelf: 'center' } },
                'Ask an administrator to connect a Facebook page.')
              : null))));
  }

  /**
   * Show what Facebook said.
   *
   * Every line is Facebook's own answer, not our guess, and every failure
   * carries the fix next to it. The point is that nobody has to read a log or
   * describe a symptom over the phone: the screen says what is wrong.
   */
  function showDiagnosis(report) {
    const tone = { PASS: 'success', FAIL: 'danger', INFO: 'info' };
    const NL = String.fromCharCode(10);

    const check = (c) => h('div', { class: `alert ${tone[c.state] || 'info'} mb-8` },
      icon(c.state === 'PASS' ? 'check' : (c.state === 'FAIL' ? 'alert' : 'info'), { size: 16 }),
      h('div', null,
        h('strong', null, c.name),
        c.detail,
        c.fix ? h('div', { class: 'tiny mt-8' }, h('strong', null, 'Fix: '), c.fix) : null));

    const page = (p) => h('div', {
      style: { padding: '12px 0', borderBottom: '1px solid var(--line-2)' },
    },
    h('div', { class: 'row-between', style: { gap: '10px' } },
      h('div', { style: { minWidth: 0 } },
        h('div', { style: { fontWeight: '600' } }, p.name),
        h('div', { class: 'tiny muted' },
          `${p.formCount} form${p.formCount === 1 ? '' : 's'} · page ${p.pageId}`
          + (p.subscribedFields?.length ? ' · fields: ' + p.subscribedFields.join(', ') : ''))),
      h('span', { class: `badge ${p.healthy ? 'badge-success' : 'badge-warning'}` },
        p.healthy ? 'Receiving leads' : 'Not delivering')),
    ...(p.problems || []).map((t) => h('div', {
      class: 'tiny', style: { color: 'var(--danger)', marginTop: '6px' },
    }, t)),
    p.storedError
      ? h('div', { class: 'tiny muted', style: { marginTop: '4px' } }, 'Last recorded: ' + p.storedError)
      : null);

    const ref = drawer({
      title: 'Facebook diagnosis',
      subtitle: 'Asked Facebook directly, just now. Nothing was changed.',
      wide: true,
      body: h('div', null,
        ...report.checks.map(check),
        h('div', { class: 'tiny muted mt-16 mb-8' }, 'Connected pages'),
        report.pages.length
          ? h('div', null, ...report.pages.map(page))
          : h('div', { class: 'small muted' }, 'No page is connected yet.'),
        h('div', { class: 'tiny muted mt-16' },
          'Run at ' + new Date(report.ranAt).toLocaleString())),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Close'),
        h('button', {
          class: 'btn btn-primary',
          onClick: () => {
            // Copying beats retyping when this has to be sent to somebody.
            const lines = [
              'Growvanta CRM - Facebook diagnosis - ' + report.ranAt,
              '',
              ...report.checks.map((c) => `[${c.state}] ${c.name}: ${c.detail}`
                + (c.fix ? `${NL}        Fix: ${c.fix}` : '')),
              '',
              'PAGES',
              ...(report.pages.length
                ? report.pages.map((p) => `- ${p.name} (${p.pageId}), ${p.formCount} forms, `
                  + (p.healthy ? 'healthy' : 'NOT delivering')
                  + (p.problems?.length ? NL + '    ' + p.problems.join(NL + '    ') : ''))
                : ['  none']),
            ].join(NL);
            navigator.clipboard.writeText(lines)
              .then(() => toast.success('Copied', 'Paste it wherever you need it.'))
              .catch(() => toast.error('Could not copy', 'Your browser blocked clipboard access.'));
          },
        }, 'Copy report')),
    });
  }

  /**
   * The page picker, shown once Facebook has sent the admin back to us. The
   * authorisation behind it lasts fifteen minutes and holds a token that can
   * list every page this person administers, which is why it is spent as soon
   * as they choose.
   */
  async function openPagePicker() {
    let pages;
    try {
      pages = (await api.data('/api/meta-forms/connect/pages')).pages || [];
    } catch (err) {
      reportError(err);
      return;
    }
    if (!pages.length) {
      toast.warn('No pages found', 'This Facebook account does not administer any page.');
      return;
    }

    const chosen = new Set(pages.filter((p) => p.alreadyConnected).map((p) => p.id));
    const errorHost = h('div');
    const saveButton = h('button', { class: 'btn btn-primary' }, 'Connect these pages');

    const ref = drawer({
      title: 'Choose your pages',
      subtitle: 'Leads from the forms on these pages will arrive in this CRM.',
      body: h('div', null,
        errorHost,
        ...pages.map((p) => h('label', {
          class: 'row',
          style: {
            gap: '11px', alignItems: 'flex-start', cursor: 'pointer',
            padding: '12px 0', borderBottom: '1px solid var(--line-2)',
          },
        },
        h('input', {
          type: 'checkbox',
          checked: chosen.has(p.id),
          onChange: (e) => { if (e.target.checked) chosen.add(p.id); else chosen.delete(p.id); },
        }),
        h('div', { style: { minWidth: 0 } },
          h('div', { style: { fontWeight: '600' } }, p.name),
          h('div', { class: 'tiny muted', style: { marginTop: '2px' } },
            `${p.formCount} lead form${p.formCount === 1 ? '' : 's'}`
              + (p.alreadyConnected ? ' · already connected' : ''))))),
        h('div', { class: 'tiny muted mt-16' },
          'A page with no form yet is still worth connecting - it gets subscribed now, '
          + 'so the first form you publish starts delivering straight away.')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        saveButton),
    });

    saveButton.addEventListener('click', async () => {
      clear(errorHost);
      if (!chosen.size) {
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Choose at least one page.'));
        return;
      }
      saveButton.disabled = true;
      try {
        const d = (await api.post('/api/meta-forms/connect/pages', { pageIds: [...chosen] })).data;
        ref.close();
        if (d.problems?.length) {
          // Partial success is still success for the pages that worked; naming
          // the ones that did not is the only way they get fixed.
          toast('Connected, with problems', { body: d.problems.join(' '), type: 'warning', timeout: 9000 });
        } else {
          toast.success(
            `${d.pages} page${d.pages === 1 ? '' : 's'} connected`,
            `${d.forms} lead form${d.forms === 1 ? '' : 's'} now feeding this CRM.`
          );
        }
        load();
      } catch (err) {
        saveButton.disabled = false;
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      }
    });
  }

  function renderCard(integration) {
    const configured = integration.status === 'CONFIGURED';
    const missing = [...(integration.missing || []), ...(integration.missingEnv || [])];

    return h('section', { class: 'card' },
      h('div', { class: 'card-head' },
        h('div', { class: 'row', style: { gap: '11px', minWidth: 0 } },
          h('span', {
            style: {
              width: '38px', height: '38px', borderRadius: '11px', flex: '0 0 38px',
              display: 'grid', placeItems: 'center',
              background: configured ? 'var(--success-bg)' : 'var(--surface-3)',
              color: configured ? 'var(--success)' : 'var(--ink-3)',
            },
          }, icon(PROVIDER_ICON[integration.provider] || 'integrations', { size: 19 })),
          h('div', { style: { minWidth: 0 } },
            h('h3', { class: 'truncate' }, integration.label),
            h('div', { class: 'sub' }, integration.blurb))),
        h('span', {
          class: `badge ${integration.isEnabled ? 'badge-success' : (configured ? 'badge-info' : '')}`,
        }, integration.isEnabled ? 'Enabled' : (configured ? 'Ready' : 'Not configured'))),

      h('div', { class: 'card-body' },
        h('div', { class: 'mb-16' },
          h('div', { class: 'tiny muted mb-8' }, 'What it does'),
          h('div', { class: 'row wrap', style: { gap: '5px' } },
            ...integration.capabilities.map((c) => h('span', { class: 'badge' }, c)))),

        missing.length
          ? h('div', { class: 'alert warning mb-16' },
            icon('alert', { size: 16 }),
            h('div', null,
              h('strong', null, 'Still needed'),
              missing.join(', '),
              integration.missingEnv?.length
                ? h('div', { class: 'tiny mt-8' }, `Server variables go in .env: ${integration.missingEnv.join(', ')}`)
                : null))
          : h('div', { class: 'alert success mb-16' },
            icon('check', { size: 16 }),
            h('div', null, 'All required credentials are present.')),

        integration.lastSyncedAt
          ? h('div', { class: 'tiny muted mb-8' }, `Last synced ${relative(integration.lastSyncedAt)}`)
          : null,
        integration.lastError
          ? h('div', { class: 'small text-danger mb-8' }, integration.lastError)
          : null,

        h('div', { class: 'row wrap', style: { gap: '6px' } },
          integration.fields.length && can('integrations', 'update')
            ? h('button', { class: 'btn btn-sm', onClick: () => openConfig(integration) }, icon('settings', { size: 14 }), 'Configure')
            : null,
          h('a', {
            class: 'btn btn-sm', href: `#/developers?tab=docs`,
          }, icon('file', { size: 14 }), 'Documentation'),
          !integration.fields.length
            ? h('span', { class: 'small muted', style: { alignSelf: 'center' } }, 'Uses the REST API and webhooks — no credentials needed here.')
            : null)));
  }

  function openConfig(integration) {
    const controls = {};
    const fields = integration.fields.map((f) => {
      const el = input({
        value: integration.config?.[f.key] ?? '',
        type: f.secret ? 'password' : 'text',
        placeholder: f.placeholder || '',
        autocomplete: 'off',
      });
      controls[f.key] = el;
      return field(f.label, el, {
        required: f.required,
        hint: f.secret ? 'Stored per organisation. Leave the dots untouched to keep the current value.' : undefined,
      });
    });

    let enabled = integration.isEnabled;
    const errorHost = h('div');
    const saveButton = h('button', { class: 'btn btn-primary' }, 'Save configuration');

    const ref = drawer({
      title: `Configure ${integration.label}`,
      subtitle: integration.blurb,
      body: h('div', null,
        errorHost,
        ...fields,
        integration.envKeys?.length
          ? h('div', { class: 'alert info mt-16' },
            icon('info', { size: 16 }),
            h('div', null,
              h('strong', null, 'Server-side variables'),
              `${integration.envKeys.join(', ')} live in the server's .env file, not here. See docs/ENVIRONMENT.md.`))
          : null,
        h('div', { class: 'mt-16' },
          switchToggle('Enable this integration', enabled, (v) => { enabled = v; }))),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => ref.close() }, 'Cancel'),
        saveButton),
    });

    saveButton.addEventListener('click', async () => {
      clear(errorHost);
      const config = {};
      for (const [key, el] of Object.entries(controls)) config[key] = el.value;
      saveButton.disabled = true;
      try {
        await api.patch(`/api/integrations/${integration.provider}`, { config, isEnabled: enabled });
        toast.success(`${integration.label} saved`);
        ref.close();
        load();
      } catch (err) {
        saveButton.disabled = false;
        mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      }
    });
  }

  await load();
  return { node };
}
