/**
 * Project detail: the brochure a salesperson reads out on a call, plus the
 * gallery, plus - for a platform admin - who the project is shared with.
 *
 * The lead list at the bottom is deliberately scoped to the signed-in company
 * even when the project itself is shared platform-wide.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, store } from '../lib/store.js';
import {
  toast, reportError, emptyState, pageLoader, dropdown, menuItem,
  confirmDialog, dataTable,
} from '../lib/ui.js';
import { money, date, titleCase, number, dateTime } from '../lib/format.js';
import { pageHead, noAccess, panel, statusBadge, ownerCell } from './shared.js';
import { navigate } from '../router.js';
import { openProjectForm, confirmArchiveProject, priceRange, projectThumb } from './projects.js';

export default async function render({ params }) {
  if (!can('projects', 'read')) return { node: noAccess('projects') };

  const node = h('div', null, pageLoader('Loading project'));

  async function load() {
    let project;
    try {
      project = (await api.get(`/api/projects/${params.id}`)).data;
    } catch (err) {
      mount(node, h('div', { class: 'card' }, emptyState({
        iconName: 'building',
        title: 'Project not available',
        message: err.message,
        action: h('a', { class: 'btn btn-primary', href: '#/projects' }, 'Back to projects'),
      })));
      return;
    }

    clear(node);
    node.appendChild(pageHead({
      title: project.name,
      subtitle: `${project.code} · ${project.developerName} · ${project.location}`,
      breadcrumbs: [{ label: 'Projects', href: '#/projects' }, { label: project.name }],
      actions: [
        // A shared project is read-only here; saying so is friendlier than
        // showing an Edit button that 403s.
        !project.isOwner
          ? h('span', {
            class: project.canEdit ? 'badge badge-warning' : 'badge badge-info',
            title: project.canEdit ? 'You are editing another company’s project' : undefined,
          }, `${project.canEdit ? 'Owned by' : 'Shared by'} ${project.ownerOrgName}`)
          : null,
        project.canEdit && can('projects', 'update')
          ? h('button', {
            class: 'btn',
            onClick: () => openProjectForm({ project, onSaved: load }),
          }, icon('edit', { size: 15 }), 'Edit')
          : null,
        project.canEdit && can('projects', 'delete')
          ? dropdown(
            h('button', { class: 'icon-btn', 'aria-label': 'More actions' }, icon('more', { size: 16 })),
            (close) => h('div', null,
              menuItem('Delete project', {
                iconName: 'trash', danger: true, close,
                onClick: () => confirmArchiveProject(project, () => navigate('/projects')),
              })),
          )
          : null,
      ].filter(Boolean),
    }));

    const layout = h('div', { class: 'detail-layout' });
    layout.appendChild(h('div', null,
      gallerySection(project, load),
      overviewSection(project),
      leadsSection(project)));
    layout.appendChild(h('div', null,
      factsSection(project),
      project.contactName || project.contactPhone ? contactSection(project) : null,
      store.user?.isPlatformAdmin ? sharingSection(project, load) : null));
    node.appendChild(layout);
  }

  await load();
  return { node };
}

// ---------------------------------------------------------------------------
function gallerySection(project, reload) {
  const canManage = project.canEdit && can('projects', 'manage');
  const host = h('div', { class: 'row wrap', style: { gap: '10px' } });

  const paint = () => {
    clear(host);
    if (!project.images?.length) {
      host.appendChild(h('div', { class: 'muted small' }, 'No images uploaded yet.'));
    }
    for (const img of project.images || []) {
      host.appendChild(h('div', {
        style: { position: 'relative', width: '132px' },
      },
      h('img', {
        src: img.url,
        alt: img.caption || '',
        loading: 'lazy',
        style: {
          width: '132px', height: '92px', objectFit: 'cover',
          borderRadius: '8px', border: img.isCover ? '2px solid var(--brand-500)' : '1px solid var(--line)',
        },
      }),
      img.isCover ? h('span', {
        class: 'badge badge-brand',
        style: { position: 'absolute', top: '6px', left: '6px' },
      }, 'Cover') : null,
      canManage
        ? h('div', { class: 'row', style: { gap: '4px', marginTop: '4px' } },
          !img.isCover
            ? h('button', {
              class: 'btn btn-sm',
              onClick: async () => {
                try {
                  await api.patch(`/api/projects/${project.id}/images/${img.id}`, { isCover: true });
                  toast.success('Cover image updated');
                  reload();
                } catch (err) { reportError(err); }
              },
            }, 'Set cover')
            : null,
          h('button', {
            class: 'btn btn-sm btn-danger',
            onClick: async () => {
              const ok = await confirmDialog({
                title: 'Delete this image?', confirmLabel: 'Delete', danger: true,
              });
              if (!ok) return;
              try {
                await api.del(`/api/projects/${project.id}/images/${img.id}`);
                toast.success('Image deleted');
                reload();
              } catch (err) { reportError(err); }
            },
          }, icon('trash', { size: 13 })))
        : null));
    }
  };
  paint();

  const fileInput = h('input', {
    type: 'file',
    accept: 'image/jpeg,image/png,image/webp',
    multiple: true,
    style: { display: 'none' },
    onChange: async (e) => {
      const files = [...e.target.files];
      e.target.value = '';
      for (const file of files) {
        const form = new FormData();
        form.append('file', file);
        try {
          await api.upload(`/api/projects/${project.id}/images`, form);
        } catch (err) {
          reportError(err);
          break;
        }
      }
      if (files.length) {
        toast.success(files.length === 1 ? 'Image uploaded' : `${files.length} images uploaded`);
        reload();
      }
    },
  });

  return panel('Gallery', h('div', null, host, fileInput), {
    subtitle: canManage ? 'JPG, PNG or WEBP.' : undefined,
    action: canManage
      ? h('button', { class: 'btn btn-sm', onClick: () => fileInput.click() },
        icon('upload', { size: 14 }), 'Upload')
      : null,
  });
}

function overviewSection(project) {
  const chips = (items) => (items?.length
    ? h('div', { class: 'row wrap', style: { gap: '6px' } },
      ...items.map((c) => h('span', { class: 'chip' }, c)))
    : h('span', { class: 'muted small' }, '—'));

  return panel('Overview', h('div', null,
    project.description
      ? h('p', { style: { marginTop: 0, whiteSpace: 'pre-wrap' } }, project.description)
      : h('p', { class: 'muted small', style: { marginTop: 0 } }, 'No description added.'),
    h('div', { class: 'field' }, h('label', null, 'Configurations'), chips(project.configurations)),
    h('div', { class: 'field' }, h('label', null, 'Amenities'), chips(project.amenities))));
}

function factsSection(project) {
  const rows = [
    ['Type', titleCase(project.projectType)],
    ['Status', titleCase(project.status)],
    ['Price range', priceRange(project)],
    ['Area', project.areaMin || project.areaMax
      ? `${number(project.areaMin || 0)} – ${number(project.areaMax || 0)} ${project.areaUnit || 'SQFT'}`
      : '—'],
    ['Possession', project.possessionDate ? date(project.possessionDate) : '—'],
    ['RERA', project.reraNumber || '—'],
    ['City', project.city || '—'],
    ['State', project.state || '—'],
    ['Pincode', project.pincode || '—'],
    ['Address', project.address || '—'],
    ['Our leads', number(project.leadCount)],
    ['Created', dateTime(project.createdAt)],
  ];
  return panel('Details', h('div', { class: 'info-list' },
    ...rows.map(([label, value]) => h('div', { class: 'info-row' },
      h('span', { class: 'muted' }, label),
      h('span', { class: 'strong' }, String(value))))));
}

function contactSection(project) {
  return panel('Site contact', h('div', { class: 'info-list' },
    project.contactName
      ? h('div', { class: 'info-row' }, h('span', { class: 'muted' }, 'Name'), h('span', null, project.contactName))
      : null,
    project.contactPhone
      ? h('div', { class: 'info-row' },
        h('span', { class: 'muted' }, 'Phone'),
        h('a', { href: `tel:${project.contactPhone}` }, project.contactPhone))
      : null,
    project.contactEmail
      ? h('div', { class: 'info-row' },
        h('span', { class: 'muted' }, 'Email'),
        h('a', { href: `mailto:${project.contactEmail}` }, project.contactEmail))
      : null));
}

/**
 * Platform-only. This is the control behind the broker model: a global project
 * every client can sell, or a shortlist of named companies.
 */
