<?php
declare(strict_types=1);

/** Small shared helpers with no I/O — safe to use anywhere. */

/**
 * Generates a cuid-like unique id: lowercase, URL-safe, sortable-ish by time.
 * Kept as a string (not AUTO_INCREMENT) so it round-trips identically to the
 * Node/Prisma cuid() ids the frontend and any existing exports already expect.
 */
function gv_id(): string
{
    $time = base_convert((string) (int) (microtime(true) * 1000), 10, 36);
    $rand = bin2hex(random_bytes(8));
    return 'c' . substr(str_pad($time, 8, '0', STR_PAD_LEFT), -8) . $rand; // 1 + 8 + 16 = 25 chars
}

function digits_only(?string $v): string
{
    return preg_replace('/\D+/', '', (string) $v) ?? '';
}

/** Phone normalisation for duplicate matching: keeps the last 10 digits. */
function normalize_phone(?string $phone): ?string
{
    $d = digits_only($phone);
    if ($d === '') {
        return null;
    }
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

function normalize_email(?string $email): ?string
{
    $e = strtolower(trim((string) $email));
    return ($e !== '' && str_contains($e, '@')) ? $e : null;
}

function full_name(?string $first, ?string $last): string
{
    return trim(trim((string) $first) . ' ' . trim((string) $last));
}

function slugify(string $value): string
{
    $s = strtolower($value);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 60) : 'item';
}

/** Decodes a JSON column value; MySQL's JSON type returns a string via PDO. */
function safe_json($value, $default = [])
{
    if ($value === null) {
        return $default;
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string) $value, true);
    return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
}

function to_json($value): string
{
    return json_encode($value ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pick(array $arr, array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr)) {
            $out[$k] = $arr[$k];
        }
    }
    return $out;
}

/** Shallow field-by-field diff used for audit logs. */
function gv_diff(array $before, array $after, ?array $keys = null): array
{
    $fields = $keys ?? array_unique(array_merge(array_keys($before), array_keys($after)));
    $changes = [];
    foreach ($fields as $key) {
        $from = $before[$key] ?? null;
        $to = $after[$key] ?? null;
        if ($from === $to) {
            continue;
        }
        if (($from === null && $to === '') || ($from === '' && $to === null)) {
            continue;
        }
        $changes[$key] = ['from' => $from, 'to' => $to];
    }
    return $changes;
}

function start_of_day(?string $date = null): string
{
    return date('Y-m-d 00:00:00', $date ? strtotime($date) : time());
}
function end_of_day(?string $date = null): string
{
    return date('Y-m-d 23:59:59', $date ? strtotime($date) : time());
}
function add_days(string $date, int $days): string
{
    return date('Y-m-d H:i:s', strtotime($date) + $days * 86400);
}

function parse_date($value): ?string
{
    if (!$value) {
        return null;
    }
    $ts = strtotime((string) $value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function round2(float $n): float
{
    return round($n, 2);
}

function pct($part, $whole): float
{
    $whole = (float) $whole;
    return $whole > 0 ? round2(((float) $part / $whole) * 100) : 0.0;
}

/** camelCase <-> snake_case conversion for translating DB rows to API JSON. */
function to_camel_case(string $s): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $s))));
}
function to_snake_case(string $s): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $s));
}

/** Recursively converts DB row(s) with snake_case keys to camelCase for the API. */
function camelize($data)
{
    if (is_array($data)) {
        $isList = array_is_list($data);
        $out = [];
        foreach ($data as $k => $v) {
            $key = $isList ? $k : to_camel_case((string) $k);
            $out[$key] = camelize($v);
        }
        return $out;
    }
    return $data;
}

/** Recursively converts an API-shaped payload (camelCase) to snake_case for SQL binding. */
function decamelize($data)
{
    if (is_array($data)) {
        $isList = array_is_list($data);
        $out = [];
        foreach ($data as $k => $v) {
            $key = $isList ? $k : to_snake_case((string) $k);
            $out[$key] = decamelize($v);
        }
        return $out;
    }
    return $data;
}

function chunk_array(array $arr, int $size): array
{
    return array_chunk($arr, max(1, $size));
}

/** True/false out of a MySQL TINYINT(1), which PDO returns as string "0"/"1". */
function gv_bool($value): bool
{
    return $value === true || $value === 1 || $value === '1';
}
