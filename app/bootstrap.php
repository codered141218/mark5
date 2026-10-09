<?php
/**
 * Loads configuration, the class autoloader, helpers and the session.
 * Included by public/index.php, the installer and the tests.
 */

define('BASE_PATH', dirname(__DIR__));

// PSR-4 style autoloader: App\Services\Ledger -> app/Services/Ledger.php
spl_autoload_register(function (string $class) {
    if (!str_starts_with($class, 'App\\')) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

require BASE_PATH . '/app/helpers.php';

$configFile = BASE_PATH . '/config/config.php';
$GLOBALS['__config'] = is_file($configFile) ? require $configFile : require BASE_PATH . '/config/config.example.php';
$GLOBALS['__installed'] = is_file($configFile);

// Optional override, e.g. MARK5_DB_NAME=mark5_demo php -S ... (handy for testing several copies)
if (getenv('MARK5_DB_NAME')) $GLOBALS['__config']['db']['database'] = getenv('MARK5_DB_NAME');

date_default_timezone_set(config('app.timezone', 'Asia/Manila'));
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(BASE_PATH . '/storage/logs')) @mkdir(BASE_PATH . '/storage/logs', 0775, true);
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

App\Core\DB::configure(config('db'));
