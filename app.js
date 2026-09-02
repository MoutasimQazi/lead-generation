/* ══════════════════════════════════════════════════════════════════════════
   Shared frontend helpers: session, API calls, nav, small UI utilities.
   Loaded by every page except login.html, which uses only api() and boot-free
   parts of this file.
   ══════════════════════════════════════════════════════════════════════════ */

const $ = (id) => document.getElementById(id);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/* ── theme ─────────────────────────────────────────────────────────────── */

const THEME_KEY = 'movenetics.theme';

function preferredTheme() {
  try {
    const saved = localStorage.getItem(THEME_KEY);
    if (saved === 'light' || saved === 'dark') return saved;
  } catch (e) { /* storage can be disabled */ }

  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark' : 'light';
}

function applyTheme(theme, remember = false) {
  const next = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.dataset.theme = next;
  document.documentElement.style.colorScheme = next;

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = next === 'dark' ? '#141210' : '#FAF7F4';

  if (remember) {
    try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* optional */ }
  }

  $$('.theme-toggle').forEach((button) => {
    button.textContent = next === 'dark' ? '☀ Light' : '◐ Dark';
    button.title = 'Switch to ' + (next === 'dark' ? 'light' : 'dark') + ' mode';
    button.setAttribute('aria-label', button.title);
  });
}

function bindThemeToggle(root = document) {
  $$('.theme-toggle', root).forEach((button) => {
    if (button.dataset.themeBound) return;
    button.dataset.themeBound = '1';
    button.addEventListener('click', () => {
      const current = document.documentElement.dataset.theme || preferredTheme();
      applyTheme(current === 'dark' ? 'light' : 'dark', true);
    });
  });
  applyTheme(document.documentElement.dataset.theme || preferredTheme());
}

applyTheme(preferredTheme());
document.addEventListener('DOMContentLoaded', () => bindThemeToggle());

/* ── password visibility toggle ───────────────────────────────────────── */

function bindPasswordToggles(root = document) {
  $$('.pwtoggle', root).forEach((button) => {
    if (button.dataset.pwBound) return;
    button.dataset.pwBound = '1';
    const input = button.previousElementSibling;
    if (!input) return;

    button.addEventListener('click', () => {
      const shown = input.type === 'text';
      input.type = shown ? 'password' : 'text';
      button.querySelector('.eye-on').hidden = !shown;
      button.querySelector('.eye-off').hidden = shown;
      button.setAttribute('aria-pressed', String(!shown));
      button.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
    });
  });
}

document.addEventListener('DOMContentLoaded', () => bindPasswordToggles());

/** Session state, filled by requireSession(). */
const session = { user: null, csrf: null };

/**
 * URL of the login page, carrying where to come back to.
 *
 * Only the basename is passed, never location.pathname. A pathname of "/"
 * (the site root, served as index.html by DirectoryIndex) used to round-trip
 * to an empty string, and `location.href = ''` reloads the current page — so
 * signing in from the root landed the user back on the login form every time.
 */
