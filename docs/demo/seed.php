<?php
/**
 * Demo data for the showcase video. Run only on a FRESH install (php database/install.php): it adds a demo business, users and three weeks of transactions. Runs the real services so stock and the books stay consistent.
 * Historical business days are back-dated through the cash session (tickets take business_date from it),
 * then created_at / paid_at timestamps are moved back to the business day.
 */
require __DIR__ . '/../../app/bootstrap.php';

use App\Core\DB;
use App\Core\Auth;
use App\Services\Ledger;
use App\Services\Settings;
use App\Services\InventoryDocs;
use App\Services\InventoryCounts;
use App\Services\Pos\Tickets;
use App\Services\Pos\CashSessions;
use App\Services\Finance\Banks;
use App\Services\Finance\PettyCash;
use App\Services\Finance\Payables;
use App\Services\Finance\Receivables;
use App\Services\Finance\CashAdvances;
use App\Services\Admin\Users;

mt_srand(5);
Auth::actAs(1);
$item = fn (string $name) => (int) DB::value('SELECT id FROM items WHERE name LIKE ? ORDER BY id LIMIT 1', [$name . '%']);
$sell = fn (string $name) => (int) DB::value('SELECT id FROM items WHERE sellable = 1 AND name LIKE ? ORDER BY id LIMIT 1', [$name . '%']);
$acct = fn (string $code) => (int) DB::value('SELECT id FROM accounts WHERE code = ?', [$code]);

// ---- business identity
Settings::set('business_name', "Kusina ni Aling Nena");
Settings::set('business_address', '123 Mabini St., Poblacion, Makati City');
Settings::set('business_phone', '(02) 8123-4567');
Settings::set('receipt_footer', 'Salamat po! Please come again.');
Settings::set('service_charge_rate', '5');

// ---- users
$roleId = fn ($n) => (int) DB::value('SELECT id FROM roles WHERE name = ?', [$n]);
if (!DB::value("SELECT id FROM users WHERE username = 'mara'")) {
    Users::create(['username' => 'mara', 'full_name' => 'Mara Villanueva', 'password' => 'manager123', 'pin' => '2468', 'role_id' => $roleId('Manager')]);
    Users::create(['username' => 'maria', 'full_name' => 'Maria Santos', 'password' => 'cashier123', 'role_id' => $roleId('Cashier'), 'employee_id' => 2]);
    Users::create(['username' => 'ben', 'full_name' => 'Ben Reyes', 'password' => 'clerk123', 'role_id' => $roleId('Inventory Clerk')]);
    Users::create(['username' => 'liza', 'full_name' => 'Liza Fernandez, CPA', 'password' => 'books123', 'role_id' => $roleId('Accountant')]);
}
DB::insert('employees', ['emp_no' => 'EMP003', 'full_name' => 'Ramon Bautista', 'position' => 'Waiter', 'department' => 'Front of House', 'active' => 1, 'date_hired' => '2025-06-10']);
DB::insert('employees', ['emp_no' => 'EMP004', 'full_name' => 'Grace Lim', 'position' => 'Kitchen Helper', 'department' => 'Kitchen', 'active' => 1, 'date_hired' => '2026-02-01']);
DB::insert('customers', ['name' => 'Makati Dental Clinic (Charge Account)', 'contact_person' => 'Dr. Ana Cruz', 'terms_days' => 15, 'credit_limit' => 20000, 'active' => 1]);
DB::insert('suppliers', ['name' => 'Baguio Fresh Produce', 'contact_person' => 'Aling Rosa', 'phone' => '0920-111-2222', 'terms_days' => 7, 'active' => 1]);

// ---- capital, bank, petty cash
Ledger::post('2026-09-01', 'Owner investment', [['key' => 'cash_on_hand', 'debit' => 150000], ['key' => 'capital', 'credit' => 150000]]);
$bank = Banks::create(['bank_name' => 'BDO', 'account_name' => 'Kusina ni Aling Nena', 'account_no' => '0012-3456-7890', 'account_type' => 'checking', 'opening_balance' => 80000, 'opening_date' => '2026-09-01']);
PettyCash::record(['txn_type' => 'replenish', 'source' => 'fund', 'from_method' => 'cash', 'amount' => 5000, 'txn_date' => '2026-09-02', 'description' => 'Petty cash fund']);

