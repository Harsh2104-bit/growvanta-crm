<?php
declare(strict_types=1);

/**
 * Pre-flight check. Run this once after uploading, before you sign in.
 *
 *   Browser:  https://your-domain.com/install-check.php
 *   Shell:    php install-check.php
 *
 * It tells you whether this host can actually run the CRM: PHP version,
 * extensions, .htaccess rewriting, a writable upload directory, and whether the
 * database credentials in .env work and the schema has been imported.
 *
 * SECURITY: over the web this answers only until the first successful sign-in.
 * After that it returns 404, so a forgotten copy cannot be used to fingerprint a
 * live server. Delete it anyway when you are done.
 */

$isCli = PHP_SAPI === 'cli';

// ---------------------------------------------------------------------------
// Read .env without booting the app - the whole point is to work when the app
// cannot boot yet.
// ---------------------------------------------------------------------------
$env = [];
$envPath = __DIR__ . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

/**
 * Has the install actually finished?
 *
 * Gating on APP_KEY was wrong: the documented order sets APP_KEY before this
 * page is ever opened, so the check disabled itself exactly when it was needed.
 * The honest signal is a successful sign-in - until somebody has logged in, the
 * install is still in progress and this page should answer.
 *
 * Anything unknown (no database yet, no tables yet) counts as unfinished, which
 * is the state where the check is most useful.
 */
function install_finished(array $env): bool
{
    if (!extension_loaded('pdo_mysql') || empty($env['DB_NAME'])) {
        return false;
    }
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env['DB_HOST'] ?: 'localhost', $env['DB_PORT'] ?: '3306', $env['DB_NAME']),
            $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        return (int) $pdo->query('SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (!$isCli && install_finished($env)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not found.\n\nThis check switches off once someone has signed in. Delete install-check.php.";
    return;
}

$checks = [];
$fail = 0;
$warn = 0;

function check(string $label, string $state, string $detail = ''): void
{
    global $checks, $fail, $warn;
    $checks[] = [$label, $state, $detail];
    if ($state === 'FAIL') {
        $fail++;
    } elseif ($state === 'WARN') {
        $warn++;
    }
}

// ---------------------------------------------------------------------- PHP
check(
    'PHP version',
    version_compare(PHP_VERSION, '7.4', '>=') ? 'OK' : 'FAIL',
    PHP_VERSION . (version_compare(PHP_VERSION, '8.1', '<') ? ' (8.1+ recommended)' : '')
);

foreach (['pdo_mysql' => true, 'openssl' => true, 'json' => true, 'mbstring' => true,
          'fileinfo' => true, 'curl' => false, 'zlib' => true] as $ext => $required) {
    $loaded = extension_loaded($ext);
    check(
        "Extension: $ext",
        $loaded ? 'OK' : ($required ? 'FAIL' : 'WARN'),
        $loaded ? '' : ($required
            ? 'Enable it under Select PHP Version / PHP Configuration.'
            : 'Outbound webhooks and Meta Lead Ads need this; the rest works without it.')
    );
}

// ------------------------------------------------------------------- Config
check('.env present', is_file($envPath) ? 'OK' : 'FAIL',
    is_file($envPath) ? '' : 'Copy .env.example to .env and fill it in.');

$appKey = $env['APP_KEY'] ?? '';
check('APP_KEY set', strlen($appKey) >= 32 ? 'OK' : 'FAIL',
    strlen($appKey) >= 32
        ? ''
        : 'Generate one:  php -r "echo bin2hex(random_bytes(32));"   (32+ chars required)');

check('APP_URL set', !empty($env['APP_URL']) && $env['APP_URL'] !== 'https://crm.yourdomain.com' ? 'OK' : 'WARN',
    $env['APP_URL'] ?? '(unset)');

check('APP_ENV', ($env['APP_ENV'] ?? '') === 'production' ? 'OK' : 'WARN',
    ($env['APP_ENV'] ?? '(unset)') . ' — use "production" on a live site');

// ------------------------------------------------------------------ Uploads
$uploadDir = ($env["UPLOAD_DIR"] ?? "") ?: __DIR__ . "/uploads";
if (!is_dir($uploadDir)) {
    check('Upload directory', 'FAIL', "$uploadDir does not exist");
} elseif (!is_writable($uploadDir)) {
    check('Upload directory', 'FAIL', "$uploadDir is not writable (try chmod 755)");
} else {
    check('Upload directory', 'OK', $uploadDir);
}

