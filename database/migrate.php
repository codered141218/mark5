<?php
/** Upgrade the database to the latest structure:  php database/migrate.php */
require __DIR__ . '/../app/bootstrap.php';

$applied = App\Services\Migrations::run();
echo $applied ? 'Applied migration(s): ' . implode(', ', $applied) . "\n" : "Database is already up to date.\n";
