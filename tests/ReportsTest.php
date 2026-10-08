<?php
use App\Controllers\DashboardController;
use App\Controllers\Reports\Catalog;
use App\Controllers\Reports\ReportController;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Reports\Dashboard;
use App\Services\Reports\FinanceReports;
use App\Services\Reports\InventoryReports;
use App\Services\Reports\SalesReports;
use App\Services\Sequence;

as_user('admin');
$_SESSION = $_SESSION ?? [];

/**
 * Record a paid POS sale the way the POS posts it: ticket + lines + payment, recipe stock deduction,
 * and the journal entry Dr payment account (+ Dr sales discounts) / Cr sales, Cr output VAT, Dr COGS / Cr inventory.
 * $opt: sc (senior citizen discount with names), hour, session_id, void_line (an extra voided line)
 */
function rep_sale(string $date, array $lines, string $method, array $opt = []): array
{
    return DB::transaction(function () use ($date, $lines, $method, $opt) {
        $subtotal = 0.0;
        foreach ($lines as &$l) {
            $l['price'] = (float) DB::value('SELECT price FROM items WHERE id = ?', [$l['item_id']]);
            $subtotal += $l['price'] * $l['qty'];
        }
        unset($l);
        $disc = $exempt = 0.0;
        if (!empty($opt['sc'])) {
            $exempt = r2($subtotal / 1.12);
            $disc = r2($exempt * 0.2);
            $total = r2($exempt - $disc);
            $vat = 0.0;
        } else {
            $total = r2($subtotal);
            $vat = r2($total * 12 / 112);
        }
        $receipt = Sequence::next('OR', 'OR', 8);
        $paidAt = sprintf('%s %02d:15:00', $date, $opt['hour'] ?? 12);
        $id = DB::insert('tickets', [
            'ticket_no' => Sequence::next('TKT', 'T', 6), 'receipt_no' => $receipt, 'cash_session_id' => $opt['session_id'] ?? null,
            'business_date' => $date, 'table_label' => $opt['table'] ?? null, 'order_type' => 'dine_in', 'pax' => 2, 'status' => 'paid',
            'subtotal' => r2($subtotal), 'discount_type' => !empty($opt['sc']) ? 'sc' : 'none', 'discount_rate' => !empty($opt['sc']) ? 20 : 0,
            'discount_amount' => $disc, 'sc_count' => !empty($opt['sc']) ? 1 : 0,
            'sc_details' => !empty($opt['sc']) ? json_encode([['name' => 'Lola Nena', 'id_no' => 'SC-123']]) : null,
            'vatable_sales' => empty($opt['sc']) ? r2($total - $vat) : 0, 'vat_amount' => $vat, 'vat_exempt_sales' => $exempt,
            'total' => $total, 'paid_total' => $total, 'paid_by' => rep_user_id(), 'paid_at' => $paidAt, 'created_at' => $paidAt, 'created_by' => rep_user_id(),
        ]);
        foreach ($lines as $l) {
            DB::insert('ticket_items', ['ticket_id' => $id, 'item_id' => $l['item_id'], 'name' => DB::value('SELECT name FROM items WHERE id = ?', [$l['item_id']]),
                'qty' => $l['qty'], 'price' => $l['price'], 'line_total' => r2($l['price'] * $l['qty']), 'status' => 'active', 'created_at' => $paidAt]);
        }
        if (!empty($opt['void_line'])) {
            DB::insert('ticket_items', ['ticket_id' => $id, 'item_id' => $opt['void_line'], 'name' => 'Voided line', 'qty' => 1, 'price' => 25,
                'line_total' => 25, 'status' => 'void', 'void_reason' => 'Wrong order', 'voided_by' => rep_user_id(), 'voided_at' => $paidAt, 'created_at' => $paidAt]);
        }
        DB::insert('payments', ['ticket_id' => $id, 'cash_session_id' => $opt['session_id'] ?? null, 'method' => $method, 'amount' => $total,
            'tendered' => $total, 'user_id' => rep_user_id(), 'created_at' => $paidAt]);
        $cogs = Inventory::consume($lines, 'SALE', ['ref_type' => 'ticket', 'ref_id' => $id, 'ref_no' => $receipt, 'bdate' => $date]);
        $account = ['cash' => 'cash_on_hand', 'card' => 'card_clearing', 'gcash' => 'ewallet_clearing'][$method];
        $je = Ledger::post($date, "POS sale $receipt", [
            ['key' => $account, 'debit' => $total],
            ['key' => 'sales_discounts', 'debit' => $disc],
            ['key' => 'sales', 'credit' => r2($total - $vat + $disc)],
            ['key' => 'output_vat', 'credit' => $vat],
            ['key' => 'cogs', 'debit' => $cogs],
            ['key' => 'inventory', 'credit' => $cogs],
        ], 'pos_sale', $id, $receipt);
        DB::update('tickets', $id, ['cogs' => $cogs, 'journal_entry_id' => $je]);
        return DB::one('SELECT * FROM tickets WHERE id = ?', [$id]);
    });
}

