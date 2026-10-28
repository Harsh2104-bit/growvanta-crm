<?php
declare(strict_types=1);

/**
 * Front controller for every /api/... request — ported from src/app.js +
 * src/middleware/*.js. .htaccess rewrites all /api/* (and /f/*, /embed/*)
 * requests here; this file re-derives Express's route table by hand:
 *
 *   /api/health            - no auth
 *   /api/public/*          - public lead-capture surface, its own rate limit
 *   /api/v1/*              - API-key authenticated external ingestion
 *   /api/auth/*            - session issuing; rate limited, CSRF-protected
 *   everything else        - requires a session + resolved visibility scope
 *
 * A single try/catch turns any AppException (or bug) into the same
 * { error: { code, message } } envelope the Node backend used, so
 * public/js/lib/api.js needs zero changes.
 */

require __DIR__ . '/../bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'");

// ---------------------------------------------------------------------------
// Path resolution: strip the /api prefix .htaccess routed us under.
// ---------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = $uri;

// .htaccess maps the short public URLs (/f/KEY, /embed/KEY.js) here by passing
// the internal path in _path. Only the unauthenticated /public/ surface may be
// addressed this way, so a crafted _path cannot reach a guarded route.
$override = $_GET['_path'] ?? null;
if (is_string($override) && str_starts_with($override, '/public/')) {
    $path = $override;
} elseif (str_starts_with($path, '/api')) {
    $path = substr($path, 4);
}
$path = '/' . ltrim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}
$segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));

/** Requires api/{$name}.php to exist and dispatches into it with $rest in scope. */
function gv_dispatch(string $name, array $rest, string $method): void
{
    $file = __DIR__ . "/$name.php";
    if (!is_file($file)) {
        throw not_found("Unknown API resource: $name");
    }
    require $file;
}

// Guarded resource prefix -> handler file (mirrors app.js's `guarded.use(...)` table).
//
// Contacts, accounts, opportunities, pipelines, tasks and campaigns were
// removed: this is a real-estate CRM, where the buyer is the lead and the deal
// is a booking recorded as a lead status. They are absent from this table AND
// their handler files are deleted, so /api/accounts is a 404 with no code path
// behind it - not a hidden button.
const GV_GUARDED_ROUTES = [
    'leads' => 'leads', 'activities' => 'activities',
    'forms' => 'forms', 'users' => 'users', 'roles' => 'roles', 'teams' => 'teams', 'reports' => 'reports',
    'notifications' => 'notifications', 'automation' => 'automation', 'settings' => 'settings',
    'api-keys' => 'apikeys', 'webhooks' => 'webhooks', 'imports' => 'imports', 'documents' => 'documents',
    'search' => 'search', 'audit-logs' => 'audit', 'saved-views' => 'savedViews', 'targets' => 'targets',
    'integrations' => 'integrations',
    // New in the PHP edition: admin surface for Meta Lead Ads form connections.
    'meta-forms' => 'metaForms',
    // Real-estate SaaS surface.
    'projects' => 'projects',
    'templates' => 'templates',
    'whatsapp' => 'whatsapp',
    'email' => 'email',
    // Super Admin only; the handler re-checks with require_platform_admin().
    'platform' => 'platform',
];

try {
    if ($path === '/health') {
        $dbOk = true;
        try {
            db_scalar('SELECT 1');
        } catch (Throwable $e) {
            $dbOk = false;
        }
        json_ok([
            'status' => $dbOk ? 'ok' : 'degraded', 'database' => $dbOk ? 'up' : 'down',
            'environment' => gv_config('env'), 'time' => gmdate('c'),
        ]);
    } elseif ($segments && $segments[0] === 'public') {
        // OPTIONS is handled inside publicCapture so the CORS preflight carries
        // the form's own origin allow-list, not a blanket 204.
        gv_dispatch('publicCapture', array_slice($segments, 1), $method);
    } elseif ($segments && $segments[0] === 'v1') {
        gv_dispatch('publicApi', array_slice($segments, 1), $method);
    } elseif ($method === 'OPTIONS') {
        http_response_code(204);
    } else {
        rate_limit_api();
        issue_csrf_cookie();
        auth_load_actor();
        csrf_protect();

        if ($segments && $segments[0] === 'auth') {
            gv_dispatch('auth', array_slice($segments, 1), $method);
        } elseif ($segments && isset(GV_GUARDED_ROUTES[$segments[0]])) {
            require_auth();
            scope_info();
            gv_dispatch(GV_GUARDED_ROUTES[$segments[0]], array_slice($segments, 1), $method);
        } else {
            throw not_found('Unknown API route');
        }
    }
} catch (AppException $e) {
    json_error($e->status, $e->errorCode, $e->getMessage(), $e->details);
} catch (Throwable $e) {
    error_log('[api] unhandled exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    json_error(500, 'INTERNAL_ERROR', gv_config('debug') ? $e->getMessage() : 'Something went wrong. Please try again.');
} finally {
    finish_request_and_flush_events();
}
