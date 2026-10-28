<?php
declare(strict_types=1);

/**
 * /api/documents/* — ported from src/routes/documents.js (multer replaced by
 * PHP's own $_FILES handling).
 *
 * Uploads are written with a generated name and never with the client's
 * filename, so a crafted name cannot traverse out of the upload directory or
 * land as an executable. The original name is kept in the database for display
 * and used only in the Content-Disposition header on download.
 */

const DOCUMENT_RELATIONS = ['leadId' => 'lead_id', 'contactId' => 'contact_id', 'accountId' => 'account_id', 'opportunityId' => 'opportunity_id'];

$id = $rest[0] ?? null;
$sub = $rest[1] ?? null;
$a = actor();
$orgId = $a['organizationId'];
$uploadDir = gv_config('uploads.dir');

if ($method === 'POST' && $id === null) {
    require_permission('documents', 'create');

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw bad_request('Attach a file');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw bad_request($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'That file is larger than the upload limit.'
            : 'The upload did not complete. Please try again.');
    }
    if ($file['size'] > (int) gv_config('uploads.max_bytes')) {
        throw bad_request('That file is larger than the upload limit.');
    }

    // Trust the sniffed type, not the browser-supplied one.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!in_array($mime, gv_config('uploads.allowed_mime'), true)) {
        throw bad_request("That file type ($mime) is not allowed.");
    }

    $links = [];
    foreach (DOCUMENT_RELATIONS as $camel => $col) {
        if (!empty($_POST[$camel])) {
            $links[$col] = $_POST[$camel];
        }
    }
    if (!$links) {
        throw bad_request('Attach the document to a lead, contact, account or opportunity.');
    }
    // Verify the parent exists in this organisation before keeping the file.
    $tables = ['lead_id' => 'leads', 'contact_id' => 'contacts', 'account_id' => 'accounts', 'opportunity_id' => 'opportunities'];
    foreach ($links as $col => $value) {
        if (!db_one("SELECT id FROM {$tables[$col]} WHERE id = ? AND organization_id = ?", [$value, $orgId])) {
            throw not_found('The record this document belongs to was not found.');
        }
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $original = substr((string) $file['name'], 0, 200);
    $ext = strtolower(substr((string) pathinfo($original, PATHINFO_EXTENSION), 0, 10));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $storedName = time() . '-' . bin2hex(random_bytes(8)) . ($ext ? ".$ext" : '');
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $storedName)) {
        throw bad_request('The upload could not be saved.');
    }

    $docId = gv_id();
    $columns = array_merge(['id' => $docId, 'organization_id' => $orgId, 'file_name' => $original, 'stored_name' => $storedName,
        'mime_type' => $mime, 'size_bytes' => (int) $file['size'], 'uploaded_by_id' => $a['id']], $links);
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($columns)));
    $ph = implode(',', array_fill(0, count($columns), '?'));
    db_exec("INSERT INTO documents ($cols, created_at) VALUES ($ph, NOW())", array_values($columns));

    db_exec(
        'INSERT INTO activities (id, organization_id, type, subject, lead_id, contact_id, account_id, opportunity_id, user_id, occurred_at, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [gv_id(), $orgId, 'DOCUMENT', "Uploaded $original", $links['lead_id'] ?? null, $links['contact_id'] ?? null, $links['account_id'] ?? null, $links['opportunity_id'] ?? null, $a['id']]
    );
    audit_record($orgId, $a, 'document.uploaded', ['entityType' => 'DOCUMENT', 'entityId' => $docId, 'entityLabel' => $original]);

    $doc = db_one('SELECT * FROM documents WHERE id = ?', [$docId]);
    json_created(array_merge(camelize($doc), ['uploadedBy' => ['id' => $a['id'], 'firstName' => $a['firstName'], 'lastName' => $a['lastName']]]));
} elseif ($method === 'GET' && $id && $sub === 'download') {
    require_permission('documents', 'read');
    $doc = db_one('SELECT * FROM documents WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$doc) {
        throw not_found('Document not found');
    }
    // Re-join against the stored name, never against anything from the request.
    $filePath = $uploadDir . '/' . basename($doc['stored_name']);
    if (!is_file($filePath)) {
        throw not_found('That file is no longer on disk.');
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($doc['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
} elseif ($method === 'DELETE' && $id) {
    require_permission('documents', 'delete');
    $scope = scope_info();
    $doc = db_one('SELECT * FROM documents WHERE id = ? AND organization_id = ?', [$id, $orgId]);
    if (!$doc) {
        throw not_found('Document not found');
    }
    if ($doc['uploaded_by_id'] !== $a['id'] && !$scope['unrestricted'] && !in_array($doc['uploaded_by_id'], $scope['userIds'], true)) {
        throw forbidden('You can only remove documents you or your team uploaded.');
    }
    db_exec('DELETE FROM documents WHERE id = ?', [$doc['id']]);
    $filePath = $uploadDir . '/' . basename($doc['stored_name']);
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    audit_record($orgId, $a, 'document.deleted', ['entityType' => 'DOCUMENT', 'entityId' => $doc['id'], 'entityLabel' => $doc['file_name']]);
    json_ok(['deleted' => true]);
} else {
    throw not_found('Unknown documents route');
}
