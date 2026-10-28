<?php
declare(strict_types=1);

/**
 * /api/imports/* — CSV lead import, ported from src/routes/imports.js.
 *
 * Two-phase by design: `preview` stages the file and proposes a column mapping,
 * `execute` commits it. Staging on disk (rather than round-tripping the parsed
 * rows through the browser) keeps a 20k-row file out of the client's memory and
 * makes the mapping step cheap to iterate on.
 */

const IMPORT_TARGET_FIELDS = [
    ['key' => 'firstName', 'label' => 'First Name', 'required' => true, 'aliases' => ['first name', 'firstname', 'name', 'fname', 'lead name']],
    ['key' => 'lastName', 'label' => 'Last Name', 'aliases' => ['last name', 'lastname', 'surname', 'lname']],
    ['key' => 'email', 'label' => 'Email', 'aliases' => ['email', 'e-mail', 'email address', 'mail']],
    ['key' => 'phone', 'label' => 'Phone', 'aliases' => ['phone', 'mobile', 'phone number', 'contact', 'contact number', 'mobile number']],
    ['key' => 'altPhone', 'label' => 'Alternate Phone', 'aliases' => ['alternate phone', 'phone 2', 'secondary phone']],
    ['key' => 'company', 'label' => 'Company', 'aliases' => ['company', 'organisation', 'organization', 'company name', 'business']],
    ['key' => 'jobTitle', 'label' => 'Job Title', 'aliases' => ['job title', 'designation', 'title', 'role']],
    ['key' => 'website', 'label' => 'Website', 'aliases' => ['website', 'url', 'web']],
    ['key' => 'country', 'label' => 'Country', 'aliases' => ['country']],
    ['key' => 'state', 'label' => 'State', 'aliases' => ['state', 'province', 'region']],
    ['key' => 'city', 'label' => 'City', 'aliases' => ['city', 'town']],
    ['key' => 'address', 'label' => 'Address', 'aliases' => ['address', 'street']],
    ['key' => 'pincode', 'label' => 'Pincode', 'aliases' => ['pincode', 'pin code', 'zip', 'zipcode', 'postal code']],
    ['key' => 'sourceId', 'label' => 'Lead Source', 'aliases' => ['source', 'lead source']],
    ['key' => 'campaignId', 'label' => 'Campaign', 'aliases' => ['campaign', 'campaign code']],
    ['key' => 'statusId', 'label' => 'Lead Status', 'aliases' => ['status', 'lead status']],
    ['key' => 'priority', 'label' => 'Priority', 'aliases' => ['priority']],
    ['key' => 'expectedValue', 'label' => 'Expected Value', 'aliases' => ['expected value', 'value', 'budget', 'deal value']],
    ['key' => 'notes', 'label' => 'Notes', 'aliases' => ['notes', 'note', 'comments', 'message', 'remarks']],
    ['key' => 'medium', 'label' => 'Medium', 'aliases' => ['medium']],
    ['key' => 'utmSource', 'label' => 'UTM Source', 'aliases' => ['utm source', 'utm_source']],
    ['key' => 'utmCampaign', 'label' => 'UTM Campaign', 'aliases' => ['utm campaign', 'utm_campaign']],
    ['key' => 'tags', 'label' => 'Tags (semicolon separated)', 'aliases' => ['tags', 'tag']],
];

function imports_suggest_mapping(array $headers): array
{
    $mapping = [];
    $used = [];
    foreach ($headers as $header) {
        $norm = strtolower(trim($header));
        foreach (IMPORT_TARGET_FIELDS as $f) {
            if (isset($used[$f['key']])) {
                continue;
            }
            if (strtolower($f['key']) === $norm || in_array($norm, $f['aliases'] ?? [], true)) {
                $mapping[$header] = $f['key'];
                $used[$f['key']] = true;
                break;
            }
        }
    }
    return $mapping;
}

