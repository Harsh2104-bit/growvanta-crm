/**
 * Session state.
 *
 * Holds the signed-in user, their permission map and cached reference data
 * (statuses, sources, projects, users) that almost every screen needs. The
 * permission helpers here decide what the UI *shows*; the server independently
 * decides what it *allows*. Both checks exist on purpose.
 */
import { api } from './api.js';
import { configureFormatting } from './format.js';

const state = {
  user: null,
  organization: null,
  teams: [],
  scope: null,
  ready: false,
  reference: null,
  unreadCount: 0,
  // Present only for a platform administrator: the client list behind the
  // tenant switcher, and which client (if any) is currently open.
  platform: null,
};

const listeners = new Set();
export const subscribe = (fn) => { listeners.add(fn); return () => listeners.delete(fn); };
const notify = () => listeners.forEach((fn) => fn(state));

export const store = state;

export async function loadSession() {
  const res = await api.get('/api/auth/me');
  state.user = res.data.user;
  state.organization = res.data.organization;
  state.teams = res.data.teams || [];
  state.scope = res.data.scope;
  state.platform = res.data.platform || null;
  state.ready = true;
  configureFormatting(state.organization);
  applyTheme(getTheme());
  applyBranding(state.organization?.branding);
  notify();
  return state;
}

export function clearSession() {
  state.user = null;
  state.organization = null;
  state.ready = false;
  state.reference = null;
  state.platform = null;
  notify();
}

/**
 * White-label repaint.
 *
 * Only the brand ramp is overridden - never the ink, surface or semantic
 * colours - because those carry the contrast guarantees the palette was built
 * around, and a client picking a pale brand colour must not be able to make
 * their own text unreadable. `--on-brand` is recomputed from the chosen colour
 * so button labels stay legible whichever brand is loaded.
 */
export function applyBranding(branding) {
  const root = document.documentElement;
  const tokens = ['--brand-500', '--brand-600', '--on-brand'];
  if (!branding?.primary) {
    tokens.forEach((t) => root.style.removeProperty(t));
    return;
  }
  root.style.setProperty('--brand-500', branding.primary);
  root.style.setProperty('--brand-600', shade(branding.primary, -0.28));
  root.style.setProperty('--on-brand', readableOn(branding.primary));
}

const clamp255 = (n) => Math.max(0, Math.min(255, Math.round(n)));
function parseHex(hex) {
  const clean = String(hex || '').replace('#', '');
  if (clean.length !== 6) return null;
  return [0, 2, 4].map((i) => parseInt(clean.slice(i, i + 2), 16));
}
function shade(hex, amount) {
  const rgb = parseHex(hex);
  if (!rgb) return hex;
  const shifted = rgb.map((c) => clamp255(amount < 0 ? c * (1 + amount) : c + (255 - c) * amount));
  return `#${shifted.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
}
/** WCAG relative luminance, used to pick dark or light text over the brand. */
function readableOn(hex) {
  const rgb = parseHex(hex);
  if (!rgb) return '#0d1b2a';
  const [r, g, b] = rgb.map((c) => {
    const v = c / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  // 0.45 is where white-on-colour and near-black-on-colour cross over for
  // 4.5:1; above it the dark ink wins, below it white does.
  return luminance > 0.45 ? '#0d1b2a' : '#ffffff';
}

// ---------------------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------------------

/** `can('leads', 'create')` - the single check every view uses. */
export function can(moduleKey, action) {
  const granted = state.user?.permissions?.[moduleKey];
  return Array.isArray(granted) && granted.includes(action);
}

export const canAny = (pairs) => pairs.some(([m, a]) => can(m, a));
export const canRead = (moduleKey) => can(moduleKey, 'read');
export const isSuperAdmin = () => state.user?.role?.key === 'super_admin';
export const scopeRank = () => ({ OWN: 1, TEAM: 2, TEAM_AND_BELOW: 3, REGION: 4, ORGANIZATION: 5, ALL: 6 }[state.user?.role?.dataScope] || 1);

// ---------------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------------

/**
 * Reference lists are fetched once and reused. `refreshReference()` is called
 * by any screen that edits them, so a newly added status shows up immediately
 * rather than after a reload.
 */
export async function reference({ force = false } = {}) {
  if (state.reference && !force) return state.reference;
  const [filters, users, projects] = await Promise.all([
    api.data('/api/reports/filters').catch(() => ({})),
    api.data('/api/users/assignable').catch(() => []),
    can('projects', 'read') ? api.data('/api/projects/options').catch(() => []) : Promise.resolve([]),
  ]);
  state.reference = {
    statuses: filters.statuses || [],
    sources: filters.sources || [],
    teams: filters.teams || [],
    dimensions: filters.dimensions || [],
    users,
    projects,
  };
  return state.reference;
}

export const refreshReference = () => reference({ force: true });

export function statusById(id) {
  return state.reference?.statuses.find((s) => s.id === id) || null;
}

// ---------------------------------------------------------------------------
// Theme & preferences (per-browser, so a shared machine can differ per person)
// ---------------------------------------------------------------------------
const THEME_KEY = 'gv.theme';
const SIDEBAR_KEY = 'gv.sidebar';

export function getTheme() {
  try { return localStorage.getItem(THEME_KEY) || 'light'; } catch { return 'light'; }
}

export function applyTheme(theme) {
  const resolved = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', resolved);
  try { localStorage.setItem(THEME_KEY, resolved); } catch { /* private mode */ }
  return resolved;
}

export const toggleTheme = () => applyTheme(getTheme() === 'dark' ? 'light' : 'dark');

export function getSidebarCollapsed() {
  try { return localStorage.getItem(SIDEBAR_KEY) === '1'; } catch { return false; }
}
export function setSidebarCollapsed(collapsed) {
  try { localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0'); } catch { /* ignore */ }
}

export function setUnreadCount(count) {
  state.unreadCount = count;
  notify();
}
