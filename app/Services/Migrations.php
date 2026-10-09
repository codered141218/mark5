<?php
namespace App\Services;

use App\Core\DB;

/**
 * Database upgrades for installations made with an older version.
 * database/schema.sql always contains the latest structure for NEW installs; every change made after the
 * first release is also added here as a numbered step so existing databases are upgraded automatically.
 * Each step must be safe to run on a database that already has the change (see addColumn()).
 *
 * The current level is stored in settings.db_version. public/index.php calls runIfNeeded() on every request
 * (one cheap settings lookup); you can also run `php database/migrate.php`.
 */
class Migrations
{
    /** @return array<int, callable> version => upgrade step */
    private static function steps(): array
    {
        return [
            // 1: configurable discounts, per-item discounts
            1 => function () {
                DB::pdo()->exec("CREATE TABLE IF NOT EXISTS discounts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(60) NOT NULL UNIQUE,
                    kind ENUM('sc','pwd','percent','amount') NOT NULL,
                    value DECIMAL(14,2) NULL,
                    scope ENUM('both','order','item') NOT NULL DEFAULT 'both',
                    requires_approval TINYINT(1) NOT NULL DEFAULT 1,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                self::addColumn('tickets', 'discount_id', 'INT UNSIGNED NULL AFTER discount_type');
                self::addColumn('tickets', 'discount_name', 'VARCHAR(60) NULL AFTER discount_id');
                self::addColumn('tickets', 'sc_discount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount_amount');
                self::addColumn('tickets', 'promo_discount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER sc_discount');
                self::addColumn('ticket_items', 'discount_id', 'INT UNSIGNED NULL AFTER line_total');
                self::addColumn('ticket_items', 'discount_name', 'VARCHAR(60) NULL AFTER discount_id');
                self::addColumn('ticket_items', "discount_kind", "ENUM('sc','pwd','percent','amount') NULL AFTER discount_name");
                self::addColumn('ticket_items', 'discount_value', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount_kind');
                self::addColumn('ticket_items', 'discount_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER discount_value');
                // Existing tickets: split the stored discount into SC/PWD vs promo parts for reports
                DB::run("UPDATE tickets SET sc_discount = discount_amount WHERE discount_type IN ('sc','pwd') AND sc_discount = 0");
                DB::run("UPDATE tickets SET promo_discount = discount_amount WHERE discount_type IN ('percent','amount') AND promo_discount = 0");
                Discounts::seedDefaults();
            },
            // 2: prep stations for order slips, GL account overrides per category, VAT-exclusive pricing
            2 => function () {
                DB::pdo()->exec("CREATE TABLE IF NOT EXISTS prep_stations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(40) NOT NULL UNIQUE,
                    sort_order INT NOT NULL DEFAULT 0,
                    active TINYINT(1) NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                self::addColumn('categories', 'station_id', 'INT UNSIGNED NULL');
                self::addColumn('categories', 'sales_account_id', 'INT UNSIGNED NULL');
                self::addColumn('categories', 'cogs_account_id', 'INT UNSIGNED NULL');
                self::addColumn('categories', 'inventory_account_id', 'INT UNSIGNED NULL');
                self::addColumn('items', 'station_id', 'INT UNSIGNED NULL AFTER sort_order');
                self::addColumn('tickets', 'vat_inclusive', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER cogs');
                Stations::seedDefaults();
                // Give every item and category its own position (new ones are added at the end)
                foreach (['categories' => 'sort_order, name', 'items' => 'category_id, sort_order, name'] as $table => $order) {
                    $pos = 0;
                    foreach (DB::all("SELECT id FROM `$table` ORDER BY $order") as $r) DB::run("UPDATE `$table` SET sort_order = ? WHERE id = ?", [$pos += 10, $r['id']]);
                }
            },
        ];
    }

    public static function latest(): int
    {
        return max(array_keys(self::steps()));
    }

    public static function current(): int
    {
        return (int) DB::value("SELECT `value` FROM settings WHERE `key` = 'db_version'");
    }

    public static function runIfNeeded(): void
    {
        if (self::current() < self::latest()) self::run();
    }

    /** Apply every step newer than the stored version. Returns the versions applied. */
    public static function run(): array
    {
        $applied = [];
        foreach (self::steps() as $version => $step) {
            if ($version <= self::current()) continue;
            $step();   // DDL statements commit implicitly in MySQL, so steps are written to be re-runnable
            Settings::set('db_version', $version);
            $applied[] = $version;
        }
        return $applied;
    }

    /** ALTER TABLE ... ADD COLUMN only when the column does not exist yet (works on MySQL and MariaDB). */
    public static function addColumn(string $table, string $column, string $definition): void
    {
        $exists = DB::value(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        if (!$exists) DB::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
