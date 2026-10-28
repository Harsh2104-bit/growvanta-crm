/**
 * Shared UI kit: toasts, modals, drawers, confirms, tables, empty states.
 *
 * Everything here returns real DOM nodes and owns its own teardown. Views
 * compose these rather than hand-rolling markup, which is what keeps twenty-odd
 * screens looking like one product.
 */
import { h, clear, mount, debounce } from './dom.js';
import { icon } from './icons.js';
import { initials, avatarColor, number } from './format.js';
import { ApiError } from './api.js';

// ---------------------------------------------------------------------------
// Toasts
// ---------------------------------------------------------------------------
let toastHost = null;
function ensureToastHost() {
  if (!toastHost) {
    toastHost = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });
    document.body.appendChild(toastHost);
  }
  return toastHost;
}

export function toast(title, { body = '', type = 'info', timeout = 4200 } = {}) {
  const host = ensureToastHost();
  const node = h('div', { class: `toast ${type}` },
    h('div', { class: 'grow' },
      h('div', { class: 't-title' }, title),
      body ? h('div', { class: 't-body' }, body) : null),
    h('button', { class: 't-close', 'aria-label': 'Dismiss', onClick: () => node.remove() }, '×'));
  host.appendChild(node);
  if (timeout) setTimeout(() => node.remove(), timeout);
  return node;
}

toast.success = (title, body) => toast(title, { body, type: 'success' });
toast.error = (title, body) => toast(title, { body, type: 'error', timeout: 6500 });
toast.warn = (title, body) => toast(title, { body, type: 'warning' });

/** Turns any thrown error into a sensible toast. */
export function reportError(err, fallback = 'Something went wrong') {
  if (err?.name === 'AbortError') return;
  const message = err instanceof ApiError ? err.message : (err?.message || fallback);
  const details = err instanceof ApiError && err.details
    ? err.details.map((d) => d.message).join(' · ')
    : '';
  toast.error(message, details);
  if (!(err instanceof ApiError)) console.error(err);
}

