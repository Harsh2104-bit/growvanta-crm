/**
 * Project master.
 *
 * A broker's list mixes two very different things: stock they created and
 * stock a developer or the platform shared with them. The "Ownership" filter
 * and the "Shared" badge exist so that distinction is never ambiguous - you
 * can sell a shared project but you cannot edit it, and the UI has to make
 * that obvious before the user clicks Edit and gets a 403.
 */
import { h, mount } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import {
  dataTable, searchBox, toast, reportError, drawer, field, input, textarea,
  select, confirmDialog,
} from '../lib/ui.js';
import { money, date, titleCase, number } from '../lib/format.js';
import { pageHead, noAccess } from './shared.js';
import { navigate } from '../router.js';

export const PROJECT_TYPES = ['RESIDENTIAL', 'COMMERCIAL', 'MIXED_USE', 'PLOTTED', 'VILLA'];
export const PROJECT_STATUSES = [
  'PRE_LAUNCH', 'NEW_LAUNCH', 'ONGOING', 'READY_TO_MOVE', 'COMPLETED', 'SOLD_OUT', 'ON_HOLD',
];

const STATUS_TONE = {
  PRE_LAUNCH: 'badge-info',
  NEW_LAUNCH: 'badge-brand',
  ONGOING: 'badge-success',
  READY_TO_MOVE: 'badge-success',
  COMPLETED: '',
  SOLD_OUT: 'badge-warning',
  ON_HOLD: 'badge-danger',
};

export const priceRange = (row) => {
  if (!row.priceMin && !row.priceMax) return '—';
  if (row.priceMin && row.priceMax) {
    return `${money(row.priceMin, { compact: true })} – ${money(row.priceMax, { compact: true })}`;
  }
  return money(row.priceMin || row.priceMax, { compact: true });
};

export default async function render({ query }) {
  if (!can('projects', 'read')) return { node: noAccess('projects') };

  let table;
  const node = h('div', null,
    pageHead({
      title: 'Projects',
      subtitle: store.user?.isPlatformAdmin && !store.user?.isImpersonating
        ? 'Every project on the platform, whichever company owns it. You can correct any of them.'
        : store.organization?.usesGlobalProjects
          ? 'Your own inventory plus every project shared with your company.'
          : 'Every project your company is selling.',
      actions: [
        can('projects', 'export')
          ? h('button', {
            class: 'btn',
            onClick: () => api.download('/api/projects/export')
              .then(() => toast.success('Export downloaded'))
              .catch(reportError),
          }, icon('download', { size: 15 }), 'Export')
          : null,
        can('projects', 'create')
          ? h('button', {
            class: 'btn btn-primary',
            onClick: () => openProjectForm({ onSaved: () => table.refresh() }),
          }, icon('plus', { size: 15 }), 'New project')
          : null,
      ].filter(Boolean),
    }));

  const typeFilter = select(
    [{ value: '', label: 'All types' }, ...PROJECT_TYPES.map((t) => ({ value: t, label: titleCase(t) }))],
    { value: query.projectType || '', onChange: (e) => table.setParams({ projectType: e.target.value }) },
  );
  const statusFilter = select(
    [{ value: '', label: 'All statuses' }, ...PROJECT_STATUSES.map((s) => ({ value: s, label: titleCase(s) }))],
    { value: query.status || '', onChange: (e) => table.setParams({ status: e.target.value }) },
  );
  const ownershipFilter = select([
    { value: '', label: 'All projects' },
    { value: 'own', label: 'Created by us' },
    { value: 'shared', label: 'Shared with us' },
  ], { value: query.ownership || '', onChange: (e) => table.setParams({ ownership: e.target.value }) });

  table = dataTable({
    toolbar: h('div', { class: 'row wrap grow', style: { gap: '10px' } },
      searchBox('Search by name, location or developer…', (v) => table.setParams({ q: v }), { value: query.q || '' }),
      ownershipFilter, typeFilter, statusFilter),
    initialParams: {
      q: query.q || '', projectType: query.projectType || '',
      status: query.status || '', ownership: query.ownership || '',
    },
    defaultSort: 'name',
    defaultDir: 'asc',
    onRowClick: (row) => navigate(`/projects/${row.id}`),
    load: (params) => api.get('/api/projects', params),
    emptyTitle: 'No projects yet',
    emptyMessage: 'Add the projects your team is selling so every lead can be attributed to one.',
    emptyAction: can('projects', 'create')
      ? h('button', {
        class: 'btn btn-primary',
        onClick: () => openProjectForm({ onSaved: () => table.refresh() }),
      }, 'Add a project')
      : null,
    columns: [
      {
        label: 'Project',
        sortKey: 'name',
        render: (row) => h('div', { class: 'row', style: { gap: '10px' } },
          projectThumb(row),
          h('div', { style: { minWidth: 0 } },
            h('div', { class: 'cell-main truncate' }, row.name),
            h('div', { class: 'cell-sub truncate' }, `${row.code} · ${row.developerName}`))),
      },
      {
        label: 'Location',
        sortKey: 'location',
        render: (row) => h('div', null,
          h('div', { class: 'truncate' }, row.location),
          row.city ? h('div', { class: 'cell-sub' }, row.city) : null),
      },
      {
        label: 'Type',
        cellClass: 'hide-sm',
        render: (row) => h('span', { class: 'badge' }, titleCase(row.projectType)),
      },
      {
        label: 'Status',
        render: (row) => h('span', { class: `badge ${STATUS_TONE[row.status] || ''}` }, titleCase(row.status)),
      },
      {
        label: 'Price range',
        cellClass: 'right hide-sm',
        sortKey: 'priceMin',
        render: (row) => priceRange(row),
      },
      {
        label: 'Leads',
        cellClass: 'right',
        // Always this tenant's own count, even on a shared project.
        render: (row) => number(row.leadCount),
      },
      {
        // A platform admin sees projects from every company, so "Shared" would
        // be meaningless - they need to know whose listing they are looking at.
        label: store.user?.isPlatformAdmin && !store.user?.isImpersonating ? 'Owned by' : 'Source',
        render: (row) => {
          if (store.user?.isPlatformAdmin && !store.user?.isImpersonating) {
            return h('span', { class: 'small' }, row.ownerOrgName || '—');
          }
          return row.isOwner
            ? h('span', { class: 'badge badge-brand' }, 'Ours')
            : h('span', { class: 'badge badge-info', title: `Shared by ${row.ownerOrgName}` }, 'Shared');
        },
      },
    ],
  });

  node.appendChild(table.root);
  return { node };
}

