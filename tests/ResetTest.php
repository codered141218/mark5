<?php
/** "Start fresh": everything deleted except the administrator; the system then works from an empty chart of accounts. */
use App\Core\DB;
use App\Services\Admin\Reset;
use App\Services\Backup;
use App\Services\GlSetup;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;
use App\Services\Settings;

Backup::$dir = sys_get_temp_dir() . '/mark5_reset_test_' . getmypid();
as_user('admin');

test('start fresh needs the confirmation text, the password and full access', function () {
    $admin = (int) DB::value("SELECT id FROM users WHERE username = 'admin'");
    throws(fn () => Reset::startFresh($admin, 'admin123', 'delete all'), 'DELETE ALL');
    throws(fn () => Reset::startFresh($admin, 'wrong', 'DELETE ALL'), 'password');
    DB::insert('users', ['username' => 'cash9', 'full_name' => 'Cashier', 'password_hash' => password_hash('secret1', PASSWORD_DEFAULT),
        'role_id' => (int) DB::value("SELECT id FROM roles WHERE name = 'Cashier'"), 'active' => 1, 'created_at' => now()]);
    throws(fn () => Reset::startFresh((int) DB::value("SELECT id FROM users WHERE username = 'cash9'"), 'secret1', 'DELETE ALL'), 'Administrator');
});

test('start fresh deletes all data and keeps the admin, its role and the business settings', function () {
    CashSessions::open(500);
    $t = Tickets::create(['order_type' => 'takeout']);
    Tickets::pay(Tickets::addItem($t['id'], item_id('Coke'), 1)['id'], [['method' => 'cash', 'amount' => 65]]);
    Settings::set('business_name', 'Mama Gapos');
    $admin = (int) DB::value("SELECT id FROM users WHERE username = 'admin'");
    $backup = Reset::startFresh($admin, 'admin123', 'DELETE ALL');
    ok(is_file(Backup::path($backup)), 'backup made first');
    foreach (['accounts', 'items', 'categories', 'uoms', 'tickets', 'journal_entries', 'discounts', 'prep_stations', 'suppliers', 'employees', 'cash_sessions'] as $tbl) {
        eq(0, (int) DB::value("SELECT COUNT(*) FROM `$tbl`"), "$tbl emptied");
    }
    eq(1, (int) DB::value('SELECT COUNT(*) FROM users'));
    eq(1, (int) DB::value('SELECT COUNT(*) FROM roles'));
    eq('Administrator', DB::value('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id'));
    eq('Mama Gapos', Settings::get('business_name'));
    eq(App\Services\Migrations::latest(), App\Services\Migrations::current(), 'database version kept');
    ok(password_verify('admin123', DB::value("SELECT password_hash FROM users WHERE username = 'admin'")), 'admin can still sign in');
});

test('empty system: screens still work, postings ask for the GL account, then everything works again', function () {
    eq(null, Ledger::accountOrNull('cash_on_hand'));
    ok(is_array(App\Services\Reports\Dashboard::data(today(), today())), 'dashboard works with no accounts');
    ok(is_array(App\Services\Reports\Dashboard::position(today())), 'financial position works with no accounts');
    eq(0.0, App\Services\Finance\PettyCash::fundBalance());
    ok(count(GlSetup::rows()) > 10 && GlSetup::rows()[0]['account_id'] === null, 'GL setup lists roles with no account');

    // Add the owner's own units, item and accounts
    $pc = DB::insert('uoms', ['name' => 'Piece', 'abbr' => 'pc']);
    $item = Items::create(['name' => 'Halo-halo', 'item_type' => 'non_inventory', 'base_uom_id' => $pc, 'price' => 112, 'sellable' => 1, 'active' => 1]);
    CashSessions::open(1000);
    $t = Tickets::addItem(Tickets::create(['order_type' => 'takeout'])['id'], $item, 1);
    throws(fn () => Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 112]]), 'GL Account Setup');
    eq('open', DB::value('SELECT status FROM tickets WHERE id = ?', [$t['id']]), 'nothing half-saved');

    $acct = fn ($code, $name, $type) => DB::insert('accounts', ['code' => $code, 'name' => $name, 'type' => $type, 'active' => 1]);
    GlSetup::save([
        'cash_on_hand' => $acct('101', 'Cash Drawer', 'asset'), 'sales' => $acct('401', 'Sales', 'income'),
        'sales_discounts' => $acct('402', 'Discounts', 'income'), 'output_vat' => $acct('201', 'Output VAT', 'liability'),
        'service_charge' => $acct('202', 'Service Charge', 'liability'), 'cogs' => $acct('501', 'Cost of Sales', 'expense'),
        'inventory' => $acct('121', 'Inventory', 'asset'),
    ]);
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 112]]);
    eq('paid', $paid['status']);
    ok(str_ends_with($paid['receipt_no'], '00000001'), 'receipt numbers start again from 1: ' . $paid['receipt_no']);
    eq(112, Ledger::balance((int) DB::value("SELECT id FROM accounts WHERE code = '101'")));
    eq(-100, Ledger::balance((int) DB::value("SELECT id FROM accounts WHERE code = '401'")));
    assert_books_balance();
});

array_map('unlink', glob(Backup::dir() . '/*'));
rmdir(Backup::dir());