// ---- deliveries (RECEIVE): several dates, several payment modes
$receive = function (string $date, int $supplier, string $mode, array $lines, string $inv) use ($bank) {
    return InventoryDocs::create('RECEIVE', ['doc_date' => $date, 'supplier_id' => $supplier, 'payment_mode' => $mode, 'invoice_no' => $inv,
        'bank_account_id' => $mode === 'bank' ? $bank : null, 'vat_inclusive' => 1, 'post' => 1,
        'lines' => array_map(fn ($l) => ['item_id' => $l[0], 'qty' => $l[1], 'unit_cost' => $l[2]], $lines)]);
};
$weeks = ['2026-09-18', '2026-09-25', '2026-10-02', '2026-10-09'];
foreach ($weeks as $i => $d) {
    $receive($d, 1, 'credit', [[$item('Chicken'), 40, 190], [$item('Pork Liempo'), 25, 320], [$item('Pork Maskara'), 20, 180]], 'MMS-' . (1041 + $i));
    $receive($d, 3, 'petty_cash', [[$item('Garlic'), 4, 140], [$item('Red Onion'), 6, 160], [$item('Kangkong'), 8, 60], [$item('Calamansi'), 4, 80], [$item('Egg'), 180, 8]], 'BFP-' . (220 + $i));
}
$receive('2026-09-17', 2, 'bank', [[$item('Rice (Dinorado)'), 200, 55], [$item('Soy Sauce'), 12, 60], [$item('Cane Vinegar'), 10, 45], [$item('Cooking Oil'), 20, 95],
    [$item('Sinigang Mix'), 60, 22], [$item('Pancit Canton'), 40, 48], [$item('Sugar'), 15, 75], [$item('Iced Tea Powder'), 30, 35], [$item('Halo-halo'), 10, 250], [$item('Evaporated Milk'), 72, 32]], 'DDG-5581');
$receive('2026-09-24', 2, 'bank', [[$item('Soy Sauce'), 24, 60], [$item('Cane Vinegar'), 20, 45], [$item('Sinigang Mix'), 120, 22], [$item('Pancit Canton'), 60, 48], [$item('Halo-halo'), 25, 250], [$item('Iced Tea Powder'), 40, 35], [$item('Sugar'), 20, 75]], 'DDG-5590');
$receive('2026-10-06', 2, 'bank', [[$item('Soy Sauce'), 24, 61], [$item('Cane Vinegar'), 20, 45], [$item('Sinigang Mix'), 100, 22], [$item('Pancit Canton'), 60, 49], [$item('Halo-halo'), 20, 250], [$item('Iced Tea Powder'), 30, 35], [$item('Evaporated Milk'), 72, 32]], 'DDG-5625');
$receive('2026-10-01', 2, 'bank', [[$item('Rice (Dinorado)'), 150, 56], [$item('Cooking Oil'), 15, 96], [$item('Sinigang Mix'), 40, 22], [$item('Pancit Canton'), 30, 48], [$item('Evaporated Milk'), 48, 32]], 'DDG-5602');
$receive('2026-09-17', 2, 'cash', [[$item('Coke in Can'), 240, 38], [$item('San Miguel'), 144, 52], [$item('Bottled Water'), 240, 12]], 'DDG-5582');
$receive('2026-10-05', 2, 'cash', [[$item('Coke in Can'), 144, 38], [$item('San Miguel'), 96, 52], [$item('Bottled Water'), 144, 12]], 'DDG-5620');