function imports_staging_dir(): string
{
    $dir = gv_config('uploads.dir') . '/imports';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function imports_shape(array $r): array
{
    $out = camelize($r);
    $out['mapping'] = safe_json($r['mapping'] ?? '{}', []);
    $out['errors'] = safe_json($r['errors'] ?? '[]', []);
    return $out;
}

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$body = request_body();
$query = $_GET;
$a = actor();
$orgId = $a['organizationId'];

if ($method === 'GET' && $id === 'target-fields') {
    require_permission('imports', 'read');
    $customFields = db_all("SELECT `key`, label FROM custom_fields WHERE organization_id = ? AND entity = 'LEAD' AND is_active = 1", [$orgId]);
    $out = array_map(fn($f) => ['key' => $f['key'], 'label' => $f['label'], 'required' => !empty($f['required'])], IMPORT_TARGET_FIELDS);
    foreach ($customFields as $f) {
        $out[] = ['key' => 'customFields.' . $f['key'], 'label' => $f['label'] . ' (custom)', 'required' => false];
    }
    json_ok($out);
} elseif ($method === 'GET' && $id === 'template.csv') {
    require_permission('imports', 'read');
    $sample = [[
        'firstName' => 'Aarav', 'lastName' => 'Sharma', 'email' => 'aarav.sharma@example.com', 'phone' => '9876543210',
        'company' => 'Northwind Traders', 'jobTitle' => 'Procurement Head', 'city' => 'Gurugram', 'state' => 'Haryana',
        'country' => 'India', 'pincode' => '122001', 'sourceId' => 'google_ads', 'priority' => 'HIGH',
        'expectedValue' => '450000', 'notes' => 'Asked for a callback on Monday', 'tags' => 'enterprise;warm',
    ]];
    $csv = to_csv($sample, array_map(fn($f) => ['key' => $f['key'], 'label' => $f['label']], IMPORT_TARGET_FIELDS));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="growvanta-lead-import-template.csv"');
    echo $csv;
} elseif ($method === 'POST' && $id === 'preview') {
    require_permission('imports', 'create');
    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw bad_request('Attach a CSV file');
    }
    $name = (string) ($file['name'] ?? 'import.csv');
    $mime = (string) ($file['type'] ?? '');
    if (!preg_match('/\.csv$/i', $name) && !in_array($mime, ['text/csv', 'application/vnd.ms-excel', 'text/plain', 'application/csv'], true)) {
        throw bad_request('Upload a .csv file. Export from Excel or Sheets as CSV first.');
    }
    $text = file_get_contents($file['tmp_name']);
    if ($text === false) {
        throw bad_request('That file could not be read.');
    }
    ['headers' => $headers, 'records' => $records] = parse_csv_objects($text, 20000);
    if (!$headers) {
        throw bad_request('That file has no header row.');
    }
    if (!$records) {
        throw bad_request('That file has a header but no data rows.');
    }

    $token = bin2hex(random_bytes(16));
    file_put_contents(imports_staging_dir() . "/$token.csv", $text);

    json_ok([
        'token' => $token, 'fileName' => $name, 'headers' => $headers, 'totalRows' => count($records),
        'sample' => array_slice($records, 0, 10), 'suggestedMapping' => imports_suggest_mapping($headers),
    ]);
} elseif ($method === 'POST' && $id === 'execute') {
    require_permission('imports', 'create');
    $token = $body['token'] ?? '';
    $mapping = $body['mapping'] ?? null;
    $duplicateHandling = $body['duplicateHandling'] ?? 'SKIP';
    $defaultSourceId = $body['defaultSourceId'] ?? null;
    $defaultOwnerId = $body['defaultOwnerId'] ?? null;
    $runAssignment = array_key_exists('runAssignment', $body) ? bool_val($body['runAssignment']) : true;

    // A spreadsheet of enquiries is always a spreadsheet about something, and
    // the file rarely names it. Required for the run, applied to every row, and
    // recorded on the job so the import history says what was attributed.
    if (empty($body['projectId'])) {
        throw bad_request('Choose the project these leads belong to before importing.');
    }
    $vis = project_visible_where($orgId);
    $importProjectId = db_scalar(
        "SELECT projects.id FROM projects WHERE projects.id = ? AND {$vis['sql']}",
        array_merge([$body['projectId']], $vis['params'])
    );
    if (!$importProjectId) {
        throw bad_request('That project is not available to your company.');
    }

    if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw bad_request('Upload the file again - the staging token is missing or invalid.');
    }
    if (!is_array($mapping) || !$mapping) {
        throw bad_request('Provide a column mapping.');
    }
    $staged = imports_staging_dir() . "/$token.csv";
    if (!is_file($staged)) {
        throw bad_request('That upload has expired. Please upload the file again.');
    }
    $mapped = array_values($mapping);
    if (!in_array('firstName', $mapped, true)) {
        throw bad_request('Map one column to First Name.');
    }
    if (!in_array('email', $mapped, true) && !in_array('phone', $mapped, true)) {
        throw bad_request('Map a column to Email or Phone so leads can be de-duplicated.');
    }
    if (!in_array($duplicateHandling, ['SKIP', 'IMPORT', 'UPDATE'], true)) {
        throw bad_request('duplicateHandling must be SKIP, IMPORT or UPDATE');
    }

    ['records' => $records] = parse_csv_objects((string) file_get_contents($staged), 20000);

    $jobId = gv_id();
    db_exec(
        "INSERT INTO import_jobs (id, organization_id, entity, project_id, file_name, total_rows, status, mapping, errors, created_by_id, created_at)
         VALUES (?,?,'LEAD',?,?,?,'RUNNING',?,'[]',?,NOW())",
        [$jobId, $orgId, $importProjectId, $body['fileName'] ?? 'import.csv', count($records), to_json($mapping), $a['id']]
    );

    $errors = [];
    $createdRows = 0;
    $updatedRows = 0;
    $duplicateRows = 0;

    foreach ($records as $index => $record) {
        $rowNumber = $index + 2; // +1 for header, +1 for 1-based rows
        $input = ['customFields' => []];
        foreach ($mapping as $column => $target) {
            if (!$target) {
                continue;
            }
            $value = trim((string) ($record[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            if (str_starts_with($target, 'customFields.')) {
                $input['customFields'][substr($target, 13)] = $value;
            } elseif ($target === 'tags') {
                $input['tags'] = array_values(array_filter(array_map('trim', explode(';', $value))));
            } else {
                $input[$target] = $value;
            }
        }

        try {
            if (empty($input['firstName'])) {
                throw new RuntimeException('First name is empty');
            }
            if (empty($input['email']) && empty($input['phone'])) {
                throw new RuntimeException('Neither email nor phone is present');
            }
            if (empty($input['sourceId']) && $defaultSourceId) {
                $input['sourceId'] = $defaultSourceId;
            }
            if (empty($input['sourceId'])) {
                $input['sourceId'] = 'import';
            }

            $duplicates = find_duplicates($orgId, $input, null, 1);
            if ($duplicates) {
                $duplicateRows++;
                if ($duplicateHandling === 'SKIP') {
                    $errors[] = ['row' => $rowNumber, 'message' => "Duplicate of {$duplicates[0]['code']}", 'level' => 'INFO'];
                    continue;
                }
                if ($duplicateHandling === 'UPDATE') {
                    $patch = [];
                    foreach (['lastName' => 'last_name', 'email' => 'email', 'phone' => 'phone', 'company' => 'company', 'jobTitle' => 'job_title',
                        'city' => 'city', 'state' => 'state', 'country' => 'country', 'pincode' => 'pincode', 'notes' => 'notes'] as $camel => $col) {
                        if (!empty($input[$camel])) {
                            $patch[$col] = $input[$camel];
                        }
                    }
                    if (isset($patch['email'])) {
                        $patch['email_norm'] = normalize_email($patch['email']);
                    }
                    if (isset($patch['phone'])) {
                        $patch['phone_norm'] = normalize_phone($patch['phone']);
                    }
                    if ($patch) {
                        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($patch)));
                        db_exec("UPDATE leads SET $sets, updated_at = NOW() WHERE id = ?", array_merge(array_values($patch), [$duplicates[0]['id']]));
                    }
                    $updatedRows++;
                    continue;
                }
            }

            // The run's project wins unless the file itself mapped one, so a
            // spreadsheet that does name a project per row is still honoured.
            if (empty($input['projectId']) && empty($input['project'])) {
                $input['projectId'] = $importProjectId;
            }

            create_lead($input, [
                'organizationId' => $orgId, 'actor' => $a, 'origin' => 'IMPORT', 'duplicatePolicy' => 'ALLOW',
                'skipAssignment' => !$runAssignment, 'ownerId' => $defaultOwnerId ?: null,
            ]);
            $createdRows++;
        } catch (Throwable $e) {
            $errors[] = ['row' => $rowNumber, 'message' => substr($e->getMessage(), 0, 200) ?: 'Unknown error', 'level' => 'ERROR'];
        }
    }

    $errorRows = count(array_filter($errors, fn($e) => $e['level'] === 'ERROR'));
    db_exec(
        "UPDATE import_jobs SET status = 'COMPLETED', created_rows = ?, updated_rows = ?, duplicate_rows = ?, error_rows = ?, errors = ?, finished_at = NOW() WHERE id = ?",
        [$createdRows, $updatedRows, $duplicateRows, $errorRows, to_json(array_slice($errors, 0, 500)), $jobId]
    );
    @unlink($staged);

    $finished = db_one('SELECT * FROM import_jobs WHERE id = ?', [$jobId]);
    audit_record($orgId, $a, 'lead.imported', [
        'entityType' => 'IMPORT_JOB', 'entityId' => $jobId, 'entityLabel' => $finished['file_name'],
        'changes' => ['createdRows' => $createdRows, 'updatedRows' => $updatedRows, 'duplicateRows' => $duplicateRows, 'errorRows' => $errorRows],
    ]);
    notify_user($orgId, $a['id'], 'IMPORT_COMPLETE', "Import finished: $createdRows lead(s) created", "$duplicateRows duplicate(s), $errorRows error(s)", 'IMPORT_JOB', $jobId, '#/imports');

    json_created(array_merge(imports_shape($finished), ['errors' => array_slice($errors, 0, 100)]));
} elseif ($method === 'GET' && $id === null) {
    require_permission('imports', 'read');
    ['page' => $page, 'limit' => $limit, 'offset' => $offset] = parse_paging($query);
    $rows = db_all(
        'SELECT j.*, u.first_name AS cb_first_name, u.last_name AS cb_last_name
         FROM import_jobs j LEFT JOIN users u ON u.id = j.created_by_id WHERE j.organization_id = ? ORDER BY j.created_at DESC LIMIT ? OFFSET ?',
        [$orgId, $limit, $offset]
    );
    $total = (int) db_scalar('SELECT COUNT(*) FROM import_jobs WHERE organization_id = ?', [$orgId]);
    json_ok(array_map(function ($r) {
        $out = imports_shape($r);
        unset($out['cbFirstName'], $out['cbLastName']);
        $out['createdBy'] = $r['created_by_id'] ? ['firstName' => $r['cb_first_name'], 'lastName' => $r['cb_last_name']] : null;
        return $out;
    }, $rows), page_meta($page, $limit, $total));
} elseif ($method === 'GET' && $id && $sub === 'errors.csv') {
    require_permission('imports', 'read');
    $job = db_one('SELECT * FROM import_jobs WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$job) {
        throw not_found('Import job not found');
    }
    $errors = safe_json($job['errors'] ?? '[]', []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import-errors-' . $job['id'] . '.csv"');
    echo to_csv($errors, [['key' => 'row', 'label' => 'Row'], ['key' => 'level', 'label' => 'Level'], ['key' => 'message', 'label' => 'Message']]);
} elseif ($method === 'GET' && $id) {
    require_permission('imports', 'read');
    $job = db_one(
        'SELECT j.*, u.first_name AS cb_first_name, u.last_name AS cb_last_name
         FROM import_jobs j LEFT JOIN users u ON u.id = j.created_by_id WHERE j.id = ? AND j.organization_id = ?',
        [$id, $orgId]
    );
    if (!$job) {
        throw not_found('Import job not found');
    }
    $out = imports_shape($job);
    unset($out['cbFirstName'], $out['cbLastName']);
    $out['createdBy'] = $job['created_by_id'] ? ['firstName' => $job['cb_first_name'], 'lastName' => $job['cb_last_name']] : null;
    json_ok($out);
} else {
    throw not_found('Unknown imports route');
}
