/**
 * Authentication screens: sign in, two-factor, forgot/reset password,
 * invitation acceptance, email verification and new-organisation signup.
 *
 * All of them share one box and are selected by the hash, so a reset link can
 * deep-link straight into the right form.
 */
import { h, mount, clear } from './lib/dom.js';
import { icon } from './lib/icons.js';
import { api, ApiError, setUnauthorizedHandler } from './lib/api.js';
import { toast, reportError, field, input as inputEl } from './lib/ui.js';
import { applyTheme, getTheme, toggleTheme } from './lib/store.js';

setUnauthorizedHandler(() => {}); // we are already on the login page

const box = document.getElementById('authBox');
applyTheme(getTheme());

// Demo accounts are fetched live from /api/public/demo-accounts rather than
// hard-coded here. A hard-coded list silently rots the moment the seed changes
// and then offers sign-ins for people who no longer exist - which is exactly
// what happened to the previous version of this file. The endpoint is refused
// outside a local/development install.
let demoAccounts = [];
const DEMO_PASSWORD = 'Growvanta@123';

let isDevelopment = false;

function parseHash() {
  const raw = window.location.hash.replace(/^#/, '') || '/';
  const [path, qs] = raw.split('?');
  return { path: path.replace(/\/$/, '') || '/', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}

function header(title, subtitle) {
  return h('div', null,
    h('div', { class: 'row mb-16', style: { gap: '10px' } },
      h('img', {
        class: 'brand-mark', src: '/assets/mark-64.png',
        srcset: '/assets/mark-64.png 1x, /assets/mark-128.png 2x',
        alt: '', width: 30, height: 30,
      }),
      h('div', { style: { fontWeight: '700', fontSize: '16px', letterSpacing: '-.02em', color: 'var(--ink)' } },
        'GROW', h('span', { style: { color: 'var(--brand-600)' } }, 'VANTA')),
      h('button', {
        class: 'icon-btn', style: { marginLeft: 'auto' }, 'aria-label': 'Toggle theme',
        onClick: () => { toggleTheme(); },
      }, icon(getTheme() === 'dark' ? 'sun' : 'moon', { size: 17 }))),
    h('h1', null, title),
    h('p', { class: 'sub' }, subtitle));
}

function submitButton(label) {
  return h('button', { class: 'btn btn-primary btn-lg btn-block', type: 'submit' }, label);
}

async function withBusy(button, fn) {
  const original = button.textContent;
  button.disabled = true;
  button.textContent = 'Please wait…';
  try { await fn(); } finally {
    button.disabled = false;
    button.textContent = original;
  }
}

// ---------------------------------------------------------------------------
// Sign in
// ---------------------------------------------------------------------------
function renderLogin() {
  let needsTwoFactor = false;

  const email = inputEl({ type: 'email', name: 'email', placeholder: 'you@company.com', required: true, autocomplete: 'username' });
  const password = inputEl({ type: 'password', name: 'password', placeholder: '••••••••', required: true, autocomplete: 'current-password' });
  const code = inputEl({ type: 'text', inputmode: 'numeric', placeholder: '123456', maxlength: '6', autocomplete: 'one-time-code' });
  const remember = h('input', { type: 'checkbox', checked: true });
  const errorBox = h('div');
  const twoFactorField = h('div', { class: 'hidden' }, field('Verification code', code, { hint: 'Open your authenticator app and enter the current 6-digit code.' }));
  const button = submitButton('Sign in');

  const form = h('form', {
    onSubmit: (e) => {
      e.preventDefault();
      withBusy(button, async () => {
        clear(errorBox);
        try {
          const res = await api.post('/api/auth/login', {
            email: email.value.trim(),
            password: password.value,
            rememberMe: remember.checked,
            ...(needsTwoFactor ? { twoFactorCode: code.value.trim() } : {}),
          });
          if (res.data?.twoFactorRequired) {
            needsTwoFactor = true;
            twoFactorField.classList.remove('hidden');
            code.focus();
            toast('Two-factor code required', { body: 'Enter the 6-digit code from your authenticator app.' });
            return;
          }
          window.location.href = '/index.html';
        } catch (err) {
          mount(errorBox, h('div', { class: 'alert danger mb-16' }, icon('alert', { size: 16 }), h('div', null, err.message)));
          password.select();
        }
      });
    },
  },
    field('Work email', email, { required: true }),
    field('Password', password, { required: true }),
    twoFactorField,
    h('div', { class: 'row-between mb-16' },
      h('label', { class: 'checkbox' }, remember, 'Keep me signed in'),
      h('a', { href: '#/forgot' }, 'Forgot password?')),
    errorBox,
    button);

  const demo = isDevelopment && demoAccounts.length
    ? h('div', { class: 'demo-accounts' },
      h('div', { class: 'row-between mb-8' },
        h('div', { class: 'strong small' }, 'Demo accounts'),
        h('span', { class: 'badge badge-warning' }, 'development only')),
      h('p', { class: 'muted tiny mb-8' }, `Every demo account uses the password ${DEMO_PASSWORD}. Click one to fill the form.`),
      h('div', { style: { maxHeight: '210px', overflowY: 'auto' } },
        ...demoAccounts.map((account) => {
          const fill = () => { email.value = account.email; password.value = DEMO_PASSWORD; password.focus(); };
          return h('div', {
            class: 'demo-account',
            role: 'button',
            tabindex: '0',
            onClick: fill,
            onKeydown: (e) => { if (e.key === 'Enter') fill(); },
          },
          h('span', { class: 'd-role' },
            account.role,
            account.company ? h('span', { class: 'muted tiny' }, ` · ${account.company}`) : null),
          h('span', { class: 'd-email truncate' }, account.email));
        })))
    : null;

  return h('div', null,
    header('Welcome back', 'Sign in to your Growvanta workspace.'),
    form,
    demo,
    h('p', { class: 'muted small mt-16 center' },
      'New organisation? ',
      h('a', { href: '#/register' }, 'Create a workspace')));
}

// ---------------------------------------------------------------------------
// Forgot / reset
// ---------------------------------------------------------------------------
function renderForgot() {
  const email = inputEl({ type: 'email', placeholder: 'you@company.com', required: true });
  const result = h('div');
  const button = submitButton('Send reset link');

  const form = h('form', {
    onSubmit: (e) => {
      e.preventDefault();
      withBusy(button, async () => {
        try {
          const res = await api.post('/api/auth/forgot-password', { email: email.value.trim() });
          mount(result, h('div', { class: 'alert success mb-16' },
            h('div', null,
              h('strong', null, 'Check your inbox'),
              res.data.message,
              res.data.resetUrl
                ? h('div', { class: 'mt-8' },
                  h('div', { class: 'tiny mb-8' }, 'Email is not configured on this server, so the link is shown here:'),
                  h('a', { href: res.data.resetUrl, class: 'small' }, 'Open the reset link'))
                : null)));
        } catch (err) { reportError(err); }
      });
    },
  }, field('Work email', email, { required: true }), result, button);

  return h('div', null,
    header('Reset your password', 'We will email you a link to set a new password.'),
    form,
    h('p', { class: 'center mt-16' }, h('a', { href: '#/' }, '← Back to sign in')));
}

function renderReset(token) {
  const password = inputEl({ type: 'password', placeholder: 'New password', required: true, autocomplete: 'new-password' });
  const confirm = inputEl({ type: 'password', placeholder: 'Repeat the password', required: true, autocomplete: 'new-password' });
  const errorBox = h('div');
  const button = submitButton('Set new password');

  const form = h('form', {
    onSubmit: (e) => {
      e.preventDefault();
      clear(errorBox);
      if (password.value !== confirm.value) {
        mount(errorBox, h('div', { class: 'alert danger mb-16' }, 'Those passwords do not match.'));
        return;
      }
      withBusy(button, async () => {
        try {
          await api.post('/api/auth/reset-password', { token, password: password.value });
          toast.success('Password updated', 'Sign in with your new password.');
          window.location.hash = '#/';
        } catch (err) {
          mount(errorBox, h('div', { class: 'alert danger mb-16' }, err.message));
        }
      });
    },
  },
    field('New password', password, { hint: 'At least 8 characters with upper case, lower case and a number.', required: true }),
    field('Confirm password', confirm, { required: true }),
    errorBox, button);

  return h('div', null, header('Choose a new password', 'This link can only be used once.'), form);
}

// ---------------------------------------------------------------------------
// Invitation
// ---------------------------------------------------------------------------
function renderInvite(token) {
  const firstName = inputEl({ placeholder: 'First name' });
  const lastName = inputEl({ placeholder: 'Last name' });
  const password = inputEl({ type: 'password', placeholder: 'Choose a password', required: true, autocomplete: 'new-password' });
  const errorBox = h('div');
  const button = submitButton('Activate my account');

  const form = h('form', {
    onSubmit: (e) => {
      e.preventDefault();
      clear(errorBox);
      withBusy(button, async () => {
        try {
          await api.post('/api/auth/accept-invite', {
            token,
            password: password.value,
            firstName: firstName.value.trim() || undefined,
            lastName: lastName.value.trim() || undefined,
          });
          window.location.href = '/index.html';
        } catch (err) {
          mount(errorBox, h('div', { class: 'alert danger mb-16' }, err.message));
        }
      });
    },
  },
    h('div', { class: 'form-grid' },
      field('First name', firstName),
      field('Last name', lastName)),
    field('Password', password, { hint: 'At least 8 characters with upper case, lower case and a number.', required: true }),
    errorBox, button);

  return h('div', null,
    header('Set up your account', 'You have been invited to a Growvanta workspace.'),
    form);
}

function renderVerify(token) {
  const result = h('div', { class: 'page-loader' }, h('span', { class: 'spinner lg' }));
  api.post('/api/auth/verify-email', { token })
    .then(() => mount(result, h('div', null,
      h('div', { class: 'alert success mb-16' }, 'Your email address is verified.'),
      h('a', { class: 'btn btn-primary btn-block', href: '/' }, 'Go to the CRM'))))
    .catch((err) => mount(result, h('div', null,
      h('div', { class: 'alert danger mb-16' }, err.message),
      h('a', { class: 'btn btn-block', href: '#/' }, 'Back to sign in'))));
  return h('div', null, header('Verifying your email', 'One moment…'), result);
}

// ---------------------------------------------------------------------------
// New organisation
// ---------------------------------------------------------------------------
function renderRegister() {
  const orgName = inputEl({ placeholder: 'Acme Industries', required: true });
  const firstName = inputEl({ placeholder: 'First name', required: true });
  const lastName = inputEl({ placeholder: 'Last name' });
  const email = inputEl({ type: 'email', placeholder: 'you@company.com', required: true, autocomplete: 'username' });
  const password = inputEl({ type: 'password', placeholder: 'Choose a password', required: true, autocomplete: 'new-password' });
  const errorBox = h('div');
  const button = submitButton('Create workspace');

  const form = h('form', {
    onSubmit: (e) => {
      e.preventDefault();
      clear(errorBox);
      withBusy(button, async () => {
        try {
          await api.post('/api/auth/register', {
            organizationName: orgName.value.trim(),
            firstName: firstName.value.trim(),
            lastName: lastName.value.trim(),
            email: email.value.trim(),
            password: password.value,
          });
          window.location.href = '/index.html';
        } catch (err) {
          const fieldErrors = err instanceof ApiError ? err.fieldErrors : {};
          mount(errorBox, h('div', { class: 'alert danger mb-16' },
            Object.values(fieldErrors)[0] || err.message));
        }
      });
    },
  },
    field('Organisation name', orgName, { required: true }),
    h('div', { class: 'form-grid' }, field('First name', firstName, { required: true }), field('Last name', lastName)),
    field('Work email', email, { required: true }),
    field('Password', password, { hint: 'At least 8 characters with upper case, lower case and a number.', required: true }),
    errorBox, button);

  return h('div', null,
    header('Create your workspace', 'You will be the super admin, with roles, pipelines and statuses pre-configured.'),
    form,
    h('p', { class: 'center mt-16' }, h('a', { href: '#/' }, '← I already have an account')));
}

// ---------------------------------------------------------------------------
function render() {
  const { path, query } = parseHash();
  if (path === '/forgot') return mount(box, renderForgot());
  if (path === '/reset') {
    return mount(box, query.token ? renderReset(query.token) : renderInvalid('That reset link is incomplete.'));
  }
  if (path === '/invite') {
    return mount(box, query.token ? renderInvite(query.token) : renderInvalid('That invitation link is incomplete.'));
  }
  if (path === '/verify') {
    return mount(box, query.token ? renderVerify(query.token) : renderInvalid('That verification link is incomplete.'));
  }
  if (path === '/register') return mount(box, renderRegister());
  return mount(box, renderLogin());
}

function renderInvalid(message) {
  return h('div', null,
    header('Link problem', 'We could not read that link.'),
    h('div', { class: 'alert danger mb-16' }, message),
    h('a', { class: 'btn btn-block', href: '#/' }, 'Back to sign in'));
}

window.addEventListener('hashchange', render);

// The demo-account panel only appears outside production, and its contents come
// from the server so they always match whatever the seed actually created.
api.get('/api/health')
  .then((res) => {
    isDevelopment = res?.data?.environment !== 'production';
    return isDevelopment ? api.get('/api/public/demo-accounts') : null;
  })
  .then((res) => { demoAccounts = res?.data || []; })
  .catch(() => { demoAccounts = []; })
  .finally(render);