function loginUrl() {
  const here = location.pathname.replace(/^.*\//, '');

  if (here === '' || here === 'login.html') return 'login.html';

  return 'login.html?next=' + encodeURIComponent(here + location.search);
}

/** Full-page error, for failures that leave the page with nothing to show. */
function fatal(title, detail) {
  const host = $('status') || document.body;

  host.innerHTML =
    '<div class="err" style="margin:24px auto;max-width:560px">' +
      '<h3>' + esc(title) + '</h3>' +
      '<p>' + esc(detail || '') + '</p>' +
      '<p style="margin-top:12px"><a href="login.html">Go to sign in</a></p>' +
    '</div>';
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (m) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
}

const fmt = (n) => Number(n ?? 0).toLocaleString();

function bytes(n) {
  if (!n) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
  return (n / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
}

function initials(name) {
  return String(name || '?')
    .split(/\s+/).filter(Boolean).slice(0, 2)
    .map((w) => w[0].toUpperCase()).join('') || '?';
}

/* ── API ──────────────────────────────────────────────────────────────────
   One wrapper so that the CSRF token, credentials and error handling are not
   re-implemented (and mis-implemented) on five pages.                        */

async function api(method, path, body, opts = {}) {
  const headers = {};
  const timeoutMs = opts.timeoutMs ?? 150000;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  if (session.csrf) headers['X-CSRF-Token'] = session.csrf;

  let payload = body;

  const rawBody = body instanceof Blob || body instanceof ArrayBuffer || ArrayBuffer.isView(body);

  if (body !== undefined && !(body instanceof FormData) && !rawBody) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  } else if (rawBody) {
    headers['Content-Type'] = 'application/octet-stream';
  }

  let res;
  let text;
  try {
    res = await fetch(path, {
      method,
      headers,
      body: payload,
      signal: controller.signal,
      credentials: 'same-origin',
      // API responses describe mutable database state. Reusing a cached GET
      // after PATCH made successful edits appear to revert immediately.
      cache: 'no-store',
    });
    text = await res.text();
  } catch (e) {
    if (e && e.name === 'AbortError') {
      throw new Error('The server did not respond within ' + Math.ceil(timeoutMs / 1000) + ' seconds. Please try again.');
    }
    throw new Error('Could not reach the server. Check your connection and try again.');
  } finally {
    clearTimeout(timeout);
  }

  // Session gone or expired: bounce to login, preserving where they were.
  if (res.status === 401 && !opts.noRedirect) {
    location.href = loginUrl();
    throw new Error('Signed out.');
  }

  let data;

  try {
    data = text ? JSON.parse(text) : {};
  } catch (e) {
    // A PHP fatal or an Apache error page landed here instead of JSON.
    throw new Error('The server returned an unreadable response (HTTP ' + res.status + ').');
  }

  if (!res.ok || data.success === false) {
    const err = new Error(data.error || data.message || 'HTTP ' + res.status);
    err.status = res.status;
    err.data = data;
    throw err;
  }

  return data;
}

const apiGet = (p, o) => api('GET', p, undefined, o);
const apiPost = (p, b, o) => api('POST', p, b, o);
const apiPatch = (p, b) => api('PATCH', p, b);
const apiDelete = (p, b) => api('DELETE', p, b);

/* ── session + nav ────────────────────────────────────────────────────── */

/**
 * Loads the session and renders the nav. Redirects to login when signed out,
 * and to the search page when a non-admin opens an admin-only page.
 *
 * The redirect is a courtesy, not a control — every admin route checks the
 * role server-side regardless of what the browser does.
 */
async function requireSession({ page = '', adminOnly = false } = {}) {
  let data;

  try {
    data = await apiGet('api/auth/me', { noRedirect: true, timeoutMs: 15000 });
  } catch (e) {
    // A throw here is a server fault, not a signed-out session: /api/auth/me
    // answers 200 with authenticated:false when nobody is signed in. Bouncing
    // to login on a 500 would ping-pong between the login page and the app
    // forever while hiding the error that actually needs fixing.
    fatal('Cannot reach the server', e.message);
    throw e;
  }

  if (!data.authenticated) {
    location.href = loginUrl();
    throw new Error('Not signed in.');
  }

  session.user = data.user;
  session.csrf = data.csrf;

  if (adminOnly && !data.user.is_admin) {
    location.href = 'index.html';
    throw new Error('Admins only.');
  }

  renderNav(page);
  return data.user;
}

function renderNav(active) {
  const host = $('nav');
  if (!host) return;

  const u = session.user;
  const isAdmin = u.is_admin;

  const links = [
    { id: 'search', label: 'Search', href: 'index.html' },
    { id: 'datasets', label: 'Datasets', href: 'datasets.html' },
    { id: 'upload', label: 'Upload', href: 'upload.html', admin: true },
    { id: 'users', label: 'Users', href: 'users.html', admin: true },
  ].filter((l) => !l.admin || isAdmin);

  host.innerHTML =
    '<a class="logo" href="index.html" title="Lead Search">' +
      '<img src="logo.png?v=20260822" alt="Movenetics Digital">' +
    '</a>' +
    links.map((l) =>
      '<a class="navitem' + (l.id === active ? ' active' : '') + '" href="' + l.href + '">' +
        esc(l.label) + '</a>'
    ).join('') +
    '<span class="spacer"></span>' +
    '<button class="theme-toggle" type="button">Theme</button>' +
    '<span class="pill-dark">' + esc(u.role) + '</span>' +
    '<span class="avatar" title="' + esc(u.email) + '">' + esc(initials(u.full_name)) + '</span>' +
    '<span class="whoami">' + esc(u.full_name) + '</span>' +
    '<button class="signout" id="signout">Sign out</button>';

  bindThemeToggle(host);

  $('signout').addEventListener('click', async () => {
    try { await apiPost('api/auth/logout', {}); } catch (e) { /* leaving anyway */ }
    location.href = 'login.html';
  });
}

/* ── small UI helpers ─────────────────────────────────────────────────── */

let toastTimer = null;

function toast(message, bad = false) {
  let el = $('toast');

  if (!el) {
    el = document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    document.body.appendChild(el);
  }

  el.textContent = message;
  el.className = 'toast show' + (bad ? ' bad' : '');

  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = 'toast'; }, bad ? 6000 : 3000);
}

function showError(host, title, detail) {
  host.innerHTML =
    '<div class="err"><h3>' + esc(title) + '</h3><p>' + (detail || '') + '</p></div>';
}

function openModal(id) {
  const m = $(id);
  if (!m) return;
  m.classList.add('open');
  const first = m.querySelector('input,select,textarea,button');
  if (first) setTimeout(() => first.focus(), 30);
}

function closeModal(id) {
  const m = $(id);
  if (m) m.classList.remove('open');
}

/** Closes any open modal on Escape, and on a click outside the card. */
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') $$('.modal.open').forEach((m) => m.classList.remove('open'));
});

document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal')) {
    e.target.classList.remove('open');
  }
});

/** Renders a status tag for a dataset. */
function statusTag(d) {
  if (d.status === 'importing') return '<span class="tag warn">importing</span>';
  if (d.status === 'failed') return '<span class="tag bad">failed</span>';
  if (d.is_protected) return '<span class="tag locked">protected</span>';
  return '<span class="tag ok">ready</span>';
}
