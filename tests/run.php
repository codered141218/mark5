<?php
/**
 * Test runner (no PHPUnit needed):   php tests/run.php            run every tests/*Test.php
 *                                    php tests/run.php Pos        only files whose name contains "Pos"
 * Uses a separate database (config db name + "_test"), re-created for every test file so files never affect each other.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';

use App\Core\Auth;
use App\Core\DB;
use App\Services\Installer;

$db = config('db');
$db['database'] = getenv('MARK5_TEST_DB') ?: $db['database'] . '_test';
DB::configure($db);

/** Every test file starts from a freshly installed database (with the sample menu), logged in as admin. */
function fresh_database(array $db): void
{
    DB::reconnect();
    DB::server()->exec("DROP DATABASE IF EXISTS `{$db['database']}`");
    Installer::createDatabase($db['database']);
    Installer::install(true);
    App\Services\Ledger::clearCache();
    App\Services\Settings::flush();
    Auth::actAs((int) DB::value("SELECT id FROM users WHERE username = 'admin'"));
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if ($filter && !str_contains(basename($file), $filter)) continue;
    echo "\n" . basename($file) . "\n";
    fresh_database($db);
    require $file;
}
DB::server()->exec("DROP DATABASE IF EXISTS `{$db['database']}`");
exit(T::summary());
