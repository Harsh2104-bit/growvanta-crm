<?php
/**
 * Central configuration. Reads .env (if present) into getenv()-visible values
 * without requiring Composer/vendor packages — cPanel shared hosting often has
 * no shell access to run `composer install`, so this stays dependency-free.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// .env loader (minimal, no external dependency)
// ---------------------------------------------------------------------------
function gv_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes, if any.
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && $value[-1] === '"') ||
            ($value[0] === "'" && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

gv_load_env(__DIR__ . '/.env');

function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ---------------------------------------------------------------------------
$GV_CONFIG = [
    'env'       => env('APP_ENV', 'production'),
    'app_url'   => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'debug'     => env('APP_DEBUG', '0') === '1',

    'db' => [
        'host'    => env('DB_HOST', 'localhost'),
        'port'    => env('DB_PORT', '3306'),
        'name'    => env('DB_NAME', 'growvanta'),
        'user'    => env('DB_USER', 'root'),
        'pass'    => env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'auth' => [
        // Used to derive session tokens and encrypt integration secrets
        // (Meta page access tokens etc.) at rest. Must be 32+ random bytes.
        'app_key'               => env('APP_KEY', ''),
        'session_ttl_hours'     => (int) env('SESSION_TTL_HOURS', 12),
        'remember_me_ttl_days'  => (int) env('REMEMBER_ME_TTL_DAYS', 30),
        'cookie_name'           => 'gv_session',
        'csrf_cookie'           => 'gv_csrf',
    ],

    'uploads' => [
        'dir'          => rtrim((string) env('UPLOAD_DIR', __DIR__ . '/uploads'), '/'),
        'max_bytes'    => (int) env('MAX_UPLOAD_MB', 10) * 1024 * 1024,
        'allowed_mime' => [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
            'application/pdf', 'text/csv', 'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
    ],

    'rate_limit' => [
        // Stored in a DB table (rate_limit_hits) rather than APCu/Redis, since
        // shared hosting often disables both. See lib/rate_limit.php.
        'window_seconds'  => (int) env('RATE_LIMIT_WINDOW_MIN', 15) * 60,
        'max'             => (int) env('RATE_LIMIT_MAX', 1000),
        'auth_max'        => (int) env('AUTH_RATE_LIMIT_MAX', 20),
        'capture_max'     => (int) env('PUBLIC_CAPTURE_RATE_LIMIT_MAX', 60),
    ],

    // The platform's own mailbox. It sends system mail - invites, password
    // resets - and every staff alert, for every company, so those never wait on
    // a customer configuring SMTP of their own.
    'mail' => [
        'host' => env('SMTP_HOST', ''),
        'port' => (int) env('SMTP_PORT', 587),
        'encryption' => env('SMTP_ENCRYPTION', 'TLS'),
        'user' => env('SMTP_USER', ''),
        'pass' => env('SMTP_PASS', ''),
        'from' => env('SMTP_FROM', 'Growvanta CRM <no-reply@localhost>'),
    ],

    'meta' => [
        // Meta/Facebook Lead Ads + webhook verification.
        'app_id'          => env('META_APP_ID', ''),
        'app_secret'      => env('META_APP_SECRET', ''),
        'verify_token'    => env('META_VERIFY_TOKEN', ''),
        'graph_version'   => env('META_GRAPH_VERSION', 'v19.0'),
    ],

    'seed_password' => env('SEED_PASSWORD', 'Growvanta@123'),

    'pagination' => ['default_limit' => 25, 'max_limit' => 200],
];

if ($GV_CONFIG['env'] === 'production' && strlen((string) $GV_CONFIG['auth']['app_key']) < 32) {
    // A short/missing key would make session tokens guessable and Meta secrets
    // reversible — refuse to boot rather than run insecurely in production.
    //
    // On an install that worked yesterday the cause is rarely a forgotten key:
    // it is .env.example having been renamed over a working .env during an
    // upgrade. Naming that turns a baffling outage into a one-line fix, so the
    // example's own placeholder values are recognised here.
    $looksLikeExample = $GV_CONFIG['app_url'] === 'https://crm.yourdomain.com'
        || strpos((string) $GV_CONFIG['db']['name'], 'cpaneluser_') === 0;

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => 'CONFIG_ERROR', 'message' => $looksLikeExample
        ? 'Your .env still holds the example values that ship with the package, so your real settings have been '
          . 'overwritten. Put back your own APP_URL, APP_KEY and database details. Never rename .env.example over '
          . 'a working .env.'
        : 'APP_KEY is missing or too short. Set a 32+ character random value in .env.']]);
    exit;
}
if (strlen((string) $GV_CONFIG['auth']['app_key']) < 32) {
    // Non-production fallback so a fresh checkout still boots for local testing.
    $GV_CONFIG['auth']['app_key'] = 'dev-only-insecure-key-do-not-use-in-prod!!';
}

function gv_config(?string $path = null, $default = null)
{
    global $GV_CONFIG;
    if ($path === null) {
        return $GV_CONFIG;
    }
    $value = $GV_CONFIG;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}