// ---------------------------------------------------------------------------
// Modal / drawer
// ---------------------------------------------------------------------------
function trapFocus(container) {
  const selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const handler = (e) => {
    if (e.key !== 'Tab') return;
    const items = [...container.querySelectorAll(selector)].filter((el) => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };
  container.addEventListener('keydown', handler);
  return () => container.removeEventListener('keydown', handler);
}

export function modal({ title, subtitle, body, footer, size = '', onClose, closeOnBackdrop = true }) {
  const box = h('div', { class: `modal ${size}`, role: 'dialog', 'aria-modal': 'true', 'aria-label': title || 'Dialog' });
  const overlay = h('div', { class: 'overlay' }, box);

  const close = () => {
    document.removeEventListener('keydown', onKey);
    releaseFocus();
    overlay.remove();
    document.body.style.overflow = '';
    onClose?.();
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };

  box.appendChild(h('div', { class: 'modal-head' },
    h('div', null,
      h('h3', null, title || ''),
      subtitle ? h('div', { class: 'sub' }, subtitle) : null),
    h('button', { class: 'icon-btn', 'aria-label': 'Close', onClick: close }, icon('close', { size: 18 }))));

  const bodyEl = h('div', { class: 'modal-body' });
  if (body) bodyEl.appendChild(body);
  box.appendChild(bodyEl);

  const footEl = h('div', { class: 'modal-foot' });
  if (footer) footEl.appendChild(footer); else footEl.remove();
  if (footer) box.appendChild(footEl);

  if (closeOnBackdrop) {
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  }
  document.addEventListener('keydown', onKey);
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';
  const releaseFocus = trapFocus(box);
  setTimeout(() => box.querySelector('input, select, textarea, button')?.focus(), 40);

  return { overlay, box, body: bodyEl, footer: footEl, close, setBody: (node) => mount(bodyEl, node) };
}

export function drawer({ title, subtitle, body, footer, wide = false, onClose }) {
  const panel = h('aside', { class: `drawer ${wide ? 'wide' : ''}`, role: 'dialog', 'aria-modal': 'true', 'aria-label': title || 'Panel' });
  const backdrop = h('div', { class: 'drawer-overlay' });

  const close = () => {
    document.removeEventListener('keydown', onKey);
    releaseFocus();
    panel.remove();
    backdrop.remove();
    document.body.style.overflow = '';
    onClose?.();
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  backdrop.addEventListener('click', close);

  panel.appendChild(h('div', { class: 'drawer-head' },
    h('div', null,
      h('h3', null, title || ''),
      subtitle ? h('div', { class: 'sub muted small' }, subtitle) : null),
    h('button', { class: 'icon-btn', 'aria-label': 'Close', onClick: close }, icon('close', { size: 18 }))));

  const bodyEl = h('div', { class: 'drawer-body' });
  if (body) bodyEl.appendChild(body);
  panel.appendChild(bodyEl);

  if (footer) panel.appendChild(h('div', { class: 'drawer-foot' }, footer));

  document.addEventListener('keydown', onKey);
  document.body.appendChild(backdrop);
  document.body.appendChild(panel);
  document.body.style.overflow = 'hidden';
  const releaseFocus = trapFocus(panel);
  setTimeout(() => panel.querySelector('input, select, textarea, button')?.focus(), 40);

  return { panel, body: bodyEl, close, setBody: (node) => mount(bodyEl, node) };
}

/** Promise-based confirm. Destructive actions must await this. */
export function confirmDialog({
  title = 'Are you sure?', message = '', confirmLabel = 'Confirm',
  cancelLabel = 'Cancel', danger = false, requireText = null,
}) {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (value) => { if (!settled) { settled = true; resolve(value); } };

    const confirmBtn = h('button', {
      class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
      disabled: Boolean(requireText),
      onClick: () => { finish(true); ref.close(); },
    }, confirmLabel);

    const bodyNodes = [message ? h('p', { class: 'muted' }, message) : null];
    if (requireText) {
      bodyNodes.push(h('div', { class: 'field mt-16' },
        h('label', null, `Type “${requireText}” to confirm`),
        h('input', {
          class: 'input',
          placeholder: requireText,
          onInput: (e) => { confirmBtn.disabled = e.target.value.trim() !== requireText; },
        })));
    }

    const ref = modal({
      title,
      body: h('div', null, ...bodyNodes.filter(Boolean)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => { finish(false); ref.close(); } }, cancelLabel),
        confirmBtn),
      onClose: () => finish(false),
    });
  });
}

// ---------------------------------------------------------------------------
// Small building blocks
// ---------------------------------------------------------------------------
export function avatar(name, { size = '', url = null } = {}) {
  const label = initials(name);
  if (url) return h('img', { class: `avatar ${size}`, src: url, alt: name || '' });
  return h('div', {
    class: `avatar ${size}`,
    style: { background: avatarColor(name || '?') },
    title: name || '',
  }, label);
}

export function statusBadge(name, color, { dot = true } = {}) {
  if (!name) return h('span', { class: 'badge' }, '—');
  return h('span', {
    class: `badge ${dot ? 'dot' : ''}`,
    style: color ? { background: `${color}1f`, color } : null,
  }, name);
}

export function emptyState({ iconName = 'search', title, message, action }) {
  return h('div', { class: 'empty' },
    h('div', { class: 'e-icon' }, icon(iconName, { size: 24 })),
    h('h3', null, title),
    message ? h('p', null, message) : null,
    action || null);
}

export function skeletonBlock(lines = 4) {
  return h('div', { class: 'card-body' },
    ...Array.from({ length: lines }, (_, i) => h('div', {
      class: 'skeleton skeleton-line',
      style: { width: `${100 - i * 9}%` },
    })));
}