// ---- three weeks of business days with real POS sales
$menu = [
    ['Adobo Rice Meal', 30], ['Sisig Rice Meal', 26], ['Chicken Adobo', 12], ['Sinigang na Baboy', 16], ['Sizzling Pork Sisig', 14],
    ['Pancit Canton', 8], ['Plain Rice', 28], ['Garlic Rice', 14], ['House Iced Tea', 34], ['Coke in Can', 18], ['San Miguel', 10],
    ['Bottled Water', 14], ['Halo-halo', 12], ['Extra Egg', 6],
];
$menuIds = array_map(fn ($m) => [$sell($m[0]), $m[1]], $menu);
$pick = function () use ($menuIds) {
    $total = array_sum(array_column($menuIds, 1));
    $r = mt_rand(1, $total);
    foreach ($menuIds as [$id, $w]) { if (($r -= $w) <= 0) return $id; }
    return $menuIds[0][0];
};
$payMethods = ['cash', 'cash', 'cash', 'cash', 'gcash', 'gcash', 'card', 'maya', 'cash', 'bank_transfer'];
$names = ['Lola Nena', 'Mang Tonyo', 'Aling Pacita', 'Lolo Berto', 'Nanay Cora'];
$tables = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '12A', '12B', 'Patio 1', 'Patio 2'];
$cashierIds = [(int) DB::value("SELECT id FROM users WHERE username='maria'"), 1, (int) DB::value("SELECT id FROM users WHERE username='mara'")];

