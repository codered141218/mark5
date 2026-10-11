<?php
/**
 * POS beginning cash taken from the petty cash fund (and returned at close); orders board print state.
 */
use App\Core\DB;
use App\Services\Finance\PettyCash;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;

as_user('admin');

test('open day from petty cash: fund → drawer at open, back to the fund at close; drawer expected cash unchanged', function () {
    PettyCash::record(['txn_type' => 'replenish', 'amount' => 3000, 'from_method' => 'cash']);
    $cash = acct_balance('cash_on_hand');
    eq(3000, PettyCash::fundBalance());
    throws(fn () => CashSessions::open(5000, null, null, null, 'petty_cash'), 'has only');
    eq(null, CashSessions::current(), 'nothing opened when the fund is short');

    $s = CashSessions::open(2000, null, null, null, 'petty_cash');
    eq('petty_cash', $s['opening_source']);
    eq(1000, PettyCash::fundBalance());
    eq(r2($cash + 2000), acct_balance('cash_on_hand'));
    eq(2000, PettyCash::atPos());
    $out = DB::one('SELECT * FROM petty_cash_txns WHERE id = ?', [$s['opening_txn_id']]);
    eq('change_fund', $out['txn_type']);
    eq((int) $s['id'], (int) $out['cash_session_id']);
    throws(fn () => PettyCash::void((int) $out['id'], 'x'), 'cannot be voided');

    $t = Tickets::create(['order_type' => 'takeout']);
    Tickets::pay(Tickets::addItem($t['id'], item_id('Chicken Adobo'), 1)['id'], [['method' => 'cash', 'amount' => 185]]);
    $x = CashSessions::report((int) $s['id']);
    eq(2185, $x['cash']['expected'], 'the beginning cash is not a payout');
    eq([], $x['payouts']);

    $z = CashSessions::close([], 2185);
    eq(0, $z['cash']['variance']);
    eq(2000, $z['cash']['returned_to_fund']);
    eq('petty_cash', $z['cash']['opening_source']);
    eq(3000, PettyCash::fundBalance(), 'fund is whole again');
    eq(r2($cash + 185), acct_balance('cash_on_hand'), 'cash on hand keeps only the sales');
    eq(0, PettyCash::atPos());
    eq('fund_return', DB::value('SELECT p.txn_type FROM petty_cash_txns p JOIN cash_sessions s ON s.return_txn_id = p.id WHERE s.id = ?', [$s['id']]));
});

test('open day from petty cash, beginning cash kept in the drawer at close; open from cash on hand leaves the fund alone', function () {
    PettyCash::record(['txn_type' => 'replenish', 'amount' => 1000, 'from_method' => 'cash']);
    $fund = PettyCash::fundBalance();
    CashSessions::open(1000, null, null, null, 'petty_cash');
    CashSessions::close([], 1000, null, false);
    eq(r2($fund - 1000), PettyCash::fundBalance(), 'not returned');

    $fund = PettyCash::fundBalance();
    $s = CashSessions::open(500);
    eq('cash', $s['opening_source']);
    eq(null, $s['opening_txn_id']);
    eq($fund, PettyCash::fundBalance());
    $z = CashSessions::close([], 500);
    eq(0, $z['cash']['returned_to_fund']);
    eq($fund, PettyCash::fundBalance());
    // zero beginning cash from petty cash: nothing moves
    $s = CashSessions::open(0, null, null, null, 'petty_cash');
    eq('cash', $s['opening_source']);
    CashSessions::close([], 0);
});

test('orders board: new (never printed), items added after the slip, printed, empty', function () {
    CashSessions::open(0);
    $new = Tickets::create(['order_type' => 'takeout']);
    Tickets::addItem($new['id'], item_id('Chicken Adobo'), 2);
    $added = Tickets::create(['order_type' => 'dine_in', 'table_label' => '3']);
    Tickets::addItem($added['id'], item_id('Chicken Adobo'), 1);
    Tickets::done($added['id']);
    Tickets::addItem($added['id'], item_id('Coke'), 3);
    $printed = Tickets::create(['order_type' => 'dine_in', 'table_label' => '4']);
    Tickets::addItem($printed['id'], item_id('Coke'), 1);
    Tickets::done($printed['id']);
    $empty = Tickets::create(['order_type' => 'takeout']);
    $rows = array_column(Tickets::openList(), null, 'id');
    eq([1, 0, 2.0], [$rows[$new['id']]['unsent'], $rows[$new['id']]['sent'], $rows[$new['id']]['unsent_qty']]);
    eq([1, 1, 3.0], [$rows[$added['id']]['unsent'], $rows[$added['id']]['sent'], $rows[$added['id']]['unsent_qty']]);
    eq([0, 1], [$rows[$printed['id']]['unsent'], $rows[$printed['id']]['sent']]);
    eq([0, 0, 0.0], [$rows[$empty['id']]['unsent'], $rows[$empty['id']]['sent'], $rows[$empty['id']]['item_count']]);
    eq(4.0, $rows[$added['id']]['item_count']);
});

