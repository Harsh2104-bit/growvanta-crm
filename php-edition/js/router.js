/**
 * Hash router.
 *
 * Hash routing (rather than the History API) keeps deep links working when the
 * app is served from any path and removes the need for server rewrites beyond
 * the single SPA fallback. Routes are declared as `/leads/:id` patterns.
 *
 * Views are dynamically imported, so a session that never opens Reports never
 * downloads the reports module.
 */

const routes = [];
let notFoundHandler = null;
let currentCleanup = null;
let currentPath = null;

export function route(pattern, loader, meta = {}) {
  const keys = [];
  const regex = new RegExp(`^${pattern
    .replace(/\/$/, '')
    .replace(/:([A-Za-z0-9_]+)/g, (_, key) => { keys.push(key); return '([^/]+)'; })}/?$`);
  routes.push({ pattern, regex, keys, loader, meta });
}

export const setNotFound = (handler) => { notFoundHandler = handler; };

export function parseHash() {
  const raw = window.location.hash.replace(/^#/, '') || '/';
  const [path, queryString] = raw.split('?');
  const query = Object.fromEntries(new URLSearchParams(queryString || ''));
  return { path: path.replace(/\/$/, '') || '/', query, raw };
}

export function navigate(to, { replace = false } = {}) {
  const target = to.startsWith('#') ? to : `#${to}`;
  if (replace) window.history.replaceState(null, '', target);
  else window.location.hash = target;
  if (replace) resolve();
}

/** Rebuilds the current route - used after a mutation that changes the list. */
export const reload = () => resolve({ force: true });

function match(path) {
  for (const entry of routes) {
    const result = entry.regex.exec(path);
    if (!result) continue;
    const params = {};
    entry.keys.forEach((key, i) => { params[key] = decodeURIComponent(result[i + 1]); });
    return { entry, params };
  }
  return null;
}

let outlet = null;
let onBeforeRender = null;
export function configureRouter({ outlet: el, beforeRender }) {
  outlet = el;
  onBeforeRender = beforeRender;
}

let renderToken = 0;

async function resolve({ force = false } = {}) {
  const { path, query } = parseHash();
  if (!force && path === currentPath && !window.__gvForceRender) {
    // Same route, only the query changed: let the view read it via `onQuery`.
    if (currentView?.onQuery) { currentView.onQuery(query); return; }
  }
  const token = ++renderToken;

  if (typeof currentCleanup === 'function') {
    try { currentCleanup(); } catch { /* a view's teardown must never block navigation */ }
  }
  currentCleanup = null;
  currentView = null;
  currentPath = path;

  const found = match(path);
  onBeforeRender?.(path, found?.entry?.meta || {});

  if (!found) {
    if (notFoundHandler) outlet.replaceChildren(notFoundHandler(path));
    return;
  }

  try {
    const view = await found.entry.loader();
    if (token !== renderToken) return; // a later navigation won
    const render = view.default || view.render || view;
    const result = await render({ params: found.params, query, outlet });
    if (token !== renderToken) return;
    if (result?.node) {
      outlet.replaceChildren(result.node);
      currentCleanup = result.destroy || null;
      currentView = result;
    } else if (result instanceof Node) {
      outlet.replaceChildren(result);
    }
    window.scrollTo({ top: 0 });
  } catch (err) {
    if (token !== renderToken) return;
    console.error('[router] failed to render', path, err);
    outlet.replaceChildren(renderRouteError(err));
  }
}

let currentView = null;

function renderRouteError(err) {
  const div = document.createElement('div');
  div.className = 'card';
  div.innerHTML = '';
  const body = document.createElement('div');
  body.className = 'empty';
  const title = document.createElement('h3');
  title.textContent = 'This page could not be loaded';
  const msg = document.createElement('p');
  msg.textContent = err?.message || 'An unexpected error occurred.';
  const btn = document.createElement('button');
  btn.className = 'btn';
  btn.textContent = 'Reload';
  btn.addEventListener('click', () => window.location.reload());
  body.append(title, msg, btn);
  div.appendChild(body);
  return div;
}

export function startRouter() {
  window.addEventListener('hashchange', () => resolve());
  resolve();
}

export const currentRoute = () => parseHash();