$start = new DateTime('2026-09-21');
$end = new DateTime('2026-10-11');
$dayNo = 0;
for ($d = clone $start; $d <= $end; $d->modify('+1 day'), $dayNo++) {
    $date = $d->format('Y-m-d');
    $dow = (int) $d->format('N');
    $sid = DB::insert('cash_sessions', ['business_date' => $date, 'status' => 'open', 'opened_by' => $cashierIds[0], 'opened_at' => "$date 09:30:00", 'opening_cash' => 3000,
        'denominations' => json_encode(['opening' => ['1000' => 1, '500' => 2, '100' => 5, '50' => 6, '20' => 10]])]);
    $n = mt_rand(14, 22) + ($dow >= 5 ? mt_rand(6, 12) : 0) + (int) ($dayNo * 0.4);
    $voidDone = false;
    for ($i = 0; $i < $n; $i++) {
        Auth::actAs($cashierIds[mt_rand(0, 9) < 7 ? 0 : (mt_rand(0, 1) ? 1 : 2)]);
        $hour = [11, 11, 12, 12, 12, 13, 13, 14, 17, 18, 18, 19, 19, 19, 20, 20, 21][mt_rand(0, 16)];
        $ts = sprintf('%s %02d:%02d:00', $date, $hour, mt_rand(0, 59));
        $type = mt_rand(1, 10) <= 7 ? 'dine_in' : (mt_rand(0, 1) ? 'takeout' : 'delivery');
        $pax = $type === 'dine_in' ? mt_rand(1, 5) : 1;
        $t = Tickets::create(['order_type' => $type, 'table_label' => $type === 'dine_in' ? $tables[mt_rand(0, count($tables) - 1)] : null, 'pax' => $pax,
            'customer_name' => $type !== 'dine_in' ? ['Rico', 'Jenny', 'Paolo', 'Grab · Kuya Mike', 'Dana'][mt_rand(0, 4)] : null]);
        $lines = max(1, $pax) + mt_rand(0, 2);
        for ($k = 0; $k < $lines; $k++) $t = Tickets::addItem($t['id'], $pick(), mt_rand(1, 10) > 8 ? 2 : 1, mt_rand(1, 12) === 1 ? 'extra spicy' : null);
        Tickets::send($t['id']);
        if (mt_rand(1, 14) === 1) {   // Senior citizen on the whole receipt
            Auth::actAs($cashierIds[2]);
            $t = Tickets::discount($t['id'], ['discount_type' => 'sc', 'sc_count' => 1, 'pax' => max($pax, 2), 'sc_details' => [['name' => $names[mt_rand(0, 4)], 'id_no' => 'SC-' . mt_rand(10000, 99999)]]]);
        } elseif (mt_rand(1, 18) === 1) {
            Auth::actAs($cashierIds[2]);
            $t = Tickets::discount($t['id'], ['discount_type' => 'preset', 'discount_id' => 3]);
        }
        $t = Tickets::get($t['id']);
        $method = $type === 'delivery' ? (mt_rand(0, 1) ? 'grabfood' : 'foodpanda') : $payMethods[mt_rand(0, 9)];
        if ($dow === 3 && $i === 3) { $method = 'charge'; }
        $total = (float) $t['total'];
        $pay = $method === 'cash' ? [['method' => 'cash', 'amount' => ceil($total / 100) * 100 + (mt_rand(0, 2) ? 0 : 100)]]
             : ($method === 'charge' ? [['method' => 'charge', 'amount' => $total, 'customer_id' => mt_rand(1, 2)]]
             : [['method' => $method, 'amount' => $total, 'reference' => strtoupper(substr(md5($ts . $i), 0, 10))]]);
        if ($method !== 'cash' && $method !== 'charge' && mt_rand(1, 6) === 1 && $total > 300) {   // split payment
            $pay = [['method' => $method, 'amount' => 200, 'reference' => 'SPLIT' . mt_rand(100, 999)], ['method' => 'cash', 'amount' => ceil(($total - 200) / 50) * 50]];
        }
        $paid = Tickets::pay($t['id'], $pay);
        DB::run('UPDATE tickets SET created_at = ?, paid_at = ? WHERE id = ?', [$ts, date('Y-m-d H:i:s', strtotime($ts) + mt_rand(900, 3600)), $t['id']]);
        DB::run('UPDATE ticket_items SET created_at = ? WHERE ticket_id = ?', [$ts, $t['id']]);
        DB::run('UPDATE payments SET created_at = ? WHERE ticket_id = ?', [$ts, $t['id']]);
        if (!$voidDone && mt_rand(1, 5) === 1) {
            Auth::actAs($cashierIds[2]);
            Tickets::void($paid['id'], ['Customer changed mind', 'Duplicate entry', 'Wrong table charged'][mt_rand(0, 2)]);
            DB::run('UPDATE tickets SET voided_at = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime($ts) + 1200), $paid['id']]);
            $voidDone = true;
        }
    }
    Auth::actAs($cashierIds[0]);
    // Drawer payout from petty cash on some days
    if ($dow === 2) PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 850, 'account_id' => $acct('6210'), 'payee' => 'Petron Gasul', 'description' => 'LPG refill 11 kg']);
    if ($dow === 5) PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => mt_rand(12, 40) * 10, 'account_id' => $acct('6510'), 'payee' => 'Divisoria Packaging', 'description' => 'Take-out boxes & paper bags']);
    // Close the day: counted = expected, with a small short/over now and then
    $expected = CashSessions::report($sid)['cash']['expected'];
    $counted = $expected + ([0, 0, 0, 0, -20, 0, 50, 0, -5][mt_rand(0, 8)]);
    $z = CashSessions::close([], $counted, null);
    DB::run('UPDATE cash_sessions SET closed_at = ?, closed_by = ? WHERE id = ?', ["$date 22:" . sprintf('%02d', mt_rand(5, 40)) . ':00', $cashierIds[2], $sid]);
    DB::run('UPDATE cash_sessions SET z_data = ? WHERE id = ?', [json_encode(CashSessions::report($sid)), $sid]);
}
Auth::actAs(1);

// ---- wastage, issuance, petty cash expenses, count
InventoryDocs::create('WASTE', ['doc_date' => '2026-09-27', 'reason' => 'spoilage', 'post' => 1, 'lines' => [['item_id' => $item('Kangkong'), 'qty' => 1.5], ['item_id' => $item('Chicken'), 'qty' => 2]]]);
InventoryDocs::create('WASTE', ['doc_date' => '2026-10-06', 'reason' => 'expired', 'post' => 1, 'lines' => [['item_id' => $item('Evaporated Milk'), 'qty' => 6], ['item_id' => $item('Egg'), 'qty' => 12]]]);
InventoryDocs::create('ISSUE', ['doc_date' => '2026-10-03', 'issued_to' => 'Kitchen crew', 'reason' => 'Staff meals', 'expense_account_id' => $acct('5120'), 'post' => 1,
    'lines' => [['item_id' => $item('Rice (Dinorado)'), 'qty' => 3], ['item_id' => $item('Egg'), 'qty' => 12], ['item_id' => $item('Chicken'), 'qty' => 1.5]]]);
