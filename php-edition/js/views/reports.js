/**
 * Reports: lead and source analytics with a shared filter bar and CSV
 * export for each tabular report.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, reference } from '../lib/store.js';
import { pageLoader, emptyState, toast, reportError, kpiCard, select, tabs } from '../lib/ui.js';
import { barList, donutChart, funnelChart, lineChart, columnChart, PALETTE } from '../lib/charts.js';
import { money, number, percent, date, titleCase } from '../lib/format.js';
import { pageHead, filterBar, noAccess, panel } from './shared.js';

const TABS = [
  { key: 'leads', label: 'Lead reports' },
  { key: 'marketing', label: 'Marketing & attribution' },
];

export default async function render({ query }) {
  if (!can('reports', 'read')) return { node: noAccess('reports') };

  const state = {
    tab: TABS.some((t) => t.key === query.tab) ? query.tab : 'leads',
    dimension: query.dimension || 'source',
    filters: {
      range: query.range || 'last_30_days',
      ownerId: query.ownerId || '',
      teamId: query.teamId || '',
      sourceId: query.sourceId || '',
      projectId: query.projectId || '',
    },
  };

  const filterHost = h('div', { class: 'row wrap filter-row', style: { gap: '8px' } });
  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div', null, pageLoader());

  const node = h('div', null,
    pageHead({
      title: 'Reports & Analytics',
      subtitle: 'Everything filtered to what your role can see.',
      actions: [
        filterHost,
        can('reports', 'export')
          ? h('button', {
            class: 'btn',
            onClick: () => api.download(`/api/reports/export/${state.tab === 'marketing' ? 'marketing' : state.tab}`, {
              ...state.filters, dimension: state.dimension,
            }).then(() => toast.success('Report exported')).catch(reportError),
          }, icon('download', { size: 15 }), 'Export CSV')
          : null,
      ].filter(Boolean),
    }),
    tabHost, body);

  function syncHash() {
    const params = new URLSearchParams({ tab: state.tab, dimension: state.dimension });
    for (const [k, v] of Object.entries(state.filters)) if (v) params.set(k, v);
    window.history.replaceState(null, '', `#/reports?${params}`);
  }

  async function buildFilters() {
    const controls = await filterBar(['range', 'project', 'owner', 'team', 'source'], state.filters, async (patch) => {
      Object.assign(state.filters, patch);
      syncHash();
      await buildFilters();
      load();
    });
    mount(filterHost, ...controls);
  }

  function buildTabs() {
    mount(tabHost, tabs(TABS, state.tab, (key) => {
      state.tab = key;
      syncHash();
      buildTabs();
      load();
    }));
  }

  async function load() {
    mount(body, pageLoader('Crunching numbers'));
    try {
      if (state.tab === 'leads') mount(body, await renderLeadReports());
      else mount(body, await renderMarketingReports());
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Report unavailable', message: err.message })));
    }
  }

  // -------------------------------------------------------------------------
  async function renderLeadReports() {
    const ref = await reference();
    const [report, trend] = await Promise.all([
      api.data('/api/reports/leads', { ...state.filters, dimension: state.dimension }),
      api.data('/api/reports/trend', state.filters),
    ]);

    const dimensionSelect = select(
      (ref.dimensions || []).map((d) => ({ value: d.key, label: d.label })),
      {
        value: state.dimension,
        onChange: (e) => { state.dimension = e.target.value; syncHash(); load(); },
      },
    );

    const totals = report.rows.reduce((acc, r) => ({
      leads: acc.leads + r.leads,
      qualified: acc.qualified + r.qualified,
      converted: acc.converted + r.converted,
      value: acc.value + r.expectedValue,
    }), { leads: 0, qualified: 0, converted: 0, value: 0 });

    return h('div', null,
      h('div', { class: 'kpi-grid mb-16' },
        kpiCard({ label: 'Leads', value: number(totals.leads), accent: PALETTE[0] }),
        kpiCard({ label: 'Qualified', value: number(totals.qualified), foot: percent(totals.leads ? (totals.qualified / totals.leads) * 100 : 0), accent: PALETTE[1] }),
        kpiCard({ label: 'Converted', value: number(totals.converted), foot: percent(totals.leads ? (totals.converted / totals.leads) * 100 : 0), accent: PALETTE[2] }),
        kpiCard({ label: 'Expected value', value: money(totals.value, { compact: true }), accent: PALETTE[3] })),

      h('div', { class: 'chart-grid two mb-16' },
        panel('Lead trend', trend.length
          ? lineChart([
            { key: 'leads', label: 'Leads', color: PALETTE[0], values: trend.map((d) => d.leads) },
            { key: 'qualified', label: 'Qualified', color: PALETTE[1], values: trend.map((d) => d.qualified) },
            { key: 'converted', label: 'Converted', color: PALETTE[2], values: trend.map((d) => d.converted) },
          ], trend.map((d) => date(d.date, { day: '2-digit', month: 'short', year: undefined })), { height: 250 })
          : emptyState({ title: 'No leads in this window' })),
        panel(`Share by ${report.dimensionLabel.toLowerCase()}`, report.rows.length
          ? donutChart(report.rows.slice(0, 8).map((r) => ({ label: r.label, value: r.leads })), { centerLabel: 'Leads' })
          : emptyState({ title: 'No data' }))),

      panel(`Leads by ${report.dimensionLabel.toLowerCase()}`,
        report.rows.length
          ? h('div', { class: 'table-wrap' },
            h('table', { class: 'data' },
              h('thead', null, h('tr', null,
                h('th', null, report.dimensionLabel),
                h('th', { class: 'right' }, 'Leads'),
                h('th', { class: 'right' }, 'Qualified'),
                h('th', { class: 'right' }, 'Site visits'),
                h('th', { class: 'right' }, 'Converted'),
                h('th', { class: 'right hide-sm' }, 'Qual. rate'),
                h('th', { class: 'right' }, 'Conv. rate'),
                h('th', { class: 'right hide-sm' }, 'Expected value'),
                h('th', { class: 'right hide-sm' }, 'Share'))),
              h('tbody', null, ...report.rows.map((r) => h('tr', null,
                h('td', { class: 'cell-main' }, r.label),
                h('td', { class: 'right' }, number(r.leads)),
                h('td', { class: 'right' }, number(r.qualified)),
                h('td', { class: 'right' }, number(r.siteVisits)),
                h('td', { class: 'right' }, number(r.converted)),
                h('td', { class: 'right hide-sm' }, percent(r.qualificationRate)),
                h('td', { class: 'right' }, h('span', {
                  class: r.conversionRate >= 20 ? 'text-success strong' : '',
                }, percent(r.conversionRate))),
                h('td', { class: 'right hide-sm' }, money(r.expectedValue, { compact: true })),
                h('td', { class: 'right hide-sm muted' }, percent(r.share)))))))
          : emptyState({ title: 'No leads match these filters' }),
      { action: dimensionSelect, flush: true }));
  }

  // -------------------------------------------------------------------------
  // -------------------------------------------------------------------------
  // Source performance. Campaign objects are gone, so attribution is grouped by
  // lead source, by UTM campaign, and by project.
  async function renderMarketingReports() {
    const data = await api.data('/api/reports/marketing', state.filters);
    const t = data.totals;

    return h('div', null,
      h('div', { class: 'kpi-grid mb-16' },
        kpiCard({ label: 'Marketing spend', value: money(t.spend, { compact: true }), accent: PALETTE[3] }),
        kpiCard({ label: 'Leads generated', value: number(t.leads), accent: PALETTE[0] }),
        kpiCard({ label: 'Blended cost per lead', value: money(t.costPerLead), accent: PALETTE[5] }),
        kpiCard({ label: 'Booking value', value: money(t.revenue, { compact: true }), accent: PALETTE[2] }),
        kpiCard({
          label: 'Return on spend',
          value: t.roi === null ? '—' : percent(t.roi, 0),
          accent: t.roi >= 0 ? PALETTE[2] : PALETTE[4],
        })),

      panel('Lead source performance', data.sources.length
        ? h('div', { class: 'table-wrap' },
          h('table', { class: 'data' },
            h('thead', null, h('tr', null,
              h('th', null, 'Source'),
              h('th', { class: 'right hide-sm' }, 'Spend'),
              h('th', { class: 'right' }, 'Leads'),
              h('th', { class: 'right hide-sm' }, 'Qualified'),
              h('th', { class: 'right' }, 'Site visits'),
              h('th', { class: 'right' }, 'Bookings'),
              h('th', { class: 'right hide-sm' }, 'Cost / lead'),
              h('th', { class: 'right hide-sm' }, 'Cost / booking'),
              h('th', { class: 'right' }, 'Conv. %'),
              h('th', { class: 'right hide-sm' }, 'ROI %'))),
            h('tbody', null, ...data.sources.map((r) => h('tr', null,
              h('td', { class: 'cell-main' }, r.label),
              h('td', { class: 'right hide-sm' }, r.spend ? money(r.spend, { compact: true }) : '—'),
              h('td', { class: 'right' }, number(r.leads)),
              h('td', { class: 'right hide-sm' }, number(r.qualified)),
              h('td', { class: 'right' }, number(r.siteVisits)),
              h('td', { class: 'right strong' }, number(r.converted)),
              h('td', { class: 'right hide-sm' }, r.costPerLead ? money(r.costPerLead) : '—'),
              h('td', { class: 'right hide-sm' }, r.costPerBooking ? money(r.costPerBooking) : '—'),
              h('td', { class: 'right' }, percent(r.conversionRate)),
              h('td', {
                class: `right hide-sm ${r.roi !== null && r.roi >= 0 ? 'text-success strong' : ''}`,
              }, r.roi === null ? '—' : percent(r.roi, 0)))))))
        : emptyState({ title: 'No source data', message: 'Attribute leads to a source to populate this report.' }),
      { flush: true }),

      h('div', { class: 'chart-grid two mt-16' },
        panel('Ad campaigns (UTM)', data.campaigns.length
          ? h('div', { class: 'table-wrap' },
            h('table', { class: 'data' },
              h('thead', null, h('tr', null,
                h('th', null, 'Campaign'), h('th', null, 'Source'),
                h('th', { class: 'right' }, 'Leads'), h('th', { class: 'right' }, 'Booked'))),
              h('tbody', null, ...data.campaigns.map((c) => h('tr', null,
                h('td', { class: 'cell-main truncate' }, c.label),
                h('td', { class: 'muted small' }, c.source),
                h('td', { class: 'right' }, number(c.leads)),
                h('td', { class: 'right strong' }, number(c.converted)))))))
          : emptyState({ title: 'No UTM campaigns', message: 'Tag your ad URLs with utm_campaign to see them here.' }),
        { subtitle: 'From the utm_campaign on each lead', flush: true }),

        panel('Projects', data.projects.length
          ? barList(data.projects.map((p) => ({ label: p.label, value: p.leads })))
          : emptyState({ title: 'No project attribution' }),
        { subtitle: 'Enquiries per project' })));
  }

  await buildFilters();
  buildTabs();
  await load();

  return { node };
}
