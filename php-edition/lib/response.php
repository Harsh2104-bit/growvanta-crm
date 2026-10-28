<?php
declare(strict_types=1);

/**
 * Response envelope + pagination helpers. Kept byte-for-byte compatible with
 * the Node API's shape ({ data, meta } / { error }) so public/js/lib/api.js
 * needs zero changes.
 */

function json_ok($data, ?array $meta = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    $body = ['data' => $data];
    if ($meta !== null) {
        $body['meta'] = $meta;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json_created($data, ?array $meta = null): void
{
    http_response_code(201);
    json_ok($data, $meta);
}

function json_error(int $status, string $code, string $message, $details = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $error = ['code' => $code, 'message' => $message];
    if ($details !== null) {
        $error['details'] = $details;
    }
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Reads and clamps ?page=&limit= from the query string. */
function parse_paging(array $query): array
{
    $cfg = gv_config('pagination');
    $page = max(1, (int) ($query['page'] ?? 1));
    $requested = (int) ($query['limit'] ?? $cfg['default_limit']);
    $limit = min(max(1, $requested ?: $cfg['default_limit']), $cfg['max_limit']);
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function page_meta(int $page, int $limit, int $total): array
{
    $totalPages = max(1, (int) ceil($total / max(1, $limit)));
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'totalPages' => $totalPages,
        'hasNext' => $page * $limit < $total,
        'hasPrev' => $page > 1,
    ];
}

/**
 * Validates `?sort=field&dir=asc|desc` against an allow-list, exactly like the
 * Node version's parseSort — the client can never reach into an arbitrary
 * column or relation.
 */
function parse_sort(array $query, array $allowed, string $fallbackColumn, string $fallbackDir = 'DESC'): array
{
    $dir = strtoupper((string) ($query['dir'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
    $field = $query['sort'] ?? null;
    if ($field !== null && in_array($field, $allowed, true)) {
        return [to_snake_case($field), $dir];
    }
    return [$fallbackColumn, $fallbackDir];
}

/** Reads the parsed JSON request body (once). */
function request_body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $parsed = [];
    }
    $decoded = json_decode($raw, true);
    return $parsed = (is_array($decoded) ? $decoded : []);
}
