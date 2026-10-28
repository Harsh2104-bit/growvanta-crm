/** My profile: personal details, password, two-factor and active sessions. */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { store, loadSession, getTheme, applyTheme } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, avatar, field, input, modal,
  confirmDialog, tabs, copyToClipboard, switchToggle,
} from '../lib/ui.js';
import { dateTime, relative, titleCase } from '../lib/format.js';
import { pageHead, panel } from './shared.js';

export default async function render() {
  const user = store.user;
  const tabHost = h('div', { class: 'mb-16' });
  const body = h('div');
  let tab = 'profile';

  const node = h('div', null,
    pageHead({
      title: 'My profile',
      subtitle: `${user.role?.name} · ${store.organization?.name}`,
    }),
    tabHost, body);

  function buildTabs() {
    mount(tabHost, tabs([
      { key: 'profile', label: 'Details' },
      { key: 'security', label: 'Security' },
      { key: 'sessions', label: 'Sessions' },
      { key: 'preferences', label: 'Preferences' },
    ], tab, (key) => { tab = key; buildTabs(); draw(); }));
  }

  async function draw() {
    if (tab === 'profile') mount(body, renderProfile());
    else if (tab === 'security') mount(body, renderSecurity());
    else if (tab === 'sessions') { mount(body, pageLoader()); mount(body, await renderSessions()); }
    else mount(body, renderPreferences());
  }

  function renderProfile() {
    const firstName = input({ value: user.firstName });
    const lastName = input({ value: user.lastName || '' });
    const phone = input({ value: user.phone || '', type: 'tel' });
    const jobTitle = input({ value: user.jobTitle || '' });

    return h('div', { class: 'chart-grid two' },
      panel('Personal details', h('div', null,
        h('div', { class: 'row mb-16', style: { gap: '14px' } },
          avatar(`${user.firstName} ${user.lastName || ''}`, { size: 'xl', url: user.avatarUrl }),
          h('div', null,
            h('h3', null, `${user.firstName} ${user.lastName || ''}`.trim()),
            h('div', { class: 'muted small' }, user.email),
            h('div', { class: 'row wrap mt-8', style: { gap: '6px' } },
              h('span', { class: 'badge badge-brand' }, user.role?.name || ''),
              h('span', { class: 'badge' }, titleCase(user.role?.dataScope || ''))))),
        h('div', { class: 'form-grid' },
          field('First name', firstName),
          field('Last name', lastName),
          field('Phone', phone),
          field('Job title', jobTitle)),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            e.currentTarget.disabled = true;
            try {
              await api.patch('/api/users/me/profile', {
                firstName: firstName.value.trim(),
                lastName: lastName.value.trim(),
                phone: phone.value.trim() || null,
                jobTitle: jobTitle.value.trim() || null,
              });
              await loadSession();
              toast.success('Profile updated');
              window.location.reload();
            } catch (err) { reportError(err); e.currentTarget.disabled = false; }
          },
        }, 'Save changes'))),

      panel('Account', h('div', { class: 'info-list' },
        infoRow('Email', user.email),
        infoRow('Role', user.role?.name || '—'),
        infoRow('Data scope', titleCase(user.role?.dataScope || '')),
        infoRow('Organisation', store.organization?.name || ''),
        infoRow('Teams', store.teams?.length
          ? h('div', { class: 'row wrap', style: { gap: '4px' } },
            ...store.teams.map((t) => h('span', { class: 'badge' }, t.name)))
          : '—'),
        infoRow('Email verified', user.emailVerifiedAt ? 'Yes' : h('span', { class: 'badge badge-warning' }, 'Not verified')),
        infoRow('Last sign-in', user.lastLoginAt ? dateTime(user.lastLoginAt) : 'This is your first session'))));
  }

  const infoRow = (label, value) => h('div', { class: 'info-row' },
    h('div', { class: 'i-label' }, label), h('div', { class: 'i-value' }, value));

  function renderSecurity() {
    const current = input({ type: 'password', autocomplete: 'current-password' });
    const next = input({ type: 'password', autocomplete: 'new-password' });
    const confirm = input({ type: 'password', autocomplete: 'new-password' });
    const errorHost = h('div');

    return h('div', { class: 'chart-grid two' },
      panel('Change password', h('div', null,
        errorHost,
        field('Current password', current, { required: true }),
        field('New password', next, { required: true, hint: 'At least 8 characters with upper case, lower case and a number.' }),
        field('Confirm new password', confirm, { required: true }),
        h('button', {
          class: 'btn btn-primary',
          onClick: async (e) => {
            clear(errorHost);
            if (next.value !== confirm.value) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, 'Those passwords do not match.'));
              return;
            }
            e.currentTarget.disabled = true;
            try {
              await api.post('/api/auth/change-password', {
                currentPassword: current.value, newPassword: next.value,
              });
              toast.success('Password changed', 'Your other sessions were signed out.');
              current.value = ''; next.value = ''; confirm.value = '';
            } catch (err) {
              mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
            } finally { e.currentTarget.disabled = false; }
          },
        }, 'Change password'))),

      panel('Two-factor authentication', h('div', null,
        user.twoFactorEnabled
          ? h('div', null,
            h('div', { class: 'alert success mb-16' },
              icon('shield', { size: 16 }),
              h('div', null, 'Two-factor authentication is on. You will be asked for a code at every sign-in.')),
            h('button', { class: 'btn btn-danger', onClick: disable2fa }, 'Turn off two-factor'))
          : h('div', null,
            h('p', { class: 'muted mb-16' },
              'Add a second step at sign-in using any authenticator app (Google Authenticator, Authy, 1Password).'),
            h('button', { class: 'btn btn-primary', onClick: setup2fa }, icon('shield', { size: 15 }), 'Set up two-factor')))));
  }

  async function setup2fa() {
    try {
      const res = await api.post('/api/auth/2fa/setup');
      const codeInput = input({ inputmode: 'numeric', maxlength: '6', placeholder: '123456' });
      const dialog = modal({
        title: 'Set up two-factor authentication',
        subtitle: 'Add this secret to your authenticator app, then confirm the code it shows.',
        body: h('div', null,
          h('div', { class: 'field' },
            h('label', null, 'Setup key'),
            h('div', { class: 'row', style: { gap: '8px' } },
              h('code', { class: 'code-block grow', style: { wordBreak: 'break-all' } }, res.data.secret),
              h('button', {
                class: 'btn btn-sm',
                onClick: () => copyToClipboard(res.data.secret, 'Setup key copied'),
              }, icon('copy', { size: 14 })))),
          h('div', { class: 'field' },
            h('label', null, 'Or paste this URI into your app'),
            h('div', { class: 'code-block', style: { wordBreak: 'break-all', fontSize: '11px' } }, res.data.otpauthUri)),
          field('Verification code', codeInput, { required: true, hint: 'Enter the current 6-digit code.' })),
        footer: h('div', { class: 'row' },
          h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
          h('button', {
            class: 'btn btn-primary',
            onClick: async (e) => {
              e.currentTarget.disabled = true;
              try {
                await api.post('/api/auth/2fa/enable', { code: codeInput.value.trim() });
                toast.success('Two-factor authentication enabled');
                dialog.close();
                await loadSession();
                draw();
              } catch (err) {
                e.currentTarget.disabled = false;
                reportError(err);
              }
            },
          }, 'Enable')),
      });
    } catch (err) { reportError(err); }
  }

  async function disable2fa() {
    const passwordInput = input({ type: 'password', autocomplete: 'current-password' });
    const dialog = modal({
      title: 'Turn off two-factor authentication',
      subtitle: 'Confirm your password to continue.',
      body: field('Password', passwordInput, { required: true }),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-danger',
          onClick: async (e) => {
            e.currentTarget.disabled = true;
            try {
              await api.post('/api/auth/2fa/disable', { password: passwordInput.value });
              toast.success('Two-factor authentication turned off');
              dialog.close();
              await loadSession();
              draw();
            } catch (err) { e.currentTarget.disabled = false; reportError(err); }
          },
        }, 'Turn off')),
    });
  }

  async function renderSessions() {
    try {
      const sessions = await api.data('/api/auth/sessions');
      return panel('Active sessions', h('div', null,
        h('div', { class: 'col', style: { gap: '9px' } }, ...sessions.map((s) => h('div', {
          class: 'row-between',
          style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px' },
        },
          h('div', { style: { minWidth: 0 } },
            h('div', { class: 'strong truncate' },
              s.current ? h('span', { class: 'badge badge-success', style: { marginRight: '6px' } }, 'This device') : null,
              s.userAgent || 'Unknown device'),
            h('div', { class: 'tiny muted' },
              `${s.ip || 'unknown IP'} · started ${relative(s.createdAt)} · last seen ${relative(s.lastSeenAt)}`)),
          !s.current
            ? h('button', {
              class: 'btn btn-sm',
              onClick: async () => {
                try {
                  await api.del(`/api/auth/sessions/${s.id}`);
                  toast.success('Session ended');
                  draw();
                } catch (err) { reportError(err); }
              },
            }, 'Sign out')
            : null))),
        h('button', {
          class: 'btn btn-danger mt-16',
          onClick: async () => {
            const yes = await confirmDialog({
              title: 'Sign out everywhere?',
              message: 'Every session, including this one, is ended. You will need to sign in again.',
              danger: true, confirmLabel: 'Sign out everywhere',
            });
            if (!yes) return;
            try {
              await api.post('/api/auth/logout-all');
              window.location.href = '/login.html';
            } catch (err) { reportError(err); }
          },
        }, icon('logout', { size: 15 }), 'Sign out everywhere')),
      { subtitle: 'Each browser you sign in from appears here.' });
    } catch (err) {
      return h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load sessions', message: err.message }));
    }
  }

  function renderPreferences() {
    return panel('Appearance', h('div', null,
      h('p', { class: 'muted mb-16' }, 'Saved in this browser only, so a shared machine can look different for each person.'),
      switchToggle('Dark theme', getTheme() === 'dark', (value) => {
        applyTheme(value ? 'dark' : 'light');
        toast.success(`Switched to the ${value ? 'dark' : 'light'} theme`);
      }),
      h('div', { class: 'alert info mt-16' },
        icon('info', { size: 16 }),
        h('div', null,
          h('strong', null, 'Keyboard shortcuts'),
          'Press ', h('span', { class: 'code-inline' }, 'Ctrl/⌘ K'), ' or ', h('span', { class: 'code-inline' }, '/'),
          ' anywhere to open search and jump between records.'))));
  }

  buildTabs();
  await draw();
  return { node };
}
