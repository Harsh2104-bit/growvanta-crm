/**
 * Lead 360.
 *
 * Left column: identity, score, attribution and the editable field summary.
 * Right column: tabbed timeline, activities, documents,
 * duplicates and (for those who may see it) the audit trail.
 *
 * Every action here is a real API call - logging a call updates the timeline,
 * the last-contacted date and the lead score in one round trip.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon, ACTIVITY_ICONS } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, canAny, reference, store } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, avatar, statusBadge, dropdown, menuItem,
  confirmDialog, modal, drawer, field, input, textarea, select, tabs, copyToClipboard,
} from '../lib/ui.js';
import { scoreRing } from '../lib/charts.js';
import {
  money, date, dateTime, relative, titleCase, duration, fileSize, number, SCORE_TONE, inputDateTime,
} from '../lib/format.js';
import { pageHead, noAccess, panel, linkTo, priorityBadge } from './shared.js';
import { navigate } from '../router.js';
import { openLeadForm } from './leadForm.js';

export default async function render({ params }) {
  if (!can('leads', 'read')) return { node: noAccess('leads') };

  const node = h('div', null, pageLoader('Loading lead'));
  let data = null;
  let activeTab = 'timeline';
  let statuses = [];

  async function load() {
    try {
      const res = await api.get(`/api/leads/${params.id}`);
      data = res.data;
      if (can('leads', 'change_status') && !statuses.length) {
        // Its own try: a reference lookup that fails should cost the status
        // picker, not the whole lead page.
        try { statuses = (await reference()).statuses || []; } catch { statuses = []; }
      }
      draw();
    } catch (err) {
      mount(node, h('div', { class: 'card' }, emptyState({
        iconName: 'alert',
        title: err.status === 404 ? 'Lead not found' : 'Could not load this lead',
        message: err.message,
        action: h('a', { class: 'btn', href: '#/leads' }, 'Back to leads'),
      })));
    }
  }

  // -------------------------------------------------------------------------
  // Actions
  // -------------------------------------------------------------------------
  async function patchLead(payload, successMessage) {
    try {
      await api.patch(`/api/leads/${params.id}`, payload);
      if (successMessage) toast.success(successMessage);
      await load();
      window.dispatchEvent(new CustomEvent('gv:data-changed'));
    } catch (err) { reportError(err); }
  }

  function openLogActivity(type = 'CALL') {
    const typeSelect = select(
      ['CALL', 'EMAIL', 'MEETING', 'WHATSAPP', 'SMS', 'NOTE'].map((t) => ({ value: t, label: titleCase(t) })),
      { value: type },
    );
    const directionSelect = select([
      { value: 'OUTBOUND', label: 'Outbound' }, { value: 'INBOUND', label: 'Inbound' },
    ], { value: 'OUTBOUND' });
    const outcomeSelect = select(
      ['CONNECTED', 'NO_ANSWER', 'BUSY', 'INTERESTED', 'NOT_INTERESTED', 'CALLBACK'].map((o) => ({ value: o, label: titleCase(o) })),
      { placeholder: 'Outcome (optional)' },
    );
    const subjectInput = input({ placeholder: 'Discussed pricing and timelines' });
    const bodyInput = textarea({ rows: 4, placeholder: 'What was said? What happens next?' });
    const durationInput = input({ type: 'number', min: '0', placeholder: 'Minutes' });
    const occurredInput = input({ type: 'datetime-local', value: inputDateTime(new Date()) });
    const followUpInput = input({ type: 'datetime-local' });

    const dialog = modal({
      title: 'Log an interaction',
      subtitle: `${data.lead.firstName} ${data.lead.lastName || ''} · ${data.lead.code}`.trim(),
      body: h('div', null,
        h('div', { class: 'form-grid' },
          field('Type', typeSelect),
          field('Direction', directionSelect),
          field('Outcome', outcomeSelect),
          field('Duration (minutes)', durationInput)),
        field('Subject', subjectInput),
        field('Notes', bodyInput),
        h('div', { class: 'form-grid' },
          field('When', occurredInput),
          field('Next follow-up', followUpInput)),
        h('div', { class: 'tiny muted' }, 'Setting a next follow-up puts this lead back in the follow-up queue.')),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            try {
              await api.post(`/api/leads/${params.id}/activities`, {
                type: typeSelect.value,
                direction: typeSelect.value === 'NOTE' ? null : directionSelect.value,
                outcome: outcomeSelect.value || null,
                subject: subjectInput.value.trim() || null,
                body: bodyInput.value.trim() || null,
                durationSec: durationInput.value ? Number(durationInput.value) * 60 : null,
                occurredAt: occurredInput.value || null,
                nextFollowUpAt: followUpInput.value || null,
              });
              toast.success('Interaction logged');
              dialog.close();
              await load();
              window.dispatchEvent(new CustomEvent('gv:data-changed'));
            } catch (err) { reportError(err); }
          },
        }, 'Save')),
    });
  }

  /** Schedules the lead's next follow-up. Replaces the old "add task" flow. */
  function openFollowUp() {
    const whenInput = input({ type: 'datetime-local', value: inputDateTime(data.lead.nextFollowUpAt) });
    const dialog = modal({
      title: 'Set next follow-up',
      subtitle: 'The lead returns to the follow-up queue on this date, and its owner is reminded.',
      body: field('Next follow-up', whenInput),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            try {
              await api.patch(`/api/leads/${params.id}`, { nextFollowUpAt: whenInput.value || null });
              toast.success(whenInput.value ? 'Follow-up scheduled' : 'Follow-up cleared');
              dialog.close();
              await load();
            } catch (err) { reportError(err); }
          },
        }, 'Save')),
    });
  }

  /**
   * The status is what a salesperson touches most often, so it is a control
   * here rather than a read-only badge - no opening the edit form, no bulk
   * selection of one. Anyone holding leads.change_status may move a lead that
   * is already visible to them, which is how the API gates it too.
   */
  function statusControl(lead) {
    const badge = statusBadge(lead.status?.name, lead.status?.color);
    if (!can('leads', 'change_status') || !statuses.length) return badge;

    const trigger = h('button', {
      class: 'status-picker',
      title: 'Change status',
      'aria-label': `Status: ${lead.status?.name || 'none'}. Change it.`,
    }, badge, icon('chevronDown', { size: 13 }));

    return dropdown(trigger, (close) => h('div', null,
      ...statuses.map((s) => menuItem(s.name, {
        iconName: s.id === lead.status?.id ? 'check' : undefined,
        close,
        onClick: () => { if (s.id !== lead.status?.id) chooseStatus(s); },
      })),
    ), { align: 'left' });
  }

  function chooseStatus(status) {
    if (!status.isLost) {
      patchLead({ statusId: status.id }, `Status changed to ${status.name}`);
      return;
    }
    // Losing a lead is the one status change worth a sentence of explanation:
    // it is what the lost-reason reporting is built on.
    const reasonInput = input({ placeholder: 'Why was this lead lost?' });
    const dialog = modal({
      title: `Mark as ${status.name}`,
      body: h('div', null, field('Reason', reasonInput, { hint: 'Optional, but it is what the lost-reason report reads.' })),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            dialog.close();
            await patchLead(
              { statusId: status.id, lostReason: reasonInput.value || undefined },
              `Status changed to ${status.name}`,
            );
          },
        }, 'Save')),
    });
  }

  async function openAssign() {
    const ref = await reference();
    const userSelect = select(ref.users.map((u) => ({ value: u.id, label: u.fullName || u.name })), {
      value: data.lead.ownerId || '', placeholder: 'Unassigned',
    });
    const teamSelect = select(ref.teams.map((t) => ({ value: t.id, label: t.name })), {
      value: data.lead.teamId || '', placeholder: 'No team',
    });
    const dialog = modal({
      title: 'Assign this lead',
      body: h('div', null, field('Owner', userSelect), field('Team', teamSelect)),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            dialog.close();
            await patchLead({ ownerId: userSelect.value || null, teamId: teamSelect.value || null }, 'Lead reassigned');
          },
        }, 'Save')),
    });
  }

  function openUpload() {
    const fileInput = h('input', { type: 'file', class: 'input', style: { paddingTop: '7px' } });
    const dialog = modal({
      title: 'Attach a document',
      subtitle: 'PDF, image, spreadsheet or Word document.',
      body: field('File', fileInput),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', {
          class: 'btn btn-primary',
          onClick: async () => {
            const file = fileInput.files?.[0];
            if (!file) { toast.warn('Choose a file first'); return; }
            const formData = new FormData();
            formData.append('file', file);
            formData.append('leadId', params.id);
            try {
              await api.upload('/api/documents', formData);
              toast.success('Document uploaded');
              dialog.close();
              await load();
            } catch (err) { reportError(err); }
          },
        }, 'Upload')),
    });
  }

  async function mergeWith(duplicateId) {
    const yes = await confirmDialog({
      title: 'Merge these leads?',
      message: 'The other lead becomes a duplicate record; its activities, tasks and documents move here. Empty fields on this lead are filled from it.',
      confirmLabel: 'Merge',
    });
    if (!yes) return;
    try {
      await api.post('/api/leads/merge', { targetId: params.id, sourceIds: [duplicateId] });
      toast.success('Leads merged');
      await load();
    } catch (err) { reportError(err); }
  }

  // -------------------------------------------------------------------------
  // Render
  // -------------------------------------------------------------------------
  function draw() {
    const lead = data.lead;
    const fullName = `${lead.firstName} ${lead.lastName || ''}`.trim();
    const scoreColor = SCORE_TONE[lead.scoreBand] || 'var(--brand-600)';

    const actions = [
      can('activities', 'create')
        ? h('button', { class: 'btn', onClick: () => openLogActivity('CALL') }, icon('phone', { size: 15 }), 'Log call')
        : null,
      can('leads', 'update')
        ? h('button', { class: 'btn', onClick: openFollowUp }, icon('clock', { size: 15 }), 'Set follow-up')
        : null,
      dropdown(
        h('button', { class: 'btn' }, icon('more', { size: 16 })),
        (close) => h('div', null,
          can('leads', 'update')
            ? menuItem('Edit lead', { iconName: 'edit', close, onClick: () => openLeadForm({ lead, onSaved: load }) })
            : null,
          canAny([['leads', 'assign'], ['leads', 'reassign']])
            ? menuItem('Reassign', { iconName: 'user', close, onClick: openAssign })
            : null,
          can('documents', 'create')
            ? menuItem('Attach document', { iconName: 'file', close, onClick: openUpload })
            : null,
          can('leads', 'update')
            ? menuItem('Recalculate score', {
              iconName: 'refresh', close,
              onClick: async () => {
                try {
                  await api.post(`/api/leads/${lead.id}/rescore`);
                  toast.success('Score recalculated');
                  load();
                } catch (err) { reportError(err); }
              },
            })
            : null,
          menuItem('Copy lead link', {
            iconName: 'link', close,
            onClick: () => copyToClipboard(`${window.location.origin}/#/leads/${lead.id}`, 'Lead link copied'),
          }),
          can('leads', 'delete') ? h('div', { class: 'dropdown-sep' }) : null,
          can('leads', 'delete')
            ? menuItem('Delete lead', {
              iconName: 'trash', close, danger: true,
              onClick: async () => {
                const yes = await confirmDialog({
                  title: 'Delete this lead?',
                  message: `${fullName} and its entire history will be removed permanently.`,
                  confirmLabel: 'Delete', danger: true, requireText: 'DELETE',
                });
                if (!yes) return;
                try {
                  await api.del(`/api/leads/${lead.id}`);
                  toast.success('Lead deleted');
                  navigate('/leads');
                } catch (err) { reportError(err); }
              },
            })
            : null),
      ),
    ].filter(Boolean);

    // ---- left column ----
    const identity = h('section', { class: 'card' },
      h('div', { class: 'card-body' },
        h('div', { class: 'row', style: { gap: '14px', alignItems: 'flex-start' } },
          avatar(fullName, { size: 'lg' }),
          h('div', { class: 'grow', style: { minWidth: 0 } },
            h('h2', { class: 'truncate' }, fullName),
            h('div', { class: 'muted small truncate' }, [lead.jobTitle, lead.company].filter(Boolean).join(' at ') || lead.code),
            h('div', { class: 'row wrap mt-8', style: { gap: '6px' } },
              statusControl(lead),
              priorityBadge(lead.priority),
              lead.isConverted ? h('span', { class: 'badge badge-success' }, 'Converted') : null))),

        h('div', { class: 'row mt-16', style: { gap: '16px' } },
          scoreRing(lead.score, { color: scoreColor }),
          h('div', { class: 'grow' },
            h('div', { class: 'strong', style: { color: scoreColor } }, titleCase(lead.scoreBand)),
            h('div', { class: 'muted tiny' }, `${lead.score}/100 lead score`),
            data.score?.breakdown?.length
              ? h('details', { class: 'mt-8' },
                h('summary', { class: 'tiny muted', style: { cursor: 'pointer' } }, 'How this was calculated'),
                h('div', { class: 'col tiny mt-8', style: { gap: '3px' } },
                  ...data.score.breakdown.map((b) => h('div', { class: 'row-between' },
                    h('span', null, b.rule),
                    h('span', { class: b.points >= 0 ? 'text-success' : 'text-danger' },
                      `${b.points > 0 ? '+' : ''}${b.points}`)))))
              : null)),

        h('div', { class: 'row wrap mt-16', style: { gap: '8px' } },
          lead.phone ? h('a', { class: 'btn btn-sm', href: `tel:${lead.phone}` }, icon('phone', { size: 14 }), 'Call') : null,
          lead.email ? h('a', { class: 'btn btn-sm', href: `mailto:${lead.email}` }, icon('mail', { size: 14 }), 'Email') : null,
          lead.phone
            ? h('a', {
              class: 'btn btn-sm', target: '_blank', rel: 'noopener',
              href: `https://wa.me/${lead.phone.replace(/\D/g, '')}`,
            }, icon('message', { size: 14 }), 'WhatsApp')
            : null)));

    const details = panel('Details', h('div', { class: 'info-list' },
      infoRow('Lead ID', h('span', { class: 'mono' }, lead.code)),
      infoRow('Email', lead.email
        ? h('a', { href: `mailto:${lead.email}` }, lead.email) : h('span', { class: 'muted' }, 'Not provided')),
      infoRow('Phone', lead.phone || '—'),
      infoRow('Alternate', lead.altPhone || '—'),
      infoRow('Company', lead.company || '—'),
      infoRow('Website', lead.website
        ? h('a', { href: lead.website, target: '_blank', rel: 'noopener noreferrer' }, lead.website) : '—'),
      infoRow('Location', [lead.city, lead.state, lead.country].filter(Boolean).join(', ') || '—'),
      infoRow('Pincode', lead.pincode || '—'),
      infoRow('Owner', lead.owner
        ? `${lead.owner.firstName} ${lead.owner.lastName || ''}`.trim()
        : h('span', { class: 'badge' }, 'Unassigned')),
      infoRow('Team', lead.team?.name || '—'),
      infoRow('Expected value', lead.expectedValue ? money(lead.expectedValue) : '—'),
      infoRow('Last contacted', lead.lastContactedAt ? dateTime(lead.lastContactedAt) : 'Never'),
      infoRow('Next follow-up', lead.nextFollowUpAt
        ? h('span', {
          class: new Date(lead.nextFollowUpAt) < new Date() ? 'badge badge-danger' : 'badge badge-info',
        }, dateTime(lead.nextFollowUpAt))
        : '—'),
      infoRow('Created', `${dateTime(lead.createdAt)}${lead.createdBy ? ` by ${lead.createdBy.firstName}` : ''}`),
      infoRow('Tags', lead.tags?.length
        ? h('div', { class: 'row wrap', style: { gap: '4px' } },
          ...lead.tags.map((t) => h('span', { class: 'badge', style: { background: `${t.color}1f`, color: t.color } }, t.name)))
        : '—')));

    const attribution = panel('Attribution', h('div', { class: 'info-list' },
      infoRow('Source', lead.source?.name || '—'),
      infoRow('Campaign', lead.campaign
        ? linkTo(`/campaigns/${lead.campaign.id}`, lead.campaign.name)
        : '—'),
      infoRow('Medium', lead.medium || '—'),
      infoRow('First touch', lead.firstTouchSource
        ? `${lead.firstTouchSource}${lead.firstTouchAt ? ` · ${date(lead.firstTouchAt)}` : ''}` : '—'),
      infoRow('Last touch', lead.lastTouchSource
        ? `${lead.lastTouchSource}${lead.lastTouchAt ? ` · ${date(lead.lastTouchAt)}` : ''}` : '—'),
      infoRow('UTM source', lead.utmSource || '—'),
      infoRow('UTM medium', lead.utmMedium || '—'),
      infoRow('UTM campaign', lead.utmCampaign || '—'),
      infoRow('Landing page', lead.landingPage
        ? h('a', {
          href: lead.landingPage, target: '_blank', rel: 'noopener noreferrer',
          class: 'truncate', style: { display: 'inline-block', maxWidth: '100%' },
        }, lead.landingPage)
        : '—')));

    const customFieldEntries = Object.entries(lead.customFields || {});
    const customPanel = customFieldEntries.length
      ? panel('Additional details', h('div', { class: 'info-list' },
        ...customFieldEntries.map(([key, value]) => infoRow(titleCase(key),
          Array.isArray(value) ? value.join(', ') : String(value)))))
      : null;

    const left = h('div', { class: 'col', style: { gap: '14px' } },
      identity, details, attribution, customPanel);

    // ---- right column ----
    const tabItems = [
      { key: 'timeline', label: 'Timeline', count: data.activities.length },
      { key: 'documents', label: 'Documents', count: data.documents.length },
      { key: 'duplicates', label: 'Duplicates', count: data.duplicates.length },
    ];
    if (can('audit', 'read')) tabItems.push({ key: 'audit', label: 'Audit', count: data.auditTrail.length });

    const tabBody = h('div', { class: 'card-body' });
    const right = h('section', { class: 'card' },
      h('div', { style: { padding: '0 12px' } },
        tabs(tabItems, activeTab, (key) => { activeTab = key; mount(tabBody, renderTab(key)); })),
      tabBody);
    mount(tabBody, renderTab(activeTab));

    mount(node,
      pageHead({
        title: fullName,
        subtitle: `${lead.code} · created ${relative(lead.createdAt)}`,
        breadcrumbs: [{ label: 'Leads', path: '/leads' }, { label: fullName }],
        actions,
      }),
      h('div', { class: 'detail-layout' }, left, right));
  }

  function infoRow(label, value) {
    return h('div', { class: 'info-row' },
      h('div', { class: 'i-label' }, label),
      h('div', { class: 'i-value' }, value ?? '—'));
  }

  function renderTab(key) {
    if (key === 'timeline') return renderTimeline();
    if (key === 'documents') return renderDocuments();
    if (key === 'duplicates') return renderDuplicates();
    if (key === 'audit') return renderAudit();
    return h('div');
  }

  function renderTimeline() {
    if (!data.activities.length) {
      return emptyState({
        iconName: 'activity',
        title: 'No activity yet',
        message: 'Log a call, email or meeting to start the timeline.',
        action: can('activities', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: () => openLogActivity() }, 'Log an interaction')
          : null,
      });
    }
    return h('div', null,
      can('activities', 'create')
        ? h('div', { class: 'row wrap mb-16', style: { gap: '6px' } },
          h('button', { class: 'btn btn-sm', onClick: () => openLogActivity('CALL') }, icon('phone', { size: 14 }), 'Call'),
          h('button', { class: 'btn btn-sm', onClick: () => openLogActivity('EMAIL') }, icon('mail', { size: 14 }), 'Email'),
          h('button', { class: 'btn btn-sm', onClick: () => openLogActivity('MEETING') }, icon('calendar', { size: 14 }), 'Meeting'),
          h('button', { class: 'btn btn-sm', onClick: () => openLogActivity('WHATSAPP') }, icon('message', { size: 14 }), 'WhatsApp'),
          h('button', { class: 'btn btn-sm', onClick: () => openLogActivity('NOTE') }, icon('edit', { size: 14 }), 'Note'))
        : null,
      h('div', { class: 'timeline' }, ...data.activities.map((a) => h('div', { class: 'tl-item' },
        h('div', {
          class: 'tl-dot',
          style: ['CALL', 'EMAIL', 'MEETING', 'WHATSAPP', 'SMS'].includes(a.type)
            ? { borderColor: 'var(--brand-400)', color: 'var(--brand-600)' } : null,
        }, icon(ACTIVITY_ICONS[a.type] || 'info', { size: 11 })),
        h('div', { class: 'tl-head' },
          h('span', { class: 'tl-title' }, a.subject || titleCase(a.type)),
          h('span', { class: 'tl-time', title: dateTime(a.occurredAt) }, relative(a.occurredAt))),
        a.body ? h('div', { class: 'tl-body' }, a.body) : null,
        h('div', { class: 'tl-meta' },
          h('span', { class: 'badge' }, titleCase(a.type)),
          a.direction ? h('span', { class: 'badge' }, titleCase(a.direction)) : null,
          a.outcome ? h('span', { class: 'badge badge-info' }, titleCase(a.outcome)) : null,
          a.durationSec ? h('span', { class: 'badge' }, duration(a.durationSec)) : null,
          a.user ? h('span', { class: 'tiny muted' }, `${a.user.firstName} ${a.user.lastName || ''}`.trim()) : null)))));
  }

  function renderDuplicates() {
    if (!data.duplicates.length) {
      return emptyState({ iconName: 'check', title: 'No duplicates found', message: 'No other lead shares this phone number or email address.' });
    }
    return h('div', null,
      h('div', { class: 'alert warning mb-16' },
        icon('alert', { size: 16 }),
        h('div', null, `${data.duplicates.length} possible duplicate${data.duplicates.length === 1 ? '' : 's'}. Merging keeps this lead and folds the others into it.`)),
      h('div', { class: 'col', style: { gap: '10px' } }, ...data.duplicates.map((dup) => h('div', {
        class: 'row-between',
        style: { padding: '12px', border: '1px solid var(--line)', borderRadius: '10px' },
      },
        h('div', { style: { minWidth: 0 } },
          h('div', { class: 'strong truncate' }, `${dup.firstName} ${dup.lastName || ''}`.trim()),
          h('div', { class: 'tiny muted' },
            `${dup.code} · ${dup.company || 'No company'} · matched on ${dup.matchedOn.join(', ').toLowerCase()}`),
          h('div', { class: 'tiny muted' },
            `${dup.owner ? `${dup.owner.firstName} ${dup.owner.lastName || ''}` : 'Unassigned'} · created ${relative(dup.createdAt)}`)),
        h('div', { class: 'row', style: { gap: '6px' } },
          h('button', { class: 'btn btn-sm', onClick: () => navigate(`/leads/${dup.id}`) }, 'Open'),
          can('leads', 'update')
            ? h('button', { class: 'btn btn-sm btn-primary', onClick: () => mergeWith(dup.id) }, icon('merge', { size: 14 }), 'Merge')
            : null)))));
  }

  function renderAudit() {
    if (!data.auditTrail.length) {
      return emptyState({ iconName: 'audit', title: 'No audit entries', message: 'Changes to this lead will be recorded here.' });
    }
    return h('div', { class: 'timeline' }, ...data.auditTrail.map((entry) => h('div', { class: 'tl-item' },
      h('div', { class: 'tl-dot' }, icon('audit', { size: 11 })),
      h('div', { class: 'tl-head' },
        h('span', { class: 'tl-title' }, titleCase(entry.action.replace(/\./g, ' '))),
        h('span', { class: 'tl-time' }, dateTime(entry.createdAt))),
      h('div', { class: 'tl-body' }, entry.actorLabel || 'System'),
      Object.keys(entry.changes || {}).length
        ? h('div', { class: 'col tiny mt-8', style: { gap: '2px' } },
          ...Object.entries(entry.changes).map(([fieldName, change]) => h('div', { class: 'muted' },
            `${titleCase(fieldName)}: ${formatChange(change)}`)))
        : null)));
  }

  function formatChange(change) {
    if (change && typeof change === 'object' && 'from' in change) {
      return `${change.from ?? '(empty)'} → ${change.to ?? '(empty)'}`;
    }
    return typeof change === 'object' ? JSON.stringify(change) : String(change);
  }

  await load();
  return { node };
}