PettyCash::record(['txn_type' => 'expense', 'source' => 'fund', 'amount' => 1200, 'txn_date' => '2026-09-23', 'account_id' => $acct('6500'), 'payee' => 'Handyman Hardware', 'description' => 'Dishwashing liquid, sponges, trash bags']);
PettyCash::record(['txn_type' => 'expense', 'source' => 'fund', 'amount' => 450, 'txn_date' => '2026-10-04', 'account_id' => $acct('6400'), 'payee' => 'Lalamove', 'description' => 'Delivery of supplier order']);
PettyCash::record(['txn_type' => 'expense', 'source' => 'fund', 'amount' => 980, 'txn_date' => '2026-10-07', 'account_id' => $acct('6300'), 'payee' => 'Mang Ernie', 'description' => 'Repair of chest freezer door']);
PettyCash::record(['txn_type' => 'replenish', 'source' => 'fund', 'from_method' => 'bank', 'bank_account_id' => $bank, 'amount' => 6000, 'txn_date' => '2026-10-08', 'description' => 'Replenish petty cash']);

// bank: rent & utilities paid from the bank, card settlement
Banks::postTxn(['bank_account_id' => $bank, 'txn_type' => 'withdrawal', 'amount' => 45000, 'txn_date' => '2026-10-01', 'counter_account_id' => $acct('6100'), 'payee' => 'Ayala Property Mgt', 'description' => 'October rent', 'reference' => 'CHK 00123']);
Banks::postTxn(['bank_account_id' => $bank, 'txn_type' => 'withdrawal', 'amount' => 18650, 'txn_date' => '2026-10-05', 'counter_account_id' => $acct('6200'), 'payee' => 'Meralco / Maynilad', 'description' => 'Electricity & water - September', 'reference' => 'Online']);
Banks::postTxn(['bank_account_id' => $bank, 'txn_type' => 'deposit', 'amount' => 60000, 'txn_date' => '2026-09-29', 'counter_account_id' => $acct('1000'), 'description' => 'Deposit of cash sales', 'reference' => 'Dep slip 7781']);

