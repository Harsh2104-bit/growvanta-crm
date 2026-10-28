<?php
declare(strict_types=1);

/**
 * Append-only audit trail. Failures are logged and swallowed — an audit write
 * must never break the business operation that triggered it, but it must also
 * never fail silently server-side.
 */
function audit_record(string $orgId, ?array $actor, string $action, array $opts = []): void
{
    try {
        db_exec(
            'INSERT INTO audit_logs (id, organization_id, user_id, actor_label, action, entity_type, entity_id, entity_label, changes, ip, user_agent, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                gv_id(),
                $orgId,
                $actor['id'] ?? null,
                $actor ? ($actor['firstName'] . ' ' . $actor['lastName'] . ' <' . $actor['email'] . '>')
                    : (isset($GLOBALS['GV_API_KEY']) ? 'API key ' . $GLOBALS['GV_API_KEY']['prefix'] : 'system'),
                $action,
                $opts['entityType'] ?? null,
                $opts['entityId'] ?? null,
                $opts['entityLabel'] ?? null,
                to_json($opts['changes'] ?? []),
                client_ip(),
                user_agent(),
            ]
        );
    } catch (Throwable $e) {
        error_log('[audit] failed to record ' . $action . ': ' . $e->getMessage());
    }
}

/**
 * Records a data export (spec section 18): who took what out, under which
 * filters, and at what visibility scope. Written before the file is streamed,
 * so an export that dies mid-download still leaves a trace.
 */
function export_record(string $orgId, ?array $actor, string $entity, string $format, int $rowCount, array $filters = []): void
{
    try {
        // Paging and sort parameters say nothing about which data left the
        // building, and keeping them makes the audit row harder to read.
        unset($filters['page'], $filters['limit'], $filters['sort'], $filters['order'], $filters['_path']);
        db_exec(
            'INSERT INTO export_jobs (id, organization_id, user_id, actor_label, entity, format, row_count, filters, data_scope, ip, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [
                gv_id(), $orgId, $actor['id'] ?? null,
                $actor ? ($actor['firstName'] . ' ' . $actor['lastName'] . ' <' . $actor['email'] . '>') : 'system',
                $entity, $format, $rowCount, to_json($filters),
                $actor['role']['dataScope'] ?? null, client_ip(),
            ]
        );
        audit_record($orgId, $actor, 'data.exported', [
            'entityType' => 'EXPORT', 'entityLabel' => $entity,
            'changes' => ['format' => $format, 'rowCount' => $rowCount, 'filters' => $filters],
        ]);
    } catch (Throwable $e) {
        error_log('[audit] export record failed: ' . $e->getMessage());
    }
}
