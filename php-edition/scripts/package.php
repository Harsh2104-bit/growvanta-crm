<?php
declare(strict_types=1);

/**
 * Builds a clean, upload-ready release of the CRM.
 *
 *   php scripts/package.php                 full release, data included
 *   php scripts/package.php --no-data       code only, empty database
 *   php scripts/package.php --data-only     just refresh database/data.sql
 *
 * Produces  dist/growvanta-crm-hostinger-<date>/  and the matching .zip
 *
 * What is deliberately LEFT OUT:
 *   .env                 real credentials never travel in a zip
 *   dev-server.php       routes by inspecting the URL, which is .htaccess's job
 *                        on a real host; shipping both is a second way in
 *   uploads/*            customer files; the folder ships empty but present
 *   dist/, *.log         build output and local noise
 *
 * The data export covers every table except live sessions and rate-limit
 * counters, which are per-machine and would be meaningless - or harmful -
 * carried to another server.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require __DIR__ . '/../bootstrap.php';

const RELEASE_PREFIX = 'growvanta-crm-hostinger';

/** Live tokens and per-host counters. Never travel. */
const SKIP_TABLES = ['sessions', 'rate_limit_hits', 'meta_webhook_events'];

/** Paths never copied into a release. */
const SKIP_NAMES = ['.env', 'dev-server.php', '.git', 'node_modules', 'dist'];

function pkg_human_size(int $bytes): string
{
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}

function pkg_rmtree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = "$dir/$entry";
        is_dir($path) ? pkg_rmtree($path) : unlink($path);
    }
    rmdir($dir);
}

$args = array_slice($argv, 1);
$withData = !in_array('--no-data', $args, true);
$dataOnly = in_array('--data-only', $args, true);

$root = dirname(__DIR__);
$stamp = date('Y-m-d');
$name = RELEASE_PREFIX . '-' . $stamp;

echo "\nBuilding $name\n" . str_repeat('-', 62) . "\n";

// ---------------------------------------------------------------------------
// 1. The data export
// ---------------------------------------------------------------------------
/**
 * Dumps every row as INSERT statements, ordered so that a parent row always
 * precedes the child rows that point at it.
 *
 * Ordering matters because `SET FOREIGN_KEY_CHECKS = 0` cannot be relied on:
 * phpMyAdmin's import screen has its own "Enable foreign key checks" box and
 * turns the setting straight back on. An alphabetical dump therefore failed on
 * audit_logs before organizations existed. The statement is still emitted as a
 * second line of defence, but the file no longer depends on it.
 *
 * Self-referencing columns (a user's manager, a role's parent) cannot be fixed
 * by table order, because the target may be a later row in the same table.
 * Those go in as NULL and are filled by UPDATEs once every row exists.
 */
