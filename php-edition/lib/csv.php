<?php
declare(strict_types=1);

/** CSV parsing/serialising — state-machine parser so quoted fields, escaped
 * quotes and embedded newlines from a real Excel export round-trip correctly. */

function parse_csv(string $text, int $maxRows = 50000): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // strip BOM
    $rows = [];
    $field = '';
    $row = [];
    $inQuotes = false;
    $len = strlen($text);

    for ($i = 0; $i < $len; $i++) {
        $char = $text[$i];
        if ($inQuotes) {
            if ($char === '"') {
                if (($text[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } else {
                    $inQuotes = false;
                }
            } else {
                $field .= $char;
            }
            continue;
        }
        if ($char === '"') {
            $inQuotes = true;
            continue;
        }
        if ($char === ',') {
            $row[] = $field;
            $field = '';
            continue;
        }
        if ($char === "\r") {
            continue;
        }
        if ($char === "\n") {
            $row[] = $field;
            $rows[] = $row;
            $field = '';
            $row = [];
            if (count($rows) > $maxRows) {
                throw bad_request("CSV exceeds the $maxRows row limit");
            }
            continue;
        }
        $field .= $char;
    }
    if ($field !== '' || $row) {
        $row[] = $field;
        $rows[] = $row;
    }
    while ($rows && count(array_filter($rows[count($rows) - 1], fn($c) => trim($c) !== '')) === 0) {
        array_pop($rows);
    }
    return $rows;
}

function parse_csv_objects(string $text, int $maxRows = 50000): array
{
    $rows = parse_csv($text, $maxRows);
    if (!$rows) {
        return ['headers' => [], 'records' => []];
    }
    $headers = array_map('trim', $rows[0]);
    $records = [];
    for ($i = 1; $i < count($rows); $i++) {
        $obj = [];
        foreach ($headers as $j => $h) {
            $obj[$h] = trim($rows[$i][$j] ?? '');
        }
        $records[] = $obj;
    }
    return ['headers' => $headers, 'records' => $records];
}

/** Prefixes formula-leading characters so an exported note can't execute in Excel (CSV injection). */
function escape_csv_cell($value): string
{
    if ($value === null) {
        return '';
    }
    $str = (string) $value;
    if (preg_match('/^[=+\-@\t\r]/', $str)) {
        $str = "'" . $str;
    }
    if (preg_match('/[",\n\r]/', $str)) {
        return '"' . str_replace('"', '""', $str) . '"';
    }
    return $str;
}

/** @param columns [{key, label, value?: fn(row)}] */
function to_csv(array $rows, array $columns): string
{
    $header = implode(',', array_map(fn($c) => escape_csv_cell($c['label'] ?? $c['key']), $columns));
    $body = [];
    foreach ($rows as $row) {
        $cells = array_map(function ($c) use ($row) {
            $value = isset($c['value']) ? $c['value']($row) : ($row[$c['key']] ?? null);
            return escape_csv_cell($value);
        }, $columns);
        $body[] = implode(',', $cells);
    }
    return "\xEF\xBB\xBF" . $header . "\r\n" . implode("\r\n", $body);
}
