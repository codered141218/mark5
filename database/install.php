<?php
/**
 * Command-line installer.
 *   php database/install.php            create database + tables + default data + sample menu
 *   php database/install.php --empty    same, without the sample menu
 * Reads the database settings from config/config.php (copy config/config.example.php first).
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Installer;

if (!$GLOBALS['__installed']) {
    fwrite(STDERR, "config/config.php not found. Copy config/config.example.php to config/config.php and set your MySQL details.\n");
    exit(1);
}
try {
    Installer::createDatabase(config('db.database'));
    Installer::install(!in_array('--empty', $argv, true));
    echo "Installed. Log in with admin / admin123 (manager PIN 1234) and change them under My Account.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
