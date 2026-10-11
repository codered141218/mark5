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
            // 3: payments & expenses vouchers; show the setup wizard once (also on existing installations)
            3 => function () {
                DB::pdo()->exec("CREATE TABLE IF NOT EXISTS disbursements (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc_no VARCHAR(32) NULL, txn_date DATE NOT NULL,
                    pay_from ENUM('cash','petty_cash','bank') NOT NULL, bank_account_id INT UNSIGNED NULL, payee VARCHAR(120) NULL,
                    supplier_id INT UNSIGNED NULL, account_id INT UNSIGNED NOT NULL, amount DECIMAL(14,2) NOT NULL,
                    vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0, reference VARCHAR(60) NULL, description VARCHAR(255) NULL,
                    status ENUM('posted','void') NOT NULL DEFAULT 'posted', void_reason VARCHAR(255) NULL, journal_entry_id INT UNSIGNED NULL,
                    bank_txn_id INT UNSIGNED NULL, created_by INT UNSIGNED NULL, created_at DATETIME NULL, KEY ix_disb_date (txn_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                if (Settings::get('setup_done') === null) Settings::set('setup_done', '0');
            },
            // 4: beginning cash of the POS drawer taken from the petty cash fund (and returned at close)
            4 => function () {
                DB::pdo()->exec("ALTER TABLE petty_cash_txns MODIFY txn_type ENUM('expense','replenish','change_fund','fund_return') NOT NULL");
                self::addColumn('cash_sessions', 'opening_source', "VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER opening_cash");
                self::addColumn('cash_sessions', 'opening_txn_id', 'INT UNSIGNED NULL AFTER opening_source');
                self::addColumn('cash_sessions', 'return_txn_id', 'INT UNSIGNED NULL AFTER opening_txn_id');
            },
            // 5: "sent" (Done, e.g. by a waiter on a phone) and "order slip printed" are tracked separately
            5 => function () {
                $had = (int) DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ticket_items' AND column_name = 'printed'");
                self::addColumn('ticket_items', 'printed', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER kitchen_sent');
                if (!$had) DB::run('UPDATE ticket_items SET printed = kitchen_sent');   // until now Done meant printed
            },
            // 6: back-office payments from petty cash while the fund is the POS beginning cash show as payouts of that day
            6 => function () {
                self::addColumn('petty_cash_txns', 'ref_type', 'VARCHAR(30) NULL AFTER cash_session_id');
                self::addColumn('petty_cash_txns', 'ref_id', 'INT UNSIGNED NULL AFTER ref_type');
            },
            // 7: when each order line was sent with Done (order-taking time on the dashboard)
            7 => function () {
                self::addColumn('ticket_items', 'sent_at', 'DATETIME NULL AFTER printed');
            },
            // 8: payroll — employee pay setup, biometric attendance, payroll runs, payslips
            8 => function () {
                foreach ([
                    ['biometric_id', 'VARCHAR(20) NULL'], ['pay_type', "VARCHAR(10) NOT NULL DEFAULT 'monthly'"],
                    ['monthly_rate', 'DECIMAL(14,2) NOT NULL DEFAULT 0'], ['daily_rate', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
                    ['sss', 'DECIMAL(12,2) NOT NULL DEFAULT 0'], ['philhealth', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
                    ['pagibig', 'DECIMAL(12,2) NOT NULL DEFAULT 0'], ['wtax', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
                    ['ca_deduction', 'DECIMAL(12,2) NOT NULL DEFAULT 0'],
                ] as [$col, $def]) self::addColumn('employees', $col, $def);
                $sql = file_get_contents(BASE_PATH . '/database/schema.sql');
                foreach (['attendance_imports', 'attendance', 'payroll_runs', 'payslips', 'payslip_lines'] as $t) {
                    if (preg_match('/CREATE TABLE IF NOT EXISTS ' . $t . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) DB::pdo()->exec($m[0]);
                }
                // statutory and withholding tax payables get system keys so payroll can find them
                foreach (['2210' => 'statutory_payable', '2220' => 'wtax_payable'] as $code => $key) {
                    if (!DB::value('SELECT id FROM accounts WHERE system_key = ?', [$key])) {
                        DB::run('UPDATE accounts SET system_key = ? WHERE code = ? AND system_key IS NULL', [$key, $code]);
                    }
                }
                // permissions: everyone may see their own payslips; managers / accountants run payroll
                foreach (DB::all('SELECT id, name, permissions FROM roles') as $r) {
                    $p = json_decode($r['permissions'], true) ?: [];
                    if (in_array('*', $p, true)) continue;
                    $add = ['payroll.self'];
                    if (in_array($r['name'], ['Manager', 'Accountant'], true)) $add = array_merge($add, ['payroll.view', 'payroll.manage', 'payroll.post']);
                    DB::run('UPDATE roles SET permissions = ? WHERE id = ?', [json_encode(array_values(array_unique(array_merge($p, $add)))), $r['id']]);
                }
            },
            // 9: each employee's work schedule (fixed day-offs, paid day-offs a week, own shift); paid days on the payslip
            9 => function () {
                self::addColumn('employees', 'work_days', 'VARCHAR(7) NULL');
                self::addColumn('employees', 'paid_rest_days', 'TINYINT NULL');
                self::addColumn('employees', 'shift_start', 'VARCHAR(5) NULL');
                self::addColumn('employees', 'shift_hours', 'DECIMAL(4,2) NULL');
                self::addColumn('payslips', 'days_paid', 'DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER days_leave');
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
