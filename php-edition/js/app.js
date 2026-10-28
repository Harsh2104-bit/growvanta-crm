/**
 * Application shell.
 *
 * Boots the session, builds the chrome (sidebar, topbar, command palette,
 * notification centre) and registers every route. Views are lazily imported by
 * the router, so this file stays the only thing that always loads.
 */
import { h, mount, clear, debounce } from './lib/dom.js';
import { icon } from './lib/icons.js';
import { api, setUnauthorizedHandler } from './lib/api.js';
import {
  store, loadSession, can, canAny, getTheme, toggleTheme,
  getSidebarCollapsed, setSidebarCollapsed, setUnreadCount, reference,
} from './lib/store.js';
import { toast, reportError, avatar, dropdown, menuItem, emptyState } from './lib/ui.js';
import { relative } from './lib/format.js';
import { route, setNotFound, configureRouter, startRouter, navigate, parseHash } from './router.js';

setUnauthorizedHandler(() => {
  if (!window.location.pathname.startsWith('/login')) window.location.href = '/login.html';
});

const appRoot = document.getElementById('app');

// ---------------------------------------------------------------------------
// Navigation model. Each entry declares the permission that reveals it, so the
// sidebar is a projection of the signed-in role rather than a fixed list.
// ---------------------------------------------------------------------------
const isPlatformAdmin = () => Boolean(store.user?.isPlatformAdmin);
// A platform admin who has switched into a client is working inside that
// client; the platform-only screens are hidden until they step back out.
const onPlatform = () => isPlatformAdmin() && !store.user?.isImpersonating;

const NAV = [
  {
    section: 'Platform',
    items: [
      { path: '/platform', label: 'Platform overview', iconName: 'grid', show: onPlatform },
      { path: '/clients', label: 'Client companies', iconName: 'building', show: onPlatform },
    ],
  },
  {
    section: null,
    items: [
      { path: '/', label: 'Dashboard', iconName: 'dashboard', show: () => can('dashboard', 'read') },
      { path: '/leads', label: 'Leads', iconName: 'leads', show: () => can('leads', 'read') },
      { path: '/projects', label: 'Projects', iconName: 'building', show: () => can('projects', 'read') },
    ],
  },
  {
    section: 'Marketing',
    items: [
      { path: '/forms', label: 'Web Forms', iconName: 'forms', show: () => can('forms', 'read') },
    ],
  },
  {
    section: 'Insights',
    items: [
      { path: '/reports', label: 'Reports', iconName: 'reports', show: () => can('reports', 'read') },
      { path: '/performance', label: 'Performance', iconName: 'trending', show: () => can('reports', 'read') || can('targets', 'read') },
      { path: '/activities', label: 'Activity Feed', iconName: 'activity', show: () => can('activities', 'read') },
    ],
  },
  {
    section: 'Administration',
    items: [
      { path: '/users', label: 'Users', iconName: 'users', show: () => can('users', 'read') },
      { path: '/teams', label: 'Teams', iconName: 'teams', show: () => can('teams', 'read') || can('users', 'read') },
      { path: '/roles', label: 'Roles & Permissions', iconName: 'shield', show: () => can('roles', 'read') },
      { path: '/automation', label: 'Automation', iconName: 'automation', show: () => can('automation', 'read') },
      { path: '/communication', label: 'WhatsApp & Email', iconName: 'message', show: () => canAny([['whatsapp', 'read'], ['email', 'read']]) },
      { path: '/imports', label: 'Import & Export', iconName: 'import', show: () => can('imports', 'read') },
      { path: '/settings', label: 'Settings', iconName: 'settings', show: () => can('settings', 'read') },
      { path: '/developers', label: 'Developers', iconName: 'key', show: () => canAny([['apikeys', 'read'], ['webhooks', 'read']]) },
      { path: '/integrations', label: 'Integrations', iconName: 'integrations', show: () => can('integrations', 'read') },
      { path: '/audit', label: 'Audit Log', iconName: 'audit', show: () => can('audit', 'read') },
    ],
  },
];

let sidebarNav = null;

