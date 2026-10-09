<?php
namespace App\Services\Admin;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\Ledger;
use App\Services\Settings;

/**
 * "Start fresh" (Administration → Backup & Restore): delete ALL data — sales, inventory, items, units, chart of
 * accounts, banks, suppliers, customers, employees, discounts, stations, other users and roles — so the owner can
 * enter their own records one by one. Kept: the signed-in administrator, the role that gives them full access, the
 * business settings (name, TIN, receipt text, tax rates) and the database version. A backup is made first.
 */
class Reset
{
    /** Tables emptied completely (children before parents does not matter: foreign key checks are off). */
    private const WIPE = [
        'payments', 'ticket_items', 'tickets', 'cash_sessions', 'petty_cash_txns',
        'count_lines', 'count_sessions', 'inv_doc_lines', 'inv_docs', 'stock_movements',
        'item_components', 'item_uoms', 'items', 'categories', 'prep_stations', 'uom_conversions', 'uoms', 'discounts',
        'ca_repayments', 'cash_advances', 'ar_receipts', 'ar_invoices', 'ap_payments', 'ap_bills',
        'bank_txns', 'bank_accounts', 'journal_lines', 'journal_entries', 'accounts',
        'suppliers', 'customers', 'employees', 'sequences', 'login_attempts', 'audit_log',
    ];

    /** Returns the name of the backup made before deleting. */
    public static function startFresh(int $keepUserId, string $password, string $confirm): string
    {
        if (trim($confirm) !== 'DELETE ALL') throw HttpException::bad('Type DELETE ALL (in capital letters) to confirm.');
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$keepUserId]);
        if (!$user || !password_verify($password, $user['password_hash'])) throw HttpException::bad('Your password is not correct.');
        $role = DB::one('SELECT * FROM roles WHERE id = ?', [$user['role_id']]);
        if (!$role || !in_array('*', json_decode($role['permissions'], true) ?: [], true)) {
            throw HttpException::bad('Only a user with the full-access Administrator role can start fresh.');
        }

        $backup = Backup::create('pre-reset');
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (self::WIPE as $table) $pdo->exec("DELETE FROM `$table`");
            foreach (self::WIPE as $table) {
                try { $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1"); } catch (\PDOException $e) { /* not important */ }
            }
            DB::run('DELETE FROM users WHERE id <> ?', [$keepUserId]);
            DB::run('UPDATE users SET employee_id = NULL WHERE id = ?', [$keepUserId]);
            DB::run('DELETE FROM roles WHERE id <> ?', [$role['id']]);
            // GL account choices point at deleted accounts
            DB::run("DELETE FROM settings WHERE `key` LIKE 'gl.%'");
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        Settings::flush();
        Ledger::clearCache();
        Audit::log('start_fresh', 'database', null, ['backup' => $backup['name'], 'by' => $user['username']]);
        return $backup['name'];
    }
}