// ----------------------------------------------------------------- Database
$dbOk = false;
if (extension_loaded('pdo_mysql') && !empty($env['DB_NAME'])) {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?: 'localhost',
        $env['DB_PORT'] ?: '3306',
        $env['DB_NAME']
    );
    try {
        $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $dbOk = true;
        check('Database connection', 'OK', $env['DB_NAME']);

        $tables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();
        check(
            'Schema imported',
            $tables >= 51 ? 'OK' : ($tables > 0 ? 'WARN' : 'FAIL'),
            $tables === 0
                ? 'No tables. Import database/schema.sql then both migrations.'
                : ($tables < 51
                    ? "$tables tables — expected 51. Did both migrations run?"
                    : "$tables tables")
        );

        if ($tables > 0) {
            $platform = (int) $pdo->query(
                'SELECT COUNT(*) FROM organizations WHERE is_platform = 1'
            )->fetchColumn();
            check('Platform bootstrapped', $platform > 0 ? 'OK' : 'WARN',
                $platform > 0 ? '' : 'Run: php database/platform_bootstrap.php');
        }
    } catch (Throwable $e) {
        check('Database connection', 'FAIL', $e->getMessage());
    }
} else {
    check('Database connection', 'FAIL', 'Fill DB_NAME / DB_USER / DB_PASS in .env');
}

// -------------------------------------------------------------- Rewriting
if (!$isCli) {
    $rewriting = isset($_SERVER['HTTP_MOD_REWRITE'])
        || getenv('HTTP_MOD_REWRITE')
        || is_file(__DIR__ . '/.htaccess');
    check('.htaccess present', is_file(__DIR__ . '/.htaccess') ? 'OK' : 'FAIL',
        is_file(__DIR__ . '/.htaccess') ? 'Confirm /api/health responds below' : 'Upload it (it is a hidden file)');
}

// ------------------------------------------------------------------ Output
$summary = $fail > 0
    ? "$fail problem(s) must be fixed before the CRM will run."
    : ($warn > 0 ? "Ready, with $warn thing(s) worth a look." : 'Everything checks out.');

if ($isCli) {
    echo "\nGrowvanta CRM — install check\n" . str_repeat('=', 64) . "\n";
    foreach ($checks as [$label, $state, $detail]) {
        printf("  [%-4s] %-26s %s\n", $state, $label, $detail);
    }
    echo str_repeat('=', 64) . "\n$summary\n";
    exit($fail > 0 ? 1 : 0);
}

$rows = '';
foreach ($checks as [$label, $state, $detail]) {
    $colour = ['OK' => '#12a64f', 'WARN' => '#c2790c', 'FAIL' => '#dc2626'][$state];
    $rows .= '<tr><td><b style="color:' . $colour . '">' . $state . '</b></td><td>'
        . htmlspecialchars($label) . '</td><td style="color:#5b6b7a">'
        . htmlspecialchars($detail) . '</td></tr>';
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Growvanta CRM — install check</title>
<style>
  body { font: 15px/1.6 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; background: #f5f7f9; color: #0d1b2a; }
  .wrap { max-width: 820px; margin: 40px auto; padding: 0 20px; }
  .card { background: #fff; border: 1px solid #e3e8ec; border-radius: 12px; padding: 24px; }
  h1 { margin: 0 0 4px; font-size: 22px; }
  p.sub { margin: 0 0 20px; color: #5b6b7a; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  td { padding: 7px 10px; border-bottom: 1px solid #eef2f5; vertical-align: top; }
  td:first-child { width: 58px; }
  .summary { margin-top: 20px; padding: 14px 16px; border-radius: 9px; font-weight: 600; }
  .ok { background: #e8f8ee; color: #0f6b35; }
  .bad { background: #fdecec; color: #a5201f; }
  .next { margin-top: 20px; font-size: 14px; color: #5b6b7a; }
  code { background: #f0f3f6; padding: 1px 5px; border-radius: 4px; }
</style>
<div class="wrap"><div class="card">
  <h1>Growvanta CRM — install check</h1>
  <p class="sub">Run once after uploading. Delete this file when you are done.</p>
  <table><?= $rows ?></table>
  <div class="summary <?= $fail > 0 ? 'bad' : 'ok' ?>"><?= htmlspecialchars($summary) ?></div>
  <div class="next">
    <p>Next: open <code>/api/health</code> — it should return
      <code>{"data":{"status":"ok","database":"up"...}}</code>.
      If it 404s, <code>mod_rewrite</code> is not picking up <code>.htaccess</code>.</p>
    <p>Then sign in at <code>/login.html</code> and <b>delete install-check.php</b>.</p>
  </div>
</div></div>
