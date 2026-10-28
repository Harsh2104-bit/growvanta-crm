/**
 * Dashboard: KPI row, lead trend, leads by project, source performance, the
 * enquiry-to-booking funnel, team leaderboard, recent activity and the
 * follow-up queue.
 *
 * Every number here comes from /api/reports/dashboard, which applies the same
 * visibility rules as the lists - so a Team Leader's totals reconcile exactly
 * with the leads they can open.
 */
import { h, mount } from '../lib/dom.js';
import { icon, ACTIVITY_ICONS } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import { pageLoader, emptyState, kpiCard, avatar, reportError } from '../lib/ui.js';
import { lineChart, barList, donutChart, funnelChart, PALETTE } from '../lib/charts.js';
import { number, money, percent, relative, date, compactNumber, titleCase } from '../lib/format.js';
import { pageHead, filterBar, panel, linkTo, noAccess } from './shared.js';
import { navigate } from '../router.js';

export default async function render({ query }) {
  if (!can('dashboard', 'read')) return { node: noAccess('the dashboard') };

  const filters = { range: query.range || 'last_30_days', ownerId: query.ownerId || '', teamId: query.teamId || '', sourceId: query.sourceId || '', projectId: query.projectId || '' };
  const filterHost = h('div', { class: 'row wrap filter-row', style: { gap: '8px' } });
  const body = h('div', null, pageLoader('Building your dashboard'));

  const node = h('div', null,
    pageHead({
      title: `Good ${greeting()}, ${store.user.firstName}`,
      subtitle: `${store.organization?.name} · ${store.user.role?.name} · ${scopeLabel()}`,
      actions: filterHost,
    }),
    body);

  async function applyFilters(patch) {
    Object.assign(filters, patch);
    const params = new URLSearchParams(Object.entries(filters).filter(([, v]) => v));
    window.history.replaceState(null, '', `#/${params.toString() ? `?${params}` : ''}`);
    await load();
    await buildFilters();
  }

  async function buildFilters() {
    const controls = await filterBar(['range', 'project', 'owner', 'team', 'source'], filters, applyFilters);
    mount(filterHost, ...controls,
      h('button', { class: 'btn btn-sm', onClick: () => load() }, icon('refresh', { size: 14 }), 'Refresh'));
  }

  async function load() {
    mount(body, pageLoader('Loading'));
    try {
      const res = await api.get('/api/reports/dashboard', filters);
      mount(body, renderDashboard(res.data, filters));
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({
        iconName: 'alert', title: 'Dashboard unavailable', message: err.message,
        action: h('button', { class: 'btn', onClick: load }, 'Try again'),
      })));
    }
  }

  await buildFilters();
  await load();
  return { node };
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'morning';
  if (hour < 17) return 'afternoon';
  return 'evening';
}

function scopeLabel() {
  const map = {
    OWN: 'Your own records', TEAM: 'Your team', TEAM_AND_BELOW: 'Your teams and everything below',
    REGION: 'Your region', ORGANIZATION: 'Organisation-wide', ALL: 'Full platform access',
  };
  return map[store.user.role?.dataScope] || '';
}