function renderSidebar() {
  const nav = h('nav', { class: 'nav', 'aria-label': 'Main navigation' });
  for (const group of NAV) {
    const visible = group.items.filter((item) => item.show());
    if (!visible.length) continue;
    if (group.section) nav.appendChild(h('div', { class: 'nav-section-title' }, group.section));
    for (const item of visible) {
      nav.appendChild(h('a', {
        class: 'nav-item',
        href: `#${item.path}`,
        dataset: { path: item.path },
        title: item.label,
      },
        icon(item.iconName, { size: 18 }),
        h('span', { class: 'nav-label' }, item.label),
        item.badge ? h('span', { class: 'nav-badge hidden', dataset: { badge: item.badge } }) : null));
    }
  }
  sidebarNav = nav;
  return nav;
}

function highlightNav(path) {
  if (!sidebarNav) return;
  const best = [...sidebarNav.querySelectorAll('.nav-item')]
    .map((el) => ({ el, p: el.dataset.path }))
    .filter(({ p }) => p === '/' ? path === '/' : path.startsWith(p))
    .sort((a, b) => b.p.length - a.p.length)[0];
  sidebarNav.querySelectorAll('.nav-item').forEach((el) => el.classList.remove('active'));
  best?.el.classList.add('active');
}


// ---------------------------------------------------------------------------
// Command palette
// ---------------------------------------------------------------------------
let paletteOpen = false;

