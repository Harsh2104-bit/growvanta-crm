/**
 * CSV import: upload, map columns, preview, then execute with a duplicate
 * policy. The whole flow lives in one wizard so the user never loses their file
 * between steps.
 */
import { h, mount, clear } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { api } from '../lib/api.js';
import { can, reference } from '../lib/store.js';
import {
  pageLoader, emptyState, toast, reportError, modal, field, input, select,
  dropdown, menuItem, switchToggle, dataTable,
} from '../lib/ui.js';
import { number, dateTime, relative, titleCase } from '../lib/format.js';
import { pageHead, noAccess, panel } from './shared.js';

export default async function render() {
  if (!can('imports', 'read')) return { node: noAccess('data import') };

  const body = h('div');
  const node = h('div', null,
    pageHead({
      title: 'Import & Export',
      subtitle: 'Bring leads in from a CSV, or take your data out.',
      actions: [
        h('button', {
          class: 'btn',
          onClick: () => api.download('/api/imports/template.csv', null, 'growvanta-lead-import-template.csv')
            .then(() => toast.success('Template downloaded'))
            .catch(reportError),
        }, icon('download', { size: 15 }), 'Download template'),
        can('leads', 'export')
          ? h('button', {
            class: 'btn',
            onClick: () => api.download('/api/leads/export').then(() => toast.success('Leads exported')).catch(reportError),
          }, icon('export', { size: 15 }), 'Export all leads')
          : null,
        can('imports', 'create')
          ? h('button', { class: 'btn btn-primary', onClick: startImport }, icon('upload', { size: 15 }), 'Import leads')
          : null,
      ].filter(Boolean),
    }),
    body);

  let table;
  table = dataTable({
    load: (params) => api.get('/api/imports', params),
    emptyTitle: 'No imports yet',
    emptyMessage: 'Upload a CSV to bring leads in. Every row is de-duplicated and routed like a normal lead.',
    emptyAction: can('imports', 'create')
      ? h('button', { class: 'btn btn-primary', onClick: startImport }, 'Import a file')
      : null,
    columns: [
      {
        label: 'File',
        render: (row) => h('div', null,
          h('div', { class: 'cell-main truncate' }, row.fileName),
          h('div', { class: 'cell-sub' }, `${relative(row.createdAt)}${row.createdBy ? ` · ${row.createdBy.firstName}` : ''}`)),
      },
      {
        label: 'Status',
        render: (row) => h('span', {
          class: `badge ${{ COMPLETED: 'badge-success', FAILED: 'badge-danger', RUNNING: 'badge-info' }[row.status] || ''}`,
        }, titleCase(row.status)),
      },
      { label: 'Rows', cellClass: 'right', render: (row) => number(row.totalRows) },
      { label: 'Created', cellClass: 'right', render: (row) => h('span', { class: 'text-success strong' }, number(row.createdRows)) },
      { label: 'Updated', cellClass: 'right hide-sm', render: (row) => number(row.updatedRows) },
      { label: 'Duplicates', cellClass: 'right hide-sm', render: (row) => number(row.duplicateRows) },
      {
        label: 'Errors',
        cellClass: 'right',
        render: (row) => (row.errorRows > 0
          ? h('span', { class: 'text-danger strong' }, number(row.errorRows))
          : h('span', { class: 'muted' }, '0')),
      },
      {
        label: '',
        width: '48px',
        render: (row) => dropdown(
          h('button', { class: 'icon-btn', 'aria-label': 'Import actions' }, icon('more', { size: 16 })),
          (close) => h('div', null,
            menuItem('View details', { iconName: 'eye', close, onClick: () => showDetails(row) }),
            row.errorRows > 0
              ? menuItem('Download error report', {
                iconName: 'download', close,
                onClick: () => api.download(`/api/imports/${row.id}/errors.csv`, null, `import-errors-${row.id}.csv`).catch(reportError),
              })
              : null),
        ),
      },
    ],
  });

  mount(body, table.root);

  function showDetails(job) {
    const dialog = modal({
      title: job.fileName,
      subtitle: `${dateTime(job.createdAt)} · ${titleCase(job.status)}`,
      size: 'wide',
      body: h('div', null,
        h('div', { class: 'kpi-grid mb-16' },
          stat('Total rows', number(job.totalRows)),
          stat('Created', number(job.createdRows)),
          stat('Updated', number(job.updatedRows)),
          stat('Duplicates', number(job.duplicateRows)),
          stat('Errors', number(job.errorRows))),
        h('h4', { class: 'mb-8' }, 'Column mapping'),
        h('div', { class: 'code-block mb-16' }, JSON.stringify(job.mapping, null, 2)),
        job.errors?.length
          ? h('div', null,
            h('h4', { class: 'mb-8' }, 'Row messages'),
            h('div', { class: 'table-wrap', style: { maxHeight: '280px', overflowY: 'auto' } },
              h('table', { class: 'data' },
                h('thead', null, h('tr', null, h('th', null, 'Row'), h('th', null, 'Level'), h('th', null, 'Message'))),
                h('tbody', null, ...job.errors.map((e) => h('tr', null,
                  h('td', null, String(e.row)),
                  h('td', null, h('span', { class: `badge ${e.level === 'ERROR' ? 'badge-danger' : ''}` }, e.level)),
                  h('td', null, e.message)))))))
          : null),
      footer: h('button', { class: 'btn', onClick: () => dialog.close() }, 'Close'),
    });
  }

  const stat = (label, value) => h('div', { class: 'kpi' },
    h('div', { class: 'k-label' }, label), h('div', { class: 'k-value' }, value));

  // -------------------------------------------------------------------------
  // Import wizard
  // -------------------------------------------------------------------------
  async function startImport() {
    const ref = await reference();
    const targetFields = await api.data('/api/imports/target-fields').catch(() => []);

    const fileInput = h('input', { type: 'file', accept: '.csv,text/csv', class: 'input', style: { paddingTop: '7px' } });
    const stepHost = h('div');
    let preview = null;
    const mapping = {};

    const dialog = modal({
      title: 'Import leads from CSV',
      subtitle: 'Step 1 of 2 — choose your file',
      size: 'wide',
      body: h('div', null,
        h('div', { class: 'alert info mb-16' },
          icon('info', { size: 16 }),
          h('div', null,
            h('strong', null, 'What happens to each row'),
            'Every row is de-duplicated on phone and email, scored, and routed through your assignment rules — exactly like a lead arriving from a form.')),
        field('CSV file', fileInput, { hint: 'Export from Excel or Sheets as CSV. Up to 20,000 rows.' }),
        stepHost),
      footer: h('div', { class: 'row' },
        h('button', { class: 'btn', onClick: () => dialog.close() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', id: 'importNext' }, 'Upload & preview')),
    });

    const nextButton = dialog.footer.querySelector('#importNext');

    nextButton.addEventListener('click', async () => {
      if (!preview) {
        const file = fileInput.files?.[0];
        if (!file) { toast.warn('Choose a CSV file first'); return; }
        nextButton.disabled = true;
        nextButton.textContent = 'Reading…';
        try {
          const formData = new FormData();
          formData.append('file', file);
          const res = await api.upload('/api/imports/preview', formData);
          preview = res.data;
          Object.assign(mapping, preview.suggestedMapping);
          renderMappingStep();
          nextButton.textContent = 'Import leads';
        } catch (err) {
          reportError(err);
          nextButton.textContent = 'Upload & preview';
        } finally {
          nextButton.disabled = false;
        }
        return;
      }
      await runImport();
    });

    let duplicateHandling = 'SKIP';
    let runAssignment = true;
    let defaultSourceId = ref.sources.find((s) => s.key === 'import')?.id || '';
    // Required: a spreadsheet of enquiries is always about something, and the
    // file almost never says what. The server refuses the run without it.
    let importProjectId = '';

    function renderMappingStep() {
      mount(stepHost,
        h('div', { class: 'mt-16' },
          h('div', { class: 'alert success mb-16' },
            icon('check', { size: 16 }),
            h('div', null, `${number(preview.totalRows)} rows found in ${preview.fileName}. Map each column below.`)),

          h('h4', { class: 'mb-8' }, 'Column mapping'),
          h('div', { class: 'table-wrap', style: { maxHeight: '260px', overflowY: 'auto' } },
            h('table', { class: 'data' },
              h('thead', null, h('tr', null,
                h('th', null, 'CSV column'), h('th', null, 'Sample value'), h('th', null, 'Maps to'))),
              h('tbody', null, ...preview.headers.map((header) => h('tr', null,
                h('td', { class: 'cell-main' }, header),
                h('td', { class: 'muted small truncate' }, preview.sample[0]?.[header] || '—'),
                h('td', null, select(
                  [{ value: '', label: 'Do not import' }, ...targetFields.map((f) => ({ value: f.key, label: f.label }))],
                  {
                    value: mapping[header] || '',
                    onChange: (e) => {
                      if (e.target.value) mapping[header] = e.target.value;
                      else delete mapping[header];
                    },
                  },
                ))))))),

          h('h4', { class: 'mt-16 mb-8' }, 'Options'),
          h('div', { class: 'form-grid' },
            field('When a duplicate is found', select([
              { value: 'SKIP', label: 'Skip the row (recommended)' },
              { value: 'UPDATE', label: 'Update the existing lead' },
              { value: 'IMPORT', label: 'Import it anyway' },
            ], { value: duplicateHandling, onChange: (e) => { duplicateHandling = e.target.value; } })),
            field('Default lead source', select(
              ref.sources.map((s) => ({ value: s.id, label: s.name })),
              { value: defaultSourceId, onChange: (e) => { defaultSourceId = e.target.value; } },
            ), { hint: 'Used when a row has no source of its own.' }),
            field('Project', select(
              ref.projects.map((p) => ({ value: p.id, label: p.name })),
              {
                value: importProjectId,
                placeholder: 'Which project are these enquiries about?',
                onChange: (e) => { importProjectId = e.target.value; },
              },
            ), {
              required: true,
              hint: ref.projects.length
                ? 'Applied to every row that does not name its own project.'
                : 'Add a project first - imported leads have to be attached to one.',
            })),
          switchToggle('Run the assignment rules on imported leads', runAssignment, (v) => { runAssignment = v; })));
    }

    async function runImport() {
      const mapped = Object.values(mapping);
      if (!mapped.includes('firstName')) { toast.warn('Map a column to First Name'); return; }
      if (!mapped.includes('email') && !mapped.includes('phone')) {
        toast.warn('Map a column to Email or Phone', 'Needed so duplicates can be detected.');
        return;
      }
      if (!importProjectId) {
        toast.warn('Choose a project', 'Every imported enquiry has to be attached to one.');
        return;
      }

      nextButton.disabled = true;
      nextButton.textContent = 'Importing…';
      mount(stepHost, h('div', { class: 'page-loader' },
        h('span', { class: 'spinner lg' }),
        h('span', { class: 'muted' }, `Importing ${number(preview.totalRows)} rows. This runs duplicate checks, scoring and routing for each one.`)));

      try {
        const res = await api.post('/api/imports/execute', {
          token: preview.token,
          fileName: preview.fileName,
          mapping,
          duplicateHandling,
          defaultSourceId: defaultSourceId || undefined,
          projectId: importProjectId,
          runAssignment,
        });
        const job = res.data;
        mount(stepHost, h('div', { class: 'mt-16' },
          h('div', { class: `alert ${job.errorRows ? 'warning' : 'success'} mb-16` },
            icon(job.errorRows ? 'alert' : 'check', { size: 16 }),
            h('div', null,
              h('strong', null, 'Import finished'),
              `${number(job.createdRows)} created · ${number(job.updatedRows)} updated · ${number(job.duplicateRows)} duplicates · ${number(job.errorRows)} errors`)),
          job.errors?.length
            ? h('div', { class: 'table-wrap', style: { maxHeight: '220px', overflowY: 'auto' } },
              h('table', { class: 'data' },
                h('thead', null, h('tr', null, h('th', null, 'Row'), h('th', null, 'Message'))),
                h('tbody', null, ...job.errors.slice(0, 50).map((e) => h('tr', null,
                  h('td', null, String(e.row)), h('td', null, e.message))))))
            : null));
        nextButton.textContent = 'Done';
        nextButton.disabled = false;
        nextButton.onclick = () => { dialog.close(); table.refresh(); };
        table.refresh();
        window.dispatchEvent(new CustomEvent('gv:data-changed'));
      } catch (err) {
        reportError(err);
        nextButton.disabled = false;
        nextButton.textContent = 'Import leads';
        mount(stepHost, h('div', { class: 'alert danger mt-16' }, err.message));
      }
    }
  }

  return { node };
}
