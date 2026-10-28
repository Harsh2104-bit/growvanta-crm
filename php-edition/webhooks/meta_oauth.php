<?php
declare(strict_types=1);

/**
 * Where Facebook returns the admin after they authorise the app.
 *
 * Register this exact URL in the Meta app dashboard under
 * Facebook Login > Settings > Valid OAuth Redirect URIs:
 *
 *     https://your-domain.com/webhooks/meta_oauth.php
 *
 * It is deliberately a redirector, not an API endpoint: a person's browser
 * lands here, so it must end with them back inside the CRM looking at a screen
 * that tells them what happened - never at a page of JSON.
 *
 * Nothing here trusts the caller. The `state` is the only thing that ties this
 * request to the one that left the CRM, and it is checked against a hash held
 * server-side, for one user, with a fifteen-minute life.
 */

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/MetaConnect.php';

$app = rtrim((string) gv_config('app_url'), '/');

/** Always end up on the integrations screen, with the outcome in the URL. */
function meta_oauth_back(string $app, string $status, string $message = ''): never
{
    // /index.html, not "/". The marketing site is the front door now, so
    // sending someone back to the bare domain after they authorise drops them
    // on the home page instead of the page picker - the authorisation is
    // fine, it just looks like nothing happened.
    $url = $app . '/index.html#/integrations?meta=' . rawurlencode($status)
        . ($message !== '' ? '&reason=' . rawurlencode(substr($message, 0, 300)) : '');
    header('Location: ' . $url, true, 302);
    exit;
}

// The admin pressed Cancel on Facebook's screen, or declined a permission.
if (isset($_GET['error'])) {
    $reason = (string) ($_GET['error_description'] ?? $_GET['error'] ?? 'Authorisation was cancelled.');
    meta_oauth_back($app, 'cancelled', $reason);
}

$code = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

if ($code === '' || $state === '') {
    meta_oauth_back($app, 'failed', 'Facebook did not return an authorisation code.');
}

try {
    meta_connect_complete($state, $code);
    // Success only means "we hold a token"; the admin still has to choose which
    // of their pages belongs in this CRM, which the screen prompts for next.
    meta_oauth_back($app, 'choose');
} catch (Throwable $e) {
    error_log('[meta-oauth] ' . $e->getMessage());
    meta_oauth_back($app, 'failed', $e->getMessage());
}
