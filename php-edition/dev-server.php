<?php
/**
  * Optional dev-server router for `php -S` (which ignores .htaccess).
 *
 * Emulates the rewrites in the project's .htaccess so the app behaves locally
 * the way it will on Apache/LiteSpeed. Dev-only — not part of the deployment.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$root = __DIR__; // set via -t

// /api/... -> the front controller
if (preg_match('#^/api(/|$)#', $path)) {
    require __DIR__ . '/api/index.php';
    return true;
}

// /f/KEY -> hosted form page
if (preg_match('#^/f/([A-Za-z0-9_.-]+)/?$#', $path, $m)) {
    $_GET['_path'] = '/public/hosted/' . $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}

// /embed/KEY.js -> embeddable form script
if (preg_match('#^/embed/([A-Za-z0-9_.-]+)\.js$#', $path, $m)) {
    $_GET['_path'] = '/public/embed/' . $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}

// The front door is the marketing site, matching DirectoryIndex in .htaccess.
// The application is /index.html, and the SPA fallback below still carries its
// deep links.
if ($path === '/') {
    require __DIR__ . '/home.html';
    return true;
}

// Real files (css, js, images, webhooks/*.php) are served as-is.
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// SPA fallback for extension-less paths.
if (!preg_match('/\.[a-zA-Z0-9]{2,5}$/', $path)) {
    require __DIR__ . '/index.html';
    return true;
}

return false;
