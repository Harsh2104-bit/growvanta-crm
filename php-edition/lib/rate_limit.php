<?php
declare(strict_types=1);

/**
 * DB-backed rate limiting. Shared cPanel hosting usually has no Redis/APCu
 * available across requests, so hits are counted in a small MySQL table
 * instead. A single DELETE keeps it from growing unbounded.
 */

function rate_limit_check(string $bucket, string $key, int $max, int $windowSeconds): void
{
    if ($max <= 0) {
        return;
    }
    $pdo = gv_pdo();
    $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

    // Opportunistic cleanup, cheap because it's indexed on hit_at.
    db_exec('DELETE FROM rate_limit_hits WHERE hit_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);

    $count = (int) db_scalar(
        'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ? AND rkey = ? AND hit_at >= ?',
        [$bucket, $key, $windowStart]
    );
    if ($count >= $max) {
        throw too_many('Too many requests. Please slow down.');
    }
    db_exec('INSERT INTO rate_limit_hits (bucket, rkey, hit_at) VALUES (?, ?, NOW())', [$bucket, $key]);
}

function rate_limit_api(): void
{
    rate_limit_check('api', client_ip() ?? 'unknown', (int) gv_config('rate_limit.max'), (int) gv_config('rate_limit.window_seconds'));
}

function rate_limit_auth(): void
{
    rate_limit_check('auth', client_ip() ?? 'unknown', (int) gv_config('rate_limit.auth_max'), (int) gv_config('rate_limit.window_seconds'));
}

function rate_limit_capture(): void
{
    rate_limit_check('capture', client_ip() ?? 'unknown', (int) gv_config('rate_limit.capture_max'), 60);
}