// payables: pay the first two meat bills by bank, one partially
$bills = DB::all("SELECT id, amount FROM ap_bills WHERE status IN ('open','partial') ORDER BY bill_date LIMIT 2");
Payables::pay((int) $bills[0]['id'], ['amount' => $bills[0]['amount'], 'method' => 'bank', 'bank_account_id' => $bank, 'pay_date' => '2026-10-02', 'reference' => 'CHK 00124']);
Payables::pay((int) $bills[1]['id'], ['amount' => 10000, 'method' => 'bank', 'bank_account_id' => $bank, 'pay_date' => '2026-10-09', 'reference' => 'CHK 00125']);
// a manual supplier bill (ice & water delivery)
Payables::create(['supplier_id' => 2, 'bill_date' => '2026-10-06', 'amount' => 3200, 'expense_account_id' => $acct('6500'), 'ref_no' => 'INV-9981', 'description' => 'Ice & purified water delivery']);
// receivables: catering invoice and a collection
$inv = Receivables::create(['customer_id' => 1, 'inv_date' => '2026-09-26', 'amount' => 18500, 'ref_no' => 'CAT-001', 'description' => 'Catering - ABC Corp town hall (50 pax)']);
Receivables::collect($inv, ['amount' => 10000, 'method' => 'bank', 'bank_account_id' => $bank, 'pay_date' => '2026-10-08', 'reference' => 'InstaPay 44821']);
// cash advances
$ca = CashAdvances::request(['employee_id' => 1, 'amount' => 3000, 'request_date' => '2026-09-24', 'reason' => 'Tuition of daughter', 'repayment_terms' => '₱500 per payday']);
CashAdvances::approve($ca, ['release_method' => 'cash', 'release_date' => '2026-09-25']);
CashAdvances::repay($ca, ['amount' => 500, 'method' => 'payroll', 'pay_date' => '2026-09-30']);
CashAdvances::repay($ca, ['amount' => 500, 'method' => 'payroll', 'pay_date' => '2026-10-10']);
CashAdvances::request(['employee_id' => 3, 'amount' => 1500, 'request_date' => '2026-10-11', 'reason' => 'Medical check-up']);
// inventory count: produce category, posted with small variances
$produce = (int) DB::value("SELECT id FROM categories WHERE name = 'Produce'");
$cnt = InventoryCounts::start('2026-10-05', $produce, 'Weekly produce count');
$counts = [];
foreach (DB::all('SELECT id, system_qty FROM count_lines WHERE session_id = ?', [$cnt]) as $l) $counts[$l['id']] = max(0, round($l['system_qty'] * [0.97, 1, 1, 0.95, 1.02][mt_rand(0, 4)], 2));
InventoryCounts::save($cnt, $counts);
InventoryCounts::post($cnt);

// ---- today: open day with a few live orders for the recording
Auth::actAs($cashierIds[0]);
CashSessions::open(3000, today(), ['1000' => 1, '500' => 2, '100' => 5, '50' => 6, '20' => 10]);
$live = function (array $spec) use ($sell) {
    $t = Tickets::create($spec['head']);
    foreach ($spec['items'] as [$n, $q, $note]) $t = Tickets::addItem($t['id'], $sell($n), $q, $note);
    if (!empty($spec['send'])) Tickets::send($t['id']);
    if (!empty($spec['pay'])) Tickets::pay($t['id'], $spec['pay']);
    return $t;
};
$live(['head' => ['order_type' => 'dine_in', 'table_label' => '3', 'pax' => 2], 'items' => [['Adobo Rice Meal', 2, null], ['House Iced Tea', 2, 'less ice']], 'send' => 1, 'pay' => [['method' => 'cash', 'amount' => 600]]]);
$live(['head' => ['order_type' => 'takeout', 'customer_name' => 'Jenny'], 'items' => [['Pancit Canton', 1, null], ['Coke in Can', 3, null]], 'send' => 1, 'pay' => [['method' => 'gcash', 'amount' => 475, 'reference' => 'GC88213']]]);
$live(['head' => ['order_type' => 'dine_in', 'table_label' => '7', 'pax' => 4], 'items' => [['Sinigang na Baboy', 1, null], ['Sizzling Pork Sisig', 1, 'extra spicy'], ['Garlic Rice', 4, null], ['San Miguel', 4, null]], 'send' => 1]);
$live(['head' => ['order_type' => 'dine_in', 'table_label' => 'Patio 2', 'pax' => 2], 'items' => [['Halo-halo', 2, null], ['Bottled Water', 2, null]], 'send' => 1]);
$live(['head' => ['order_type' => 'delivery', 'customer_name' => 'Grab · Kuya Mike'], 'items' => [['Sisig Rice Meal', 2, null], ['House Iced Tea', 2, null]], 'send' => 0]);
DB::run("UPDATE tickets SET created_at = DATE_SUB(created_at, INTERVAL 38 MINUTE) WHERE status='open' AND table_label='7'");
DB::run("UPDATE tickets SET created_at = DATE_SUB(created_at, INTERVAL 12 MINUTE) WHERE status='open' AND table_label='Patio 2'");

$r = DB::one('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines');
echo "Tickets: " . DB::value("SELECT COUNT(*) FROM tickets WHERE status='paid'") . " paid, journal Dr {$r['d']} / Cr {$r['c']}\n";