/** Id of the user the test is acting as. */
function rep_user_id(): int
{
    return (int) App\Core\Auth::id();
}

/** Render a report page through the controller (views included); PHP warnings fail the test. */
function rep_page(string $method, array $query, string $path = '/reports'): string|Response
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $path . '?' . http_build_query($query);
    $GLOBALS['__request'] = $req = new Request();
    set_error_handler(function ($no, $msg, $file, $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
    try {
        return $method === 'dashboard' ? (new DashboardController())->index($req) : (new ReportController())->$method($req);
    } finally {
        restore_error_handler();
    }
}

/** Open every report of every group (screen and Excel). */
function rep_all_pages(array $extra): void
{
    foreach (['sales', 'inventory', 'finance'] as $group) {
        foreach (array_keys(Catalog::group($group)['reports']) as $key) {
            $html = rep_page($group, ['report' => $key, 'from' => '2026-09-01', 'to' => '2026-09-30'] + $extra, "/reports/$group");
            ok(is_string($html) && str_contains($html, 'page-header'), "$group/$key renders");
            $x = rep_page($group, ['report' => $key, 'from' => '2026-09-01', 'to' => '2026-09-30', 'export' => 'xlsx'] + $extra, "/reports/$group");
            if ($x instanceof Response) ok(str_starts_with($x->body, 'PK'), "$group/$key exports xlsx");
        }
    }
    ok(str_contains(rep_page('dashboard', ['from' => '2026-09-01', 'to' => '2026-09-30'], '/'), 'Net sales'), 'dashboard renders');
    ok(str_contains(rep_page('dashboard', ['from' => '2026-09-10', 'to' => '2026-09-10'], '/'), 'Sales by hour'), 'one-day dashboard shows hours');
}

test('every report and the dashboard run on an empty database', function () {
    rep_all_pages(['item_id' => item_id('Chicken'), 'account_id' => Ledger::account('cash_on_hand')]);
    $d = Dashboard::data('2026-09-01', '2026-09-30');
    eq(0, $d['kpi']['net_sales']);
    eq(0, $d['kpi']['food_cost_pct']);
});

// ------------------------------------------------------------------ sample month (September 2026)
$GLOBALS['rep'] = [];
test('set up a month of transactions', function () {
    $chicken = item_id('Chicken');
    // Opening capital, petty cash fund and a bank account
    Ledger::post('2026-09-01', 'Owner investment', [['key' => 'cash_on_hand', 'debit' => 50000], ['key' => 'capital', 'credit' => 50000]]);
    $pcr = DB::insert('petty_cash_txns', ['doc_no' => 'PCV-1', 'txn_date' => '2026-09-01', 'txn_type' => 'replenish', 'source' => 'fund', 'amount' => 2000, 'status' => 'posted']);
    Ledger::post('2026-09-01', 'Petty cash fund', [['key' => 'petty_cash', 'debit' => 2000], ['key' => 'cash_on_hand', 'credit' => 2000]], 'petty_cash', $pcr);
    $gl = DB::insert('accounts', ['code' => '1031', 'name' => 'Cash in Bank - BDO', 'type' => 'asset', 'subtype' => 'bank', 'active' => 1]);
    $bank = DB::insert('bank_accounts', ['bank_name' => 'BDO', 'account_no' => '001234567', 'gl_account_id' => $gl, 'active' => 1]);
    Ledger::post('2026-09-02', 'Deposit', [['account_id' => $gl, 'debit' => 10000, 'bank_account_id' => $bank], ['key' => 'cash_on_hand', 'credit' => 10000]], 'bank');
    Ledger::post('2026-09-20', 'Bank charge', [['key' => 'bank_charges', 'debit' => 50], ['account_id' => $gl, 'credit' => 50, 'bank_account_id' => $bank]], 'bank');

    // Stock in (09-05): chicken 10 kg @190, coke 48 @38, rice 50 kg @55
    $doc = DB::insert('inv_docs', ['doc_type' => 'RECEIVE', 'doc_no' => 'RR-1', 'doc_date' => '2026-09-05', 'status' => 'posted', 'supplier_id' => 1,
        'payment_mode' => 'cash', 'total_cost' => 1900 + 1824 + 2750]);
    foreach ([[$chicken, 10, 190], [item_id('Coke'), 48, 38], [item_id('Rice'), 50, 55]] as [$item, $q, $cost]) {
        DB::insert('inv_doc_lines', ['doc_id' => $doc, 'item_id' => $item, 'qty' => $q, 'base_qty' => $q, 'unit_cost' => $cost, 'line_total' => $q * $cost]);
        Inventory::move(['item_id' => $item, 'qty' => $q, 'mtype' => 'RECEIVE', 'unit_cost' => $cost, 'ref_type' => 'inv_doc', 'ref_id' => $doc, 'ref_no' => 'RR-1', 'bdate' => '2026-09-05']);
    }
    Ledger::post('2026-09-05', 'Stock in RR-1', [['key' => 'inventory', 'debit' => 6474], ['key' => 'cash_on_hand', 'credit' => 6474]], 'inv_receive', $doc, 'RR-1');

    // Business day 09-10 with two sales, 09-11 with one sale and one voided receipt
    $s1 = DB::insert('cash_sessions', ['business_date' => '2026-09-10', 'status' => 'open', 'opened_by' => rep_user_id(), 'opened_at' => '2026-09-10 08:00:00', 'opening_cash' => 2000]);
    $a = rep_sale('2026-09-10', [['item_id' => item_id('Chicken Adobo'), 'qty' => 2], ['item_id' => item_id('Coke'), 'qty' => 2]], 'cash',
        ['hour' => 10, 'session_id' => $s1, 'table' => 'T5', 'void_line' => item_id('Plain Rice')]);
    $b = rep_sale('2026-09-10', [['item_id' => item_id('Adobo Rice Meal'), 'qty' => 1]], 'card', ['hour' => 12, 'session_id' => $s1, 'sc' => true]);
    $c = rep_sale('2026-09-11', [['item_id' => item_id('Coke'), 'qty' => 3]], 'gcash', ['hour' => 19]);
    $v = rep_sale('2026-09-11', [['item_id' => item_id('Coke'), 'qty' => 1]], 'cash', ['hour' => 20]);
    Inventory::reverseMovements('ticket', (int) $v['id'], '2026-09-11', 'Void');
    Ledger::reverse((int) $v['journal_entry_id'], '2026-09-11', 'Void receipt');
    DB::update('tickets', (int) $v['id'], ['status' => 'void', 'void_reason' => 'Customer left', 'voided_by' => rep_user_id(), 'voided_at' => '2026-09-11 20:30:00']);

    // Wastage 0.5 kg chicken (09-11) and an LPG expense from petty cash (09-10)
    $w = DB::insert('inv_docs', ['doc_type' => 'WASTE', 'doc_no' => 'WS-1', 'doc_date' => '2026-09-11', 'status' => 'posted', 'reason' => 'Spoiled', 'total_cost' => 95]);
    DB::insert('inv_doc_lines', ['doc_id' => $w, 'item_id' => $chicken, 'qty' => 0.5, 'base_qty' => 0.5, 'unit_cost' => 190, 'line_total' => 95]);
    Inventory::move(['item_id' => $chicken, 'qty' => -0.5, 'mtype' => 'WASTE', 'ref_type' => 'inv_doc', 'ref_id' => $w, 'ref_no' => 'WS-1', 'bdate' => '2026-09-11']);
    Ledger::post('2026-09-11', 'Wastage WS-1', [['key' => 'wastage', 'debit' => 95], ['key' => 'inventory', 'credit' => 95]], 'inv_waste', $w, 'WS-1');
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE code = '6210'");
    $pc = DB::insert('petty_cash_txns', ['doc_no' => 'PCV-2', 'txn_date' => '2026-09-10', 'txn_type' => 'expense', 'source' => 'fund', 'amount' => 150,
        'account_id' => $lpg, 'payee' => 'Petron', 'status' => 'posted']);
    Ledger::post('2026-09-10', 'LPG refill', [['account_id' => $lpg, 'debit' => 150], ['key' => 'petty_cash', 'credit' => 150]], 'petty_cash', $pc, 'PCV-2');

    // Open payables / receivables for aging, a released cash advance
    DB::insert('ap_bills', ['bill_no' => 'AP-1', 'supplier_id' => 1, 'bill_date' => '2026-08-01', 'due_date' => '2026-08-16', 'amount' => 1000, 'status' => 'open']);
    DB::insert('ap_bills', ['bill_no' => 'AP-2', 'supplier_id' => 1, 'bill_date' => '2026-09-25', 'due_date' => '2026-10-15', 'amount' => 500, 'paid_amount' => 100, 'status' => 'partial']);
    DB::insert('ap_bills', ['bill_no' => 'AP-3', 'supplier_id' => 2, 'bill_date' => '2026-05-01', 'due_date' => '2026-05-01', 'amount' => 700, 'status' => 'open']);
    DB::insert('ar_invoices', ['invoice_no' => 'AR-1', 'customer_id' => 1, 'inv_date' => '2026-09-01', 'due_date' => '2026-09-20', 'amount' => 300, 'status' => 'open']);
    DB::insert('cash_advances', ['doc_no' => 'CA-1', 'employee_id' => 1, 'request_date' => '2026-09-03', 'amount' => 1000, 'status' => 'approved', 'balance' => 1000]);

    $GLOBALS['rep'] = ['session' => $s1, 'bank' => $bank, 'a' => $a, 'b' => $b, 'c' => $c, 'v' => $v];
    assert_books_balance();
});

test('dashboard KPIs', function () {
    ['a' => $a, 'b' => $b, 'c' => $c] = $GLOBALS['rep'];
    $k = Dashboard::data('2026-09-01', '2026-09-30')['kpi'];
    eq(500 + 142.14 + 195, $k['net_sales'], 'net sales');
    eq(3, $k['receipts']);
    eq(53.57 + 20.89, $k['vat'], 'vat');
    eq(762.68, $k['net_of_vat'], 'net of VAT');
    eq(r2(837.14 / 3), $k['avg_ticket']);
    eq(35.54, $k['discounts']);
    $cogs = r2($a['cogs'] + $b['cogs'] + $c['cogs']);
    eq($cogs, $k['cogs']);
    eq(r2(762.68 - $cogs), $k['gross_profit']);
    eq(r2($cogs / 762.68 * 100), $k['food_cost_pct']);
    eq(1, $k['voids']);
    eq(65, $k['void_amount']);
    eq(95, $k['wastage']);
    eq(6474, $k['purchases']);
    eq(150, $k['petty_cash']);
    eq(200, $k['operating_expenses'], 'LPG 150 + bank charge 50 (wastage is cost of sales)');
    eq(r2(762.68 - $cogs - 95 - 200), $k['net_income_est']);
    // the income statement agrees with the dashboard estimate
    eq($k['net_income_est'], FinanceReports::incomeStatement('2026-09-01', '2026-09-30')['net_income']);
});

test('dashboard series and financial position', function () {
    $d = Dashboard::data('2026-09-01', '2026-09-30');
    eq(2, count($d['daily']));
    eq(3, count($d['hourly']));
    eq('Chicken Adobo', $d['top_items'][0]['name']);
    eq(['Ulam / Viands', 'Beverages', 'Rice Meals'], array_column($d['categories'], 'name'));
    $p = $d['position'];
    eq(50000 - 2000 - 10000 - 6474 + 500, $p['cash_on_hand'], 'cash: void receipt reversed');
    eq(1850, $p['petty_cash']);
    eq(9950, $p['banks'][0]['balance']);
    eq(1000 + 400 + 700, $p['ap_total']);
    eq(300, $p['ar_total']);
    eq(1000, $p['ca_outstanding']);
    ok($d['session'] !== null, 'open business day');
});

test('sales by item, category, payment, hour and receipts', function () {
    $items = array_column(SalesReports::items('2026-09-01', '2026-09-30'), null, 'item');
    eq(5, $items['Coke in Can']['qty'], 'voided receipt excluded');
    eq(325, $items['Coke in Can']['gross']);
    eq(370, $items['Chicken Adobo']['gross']);
    eq(199, $items['Adobo Rice Meal']['gross']);
    eq(100, array_sum(array_column($items, 'share_pct')), '% of sales adds up');
    ok(!isset($items['Plain Rice']), 'voided line excluded');
    $cats = array_column(SalesReports::categories('2026-09-01', '2026-09-30'), 'gross', 'category');
    eq(370, $cats['Ulam / Viands']);
    eq(325, $cats['Beverages']);
    eq(199, $cats['Rice Meals']);
    $pay = array_column(SalesReports::payments('2026-09-01', '2026-09-30'), 'amount', 'method');
    eq(['cash' => 500.0, 'gcash' => 195.0, 'card' => 142.14], array_map('floatval', $pay));
    eq(['10:00 - 10:59', '12:00 - 12:59', '19:00 - 19:59'], array_column(SalesReports::hourly('2026-09-01', '2026-09-30'), 'hour'));
    $receipts = SalesReports::receipts('2026-09-01', '2026-09-30');
    eq(4, count($receipts));
    eq('T5', $receipts[0]['table_label']);
    eq('Cash', $receipts[0]['payment']);
    eq(1, count(SalesReports::receipts('2026-09-01', '2026-09-30', 'void')));
    $daily = SalesReports::daily('2026-09-01', '2026-09-30');
    eq(642.14, $daily[0]['net_sales']);
    $voids = SalesReports::voids('2026-09-01', '2026-09-30');
    eq(['Item', 'Receipt'], array_column($voids, 'kind'));
    $sc = SalesReports::discounts('2026-09-01', '2026-09-30');
    eq('SC-123', $sc[0]['id_numbers']);
    eq('Lola Nena', $sc[0]['names']);
    eq('Senior Citizen (whole receipt)', $sc[0]['discount_type']);
    eq(1, count(SalesReports::cashiers('2026-09-01', '2026-09-30')));
});

test('end of day history and X-reading', function () {
    $eod = SalesReports::eod('2026-09-01', '2026-09-30');
    eq(1, count($eod));
    eq(2, $eod[0]['receipts']);
    eq(642.14, $eod[0]['net_sales']);
    $r = SalesReports::reading($GLOBALS['rep']['session']);
    eq(2, $r['sales']['cnt']);
    eq(2500, $r['cash']['expected'], 'opening 2000 + cash sales 500');
    $_GET = [];
    $html = (new ReportController())->eod(new Request(), (string) $GLOBALS['rep']['session']);
    ok(str_contains($html, 'X-READING') && str_contains($html, 'print-root'), 'printable X-reading');
});

test('trial balance balances; income statement; balance sheet check is zero', function () {
    $tb = FinanceReports::trialBalance('2026-09-01', '2026-09-30');
    eq(round(array_sum(array_column($tb, 'period_debit')), 2), round(array_sum(array_column($tb, 'period_credit')), 2), 'period');
    eq(round(array_sum(array_column($tb, 'ending_debit')), 2), round(array_sum(array_column($tb, 'ending_credit')), 2), 'ending');
    $tbOct = FinanceReports::trialBalance('2026-10-01', '2026-10-31');
    eq(round(array_sum(array_column($tbOct, 'opening')), 2), 0.0, 'openings net to zero');

    $is = FinanceReports::incomeStatement('2026-09-01', '2026-09-30');
    eq(762.68, $is['revenue'], 'sales less SC discount, void reversed');
    eq(r2(array_sum(array_column(array_filter([$GLOBALS['rep']['a'], $GLOBALS['rep']['b'], $GLOBALS['rep']['c']]), 'cogs')) + 95), $is['total_cogs']);
    eq(200, $is['total_opex']);
    eq(r2($is['revenue'] - $is['total_cogs'] - $is['total_opex']), $is['net_income']);

    $bs = FinanceReports::balanceSheet('2026-09-30');
    eq(0, $bs['check']);
    $eq = array_column($bs['equity'], 'amount', 'name');
    eq($is['net_income'], $eq['Net Income (cumulative, unclosed)']);
    eq(0, FinanceReports::balanceSheet('2026-09-10')['check']);
});

test('general ledger, journal and bank register running balances', function () {
    $cash = Ledger::account('cash_on_hand');
    $gl = FinanceReports::generalLedger($cash, '2026-09-02', '2026-09-30');
    eq(48000, $gl[0]['balance'], 'beginning = 50000 - 2000 petty fund');
    eq(Ledger::balance($cash, '2026-09-30'), end($gl)['balance']);
    $sources = array_column(FinanceReports::journal('2026-09-11', '2026-09-11'), 'source');
    ok(in_array('pos_sale', $sources, true), 'journal lists sales and their reversals');
    $bank = FinanceReports::bankRegister($GLOBALS['rep']['bank'], '2026-09-01', '2026-09-30');
    eq(0, $bank[0]['balance']);
    eq(10000, $bank[1]['balance']);
    eq(9950, $bank[2]['balance']);
});

test('stock card running balance and movement summary', function () {
    $card = InventoryReports::stockCard(item_id('Chicken'), '2026-09-10', '2026-09-30');
    eq('BEGINNING', $card[0]['type']);
    eq(10, $card[0]['balance']);
    eq([10.0, 9.5, 9.25, 8.75], array_map('floatval', array_column($card, 'balance')));
    eq(0.5, $card[3]['qty_out'], 'wastage');
    $coke = array_column(InventoryReports::stockCard(item_id('Coke'), '2026-09-01', '2026-09-30'), 'balance');
    eq(43, end($coke), '48 - 2 - 3 - 1 + 1 (void)');
    $mv = array_column(InventoryReports::movement('2026-09-10', '2026-09-30'), null, 'item');
    $ch = $mv['Chicken (whole cut)'];
    eq(10, $ch['beginning']);
    eq(0.75, $ch['sold']);
    eq(0.5, $ch['wasted']);
    eq(8.75, $ch['ending']);
    eq(5, $mv['Coke in Can']['sold'], 'void sale nets out');
    eq(stock('Chicken'), $ch['ending']);
    $onhand = array_column(InventoryReports::onHand(), null, 'item');
    eq('REORDER', $onhand['Egg']['status'], 'no eggs, reorder point 30');
    eq(r2(43 * 38), $onhand['Coke in Can']['value']);
    $reorder = array_column(InventoryReports::reorder(), null, 'item');
    eq(60, $reorder['Egg']['suggested_order']);
    $usage = array_column(InventoryReports::usage('2026-09-01', '2026-09-30'), null, 'item');
    eq(0.75, $usage['Chicken (whole cut)']['sold_usage']);
    eq(1, count(InventoryReports::documents('WASTE', '2026-09-01', '2026-09-30')));
    eq(3, count(InventoryReports::documents('RECEIVE', '2026-09-01', '2026-09-30')));
    $costing = array_column(InventoryReports::recipeCosting(), null, 'item');
    eq(r2(185 / 1.12), $costing['Chicken Adobo']['price_net_of_vat']);
    ok($costing['Chicken Adobo']['food_cost_pct'] > 0, 'food cost %');
});

test('AP / AR aging buckets', function () {
    $ap = array_column(FinanceReports::aging('ap', '2026-09-30'), null, 'party');
    $metro = $ap['Metro Meat Supply'];
    eq(1000, $metro['d31_60'], '45 days past due');
    eq(400, $metro['current'], 'not yet due, partially paid');
    eq(1400, $metro['total']);
    eq(700, $ap['Divisoria Dry Goods Trading']['over_90']);
    eq('Metro Meat Supply', FinanceReports::aging('ap', '2026-09-30')[0]['party'], 'largest balance first');
    eq(1000, array_column(FinanceReports::aging('ap', '2026-09-01'), null, 'party')['Metro Meat Supply']['total'], 'bills after the as-of date are excluded');
    $ar = FinanceReports::aging('ar', '2026-09-30');
    eq(300, $ar[0]['d1_30']);
    eq(300, FinanceReports::aging('ar', '2026-09-15')[0]['current']);
});

test('petty cash report and cash advances', function () {
    $p = FinanceReports::pettyCash('2026-09-05', '2026-09-30');
    eq(2000, $p['beginning']);
    eq(1850, $p['ending']);
    eq(150, $p['spent']);
    eq(0, $p['added']);
    eq('LPG / Gas', $p['by_account'][0]['account']);
    eq(2000, FinanceReports::pettyCash('2026-09-01', '2026-09-30')['added']);
    eq(1000, FinanceReports::cashAdvances('2026-09-01', '2026-09-30')[0]['balance']);
});

test('every report page renders and exports with data', function () {
    rep_all_pages(['item_id' => item_id('Chicken'), 'account_id' => Ledger::account('cash_on_hand'), 'bank_account_id' => $GLOBALS['rep']['bank']]);
    $tb = rep_page('finance', ['report' => 'is', 'from' => '2026-09-01', 'to' => '2026-09-30'], '/reports/finance');
    ok(str_contains($tb, 'GROSS PROFIT') && str_contains($tb, 'NET INCOME'), 'income statement layout');
    $bs = rep_page('finance', ['report' => 'bs', 'to' => '2026-09-30'], '/reports/finance');
    ok(str_contains($bs, 'TOTAL ASSETS') && !str_contains($bs, 'Out of balance'), 'balance sheet balances');
    $pick = rep_page('inventory', ['report' => 'stockcard'], '/reports/inventory');
    ok(str_contains($pick, 'Select the item'), 'stock card asks for an item');
});

test('a user with only pettycash.view sees just the petty cash report', function () {
    $role = DB::insert('roles', ['name' => 'Petty only', 'permissions' => '["pettycash.view"]']);
    DB::insert('users', ['username' => 'pettyonly', 'full_name' => 'Petty Only', 'password_hash' => 'x', 'role_id' => $role, 'active' => 1]);
    as_user('pettyonly');
    try {
        $html = rep_page('finance', ['report' => 'tb'], '/reports/finance');
        ok(str_contains($html, 'Fund beginning balance'), 'falls back to the petty cash report');
        ok(!str_contains($html, 'Trial balance'), 'other finance reports hidden');
    } finally {
        as_user('admin');
    }
});
