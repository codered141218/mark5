<?php
use App\Core\Auth;
use App\Core\DB;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;

as_user('admin');

// Stock to sell: chicken 20 kg, coke 48 cans, maskara 5 kg, water 24
foreach ([['Chicken', 20, 190], ['Coke', 48, 38], ['Pork Maskara', 5, 180], ['Bottled Water', 24, 12], ['Rice', 50, 55]] as [$name, $q, $cost]) {
    Inventory::move(['item_id' => item_id($name), 'qty' => $q, 'mtype' => 'RECEIVE', 'unit_cost' => $cost]);
}
Ledger::post(today(), 'Opening stock', [['key' => 'inventory', 'debit' => 20 * 190 + 48 * 38 + 5 * 180 + 24 * 12 + 50 * 55], ['key' => 'opening_equity', 'credit' => 20 * 190 + 48 * 38 + 5 * 180 + 24 * 12 + 50 * 55]]);

test('cannot take orders before the day is opened', function () {
    throws(fn () => Tickets::create(['order_type' => 'dine_in']), 'not open');
});

test('open the business day with beginning cash', function () {
    $s = CashSessions::open(2000);
    eq('open', $s['status']);
    throws(fn () => CashSessions::open(100), 'already open');
});

$GLOBALS['t1'] = null;
test('order -> assign table -> SC discount -> split payment with change', function () {
    $t = Tickets::create(['order_type' => 'dine_in', 'pax' => 2]);
    ok($t['table_label'] === null, 'no table yet');
    Tickets::addItem($t['id'], item_id('Adobo Rice Meal'), 2);
    $t = Tickets::addItem($t['id'], item_id('Coke'), 2);
    eq(2 * 199 + 2 * 65, $t['subtotal']);
    $t = Tickets::setTable($t['id'], '5');
    eq('5', $t['table_label']);
    throws(fn () => Tickets::discount($t['id'], ['discount_type' => 'sc', 'sc_count' => 1, 'sc_details' => []]), 'ID number');
    $t = Tickets::discount($t['id'], ['discount_type' => 'sc', 'sc_count' => 1, 'sc_details' => [['name' => 'Lola Nena', 'id_no' => 'SC-123']]]);
    eq(235.71, $t['vat_exempt_sales']);
    eq(47.14, $t['discount_amount']);
    eq(28.29, $t['vat_amount']);
    eq(452.57, $t['total']);
    throws(fn () => Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 100]]), 'short');
    $paid = Tickets::pay($t['id'], [['method' => 'card', 'amount' => 200, 'reference' => 'APP1'], ['method' => 'cash', 'amount' => 300]]);
    eq('paid', $paid['status']);
    eq(47.43, $paid['change_amount']);
    ok(str_starts_with($paid['receipt_no'], 'OR-'), 'receipt number');
    eq(19.5, stock('Chicken'), 'recipe deducted 250 g x 2');
    eq(46, stock('Coke'));
    assert_books_balance();
    $GLOBALS['t1'] = $paid;
});

test('split, merge, change table and void with manager PIN', function () {
    $t = Tickets::create(['order_type' => 'dine_in', 'pax' => 2, 'table_label' => '7']);
    Tickets::addItem($t['id'], item_id('Sizzling Pork Sisig'), 2);
    $t = Tickets::addItem($t['id'], item_id('Bottled Water'), 2);
    Tickets::send($t['id']);
    $sisigLine = array_values(array_filter($t['items'], fn ($i) => $i['item_id'] === item_id('Sizzling Pork Sisig')))[0];
    $r = Tickets::split($t['id'], [['id' => $sisigLine['id'], 'qty' => 1]], null, '8');
    eq(225 + 60, $r['source']['subtotal']);
    eq(225, $r['target']['subtotal']);
    eq('8', $r['target']['table_label']);

    // Cashier (no void permission) needs a manager PIN to void an item already sent to the kitchen
    DB::insert('users', ['username' => 'cashier1', 'full_name' => 'Cashier One', 'password_hash' => password_hash('secret1', PASSWORD_DEFAULT),
        'role_id' => (int) DB::value("SELECT id FROM roles WHERE name = 'Cashier'"), 'active' => 1, 'created_at' => now()]);
    as_user('cashier1');
    $water = array_values(array_filter($r['source']['items'], fn ($i) => $i['item_id'] === item_id('Bottled Water')))[0];
    throws(fn () => Tickets::voidLine($t['id'], $water['id'], 'wrong order'), 'Manager authorization');
    throws(fn () => Tickets::voidLine($t['id'], $water['id'], 'wrong order', '9999'), 'Invalid manager PIN');
    $after = Tickets::voidLine($t['id'], $water['id'], 'wrong order', '1234');
    eq(225, $after['subtotal']);

    // Pay the split order with GCash, then void the receipt (stock comes back)
    $paid = Tickets::pay($r['target']['id'], [['method' => 'gcash', 'amount' => 225, 'reference' => 'G1']]);
    $before = stock('Pork Maskara');
    throws(fn () => Tickets::void($paid['id'], 'test'), 'Manager authorization');
    $v = Tickets::void($paid['id'], 'customer complaint', '1234');
    eq('void', $v['status']);
    eq($before + 0.2, stock('Pork Maskara'));

    // Merge a take-out order into table 7, then settle in cash
    $tk = Tickets::create(['order_type' => 'takeout', 'customer_name' => 'Ana']);
    Tickets::addItem($tk['id'], item_id('Extra Egg'), 1);
    $merged = Tickets::merge($t['id'], $tk['id']);
    eq(245, $merged['subtotal']);
    eq('void', Tickets::get($tk['id'])['status']);
    Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 1000]]);
    as_user('admin');
    assert_books_balance();
});