export function projectThumb(row, size = 34) {
  const style = {
    width: `${size}px`, height: `${size}px`, borderRadius: '7px',
    objectFit: 'cover', flexShrink: 0, background: 'var(--bg-2)',
  };
  if (row.coverImageUrl) {
    return h('img', { src: row.coverImageUrl, alt: '', style, loading: 'lazy' });
  }
  return h('div', {
    class: 'center',
    style: { ...style, display: 'flex', color: 'var(--ink-3)' },
  }, icon('building', { size: Math.round(size * 0.5) }));
}

// ---------------------------------------------------------------------------
// Create / edit
// ---------------------------------------------------------------------------
export function openProjectForm({ project = null, onSaved } = {}) {
  const isEdit = Boolean(project);
  const errorHost = h('div');

  const nameInput = input({ value: project?.name || '', placeholder: 'ABC Residency' });
  const locationInput = input({ value: project?.location || '', placeholder: 'Sector 84, Gurugram' });
  const developerInput = input({
    value: project?.developerName || store.organization?.name || '',
    placeholder: 'Developer or brand name',
  });
  const typeSelect = select(PROJECT_TYPES.map((t) => ({ value: t, label: titleCase(t) })),
    { value: project?.projectType || 'RESIDENTIAL' });
  const statusSelect = select(PROJECT_STATUSES.map((s) => ({ value: s, label: titleCase(s) })),
    { value: project?.status || 'ONGOING' });

  const cityInput = input({ value: project?.city || '', placeholder: 'Gurugram' });
  const stateInput = input({ value: project?.state || '', placeholder: 'Haryana' });
  const pincodeInput = input({ value: project?.pincode || '', placeholder: '122004' });
  const addressInput = textarea({ value: project?.address || '', rows: 2 });

  const reraInput = input({ value: project?.reraNumber || '', placeholder: 'HRERA-GGM-123-2024' });
  const possessionInput = input({ type: 'date', value: project?.possessionDate || '' });

  const priceMinInput = input({ type: 'number', min: '0', value: project?.priceMin || '', placeholder: '7500000' });
  const priceMaxInput = input({ type: 'number', min: '0', value: project?.priceMax || '', placeholder: '15000000' });
  const areaMinInput = input({ type: 'number', min: '0', value: project?.areaMin || '', placeholder: '1200' });
  const areaMaxInput = input({ type: 'number', min: '0', value: project?.areaMax || '', placeholder: '2400' });

  const configInput = input({
    value: (project?.configurations || []).join(', '),
    placeholder: '2 BHK, 3 BHK, 4 BHK',
  });
  const amenitiesInput = input({
    value: (project?.amenities || []).join(', '),
    placeholder: 'Clubhouse, Swimming pool, Gym',
  });
  const descriptionInput = textarea({ value: project?.description || '', rows: 3 });

  const contactNameInput = input({ value: project?.contactName || '' });
  const contactPhoneInput = input({ value: project?.contactPhone || '' });
  const contactEmailInput = input({ type: 'email', value: project?.contactEmail || '' });

  // Publishing to every client is a platform-owner decision, so the control
  // only appears for one.
  const canPublish = Boolean(store.user?.isPlatformAdmin);
  const visibilitySelect = canPublish
    ? select([
      { value: 'PRIVATE', label: 'Private - only this company' },
      { value: 'GLOBAL', label: 'Global - every client on the platform' },
    ], { value: project?.visibility || 'PRIVATE' })
    : null;

  const saveButton = h('button', { class: 'btn btn-primary' }, isEdit ? 'Save changes' : 'Create project');

  const ref_ = drawer({
    title: isEdit ? 'Edit project' : 'New project',
    subtitle: isEdit ? project.code : 'Leads, reports and targets all hang off the project.',
    wide: true,
    body: h('div', null,
      errorHost,
      h('div', { class: 'form-grid' },
        field('Project name', nameInput, { required: true }),
        field('Developer / brand', developerInput, { required: true }),
        field('Location', locationInput, { required: true, hint: 'The locality buyers would search for.' }),
        field('Type', typeSelect, { required: true }),
        field('Status', statusSelect),
        field('RERA number', reraInput),
        field('City', cityInput),
        field('State', stateInput),
        field('Pincode', pincodeInput),
        field('Possession date', possessionInput)),
      field('Address', addressInput),
      h('div', { class: 'form-grid' },
        field('Price from', priceMinInput),
        field('Price to', priceMaxInput),
        field('Area from (sq ft)', areaMinInput),
        field('Area to (sq ft)', areaMaxInput)),
      field('Configurations', configInput, { hint: 'Comma separated. These become filters on the lead form.' }),
      field('Amenities', amenitiesInput, { hint: 'Comma separated.' }),
      field('Description', descriptionInput),
      h('div', { class: 'form-grid' },
        field('Site contact name', contactNameInput),
        field('Site contact phone', contactPhoneInput),
        field('Site contact email', contactEmailInput)),
      visibilitySelect
        ? field('Visibility', visibilitySelect, {
          hint: 'Global publishes this project to every client company on the platform.',
        })
        : null),
    footer: h('div', { class: 'row', style: { gap: '8px' } },
      h('button', { class: 'btn', onClick: () => ref_.close() }, 'Cancel'),
      saveButton),
  });

  const listOf = (el) => el.value.split(',').map((s) => s.trim()).filter(Boolean);
  const numOrNull = (el) => (el.value === '' ? null : Number(el.value));

  saveButton.addEventListener('click', async () => {
    const payload = {
      name: nameInput.value.trim(),
      developerName: developerInput.value.trim(),
      location: locationInput.value.trim(),
      projectType: typeSelect.value,
      status: statusSelect.value,
      city: cityInput.value.trim() || null,
      state: stateInput.value.trim() || null,
      pincode: pincodeInput.value.trim() || null,
      address: addressInput.value.trim() || null,
      reraNumber: reraInput.value.trim() || null,
      possessionDate: possessionInput.value || null,
      priceMin: numOrNull(priceMinInput),
      priceMax: numOrNull(priceMaxInput),
      areaMin: numOrNull(areaMinInput),
      areaMax: numOrNull(areaMaxInput),
      configurations: listOf(configInput),
      amenities: listOf(amenitiesInput),
      description: descriptionInput.value.trim() || null,
      contactName: contactNameInput.value.trim() || null,
      contactPhone: contactPhoneInput.value.trim() || null,
      contactEmail: contactEmailInput.value.trim() || null,
    };
    if (visibilitySelect) payload.visibility = visibilitySelect.value;

    if (!payload.name || !payload.location || !payload.developerName) {
      mount(errorHost, h('div', { class: 'alert danger mb-16' },
        'Project name, developer and location are all required.'));
      return;
    }

    saveButton.disabled = true;
    try {
      const res = isEdit
        ? await api.patch(`/api/projects/${project.id}`, payload)
        : await api.post('/api/projects', payload);
      toast.success(isEdit ? 'Project updated' : 'Project created');
      ref_.close();
      onSaved?.(res.data);
    } catch (err) {
      saveButton.disabled = false;
      mount(errorHost, h('div', { class: 'alert danger mb-16' }, err.message));
      reportError(err);
    }
  });

  return ref_;
}

export async function confirmArchiveProject(project, onDone) {
  const ok = await confirmDialog({
    title: `Delete ${project.name}?`,
    message: project.leadCount
      ? `${project.leadCount} leads are attached to this project. It will be archived rather than deleted, so their attribution survives.`
      : 'This project has no leads attached and will be permanently deleted.',
    confirmLabel: project.leadCount ? 'Archive project' : 'Delete project',
    danger: true,
  });
  if (!ok) return;
  try {
    const res = await api.del(`/api/projects/${project.id}`);
    toast.success(res.data?.archived ? 'Project archived' : 'Project deleted');
    onDone?.();
  } catch (err) {
    reportError(err);
  }
}
