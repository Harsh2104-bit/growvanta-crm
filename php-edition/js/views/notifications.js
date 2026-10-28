/** Full notification centre. */
import { h, mount } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { pageLoader, emptyState, toast, reportError, pagination } from '../lib/ui.js';
import { relative, dateTime, titleCase, number } from '../lib/format.js';
import { pageHead } from './shared.js';
import { navigate } from '../router.js';

export default async function render() {
  const body = h('div', null, pageLoader());
  const state = { page: 1, unreadOnly: false };

  const node = h('div', null,
    pageHead({
      title: 'Notifications',
      subtitle: 'Assignments, due follow-ups, deal outcomes and system alerts.',
      actions: [
        h('button', {
          class: 'btn',
          onClick: () => { state.unreadOnly = !state.unreadOnly; state.page = 1; load(); },
        }, icon('filter', { size: 15 }), 'Unread only'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            try {
              const res = await api.post('/api/notifications/read-all');
              toast.success(`${res.data.updated} marked as read`);
              load();
              window.dispatchEvent(new CustomEvent('gv:data-changed'));
            } catch (err) { reportError(err); }
          },
        }, icon('check', { size: 15 }), 'Mark all read'),
      ],
    }),
    body);

  async function load() {
    mount(body, pageLoader());
    try {
      const res = await api.get('/api/notifications', {
        page: state.page, limit: 30, ...(state.unreadOnly ? { unread: 'true' } : {}),
      });
      const rows = res.data;
      if (!rows.length) {
        mount(body, h('div', { class: 'card' }, emptyState({
          iconName: 'bell',
          title: state.unreadOnly ? 'Nothing unread' : 'No notifications yet',
          message: 'Assignments, due tasks and deal outcomes will show up here.',
        })));
        return;
      }
      mount(body, h('div', { class: 'card' },
        h('div', { class: 'card-head' },
          h('h3', null, state.unreadOnly ? 'Unread' : 'All notifications'),
          h('span', { class: 'muted small' }, `${number(res.meta.unread)} unread`)),
        h('div', null, ...rows.map(renderRow)),
        res.meta.totalPages > 1
          ? pagination(res.meta, (page) => { state.page = page; load(); })
          : null));
    } catch (err) {
      reportError(err);
      mount(body, h('div', { class: 'card' }, emptyState({ iconName: 'alert', title: 'Could not load notifications', message: err.message })));
    }
  }

  function renderRow(n) {
    const severityColor = {
      SUCCESS: 'var(--success)', WARNING: 'var(--warning)', DANGER: 'var(--danger)',
    }[n.severity] || 'var(--brand-500)';

    return h('div', {
      class: `notif-item ${n.isRead ? '' : 'unread'}`,
      onClick: async () => {
        if (!n.isRead) {
          await api.post(`/api/notifications/${n.id}/read`).catch(() => {});
          window.dispatchEvent(new CustomEvent('gv:data-changed'));
        }
        if (n.link) navigate(n.link.replace(/^#/, ''));
        else load();
      },
    },
      h('span', { class: 'n-dot', style: { background: severityColor, opacity: n.isRead ? '.35' : '1' } }),
      h('div', { class: 'grow', style: { minWidth: 0 } },
        h('div', { class: 'n-title' }, n.title),
        n.body ? h('div', { class: 'n-body' }, n.body) : null,
        h('div', { class: 'n-time', title: dateTime(n.createdAt) },
          `${titleCase(n.type)} · ${relative(n.createdAt)}`)),
      h('button', {
        class: 'icon-btn',
        'aria-label': 'Dismiss',
        onClick: async (e) => {
          e.stopPropagation();
          try {
            await api.del(`/api/notifications/${n.id}`);
            load();
            window.dispatchEvent(new CustomEvent('gv:data-changed'));
          } catch (err) { reportError(err); }
        },
      }, icon('close', { size: 15 })));
  }

  await load();
  return { node };
}
