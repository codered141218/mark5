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

$request = new Request();
$GLOBALS['__request'] = $request;
$router = new Router();

if (!$GLOBALS['__installed'] || !App\Services\Installer::isInstalled()) {
    require BASE_PATH . '/app/routes/install.php';
} else {
    App\Services\Migrations::runIfNeeded();   // upgrade older databases automatically
    foreach (glob(BASE_PATH . '/app/routes/*.php') as $routeFile) {
        if (basename($routeFile) !== 'install.php') require $routeFile;
    }
}

$router->dispatch($request)->send();