export function skeletonTable(rows = 6, cols = 5) {
  return h('div', { class: 'card-body' },
    h('table', { class: 'data', style: { width: '100%' } },
      h('tbody', null, ...Array.from({ length: rows }, () => h('tr', null,
        ...Array.from({ length: cols }, () => h('td', null,
          h('div', { class: 'skeleton skeleton-line', style: { margin: 0 } }))))))));
}

export function pageLoader(label = 'Loading') {
  return h('div', { class: 'page-loader' }, h('span', { class: 'spinner lg' }), h('span', { class: 'muted' }, label));
}

export function kpiCard({ label, value, foot, accent = 'var(--brand-500)', iconName, onClick }) {
  return h('div', {
    class: `kpi ${onClick ? 'clickable' : ''}`,
    style: { '--accent': accent },
    onClick: onClick || null,
    tabindex: onClick ? '0' : null,
    role: onClick ? 'button' : null,
    onKeydown: onClick ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } } : null,
  },
    h('div', { class: 'k-label' }, iconName ? icon(iconName, { size: 14 }) : null, label),
    h('div', { class: 'k-value' }, value),
    foot ? h('div', { class: 'k-foot' }, foot) : null);
}

export function progressBar(value, max, tone = '') {
  const pctValue = max > 0 ? Math.min(100, (value / max) * 100) : 0;
  return h('div', { class: 'progress' }, h('div', { class: `p-fill ${tone}`, style: { width: `${pctValue}%` } }));
}

export function field(label, control, { hint, required, error } = {}) {
  return h('div', { class: 'field' },
    h('label', null, label, required ? h('span', { class: 'req-star' }, ' *') : null),
    control,
    error ? h('div', { class: 'err' }, error) : null,
    hint ? h('div', { class: 'hint' }, hint) : null);
}

export function input(props = {}) { return h('input', { class: 'input', ...props }); }
export function textarea(props = {}) { return h('textarea', { class: 'textarea', ...props }); }

export function select(options, { value, placeholder, ...props } = {}) {
  const el = h('select', { class: 'select', ...props });
  if (placeholder) el.appendChild(h('option', { value: '' }, placeholder));
  for (const opt of options) {
    const optValue = typeof opt === 'object' ? opt.value : opt;
    const optLabel = typeof opt === 'object' ? opt.label : opt;
    el.appendChild(h('option', { value: optValue, selected: String(optValue) === String(value) }, optLabel));
  }
  if (value !== undefined && value !== null) el.value = String(value);
  return el;
}

export function switchToggle(label, checked, onChange) {
  return h('label', { class: 'switch' },
    h('input', { type: 'checkbox', checked: checked || false, onChange: (e) => onChange(e.target.checked) }),
    h('span', { class: 'track' }),
    label ? h('span', { class: 'small' }, label) : null);
}

/**
 * Anchored dropdown that closes on outside click or Escape.
 *
 * The menu opens downward by default, but a trigger near the bottom of the
 * window - the sidebar profile card, the action button on the last row of a
 * table - would push it off-screen, which reads as "the menu did not open".
 * `place()` measures the real gap and flips the menu above the trigger, or
 * nudges it back inside the horizontal edges, whenever it needs to.
 */
