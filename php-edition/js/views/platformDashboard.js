/**
 * Super Admin dashboard: the whole platform at a glance.
 *
 * The setup-status table is the one that earns its place - it answers "which
 * client signed up and never finished configuring their messaging", which is
 * the difference between a client who sees value in week one and one who
 * churns.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { kpiCard, pageLoader, toast, reportError } from '../lib/ui.js';
import { barList } from '../lib/charts.js';
import { number, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';
import { navigate } from '../router.js';
import { isPlatformAdmin, openClientWizard, channelDot, switchToClient } from './clients.js';

export default async function render() {
  if (!isPlatformAdmin()) return { node: noAccess('the platform administration area') };

  const node = h('div', null, pageLoader('Loading platform overview'));

  async function load() {
    let overview;
    let automation;
    try {
      [overview, automation] = await Promise.all([
        api.data('/api/platform/overview'),
        api.data('/api/platform/automation'),
      ]);
    } catch (err) {
      mount(node, h('div', { class: 'card' }, h('div', { class: 'alert danger' }, err.message)));
      return;
    }

    clear(node);
    node.appendChild(pageHead({
      title: 'Platform overview',
      subtitle: 'Every client company, project and lead across Growvanta.',
      actions: [
        h('button', {
          class: 'btn btn-primary',
          onClick: () => openClientWizard({ onSaved: load }),
        }, icon('plus', { size: 15 }), 'Create new client'),
      ],
    }));

    node.appendChild(h('div', { class: 'kpi-grid' },
      kpiCard({
        label: 'Client companies',
        value: number(overview.clients.total),
        foot: `${number(overview.clients.active)} active · ${number(overview.clients.suspended)} suspended`,
        iconName: 'building',
        onClick: () => navigate('/clients'),
      }),
      // A lead always belongs to one company, so there is no platform-wide lead
      // list to open. The client table is where this total is actually broken
      // down - it carries a Leads column per company - so that is the target.
      kpiCard({
        label: 'Total leads',
        value: number(overview.leads.total),
        foot: `${number(overview.leads.today)} today · ${number(overview.leads.thisMonth)} this month`,
        iconName: 'leads',
        onClick: () => navigate('/clients'),
      }),
      kpiCard({
        label: 'Projects',
        value: number(overview.projects.total),
        foot: `${number(overview.projects.global)} global · ${number(overview.projects.shared)} shared`,
        iconName: 'briefcase',
        onClick: () => navigate('/projects'),
      }),
      // Same reason: the sidebar's Users screen lists this company's own people,
      // not everybody's. The per-company count lives in the client table.
      kpiCard({
        label: 'Users',
        value: number(overview.users),
        foot: 'Active across every company',
        iconName: 'users',
        onClick: () => navigate('/clients'),
      })));

    const typeRows = Object.entries(overview.clients.byType)
      .map(([key, value]) => ({ label: titleCase(key), value }))
      .filter((r) => r.value > 0);

    node.appendChild(h('div', { class: 'chart-grid' },
      panel('Clients by type', typeRows.length
        ? barList(typeRows)
        : h('div', { class: 'muted small' }, 'No clients yet.')),
      panel('Leads by client', overview.leadsByClient?.length
        ? barList(overview.leadsByClient.map((r) => ({ label: r.name, value: Number(r.total) })))
        : h('div', { class: 'muted small' }, 'No leads yet.'))));

    node.appendChild(h('div', { class: 'chart-grid' },
      panel('Leads by source', overview.leadsBySource?.length
        ? barList(overview.leadsBySource.map((r) => ({ label: r.name, value: Number(r.total) })))
        : h('div', { class: 'muted small' }, 'No leads yet.')),
      panel('Leads by project', overview.leadsByProject?.length
        ? barList(overview.leadsByProject.map((r) => ({ label: r.name, value: Number(r.total) })))
        : h('div', { class: 'muted small' }, 'No leads attributed to a project yet.'))));

    // Setup status. Sorted so the clients who need attention float to the top.
    const rank = { ORANGE: 0, RED: 1, GREEN: 2 };
    const sorted = [...automation].sort((a, b) => {
      const aWorst = Math.min(rank[a.whatsapp.status] ?? 3, rank[a.email.status] ?? 3);
      const bWorst = Math.min(rank[b.whatsapp.status] ?? 3, rank[b.email.status] ?? 3);
      return aWorst - bWorst;
    });
    const needsAttention = automation.filter(
      (c) => c.whatsapp.status === 'ORANGE' || c.email.status === 'ORANGE',
    ).length;

    node.appendChild(panel('Client setup status',
      // `data` is not decoration: the stacked-card layout that narrow screens
      // use is keyed on table.data, and the per-cell data-label is what it
      // prints as each row's heading once the thead is hidden.
      h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
        h('thead', null, h('tr', null,
          h('th', null, 'Company'),
          h('th', null, 'Type'),
          h('th', null, 'WhatsApp'),
          h('th', null, 'Email'),
          h('th', null, 'Outstanding'),
          h('th', null, ''))),
        h('tbody', null, ...sorted.map((c) => {
          const outstanding = [...(c.whatsapp.missing || []), ...(c.email.missing || [])];
          return h('tr', null,
            h('td', { 'data-label': 'Company' }, h('a', { href: `#/clients/${c.id}` }, c.name)),
            h('td', { 'data-label': 'Type' }, h('span', { class: 'badge' }, titleCase(c.companyType))),
            h('td', { 'data-label': 'WhatsApp' }, channelDot(c.whatsapp.status, titleCase(c.whatsapp.status))),
            h('td', { 'data-label': 'Email' }, channelDot(c.email.status, titleCase(c.email.status))),
            h('td', { 'data-label': 'Outstanding' }, h('span', { class: 'tiny muted' },
              // Only a channel that is switched on but unfinished is a problem
              // worth chasing; one that is deliberately off is a choice.
              outstanding.length && (c.whatsapp.status === 'ORANGE' || c.email.status === 'ORANGE')
                ? `${outstanding.length} item${outstanding.length === 1 ? '' : 's'} left`
                : '—')),
            h('td', { 'data-label': '' }, h('button', {
              class: 'btn btn-sm',
              onClick: () => switchToClient(c.id, c.name),
            }, 'Open CRM')));
        })))),
      {
        subtitle: needsAttention
          ? `${needsAttention} client${needsAttention === 1 ? '' : 's'} switched a channel on but have not finished setting it up.`
          : 'Nobody is stuck mid-setup.',
        flush: true,
      }));
  }

  await load();
  return { node };
}
