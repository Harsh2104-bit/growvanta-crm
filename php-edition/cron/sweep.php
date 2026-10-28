<?php
declare(strict_types=1);

/**
 * Due-date sweep: raises FOLLOWUP_DUE / FOLLOWUP_OVERDUE notifications for
 * leads whose next follow-up has come due.
 *
 * The Node server ran this on an in-process timer. PHP has no long-lived
 * process, so it belongs in cron. On cPanel:
 *
 *     Cron Jobs > Add New Cron Job > every 15 minutes:
 *     /usr/local/bin/php /home/USER/public_html/cron/sweep.php
 *
 * It is safe to run more often than needed — run_due_sweep() will not notify
 * the same person about the same lead twice.
 *
 * CRON_TOKEN in .env additionally allows triggering it over HTTP, for hosts
 * whose cron can only fetch a URL:
 *
 *     https://your-domain.com/cron/sweep.php?token=YOUR_CRON_TOKEN
 *
 * (The directory is deny-all by default; see cron/.htaccess. Remove that file
 * only if you need the HTTP trigger.)
 */

require __DIR__ . '/../bootstrap.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $expected = (string) env('CRON_TOKEN', '');
    $supplied = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $supplied)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['code' => 'FORBIDDEN', 'message' => 'Set CRON_TOKEN in .env and pass it as ?token=']]);
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $result = run_due_sweep();
    // Abandoned Facebook authorisations hold an encrypted user token that can
    // list every page the admin runs. Spent or expired ones are rubbish, and
    // rubbish holding a token is worth clearing on the same schedule.
    $result += meta_connect_sweep();
    $summary = sprintf(
        'tasks due: %d, follow-ups due: %d, follow-ups warned ahead: %d, daily summaries: %d',
        $result['tasksDue'],
        $result['followUpsDue'],
        $result['followUpsUpcoming'] ?? 0,
        $result['dailySummaries'] ?? 0
    ) . sprintf(', stale facebook authorisations cleared: %d', $result['metaOauthStatesCleared'] ?? 0);

    if ($isCli) {
        echo '[' . date('Y-m-d H:i:s') . "] sweep complete - $summary\n";
    } else {
        echo json_encode(['data' => $result]);
    }
} catch (Throwable $e) {
    error_log('[cron/sweep] ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, 'sweep failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'SWEEP_FAILED', 'message' => 'The sweep did not complete. Check the PHP error log.']]);
}
