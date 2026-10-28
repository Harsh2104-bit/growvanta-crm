<?php
declare(strict_types=1);

/**
 * PDO singleton. Every query in the application goes through prepared
 * statements — no query in this codebase concatenates user input into SQL.
 */

function gv_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = gv_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[db] connection failed: ' . $e->getMessage());
        gv_fail(500, 'DATABASE_ERROR', 'Could not connect to the database.');
    }

    // Pin MySQL's session clock to PHP's.
    //
    // Some rows are timestamped by PHP (date()) and some by MySQL (NOW(),
    // DEFAULT CURRENT_TIMESTAMP). If the two disagree - PHP on UTC and MySQL on
    // the machine's local zone is the common case, and was happening here by
    // 5.5 hours - then "created today" filters, audit ordering and follow-up
    // sweeps all quietly compare timestamps from two different clocks.
    // Aligning the session means it no longer matters which wrote a row.
    $offsetSeconds = (int) (new DateTimeImmutable('now'))->getOffset();
    $sign = $offsetSeconds < 0 ? '-' : '+';
    $offset = sprintf('%s%02d:%02d', $sign, intdiv(abs($offsetSeconds), 3600), intdiv(abs($offsetSeconds) % 3600, 60));
    try {
        $pdo->exec("SET time_zone = '$offset'");
    } catch (PDOException $e) {
        // Not fatal: a host without the tz tables still works, it just keeps
        // its own clock. Logged so the drift is explainable rather than eerie.
        error_log('[db] could not align session time zone: ' . $e->getMessage());
    }

    return $pdo;
}

/** Fetch a single row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $stmt = gv_pdo()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array
{
    $stmt = gv_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Execute an INSERT/UPDATE/DELETE; returns affected row count. */
function db_exec(string $sql, array $params = []): int
{
    $stmt = gv_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** Fetch a single scalar value (e.g. COUNT(*)). */
function db_scalar(string $sql, array $params = [])
{
    $stmt = gv_pdo()->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Runs $fn inside a transaction, committing on success and rolling back on any
 * exception. Nested calls reuse the outer transaction (PDO does not support
 * true nested transactions, so we just track depth).
 */
function db_transaction(callable $fn)
{
    static $depth = 0;
    $pdo = gv_pdo();
    if ($depth === 0) {
        $pdo->beginTransaction();
    }
    $depth++;
    try {
        $result = $fn($pdo);
        $depth--;
        if ($depth === 0) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        $depth--;
        if ($depth === 0 && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Builds a safe `IN (?, ?, ...)` fragment + matching bind values. */
function db_in_clause(array $values): array
{
    if (empty($values)) {
        return ['(NULL)', []];
    }
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    return ["($placeholders)", array_values($values)];
}