export function dropdown(trigger, buildMenu, { align = 'right' } = {}) {
  const wrap = h('div', { class: 'dropdown' }, trigger);
  let menu = null;

  const close = () => {
    menu?.remove();
    menu = null;
    document.removeEventListener('mousedown', onOutside, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('hashchange', close);
  };
  // The menu lives on <body>, so "outside" has to consider it as well as the wrapper.
  const onOutside = (e) => {
    if (!wrap.contains(e.target) && !menu?.contains(e.target)) close();
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };

  const GAP = 6;
  const EDGE = 8;
  const MIN_MENU_H = 120;

  function place() {
    if (!menu) return;
    // The trigger can disappear underneath an open menu when its list re-renders.
    if (!trigger.isConnected) { close(); return; }

    const anchor = trigger.getBoundingClientRect();
    const viewportH = window.innerHeight || document.documentElement.clientHeight;
    const viewportW = window.innerWidth || document.documentElement.clientWidth;
    if (!viewportH || !viewportW) return; // hidden pane: nothing meaningful to measure

    // Measure the menu's natural height with no cap imposed, so a previous
    // placement's max-height cannot skew this one.
    menu.style.maxHeight = '';
    const naturalH = menu.offsetHeight;
    const menuW = menu.offsetWidth;

    const roomBelow = viewportH - anchor.bottom - GAP - EDGE;
    const roomAbove = anchor.top - GAP - EDGE;

    // Flip above the trigger when it does not fit below and there is more room up there.
    const flipUp = naturalH > roomBelow && roomAbove > roomBelow;
    const available = Math.max(MIN_MENU_H, flipUp ? roomAbove : roomBelow);
    const menuH = Math.min(naturalH, available);
    // Capping the height means a menu taller than the screen scrolls internally
    // instead of hanging off the edge.
    menu.style.maxHeight = `${Math.round(available)}px`;

    let top = flipUp ? anchor.top - menuH - GAP : anchor.bottom + GAP;
    top = Math.max(EDGE, Math.min(top, viewportH - menuH - EDGE));

    let left = align === 'left' ? anchor.left : anchor.right - menuW;
    left = Math.max(EDGE, Math.min(left, viewportW - menuW - EDGE));

    menu.style.top = `${Math.round(top)}px`;
    menu.style.left = `${Math.round(left)}px`;
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    // `isConnected` guards the case where something else detached the menu: the
    // closure would otherwise still think it is open and swallow the next click.
    if (menu?.isConnected) { close(); return; }
    if (menu) close();
    menu = h('div', { class: `dropdown-menu ${align === 'left' ? 'left' : ''}` });
    const content = buildMenu(close);
    menu.appendChild(content);

    // Mounted on <body>, not inside the trigger's wrapper: an absolutely
    // positioned menu is clipped by any scrolling ancestor, and `.table-wrap`
    // is one - its overflow-y computes to `auto` because overflow-x is `auto`.
    document.body.appendChild(menu);
    place();
    // Re-place once the browser has settled: icons and web fonts can change the
    // menu's height after the first measurement.
    requestAnimationFrame(place);

    document.addEventListener('mousedown', onOutside, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    // A body-mounted menu would outlive a route change otherwise.
    window.addEventListener('hashchange', close);
  });

  return wrap;
}

export function menuItem(label, { iconName, onClick, danger = false, close } = {}) {
  return h('button', {
    class: `dropdown-item ${danger ? 'danger' : ''}`,
    onClick: () => { close?.(); onClick?.(); },
  }, iconName ? icon(iconName, { size: 15 }) : null, label);
}

export function tabs(items, active, onSelect) {
  return h('div', { class: 'tabs', role: 'tablist' },
    ...items.map((item) => h('button', {
      class: item.key === active ? 'active' : '',
      role: 'tab',
      'aria-selected': String(item.key === active),
      onClick: () => onSelect(item.key),
    }, item.label, item.count !== undefined ? h('span', { class: 'badge', style: { marginLeft: '6px' } }, number(item.count)) : null)));
}

export function pagination(meta, onPage) {
  if (!meta) return h('div');
  const { page, totalPages, total, limit } = meta;
  const from = total === 0 ? 0 : (page - 1) * limit + 1;
  const to = Math.min(page * limit, total);

  const buttons = [];
  const push = (label, targetPage, isActive = false, disabled = false) => {
    buttons.push(h('button', {
      class: isActive ? 'active' : '',
      disabled,
      onClick: disabled || isActive ? null : () => onPage(targetPage),
    }, label));
  };

  push('‹', page - 1, false, page <= 1);
  // Window of pages around the current one, with ellipses.
  const window_ = new Set([1, totalPages, page, page - 1, page + 1]);
  if (page <= 3) [2, 3, 4].forEach((p) => window_.add(p));
  if (page >= totalPages - 2) [totalPages - 1, totalPages - 2, totalPages - 3].forEach((p) => window_.add(p));
  const pages = [...window_].filter((p) => p >= 1 && p <= totalPages).sort((a, b) => a - b);
  let previous = 0;
  for (const p of pages) {
    if (p - previous > 1) buttons.push(h('button', { disabled: true }, '…'));
    push(String(p), p, p === page);
    previous = p;
  }
  push('›', page + 1, false, page >= totalPages);

  return h('div', { class: 'pagination' },
    h('div', { class: 'muted small' }, `${number(from)}–${number(to)} of ${number(total)}`),
    h('div', { class: 'pages' }, ...buttons));
}

/**
 * Server-side data table.
 *
 * Owns paging, sorting, selection and its own fetch loop. Callers give it a
 * `load(params)` returning `{ data, meta }` and a column definition; nothing
 * about the row shape leaks into this file.
 */
export function dataTable({
  columns, load, rowKey = (r) => r.id, onRowClick,
  selectable = false, bulkActions = null, toolbar = null,
  emptyTitle = 'Nothing here yet', emptyMessage = '', emptyAction = null,
  defaultSort = null, defaultDir = 'desc', pageSize = 25, initialParams = {},
}) {
  const state = {
    page: 1, limit: pageSize, sort: defaultSort, dir: defaultDir,
    params: { ...initialParams }, selected: new Set(), rows: [], meta: null, loading: true,
  };

  const bulkHost = h('div');
  const tableHost = h('div', { class: 'table-wrap' });
  const pagerHost = h('div');

  // Several views build their own `.toolbar` element and pass it in. Wrapping
  // that in another `.toolbar` would double the padding and draw two dividers,
  // so only wrap a node that is not already one.
  const toolbarNode = !toolbar ? null
    : (toolbar.classList?.contains('toolbar') ? toolbar : h('div', { class: 'toolbar' }, toolbar));

  const root = h('div', { class: 'card' }, toolbarNode, bulkHost, tableHost, pagerHost);

  let requestToken = 0;

  async function refresh() {
    const token = ++requestToken;
    state.loading = true;
    mount(tableHost, skeletonTable(6, columns.length + (selectable ? 1 : 0)));
    try {
      const res = await load({
        page: state.page, limit: state.limit,
        sort: state.sort || undefined, dir: state.dir,
        ...state.params,
      });
      if (token !== requestToken) return; // a newer request superseded this one
      state.rows = res?.data || [];
      state.meta = res?.meta || null;
      state.loading = false;
      render();
    } catch (err) {
      if (token !== requestToken) return;
      state.loading = false;
      mount(tableHost, emptyState({
        iconName: 'alert',
        title: 'Could not load this list',
        message: err.message,
        action: h('button', { class: 'btn', onClick: refresh }, 'Try again'),
      }));
    }
  }

  function renderBulkBar() {
    clear(bulkHost);
    if (!selectable || !state.selected.size) return;
    bulkHost.appendChild(h('div', { class: 'bulk-bar' },
      h('span', null, `${state.selected.size} selected`),
      h('button', {
        class: 'btn btn-sm btn-ghost',
        onClick: () => { state.selected.clear(); render(); },
      }, 'Clear'),
      bulkActions ? bulkActions([...state.selected], { refresh, clear: () => state.selected.clear() }) : null));
  }

  function render() {
    renderBulkBar();

    if (!state.rows.length) {
      mount(tableHost, emptyState({ title: emptyTitle, message: emptyMessage, action: emptyAction }));
      mount(pagerHost);
      return;
    }

    const allSelected = state.rows.every((r) => state.selected.has(rowKey(r)));

    const head = h('tr', null,
      selectable ? h('th', { class: 'checkbox-cell' },
        h('input', {
          type: 'checkbox', checked: allSelected, 'aria-label': 'Select all rows',
          onChange: (e) => {
            for (const row of state.rows) {
              if (e.target.checked) state.selected.add(rowKey(row));
              else state.selected.delete(rowKey(row));
            }
            render();
          },
        })) : null,
      ...columns.map((col) => h('th', {
        // The header must carry the same responsive class as its cells, or a
        // column hidden at narrow widths keeps its heading and the table skews.
        class: [col.sortKey ? 'sortable' : '', col.cellClass || ''].filter(Boolean).join(' '),
        style: col.width ? { width: col.width } : null,
        onClick: col.sortKey ? () => {
          if (state.sort === col.sortKey) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
          else { state.sort = col.sortKey; state.dir = 'desc'; }
          state.page = 1;
          refresh();
        } : null,
      }, col.label,
      col.sortKey && state.sort === col.sortKey
        ? h('span', { class: 'sort-ind' }, state.dir === 'asc' ? '▲' : '▼') : null)));

    const body = h('tbody', null, ...state.rows.map((row) => {
      const key = rowKey(row);
      return h('tr', {
        class: `${onRowClick ? 'clickable' : ''} ${state.selected.has(key) ? 'selected' : ''}`,
        onClick: onRowClick ? (e) => {
          if (e.target.closest('input, button, a, .dropdown')) return;
          onRowClick(row);
        } : null,
      },
        selectable ? h('td', {
          class: 'checkbox-cell',
          onClick: (e) => e.stopPropagation(),
        }, h('input', {
          type: 'checkbox', checked: state.selected.has(key), 'aria-label': 'Select row',
          onChange: (e) => {
            if (e.target.checked) state.selected.add(key); else state.selected.delete(key);
            render();
          },
        })) : null,
        // `data-label` is what the stacked card layout prints as each row's
        // heading once the table stops being a table on small screens.
        ...columns.map((col) => h('td', {
          class: col.cellClass || '',
          'data-label': col.label || '',
        }, col.render(row))));
    }));

    mount(tableHost, h('table', { class: 'data' }, h('thead', null, head), body));
    mount(pagerHost, state.meta && state.meta.totalPages > 1
      ? pagination(state.meta, (p) => { state.page = p; refresh(); })
      : (state.meta ? h('div', { class: 'pagination' },
        h('div', { class: 'muted small' }, `${number(state.meta.total)} record${state.meta.total === 1 ? '' : 's'}`)) : h('div')));
  }

  refresh();

  return {
    root,
    refresh,
    setParams(patch, { resetPage = true } = {}) {
      Object.assign(state.params, patch);
      for (const [k, v] of Object.entries(patch)) {
        if (v === '' || v === null || v === undefined) delete state.params[k];
      }
      if (resetPage) state.page = 1;
      refresh();
    },
    getParams: () => ({ ...state.params }),
    getSelected: () => [...state.selected],
    clearSelection() { state.selected.clear(); render(); },
    getMeta: () => state.meta,
  };
}

/** Debounced search box for table toolbars. */
export function searchBox(placeholder, onSearch, { value = '' } = {}) {
  const run = debounce((v) => onSearch(v), 320);
  return h('div', { class: 'search-input' },
    icon('search', { size: 15 }),
    h('input', {
      class: 'input', type: 'search', placeholder, value,
      onInput: (e) => run(e.target.value.trim()),
    }));
}

export function copyToClipboard(text, label = 'Copied to clipboard') {
  navigator.clipboard?.writeText(text)
    .then(() => toast.success(label))
    .catch(() => toast.error('Could not copy', 'Your browser blocked clipboard access.'));
}

/** Wraps a submit handler with a busy state so double-clicks cannot double-post. */
export function busy(button, fn) {
  return async (...args) => {
    if (button.disabled) return undefined;
    const original = button.textContent;
    button.disabled = true;
    button.classList.add('loading');
    clear(button);
    button.appendChild(h('span', { class: 'spinner' }));
    button.appendChild(document.createTextNode(` ${original}`));
    try {
      return await fn(...args);
    } finally {
      button.disabled = false;
      button.classList.remove('loading');
      clear(button);
      button.textContent = original;
    }
  };
}