test('charge to customer account creates a receivable', function () {
    $t = Tickets::create(['order_type' => 'takeout']);
    $t = Tickets::addItem($t['id'], item_id('Pancit Canton (Good'), 1);
    throws(fn () => Tickets::pay($t['id'], [['method' => 'charge', 'amount' => 280]]), 'customer account');
    $cust = (int) DB::value('SELECT id FROM customers LIMIT 1');
    $paid = Tickets::pay($t['id'], [['method' => 'charge', 'amount' => 280, 'customer_id' => $cust]]);
    $ar = DB::one("SELECT * FROM ar_invoices WHERE source_type = 'pos_sale' AND source_id = ?", [$paid['id']]);
    eq(280, (float) $ar['amount']);
    eq(280, acct_balance('ar'));
    assert_books_balance();
});

test('end of day: blind count, cash short posted to GL', function () {
    throws(function () {
        $t = Tickets::create(['order_type' => 'takeout']);
        Tickets::addItem($t['id'], item_id('Plain Rice'), 1);
        try { CashSessions::close([1000 => 2]); } finally { Tickets::void($t['id'], 'test cleanup'); }
    }, 'open order');
    $x = CashSessions::report((int) CashSessions::current()['id']);
    // cash: (452.57 - 200) from order 1 + 245 merged order = 497.57; beginning 2000
    eq(497.57, $x['cash']['cash_sales']);
    eq(2497.57, $x['cash']['expected']);
    $z = CashSessions::close([1000 => 2, 200 => 2, 50 => 1, 20 => 2, 5 => 1, 1 => 2]);
    eq(2497, $z['cash']['counted']);
    eq(-0.57, $z['cash']['variance']);
    eq(1, $z['voided']['cnt']);
    eq('closed', $z['session']['status']);
    eq(0.57, acct_balance('cash_short_over'));
    assert_books_balance();
});

test('voiding a previous day receipt refunds from the new day drawer', function () {
    $prior = $GLOBALS['t1'];
    throws(fn () => Tickets::void($prior['id'], 'late complaint'), 'Open the business day');
    $s = CashSessions::open(1000);
    Tickets::void($prior['id'], 'late complaint');
    $x = CashSessions::report((int) $s['id']);
    eq(252.57, $x['cash']['refunds']);
    eq(1000 - 252.57, $x['cash']['expected']);
    assert_books_balance();
});

test('drawer payout during the day reduces expected cash', function () {
    $s = CashSessions::current();
    $before = CashSessions::report((int) $s['id'])['cash']['expected'];
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE name LIKE 'LPG%'");
    $id = App\Services\Finance\PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 150, 'account_id' => $lpg, 'description' => 'LPG refill']);
    eq($before - 150, CashSessions::report((int) $s['id'])['cash']['expected']);
    eq(1, count(App\Services\Finance\PettyCash::listForSession((int) $s['id'])));
    App\Services\Finance\PettyCash::void($id, 'wrong amount');
    eq($before, CashSessions::report((int) $s['id'])['cash']['expected']);
    assert_books_balance();
});