test('waiter sends with Done (no printer): stays "waiting" until a device prints it; markPrinted; payment keeps it', function () {
    if (!CashSessions::current()) CashSessions::open(0);
    $t = Tickets::create(['order_type' => 'dine_in', 'table_label' => '9']);
    Tickets::addItem($t['id'], item_id('Chicken Adobo'), 2);
    $r = Tickets::done($t['id']);
    eq(1, count($r['sent']));
    eq(1, count($r['waiting']));
    $row = array_column(Tickets::openList(), null, 'id')[$t['id']];
    eq([0, 1, 2.0, 0], [$row['unsent'], $row['waiting'], $row['waiting_qty'], $row['printed']], 'sent, not printed');
    // more items, Done again: the earlier unprinted line is still waiting too
    Tickets::addItem($t['id'], item_id('Coke'), 1);
    $r = Tickets::done($t['id']);
    eq(1, count($r['sent']));
    eq(2, count($r['waiting']));
    $ids = array_column($r['waiting'], 'id');
    $after = Tickets::markPrinted($t['id'], $ids);
    ok(array_reduce($after['items'], fn ($c, $i) => $c && $i['printed'] === 1, true));
    $row = array_column(Tickets::openList(), null, 'id')[$t['id']];
    eq([0, 0, 2], [$row['unsent'], $row['waiting'], $row['printed']]);
    // lines of another order are not touched
    $o = Tickets::create(['order_type' => 'takeout']);
    $line = Tickets::addItem($o['id'], item_id('Coke'), 1)['items'][0]['id'];
    Tickets::markPrinted($t['id'], [$line]);
    eq(0, (int) DB::value('SELECT printed FROM ticket_items WHERE id = ?', [$line]));
    // Done on the other order: its line waits for a printer too
    Tickets::done($o['id']);
    $row = array_column(Tickets::openList(), null, 'id')[$o['id']];
    eq(1, $row['waiting']);
});

test('upgrade step 5: existing sent lines count as printed; re-running does not reset printed flags', function () {
    DB::pdo()->exec('ALTER TABLE ticket_items DROP COLUMN printed');
    $sent = (int) DB::value('SELECT COUNT(*) FROM ticket_items WHERE kitchen_sent = 1');
    ok($sent > 0);
    DB::run("UPDATE settings SET value = '4' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    eq($sent, (int) DB::value('SELECT COUNT(*) FROM ticket_items WHERE printed = 1'));
    DB::run('UPDATE ticket_items SET printed = 0');
    DB::run("UPDATE settings SET value = '4' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    eq(0, (int) DB::value('SELECT COUNT(*) FROM ticket_items WHERE printed = 1'));
});

test('sales trend: buckets with no gaps, previous period of the same length, weekly / monthly grouping, top 5 series', function () {
    if (!CashSessions::current()) CashSessions::open(0);
    $sid = (int) CashSessions::current()['id'];
    $mk = function (string $date, float $total, int $pax = 2) use ($sid) {
        $id = DB::insert('tickets', ['ticket_no' => 'TR' . mt_rand(), 'receipt_no' => 'TRR' . mt_rand(), 'cash_session_id' => $sid, 'business_date' => $date,
            'order_type' => 'dine_in', 'pax' => $pax, 'status' => 'paid', 'subtotal' => $total, 'total' => $total, 'created_by' => 1, 'created_at' => "$date 12:00:00"]);
        DB::insert('ticket_items', ['ticket_id' => $id, 'item_id' => item_id('Chicken Adobo'), 'name' => 'Chicken Adobo', 'qty' => 1, 'price' => $total,
            'line_total' => $total, 'status' => 'active', 'kitchen_sent' => 1, 'created_by' => 1, 'created_at' => "$date 12:00:00"]);
    };
    $mk('2030-03-02', 100); $mk('2030-03-02', 300, 4); $mk('2030-03-05', 200);
    $mk('2030-02-27', 50);                                   // previous period (Feb 24 – Mar 1 for a 6-day range)
    $t = App\Services\Reports\SalesReports::trend('2030-03-02', '2030-03-07', 'day');
    eq(6, count($t['current']), 'one bucket per day, days without sales included');
    eq([400.0, 0.0, 0.0, 200.0, 0.0, 0.0], array_column($t['current'], 'net_sales'));
    eq(2, $t['current'][0]['receipts']);
    eq(200.0, $t['current'][0]['avg_ticket']);
    eq(['2030-02-24', '2030-03-01'], [$t['prev_from'], $t['prev_to']]);
    eq(600.0, $t['totals']['net_sales']);
    eq(50.0, $t['prev_totals']['net_sales']);
    eq(8, $t['totals']['pax']);
    $w = App\Services\Reports\SalesReports::trend('2030-03-02', '2030-03-07', 'week');   // Mar 2 2030 is a Saturday
    eq(['Feb 25', 'Mar 4'], array_column($w['current'], 'label'), 'weeks start on Monday');
    eq(['2030-03-02', '2030-03-03'], [$w['current'][0]['from'], $w['current'][0]['to']], 'the first week is cut to the range');
    eq([400.0, 200.0], array_column($w['current'], 'net_sales'));
    $m = App\Services\Reports\SalesReports::trend('2030-02-27', '2030-03-07', 'month');
    eq(['Feb 2030', 'Mar 2030'], array_column($m['current'], 'label'));
    eq('day', App\Services\Reports\SalesReports::autoGrain('2030-01-01', '2030-01-31'));
    eq('week', App\Services\Reports\SalesReports::autoGrain('2030-01-01', '2030-06-30'));
    eq('month', App\Services\Reports\SalesReports::autoGrain('2029-01-01', '2030-06-30'));
    $s = App\Services\Reports\SalesReports::topSeries('2030-03-02', '2030-03-07', 'item', 'day');
    eq('Chicken Adobo', $s['series'][0]['name']);
    eq([400.0, 0.0, 0.0, 200.0, 0.0, 0.0], $s['series'][0]['values']);
    eq(6, count($s['labels']));
});