function sharingSection(project, reload) {
  const host = h('div');

  const paint = async () => {
    mount(host, h('div', { class: 'muted small' }, 'Loading companies…'));
    try {
      const clients = (await api.get('/api/platform/clients', { limit: 200 })).data || [];
      const shared = new Set(project.sharedWith || []);
      const boxes = clients.map((c) => {
        const cb = h('input', {
          type: 'checkbox',
          checked: shared.has(c.id),
          onChange: (e) => (e.target.checked ? shared.add(c.id) : shared.delete(c.id)),
        });
        return h('label', { class: 'checkbox' }, cb,
          h('span', null, c.name, h('span', { class: 'muted tiny' }, ` · ${titleCase(c.companyType)}`)));
      });

      const isGlobal = project.visibility === 'GLOBAL';
      clear(host);
      host.appendChild(h('div', { class: 'alert info mb-16' },
        isGlobal
          ? 'This project is GLOBAL: every client company can sell it. Their leads stay private to them.'
          : 'Pick the companies that may sell this project. Their leads stay private to each of them.'));

      if (!isGlobal) {
        host.appendChild(h('div', { style: { maxHeight: '260px', overflowY: 'auto' } }, ...boxes));
        host.appendChild(h('button', {
          class: 'btn btn-primary btn-sm mt-8',
          onClick: async (e) => {
            e.target.disabled = true;
            try {
              await api.put(`/api/projects/${project.id}/access`, { organizationIds: [...shared] });
              toast.success('Sharing updated');
              reload();
            } catch (err) {
              e.target.disabled = false;
              reportError(err);
            }
          },
        }, 'Save sharing'));
      }
    } catch (err) {
      mount(host, h('div', { class: 'alert danger' }, err.message));
    }
  };
  paint();

  return panel('Shared with', host, { subtitle: 'Platform administrators only.' });
}

function leadsSection(project) {
  if (!can('leads', 'read')) return null;
  const table = dataTable({
    pageSize: 10,
    onRowClick: (row) => navigate(`/leads/${row.id}`),
    load: (p) => api.get('/api/leads', { ...p, projectId: project.id }),
    emptyTitle: 'No leads on this project yet',
    emptyMessage: 'Leads captured against this project will appear here.',
    columns: [
      {
        label: 'Lead',
        render: (row) => h('div', null,
          h('div', { class: 'cell-main' }, `${row.firstName} ${row.lastName || ''}`.trim()),
          h('div', { class: 'cell-sub' }, row.code)),
      },
      { label: 'Phone', render: (row) => row.phone || '—' },
      {
        label: 'Status',
        render: (row) => statusBadge(row.status?.name, row.status?.color),
      },
      { label: 'Owner', render: (row) => ownerCell(row.owner) },
      { label: 'Created', cellClass: 'hide-sm', render: (row) => date(row.createdAt) },
    ],
  });
  return panel('Leads from this project', table.root, {
    subtitle: 'Your company only.',
    flush: true,
  });
}
