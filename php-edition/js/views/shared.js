/**
 * Pieces shared across views: page headers, breadcrumbs, the standard date-range
 * filter, owner/status pickers and a few small renderers used by more than one
 * screen.
 */
import { h } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { navigate } from '../router.js';
import { reference } from '../lib/store.js';
import { select, statusBadge, avatar } from '../lib/ui.js';
import { initials, SCORE_TONE, PRIORITY_TONE, titleCase } from '../lib/format.js';

export function pageHead({ title, subtitle, breadcrumbs, actions }) {
  return h('div', { class: 'page-head' },
    h('div', null,
      breadcrumbs?.length
        ? h('div', { class: 'breadcrumb' },
          ...breadcrumbs.flatMap((crumb, i) => [
            i > 0 ? h('span', { class: 'sep' }, '/') : null,
            crumb.path
              ? h('a', { href: `#${crumb.path}` }, crumb.label)
              : h('span', null, crumb.label),
          ].filter(Boolean)))
        : null,
      h('h1', null, title),
      subtitle ? h('div', { class: 'sub' }, subtitle) : null),
    actions ? h('div', { class: 'row wrap', style: { gap: '8px' } }, actions) : null);
}

export const DATE_RANGES = [
  { value: '', label: 'All time' },
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'last_7_days', label: 'Last 7 days' },
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'last_90_days', label: 'Last 90 days' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
  { value: 'this_quarter', label: 'This quarter' },
  { value: 'this_year', label: 'This year' },
];

export function rangeSelect(value, onChange) {
  return select(DATE_RANGES, {
    value,
    onChange: (e) => onChange(e.target.value),
    'aria-label': 'Date range',
  });
}

/**
 * Builds the standard filter row. Each requested filter is rendered only when
 * the reference data for it exists, so a user without project access simply
 * does not see a project picker.
 */
export async function filterBar(filters, current, onChange) {
  const ref = await reference();
  const controls = [];

  const add = (key, options, placeholder) => {
    if (!options?.length) return;
    controls.push(select(options, {
      value: current[key] || '',
      placeholder,
      onChange: (e) => onChange({ [key]: e.target.value }),
      'aria-label': placeholder,
    }));
  };

  for (const filter of filters) {
    if (filter === 'range') {
      controls.push(rangeSelect(current.range || '', (value) => onChange({ range: value })));
    } else if (filter === 'status') {
      add('statusId', ref.statuses.map((s) => ({ value: s.id, label: s.name })), 'All statuses');
    } else if (filter === 'source') {
      add('sourceId', ref.sources.map((s) => ({ value: s.id, label: s.name })), 'All sources');
    } else if (filter === 'project') {
      add('projectId', ref.projects.map((p) => ({ value: p.id, label: p.name })), 'All projects');
    } else if (filter === 'owner') {
      add('ownerId', ref.users.map((u) => ({ value: u.id, label: u.name || u.fullName })), 'All owners');
    } else if (filter === 'team') {
      add('teamId', ref.teams.map((t) => ({ value: t.id, label: t.name })), 'All teams');
    } else if (filter === 'priority') {
      add('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'].map((p) => ({ value: p, label: titleCase(p) })), 'Any priority');
    } else if (filter === 'scoreBand') {
      add('scoreBand', [
        { value: 'COLD', label: 'Cold' }, { value: 'WARM', label: 'Warm' },
        { value: 'HOT', label: 'Hot' }, { value: 'VERY_HOT', label: 'Very hot' },
      ], 'Any score');
    }
  }

  const hasFilters = Object.entries(current).some(([k, v]) => v && !['page', 'limit', 'sort', 'dir', 'view'].includes(k));
  if (hasFilters) {
    controls.push(h('button', {
      class: 'btn btn-sm btn-ghost',
      onClick: () => onChange(Object.fromEntries(Object.keys(current).map((k) => [k, '']))),
    }, icon('close', { size: 14 }), 'Clear'));
  }

  return controls;
}

export function ownerCell(owner) {
  if (!owner) return h('span', { class: 'badge' }, 'Unassigned');
  const name = `${owner.firstName} ${owner.lastName || ''}`.trim();
  return h('div', { class: 'row', style: { gap: '7px' } },
    avatar(name, { size: 'sm', url: owner.avatarUrl }),
    h('span', { class: 'truncate' }, name));
}

export function scoreCell(score, band) {
  const color = SCORE_TONE[band] || 'var(--ink-3)';
  return h('div', { class: 'row', style: { gap: '7px' } },
    h('span', {
      style: {
        fontWeight: '700', color, minWidth: '24px', display: 'inline-block',
      },
    }, String(score ?? 0)),
    h('span', {
      class: 'badge',
      style: { background: `${color}1a`, color, fontSize: '10.5px' },
    }, titleCase(band || 'cold')));
}

export const priorityBadge = (priority) => h('span', { class: PRIORITY_TONE[priority] || 'badge' }, titleCase(priority || 'medium'));

export function leadNameCell(lead) {
  return h('div', null,
    h('div', { class: 'cell-main truncate' }, lead.fullName || `${lead.firstName} ${lead.lastName || ''}`.trim()),
    h('div', { class: 'cell-sub truncate' }, [lead.company, lead.code].filter(Boolean).join(' · ')));
}

export function tagList(tags) {
  if (!tags?.length) return h('span', { class: 'muted' }, '—');
  return h('div', { class: 'row wrap', style: { gap: '4px' } },
    ...tags.slice(0, 3).map((tag) => h('span', {
      class: 'badge',
      style: { background: `${tag.color}1f`, color: tag.color },
    }, tag.name)),
    tags.length > 3 ? h('span', { class: 'badge' }, `+${tags.length - 3}`) : null);
}

export { statusBadge };

export function linkTo(path, label, { className = '' } = {}) {
  return h('a', {
    href: `#${path}`,
    class: className,
    onClick: (e) => { e.preventDefault(); navigate(path); },
  }, label);
}

/** Consistent "you do not have access" panel. */
export function noAccess(what = 'this section') {
  return h('div', { class: 'card' },
    h('div', { class: 'empty' },
      h('div', { class: 'e-icon' }, icon('shield', { size: 24 })),
      h('h3', null, 'No access'),
      h('p', null, `Your role does not include permission to view ${what}. Ask an administrator if you need it.`)));
}

/** Card wrapper for a chart or table with an optional header action. */
export function panel(title, content, { subtitle, action, flush = false } = {}) {
  return h('section', { class: 'card' },
    title ? h('div', { class: 'card-head' },
      h('div', null, h('h3', null, title), subtitle ? h('div', { class: 'sub' }, subtitle) : null),
      action || null) : null,
    h('div', { class: `card-body ${flush ? 'flush' : ''}` }, content));
}