function export_data(array $skip): array
{
    $pdo = gv_pdo();
    $tables = array_column(db_all(
        'SELECT TABLE_NAME AS t FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
        ['BASE TABLE']
    ), 't');
    $tables = array_values(array_diff($tables, $skip));

    // What each table depends on, and which of its columns point at itself.
    $fks = db_all(
        'SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $dependsOn = [];
    $selfRefs = [];
    foreach ($fks as $fk) {
        if ($fk['child'] === $fk['parent']) {
            $selfRefs[$fk['child']][] = $fk['col'];
        } elseif (in_array($fk['parent'], $tables, true)) {
            $dependsOn[$fk['child']][$fk['parent']] = true;
        }
    }

    // Kahn's algorithm, alphabetical within a level so the file is stable.
    $ordered = [];
    $remaining = $tables;
    while ($remaining) {
        $ready = [];
        foreach ($remaining as $t) {
            if (!array_diff(array_keys($dependsOn[$t] ?? []), $ordered)) {
                $ready[] = $t;
            }
        }
        if (!$ready) {
            // A cycle between two tables. Emit what is left alphabetically and
            // let FOREIGN_KEY_CHECKS carry it; better than dropping rows.
            sort($remaining);
            $ordered = array_merge($ordered, $remaining);
            break;
        }
        sort($ready);
        $ordered = array_merge($ordered, $ready);
        $remaining = array_values(array_diff($remaining, $ready));
    }

    $sql = "-- Growvanta CRM data export\n"
        . '-- Generated ' . date('Y-m-d H:i:s') . "\n"
        . "--\n"
        . "-- Import AFTER database/schema.sql and both migrations.\n"
        . "-- Tables are in dependency order, so this imports with foreign key\n"
        . "-- checks either on or off.\n"
        . "-- Contains no sessions and no rate-limit rows: those are per-server.\n\n"
        . "SET NAMES utf8mb4;\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $rowTotal = 0;
    $counts = [];
    $deferred = '';

    foreach ($ordered as $table) {
        $rows = db_all("SELECT * FROM `$table`");
        if (!$rows) {
            continue;
        }
        $counts[$table] = count($rows);
        $rowTotal += count($rows);

        $selfCols = $selfRefs[$table] ?? [];
        $hasId = array_key_exists('id', $rows[0]);

        $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
        $sql .= "-- $table (" . count($rows) . " rows)\n";

        foreach (array_chunk($rows, 100) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $cells = [];
                foreach ($row as $column => $value) {
                    // Held back to an UPDATE below; the target may not exist yet.
                    if ($value !== null && $hasId && in_array($column, $selfCols, true)) {
                        $deferred .= sprintf(
                            "UPDATE `%s` SET `%s` = %s WHERE `id` = %s;\n",
                            $table,
                            $column,
                            $pdo->quote((string) $value),
                            $pdo->quote((string) $row['id'])
                        );
                        $cells[] = 'NULL';
                        continue;
                    }
                    $cells[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }
                $values[] = '(' . implode(',', $cells) . ')';
            }
            $sql .= "INSERT INTO `$table` ($columns) VALUES\n" . implode(",\n", $values) . ";\n";
        }
        $sql .= "\n";
    }

    if ($deferred !== '') {
        $sql .= "-- Self-referencing links, applied once every row exists.\n" . $deferred . "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return ['sql' => $sql, 'rows' => $rowTotal, 'counts' => $counts, 'order' => $ordered];
}

if ($withData || $dataOnly) {
    $export = export_data(SKIP_TABLES);
    file_put_contents($root . '/database/data.sql', $export['sql']);
    printf("  data export       %d rows across %d tables (%s)\n",
        $export['rows'], count($export['counts']),
        pkg_human_size(strlen($export['sql'])));
    foreach ($export['counts'] as $t => $n) {
        printf("      %-24s %d\n", $t, $n);
    }
    if ($dataOnly) {
        echo "\nWrote database/data.sql only.\n";
        exit(0);
    }
} else {
    // A stale export from a previous run must not sneak into a --no-data build.
    @unlink($root . '/database/data.sql');
    echo "  data export       skipped (--no-data)\n";
}

// ---------------------------------------------------------------------------
// 2. Stage the files
// ---------------------------------------------------------------------------
$dist = $root . '/dist';
$out = $dist . '/' . $name;
if (is_dir($out)) {
    pkg_rmtree($out);
}
mkdir($out, 0755, true);

$staged = [];

/** @param string $rel path relative to the project root */
function stage(string $root, string $out, string $rel, array &$staged): void
{
    $entries = scandir($root . '/' . $rel) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, SKIP_NAMES, true)) {
            continue;
        }
        $childRel = $rel === '' ? $entry : "$rel/$entry";
        $src = $root . '/' . $childRel;

        if (is_dir($src)) {
            // uploads ships as an empty folder: the customer's own files are
            // theirs, but the folder and its deny rule must exist on arrival.
            if ($childRel === 'uploads') {
                mkdir($out . '/uploads', 0755, true);
                if (is_file($src . '/.htaccess')) {
                    copy($src . '/.htaccess', $out . '/uploads/.htaccess');
                    $staged[] = 'uploads/.htaccess';
                }
                continue;
            }
            stage($root, $out, $childRel, $staged);
            continue;
        }

        if (preg_match('/\.(log|bak)$/i', $entry)) {
            continue;
        }
        $destDir = dirname($out . '/' . $childRel);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        copy($src, $out . '/' . $childRel);
        $staged[] = $childRel;
    }
}

stage($root, $out, '', $staged);
printf("  staged            %d files\n", count($staged));

// ---------------------------------------------------------------------------
// 3. Refuse to ship a release that carries secrets
// ---------------------------------------------------------------------------
$problems = [];
if (is_file($out . '/.env')) {
    $problems[] = '.env is present in the staged release';
}
if (is_file($out . '/dev-server.php')) {
    $problems[] = 'dev-server.php is present in the staged release';
}
$liveKey = (string) gv_config('auth.app_key');
if ($liveKey !== '') {
    foreach ($staged as $rel) {
        $file = $out . '/' . $rel;
        if (!is_file($file) || filesize($file) > 4000000) {
            continue;
        }
        if (strpos((string) file_get_contents($file), $liveKey) !== false) {
            $problems[] = "this machine's APP_KEY appears in $rel";
        }
    }
}
if ($problems) {
    echo "\n  REFUSING TO PACKAGE:\n";
    foreach ($problems as $p) {
        echo "    - $p\n";
    }
    exit(1);
}
echo "  secret scan       clean (no .env, no live APP_KEY)\n";

// ---------------------------------------------------------------------------
// 4. Zip it - reusing the hand-built writer, so no ext-zip is needed
// ---------------------------------------------------------------------------
require_once $root . '/lib/xlsx.php';

$files = [];
foreach ($staged as $rel) {
    $files["$name/$rel"] = (string) file_get_contents($out . '/' . $rel);
}
$zipPath = $dist . '/' . $name . '.zip';
file_put_contents($zipPath, xlsx_zip($files));

printf("  archive           %s (%s)\n", basename($zipPath), pkg_human_size(filesize($zipPath)));
echo str_repeat('-', 62) . "\n";
echo "Release ready:\n  $zipPath\n\n";
