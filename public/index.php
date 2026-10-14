<?php
/**
 * Front controller: every request comes through here (see .htaccess).
 * 1. bootstrap (config, autoload, DB)  2. session  3. routes  4. dispatch.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

// PHP built-in server (php -S): serve real files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) return false;
}

// Detect the folder the app lives in (e.g. http://localhost/mark5/) unless configured.
if (config('app.base_path', '') === '') {
    $script = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $base = '';
    if ($script !== '' && (str_starts_with($uri, $script . '/') || $uri === $script)) $base = $script;
    elseif (str_ends_with($script, '/public') && str_starts_with($uri, dirname($script))) $base = rtrim(dirname($script), '/');
    $GLOBALS['__config']['app']['base_path'] = $base;
}

session_name('mark5_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (config('app.base_path') ?: '') . '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

// Form values from the previous request (after a validation error)
$GLOBALS['__old'] = $_SESSION['old'] ?? [];
unset($_SESSION['old']);

// A fatal PHP error (e.g. a file that did not upload completely) shows its reason instead of a blank page.
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $e = new ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']);
    $msg = App\Core\Router::errorMessage($e);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<div style="font-family:system-ui,sans-serif;max-width:640px;margin:10vh auto;padding:16px 20px;border:1px solid #fecaca;background:#fff5f5;border-radius:12px;color:#7f1d1d">'
        . '<b>This page could not be shown.</b><p style="margin:8px 0 0">' . htmlspecialchars($msg) . '</p></div>';
});

$request = new Request();
$GLOBALS['__request'] = $request;
$router = new Router();

if (!$GLOBALS['__installed'] || !App\Services\Installer::isInstalled()) {
    require BASE_PATH . '/app/routes/install.php';
} else {
    try {
        App\Services\Migrations::runIfNeeded();   // upgrade older databases automatically
    } catch (\Throwable $e) {
        // e.g. an update still being uploaded: say so (the upgrade runs again on the next page) instead of a blank error
        error_log((string) $e);
        http_response_code(503);
        header('Retry-After: 30');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Updating…</title>'
            . '<div style="font-family:system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 20px;color:#111827">'
            . '<h1 style="font-size:22px">Mark5 is finishing an update</h1><p>Please reload this page in a minute.</p>'
            . '<p style="color:#6b7280;font-size:13px">' . htmlspecialchars($e->getMessage()) . '</p></div>';
        exit;
    }
    foreach (glob(BASE_PATH . '/app/routes/*.php') as $routeFile) {
        if (basename($routeFile) !== 'install.php') require $routeFile;
    }
}

$router->dispatch($request)->send();