function openPalette() {
  if (paletteOpen) return;
  paletteOpen = true;

  const results = h('div', { class: 'palette-results' });
  const searchInput = h('input', {
    type: 'search',
    placeholder: 'Search leads, projects and people…  or jump to a page',
    'aria-label': 'Search',
  });
  const box = h('div', { class: 'palette-box' },
    h('div', { class: 'palette-input' }, icon('search', { size: 17 }), searchInput,
      h('kbd', { style: { fontSize: '11px', color: 'var(--ink-4)' } }, 'Esc')),
    results);
  const overlay = h('div', { class: 'overlay palette' }, box);

  const close = () => {
    paletteOpen = false;
    overlay.remove();
    document.removeEventListener('keydown', onKey, true);
    document.body.style.overflow = '';
  };
  const onKey = (e) => {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const items = [...results.querySelectorAll('.palette-item')];
      if (!items.length) return;
      const currentIndex = items.findIndex((el) => el.classList.contains('active'));
      const next = e.key === 'ArrowDown'
        ? Math.min(items.length - 1, currentIndex + 1)
        : Math.max(0, currentIndex - 1);
      items.forEach((el) => el.classList.remove('active'));
      items[next].classList.add('active');
      items[next].scrollIntoView({ block: 'nearest' });
    }
    if (e.key === 'Enter') {
      const active = results.querySelector('.palette-item.active') || results.querySelector('.palette-item');
      active?.click();
    }
  };

  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) close(); });
  document.addEventListener('keydown', onKey, true);
  document.body.appendChild(overlay);
  document.body.style.overflow = 'hidden';
  searchInput.focus();

  const navTargets = NAV.flatMap((g) => g.items).filter((i) => i.show());

  function renderNavigation(term) {
    const matches = navTargets.filter((i) => i.label.toLowerCase().includes(term.toLowerCase()));
    if (!matches.length) return null;
    return h('div', null,
      h('div', { class: 'palette-group-title' }, 'Go to'),
      ...matches.slice(0, 6).map((item) => h('div', {
        class: 'palette-item',
        onClick: () => { close(); navigate(item.path); },
      },
        icon(item.iconName, { size: 16 }),
        h('div', null, h('div', { class: 'p-title' }, item.label)),
        h('span', { class: 'badge p-type' }, 'Page'))));
  }

  const TYPE_ICON = { lead: 'leads', project: 'building', user: 'users' };

  const runSearch = debounce(async (term) => {
    if (term.length < 2) {
      mount(results, renderNavigation('') || h('div'));
      return;
    }
    try {
      const res = await api.get('/api/search', { q: term, limit: 6 });
      const grouped = new Map();
      for (const item of res.data.results) {
        if (!grouped.has(item.type)) grouped.set(item.type, []);
        grouped.get(item.type).push(item);
      }
      const nodes = [];
      const navGroup = renderNavigation(term);
      if (navGroup) nodes.push(navGroup);
      for (const [type, items] of grouped) {
        nodes.push(h('div', null,
          h('div', { class: 'palette-group-title' }, `${type}s`),
          ...items.map((item) => h('div', {
            class: 'palette-item',
            onClick: () => { close(); navigate(item.link.replace(/^#/, '')); },
          },
            icon(TYPE_ICON[type] || 'file', { size: 16 }),
            h('div', { class: 'grow', style: { minWidth: 0 } },
              h('div', { class: 'p-title truncate' }, item.title),
              item.subtitle ? h('div', { class: 'p-sub truncate' }, item.subtitle) : null),
            item.badge
              ? h('span', {
                class: 'badge p-type',
                style: item.badgeColor ? { background: `${item.badgeColor}1f`, color: item.badgeColor } : null,
              }, item.badge)
              : null))));
      }
      if (!nodes.length) {
        nodes.push(emptyState({ title: 'No matches', message: `Nothing found for “${term}”.` }));
      }
      mount(results, ...nodes);
      results.querySelector('.palette-item')?.classList.add('active');
    } catch (err) {
      mount(results, emptyState({ iconName: 'alert', title: 'Search failed', message: err.message }));
    }
  }, 220);

  searchInput.addEventListener('input', (e) => runSearch(e.target.value.trim()));
  mount(results, renderNavigation('') || h('div'));
}

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------
function notificationPanel(close) {
  const list = h('div', { style: { overflowY: 'auto', flex: '1' } },
    h('div', { class: 'page-loader' }, h('span', { class: 'spinner' })));

  const panel = h('div', { class: 'notif-panel' },
    h('div', { class: 'row-between', style: { padding: '12px 14px', borderBottom: '1px solid var(--line-2)' } },
      h('strong', null, 'Notifications'),
      h('button', {
        class: 'btn btn-sm btn-ghost',
        onClick: async () => {
          try {
            await api.post('/api/notifications/read-all');
            setUnreadCount(0);
            refreshUnread();
            load();
          } catch (err) { reportError(err); }
        },
      }, 'Mark all read')),
    list,
    h('div', { style: { padding: '10px 14px', borderTop: '1px solid var(--line-2)' } },
      h('button', {
        class: 'btn btn-sm btn-block',
        onClick: () => { close(); navigate('/notifications'); },
      }, 'View all')));

  async function load() {
    try {
      const res = await api.get('/api/notifications', { limit: 12 });
      const rows = res.data;
      if (!rows.length) {
        mount(list, emptyState({ iconName: 'bell', title: 'All caught up', message: 'Nothing needs your attention right now.' }));
        return;
      }
      mount(list, ...rows.map((n) => h('div', {
        class: `notif-item ${n.isRead ? '' : 'unread'}`,
        onClick: async () => {
          if (!n.isRead) {
            await api.post(`/api/notifications/${n.id}/read`).catch(() => {});
            refreshUnread();
          }
          close();
          if (n.link) navigate(n.link.replace(/^#/, ''));
        },
      },
        h('span', {
          class: 'n-dot',
          style: {
            background: { SUCCESS: 'var(--success)', WARNING: 'var(--warning)', DANGER: 'var(--danger)' }[n.severity] || 'var(--brand-500)',
            opacity: n.isRead ? '.3' : '1',
          },
        }),
        h('div', { class: 'grow', style: { minWidth: 0 } },
          h('div', { class: 'n-title' }, n.title),
          n.body ? h('div', { class: 'n-body' }, n.body) : null,
          h('div', { class: 'n-time' }, relative(n.createdAt))))));
    } catch (err) {
      mount(list, emptyState({ iconName: 'alert', title: 'Could not load notifications', message: err.message }));
    }
  }
  load();
  return panel;
}

let bellButton = null;
async function refreshUnread() {
  try {
    const res = await api.get('/api/notifications/unread-count');
    const count = res.data.unread || 0;
    setUnreadCount(count);
    if (!bellButton) return;
    const existing = bellButton.querySelector('.count');
    if (existing) existing.remove();
    if (count > 0) {
      bellButton.appendChild(h('span', { class: 'count' }, count > 99 ? '99+' : String(count)));
    }
  } catch { /* a failed poll must not disturb the user */ }
}


// ---------------------------------------------------------------------------
// Quick create
// ---------------------------------------------------------------------------
/**
 * The Create menu, scoped to what the signed-in role may actually create.
 *
 *   Super Admin  New Lead, New User, New Project, New Client Admin
 *   Admin        New Lead, New User, New Project
 *   Everyone     New Lead
 *
 * "New client admin" is platform-only: creating an administrator means creating
 * the company they administer, which is the Super Admin's job alone. The menu
 * mirrors the server's permission checks; it does not replace them.
 */
function quickCreateMenu(close) {
  const items = [];
  const fire = () => window.dispatchEvent(new CustomEvent('gv:data-changed'));

  if (can('leads', 'create')) {
    items.push(menuItem('New lead', {
      iconName: 'leads', close, onClick: async () => {
        const mod = await import('./views/leadForm.js');
        mod.openLeadForm({ onSaved: fire });
      },
    }));
  }
  if (can('projects', 'create')) {
    items.push(menuItem('New project', {
      iconName: 'building', close, onClick: async () => {
        const mod = await import('./views/projects.js');
        mod.openProjectForm({ onSaved: fire });
      },
    }));
  }
  if (can('users', 'create')) {
    items.push(menuItem('New user', {
      iconName: 'users', close, onClick: async () => {
        const mod = await import('./views/users.js');
        mod.openUserForm({ onSaved: fire });
      },
    }));
  }
  if (store.user?.isPlatformAdmin) {
    items.push(h('div', { class: 'dropdown-sep' }));
    items.push(menuItem('New client admin', {
      iconName: 'shield', close, onClick: async () => {
        const mod = await import('./views/clients.js');
        mod.openClientWizard({ onSaved: () => navigate('/clients') });
      },
    }));
  }

  if (!items.length) {
    items.push(h('div', { class: 'muted small', style: { padding: '10px' } }, 'Your role has no create permissions.'));
  }
  return h('div', null, h('div', { class: 'dropdown-title' }, 'Create'), ...items);
}


/**
 * The sidebar lockup. A client company that has uploaded a logo sees their own
 * brand here; everyone else sees Growvanta. This is the white-label surface
 * the spec asks for, driven entirely by the tenant record.
 */
function brandLockup() {
  const branding = store.organization?.branding || {};
  if (branding.logoUrl) {
    return h('div', { class: 'brand' },
      h('img', {
        class: 'brand-mark',
        // Attached before src, so the listener cannot miss the event.
        //
        // The tenant record can outlive the file it points at - a logo removed
        // from the uploads directory leaves the row behind. Without this the
        // sidebar shows a browser's broken-image icon, which reads as "this
        // application is broken" rather than "one picture is missing".
        onError: (e) => {
          const img = e.target;
          if (img.dataset.fellBack) return;
          img.dataset.fellBack = '1';
          img.src = '/assets/mark-64.png';
        },
        src: branding.logoUrl,
        alt: '',
        width: 32,
        height: 32,
        style: { objectFit: 'contain', borderRadius: '7px' },
      }),
      h('div', { class: 'brand-text truncate', title: branding.name }, branding.name));
  }
  return h('div', { class: 'brand' },
    h('img', {
      class: 'brand-mark', src: '/assets/mark-64.png',
      srcset: '/assets/mark-64.png 1x, /assets/mark-128.png 2x',
      alt: '', width: 32, height: 32,
    }),
    h('div', { class: 'brand-text' }, 'GROW', h('span', null, 'VANTA')));
}

/**
 * Tenant switcher, platform admins only.
 *
 * Being inside somebody else's CRM is exactly the situation where a person
 * forgets which data they are looking at, so when impersonating this is a
 * coloured, always-visible bar rather than a quiet dropdown - and every entry
 * into a client is written to that client's audit log.
 */
function tenantSwitcher() {
  if (!store.user?.isPlatformAdmin) return null;
  const impersonating = Boolean(store.user?.isImpersonating);

  // The label is dropped on a phone, where the topbar has no room for it; the
  // icon and the title carry the meaning there.
  const exitButton = h('button', {
    class: 'btn btn-sm exit-client',
    title: 'Exit to platform',
    'aria-label': 'Exit to platform',
    onClick: async () => {
      const { exitClientContext } = await import('./views/clients.js');
      exitClientContext();
    },
  }, icon('logout', { size: 14 }), h('span', { class: 'exit-text' }, 'Exit to platform'));

  const trigger = h('button', {
    class: 'btn btn-sm',
    style: impersonating
      ? { background: 'var(--warning)', color: '#0d1b2a', fontWeight: '700', borderColor: 'transparent' }
      : {},
  },
  icon('building', { size: 14 }),
  h('span', { class: 'truncate tenant-name' },
    impersonating ? `Inside: ${store.organization?.name}` : 'Platform'),
  icon('chevronDown', { size: 13 }));

  const menu = dropdown(trigger, (close) => {
    const clients = store.platform?.clients || [];
    return h('div', null,
      h('div', { class: 'dropdown-title' }, 'Open a client CRM'),
      ...(clients.length
        ? clients.slice(0, 40).map((c) => menuItem(c.name, {
          iconName: 'building',
          close,
          onClick: async () => {
            const { switchToClient } = await import('./views/clients.js');
            switchToClient(c.id, c.name);
          },
        }))
        : [h('div', { class: 'dropdown-item muted' }, 'No client companies yet')]),
      h('div', { class: 'dropdown-sep' }),
      menuItem('Manage clients', { iconName: 'grid', close, onClick: () => navigate('/clients') }));
  }, { align: 'left' });

  return h('div', { class: 'row tenant-switcher', style: { gap: '6px', marginLeft: '10px' } },
    menu,
    impersonating ? exitButton : null);
}

// ---------------------------------------------------------------------------
// Shell
// ---------------------------------------------------------------------------
function buildShell() {
  const user = store.user;
  const collapsed = getSidebarCollapsed();

  bellButton = h('button', { class: 'icon-btn', 'aria-label': 'Notifications' }, icon('bell', { size: 18 }));

  const shell = h('div', { class: `app ${collapsed ? 'collapsed' : ''}` },
    h('aside', { class: 'sidebar' },
      brandLockup(),
      renderSidebar(),
      h('div', { class: 'sidebar-footer' },
        dropdown(
          h('div', { class: 'u-card' },
            avatar(`${user.firstName} ${user.lastName}`, { url: user.avatarUrl }),
            h('div', { class: 'u-meta grow', style: { minWidth: 0 } },
              h('div', { class: 'u-name truncate' }, `${user.firstName} ${user.lastName}`),
              h('div', { class: 'u-role truncate' }, user.role?.name || ''))),
          (close) => h('div', null,
            h('div', { class: 'dropdown-title' }, store.organization?.name || ''),
            menuItem('My profile', { iconName: 'user', close, onClick: () => navigate('/profile') }),
            menuItem(getTheme() === 'dark' ? 'Light theme' : 'Dark theme', {
              iconName: getTheme() === 'dark' ? 'sun' : 'moon',
              close,
              onClick: () => { toggleTheme(); },
            }),
            h('div', { class: 'dropdown-sep' }),
            menuItem('Sign out', {
              iconName: 'logout', close, danger: true,
              onClick: async () => {
                try { await api.post('/api/auth/logout'); } catch { /* sign out anyway */ }
                window.location.href = '/login.html';
              },
            })),
          { align: 'left' },
        ))),

    h('div', { class: 'main' },
      h('header', { class: 'topbar' },
        h('button', {
          class: 'icon-btn mobile-only',
          'aria-label': 'Toggle navigation',
          onClick: () => shell.classList.toggle('mobile-open'),
        }, icon('menu', { size: 19 })),
        h('button', {
          class: 'icon-btn desk-only',
          'aria-label': 'Collapse sidebar',
          onClick: () => {
            const next = !shell.classList.contains('collapsed');
            shell.classList.toggle('collapsed', next);
            setSidebarCollapsed(next);
          },
        }, icon('list', { size: 18 })),

        h('button', { class: 'search-trigger', onClick: openPalette },
          icon('search', { size: 15 }),
          h('span', null, 'Search leads and projects…'),
          h('kbd', null, navigator.platform.includes('Mac') ? '⌘K' : 'Ctrl K')),

        tenantSwitcher(),

        h('div', { class: 'row', style: { marginLeft: 'auto', gap: '6px' } },
          dropdown(
            h('button', { class: 'btn btn-primary btn-sm' }, icon('plus', { size: 15 }), h('span', { class: 'hide-sm' }, 'Create')),
            quickCreateMenu,
          ),
          dropdown(bellButton, (close) => notificationPanel(close)),
          h('button', {
            class: 'icon-btn desk-only',
            'aria-label': 'Toggle theme',
            onClick: (e) => {
              const next = toggleTheme();
              const btn = e.currentTarget;
              clear(btn);
              btn.appendChild(icon(next === 'dark' ? 'sun' : 'moon', { size: 18 }));
            },
          }, icon(getTheme() === 'dark' ? 'sun' : 'moon', { size: 18 })))),

      h('main', { class: 'content', id: 'outlet' })));

  return shell;
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------
function registerRoutes() {
  route('/', () => import('./views/dashboard.js'));
  route('/leads', () => import('./views/leads.js'));
  route('/leads/:id', () => import('./views/leadDetail.js'));
  route('/projects', () => import('./views/projects.js'));
  route('/projects/:id', () => import('./views/projectDetail.js'));
  route('/communication', () => import('./views/communication.js'));
  route('/platform', () => import('./views/platformDashboard.js'));
  route('/clients', () => import('./views/clients.js'));
  route('/clients/:id', () => import('./views/clientDetail.js'));
  route('/forms', () => import('./views/forms.js'));
  route('/reports', () => import('./views/reports.js'));
  route('/performance', () => import('./views/performance.js'));
  route('/activities', () => import('./views/activities.js'));
  route('/notifications', () => import('./views/notifications.js'));
  route('/users', () => import('./views/users.js'));
  route('/teams', () => import('./views/teams.js'));
  route('/roles', () => import('./views/roles.js'));
  route('/automation', () => import('./views/automation.js'));
  route('/imports', () => import('./views/imports.js'));
  route('/settings', () => import('./views/settings.js'));
  route('/developers', () => import('./views/developers.js'));
  route('/integrations', () => import('./views/integrations.js'));
  route('/audit', () => import('./views/audit.js'));
  route('/profile', () => import('./views/profile.js'));

  setNotFound((path) => h('div', { class: 'card' }, emptyState({
    iconName: 'search',
    title: 'Page not found',
    message: `Nothing lives at ${path}.`,
    action: h('a', { class: 'btn btn-primary', href: '#/' }, 'Back to dashboard'),
  })));
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
async function boot() {
  try {
    await loadSession();
  } catch (err) {
    if (err?.status === 401) { window.location.href = '/login.html'; return; }
    mount(appRoot, h('div', { class: 'page-loader' },
      h('div', { class: 'alert danger' },
        h('div', null,
          h('strong', null, 'Could not reach the server'),
          err.message,
          h('div', { class: 'mt-16' },
            h('button', { class: 'btn', onClick: () => window.location.reload() }, 'Retry'))))));
    return;
  }

  const shell = buildShell();
  mount(appRoot, shell);
  appRoot.className = '';

  configureRouter({
    outlet: shell.querySelector('#outlet'),
    beforeRender: (path) => {
      highlightNav(path);
      shell.classList.remove('mobile-open');
    },
  });
  registerRoutes();
  startRouter();

  // Reference data is used by nearly every screen; warm it once.
  reference().catch(() => {});

  refreshUnread();
  // Light polling keeps the badges honest without a socket connection.
  const poll = setInterval(refreshUnread, 60_000);
  window.addEventListener('beforeunload', () => clearInterval(poll));

  window.addEventListener('gv:data-changed', () => {
    refreshUnread();
  });

  document.addEventListener('keydown', (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      openPalette();
      return;
    }
    // Plain "/" opens search, unless the user is typing into a field.
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName)) {
      e.preventDefault();
      openPalette();
    }
  });

  if (!parseHash().raw || parseHash().path === '/') {
    // A platform admin with no client selected owns no leads of their own, so
    // the tenant dashboard would greet them with a screen of zeros that reads
    // as a broken install. Send them to the platform overview instead.
    if (onPlatform()) {
      navigate('/platform', { replace: true });
    } else if (!can('dashboard', 'read')) {
      // Land somewhere useful for roles without dashboard access.
      if (can('leads', 'read')) navigate('/leads', { replace: true });
    }
  }
}

boot().catch((err) => {
  console.error(err);
  toast.error('Startup failed', err.message);
});