function renderDashboard(data, filters) {
  const k = data.kpis;
  const leadFilterQuery = (extra) => {
    const params = new URLSearchParams();
    if (filters.range) params.set('range', filters.range);
    if (filters.ownerId) params.set('ownerId', filters.ownerId);
    if (filters.teamId) params.set('teamId', filters.teamId);
    for (const [key, value] of Object.entries(extra || {})) params.set(key, value);
    return `/leads?${params.toString()}`;
  };

  const kpis = h('div', { class: 'kpi-grid mb-16' },
    kpiCard({
      label: 'Total leads', value: number(k.totalLeads), iconName: 'leads', accent: PALETTE[0],
      foot: `${number(k.newLeads)} new · ${number(k.unassignedLeads)} unassigned`,
      onClick: () => navigate(leadFilterQuery()),
    }),
    kpiCard({
      label: 'Qualified', value: number(k.qualifiedLeads), iconName: 'check', accent: PALETTE[1],
      foot: `${percent(k.qualificationRate)} of all leads`,
    }),
    kpiCard({
      label: 'Converted', value: number(k.convertedLeads), iconName: 'star', accent: PALETTE[2],
      foot: `${percent(k.conversionRate)} conversion rate`,
      onClick: () => navigate(leadFilterQuery({ converted: 'true' })),
    }),
    kpiCard({
      label: 'Site visits', value: number(k.siteVisitsBooked), iconName: 'calendar', accent: PALETTE[3],
      foot: `${number(k.siteVisitsUpcoming)} upcoming · ${percent(k.siteVisitRate)} of leads`,
    }),
    kpiCard({
      label: 'Booking value', value: money(k.bookedValue, { compact: true }), iconName: 'trending', accent: PALETTE[2],
      foot: `${number(k.bookings)} booking${k.bookings === 1 ? '' : 's'}`,
    }),
    kpiCard({
      label: 'Follow-ups due', value: number(k.followUpsDue), iconName: 'clock',
      accent: k.followUpsOverdue > 0 ? 'var(--danger)' : PALETTE[7],
      foot: k.followUpsOverdue > 0
        ? h('span', { class: 'text-danger strong' }, `${number(k.followUpsOverdue)} overdue`)
        : 'Nothing overdue',
      onClick: () => navigate(leadFilterQuery({ followUp: k.followUpsOverdue > 0 ? 'overdue' : 'today' })),
    }),
    kpiCard({
      label: 'Lost / junk', value: number(k.lostLeads + k.junkLeads), iconName: 'close', accent: PALETTE[4],
      foot: `${number(k.lostLeads)} lost · ${number(k.junkLeads)} junk`,
    }));

  const trendSeries = [
    { key: 'leads', label: 'Leads', color: PALETTE[0], values: data.trend.map((d) => d.leads) },
    { key: 'qualified', label: 'Qualified', color: PALETTE[1], values: data.trend.map((d) => d.qualified) },
    { key: 'converted', label: 'Converted', color: PALETTE[2], values: data.trend.map((d) => d.converted) },
  ];
  const trendLabels = data.trend.map((d) => date(d.date, { day: '2-digit', month: 'short', year: undefined }));

  const row1 = h('div', { class: 'chart-grid two mb-16' },
    panel('Lead trend', data.trend.length
      ? lineChart(trendSeries, trendLabels, { height: 250 })
      : emptyState({ iconName: 'reports', title: 'No leads in this period' }),
    { subtitle: 'Created, qualified and converted per day' }),
    panel('Lead status mix', data.byStatus.length
      ? donutChart(data.byStatus.map((s) => ({ label: s.label, value: s.count, color: s.color })), { centerLabel: 'Leads' })
      : emptyState({ title: 'Nothing to show yet' })));

  const row2 = h('div', { class: 'chart-grid two mb-16' },
    panel('Leads by project', data.byProject?.length
      ? barList(data.byProject.map((p) => ({ label: p.label, value: p.count })))
      : emptyState({ iconName: 'building', title: 'No project attribution yet', message: 'Attach leads to a project to see which inventory is pulling.' }),
    {
      subtitle: 'Where the enquiries are landing',
      action: can('projects', 'read') ? linkTo('/projects', 'All projects', { className: 'btn btn-sm' }) : null,
    }),
    panel('Conversion funnel', funnelChart(data.funnel), { subtitle: 'Enquiry to booking' }));

  const row3 = h('div', { class: 'chart-grid two mb-16' },
    panel('Lead sources', data.bySource.length
      ? barList(data.bySource.map((s) => ({ label: s.label, value: s.count })))
      : emptyState({ title: 'No source data' }),
    {
      subtitle: 'Where your leads come from',
      action: can('reports', 'read') ? linkTo('/reports', 'Full report', { className: 'btn btn-sm' }) : null,
    }),
    panel('Leads by owner', data.byOwner.length
      ? barList(data.byOwner.map((s) => ({ label: s.label, value: s.count })), { colorFn: (_, i) => PALETTE[(i + 3) % PALETTE.length] })
      : emptyState({ title: 'No owner data' })));

  const leaderboard = panel('Team leaderboard', data.leaderboard.length
    ? h('div', { class: 'table-wrap' },
      h('table', { class: 'data' },
        h('thead', null, h('tr', null,
          h('th', null, '#'), h('th', null, 'Person'),
          h('th', { class: 'right' }, 'Leads'), h('th', { class: 'right hide-sm' }, 'Activities'),
          h('th', { class: 'right' }, 'Converted'), h('th', { class: 'right hide-sm' }, 'Conv. rate'),
          h('th', { class: 'right' }, 'Revenue'))),
        h('tbody', null, ...data.leaderboard.map((row, i) => h('tr', null,
          h('td', { class: 'muted' }, String(i + 1)),
          h('td', null, h('div', { class: 'row', style: { gap: '8px' } },
            avatar(row.name, { size: 'sm', url: row.avatarUrl }),
            h('div', null,
              h('div', { class: 'cell-main' }, row.name),
              h('div', { class: 'cell-sub truncate' }, row.email)))),
          h('td', { class: 'right' }, number(row.leads)),
          h('td', { class: 'right hide-sm' }, number(row.activities)),
          h('td', { class: 'right' }, number(row.converted)),
          h('td', { class: 'right hide-sm' }, percent(row.conversionRate)),
          h('td', { class: 'right strong' }, money(row.revenue, { compact: true })))))))
    : emptyState({ iconName: 'users', title: 'No performance data yet' }),
  { subtitle: 'Ranked by closed-won revenue', flush: true });

  const activityPanel = panel('Recent activity', data.recentActivities.length
    ? h('div', { class: 'timeline' }, ...data.recentActivities.map((a) => h('div', { class: 'tl-item' },
      h('div', { class: 'tl-dot' }, icon(ACTIVITY_ICONS[a.type] || 'info', { size: 11 })),
      h('div', { class: 'tl-head' },
        h('span', { class: 'tl-title' }, a.subject || titleCase(a.type)),
        h('span', { class: 'tl-time' }, relative(a.occurredAt))),
      a.lead
        ? h('div', { class: 'tl-body' },
          linkTo(`/leads/${a.lead.id}`, `${a.lead.firstName} ${a.lead.lastName || ''}`.trim()),
          a.user ? ` · ${a.user.firstName} ${a.user.lastName || ''}`.trimEnd() : '')
        : (a.user ? h('div', { class: 'tl-body muted' }, `${a.user.firstName} ${a.user.lastName || ''}`.trim()) : null))))
    : emptyState({ iconName: 'activity', title: 'No activity yet' }),
  { action: can('activities', 'read') ? linkTo('/activities', 'See all', { className: 'btn btn-sm' }) : null });

  // The work queue is leads that are due, now that follow-ups live on the lead.
  const followUpPanel = panel('Follow-ups due', data.upcomingFollowUps?.length
    ? h('div', { class: 'col', style: { gap: '10px' } }, ...data.upcomingFollowUps.map((l) => {
      const overdue = l.dueAt && new Date(l.dueAt) < new Date();
      return h('a', {
        class: 'row',
        href: `#/leads/${l.id}`,
        style: { gap: '10px', alignItems: 'flex-start', textDecoration: 'none', color: 'inherit' },
      },
      h('span', {
        class: 'badge',
        style: { background: overdue ? 'var(--danger-bg)' : 'var(--surface-3)', color: overdue ? 'var(--danger)' : 'var(--ink-3)', flex: '0 0 auto' },
      }, overdue ? 'Overdue' : relative(l.dueAt)),
      h('div', { class: 'grow', style: { minWidth: 0 } },
        h('div', { class: 'truncate strong' }, l.name || l.code),
        h('div', { class: 'cell-sub truncate' },
          [l.projectName, l.phone, l.owner ? `${l.owner.firstName} ${l.owner.lastName || ''}`.trim() : null]
            .filter(Boolean).join(' · '))));
    }))
    : emptyState({ iconName: 'check', title: 'Nothing due', message: 'No follow-ups are scheduled.' }),
  { action: can('leads', 'read') ? linkTo('/leads?followUp=today', 'All follow-ups', { className: 'btn btn-sm' }) : null });

  const row4 = h('div', { class: 'chart-grid two' }, activityPanel, followUpPanel);

  return h('div', null, kpis, row1, row2, row3, h('div', { class: 'mb-16' }, leaderboard), row4);
}
