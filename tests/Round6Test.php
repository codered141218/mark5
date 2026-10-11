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

test('business day opens only for today: back-dated and post-dated dates are refused', function () {
    if ($s = CashSessions::current()) { DB::run("UPDATE tickets SET status = 'void' WHERE status = 'open'"); CashSessions::close([], 0); }
    throws(fn () => CashSessions::open(0, add_days(today(), -1)), 'only be opened for today');
    throws(fn () => CashSessions::open(0, add_days(today(), 1)), 'only be opened for today');
    eq(null, CashSessions::current());
    eq(today(), CashSessions::open(0, today())['business_date']);
    CashSessions::close([], 0);
    eq(today(), CashSessions::open(0)['business_date'], 'no date given: today');
});

test('petty cash fund in the POS drawer: back-office payments from petty cash are POS payouts; the fund gets back the rest', function () {
    if (CashSessions::current()) { DB::run("UPDATE tickets SET status = 'void' WHERE status = 'open'"); CashSessions::close([], (float) CashSessions::report((int) CashSessions::current()['id'])['cash']['expected']); }
    // fund exactly 3,700
    $fund = PettyCash::fundBalance();
    if ($fund < 3700) PettyCash::record(['txn_type' => 'replenish', 'amount' => r2(3700 - $fund), 'from_method' => 'cash']);
    $fund = PettyCash::fundBalance();
    $cashGl = acct_balance('cash_on_hand');
    $s = CashSessions::open(3700, null, null, null, 'petty_cash');
    eq(r2($fund - 3700), PettyCash::fundBalance());

    // stock-in paid from petty cash in the back office
    $kg = (int) DB::value("SELECT id FROM uoms WHERE abbr = 'kg'");
    $rr = App\Services\InventoryDocs::create('RECEIVE', ['payment_mode' => 'petty_cash', 'post' => 1,
        'lines' => [['item_id' => item_id('Chicken'), 'qty' => 2, 'uom_id' => $kg, 'line_total' => 500]]]);
    $x = CashSessions::report((int) $s['id']);
    eq(500, $x['cash']['payouts'], 'the stock-in is a payout of the day');
    eq(3200, $x['cash']['expected']);
    eq(3200, $x['cash']['fund_return']);
    $p = DB::one("SELECT * FROM petty_cash_txns WHERE ref_type = 'inv_doc' AND ref_id = ?", [$rr]);
    eq('drawer', $p['source']);
    throws(fn () => PettyCash::void((int) $p['id'], 'x'), 'Void that document in the back office');
    eq(r2($fund - 3700), PettyCash::fundBalance(), 'not charged to the fund a second time');

    // payment voucher from petty cash, then voided: the payout goes away
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE code = '6210'");
    $dv = App\Services\Finance\Disbursements::create(['pay_from' => 'petty_cash', 'amount' => 300, 'account_id' => $lpg, 'payee' => 'Petron']);
    eq(800, CashSessions::report((int) $s['id'])['cash']['payouts']);
    App\Services\Finance\Disbursements::void($dv, 'wrong');
    eq(500, CashSessions::report((int) $s['id'])['cash']['payouts']);

    // a petty cash expense recorded in the back office comes out of the drawer too; a POS payout as well
    PettyCash::record(['txn_type' => 'expense', 'source' => 'fund', 'amount' => 100, 'account_id' => $lpg, 'description' => 'Ice']);
    PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 50, 'account_id' => $lpg, 'description' => 'Fare']);
    $x = CashSessions::report((int) $s['id']);
    eq(650, $x['cash']['payouts']);
    eq(3050, $x['cash']['fund_return']);
    eq(3050, PettyCash::atPos());

    // a sale, then End of Day: counted = expected → no short; the fund gets back 3,700 − 650
    $t = Tickets::create(['order_type' => 'takeout']);
    Tickets::pay(Tickets::addItem($t['id'], item_id('Chicken Adobo'), 1)['id'], [['method' => 'cash', 'amount' => 185]]);
    $z = CashSessions::close([], 3235);
    eq(0, $z['cash']['variance'], 'no short: 3,700 + 185 − 650');
    eq(3050, $z['cash']['returned_to_fund']);
    eq(r2($fund - 650), PettyCash::fundBalance(), 'fund = 3,700 − what was paid from it');
    eq(r2($cashGl + 185), acct_balance('cash_on_hand'), 'cash on hand keeps only the sale');

    // the next day from cash on hand: petty cash payments go to the fund as before
    CashSessions::open(0);
    $before = PettyCash::fundBalance();
    App\Services\Finance\Disbursements::create(['pay_from' => 'petty_cash', 'amount' => 40, 'account_id' => $lpg]);
    eq(r2($before - 40), PettyCash::fundBalance());
    eq(0, CashSessions::report((int) CashSessions::current()['id'])['cash']['payouts']);
    CashSessions::close([], 0);
});

test('payouts larger than the petty cash beginning cash: nothing goes back, no negative return', function () {
    PettyCash::record(['txn_type' => 'replenish', 'amount' => 1000, 'from_method' => 'cash']);
    $s = CashSessions::open(500, null, null, null, 'petty_cash');
    $lpg = (int) DB::value("SELECT id FROM accounts WHERE code = '6210'");
    PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 700, 'account_id' => $lpg]);
    eq(0, CashSessions::report((int) $s['id'])['cash']['fund_return']);
    $z = CashSessions::close([], 0);   // expected 500 − 700 = −200; the extra 200 came out of sales cash
    eq(0, $z['cash']['returned_to_fund']);
    eq(null, DB::value('SELECT return_txn_id FROM cash_sessions WHERE id = ?', [$s['id']]));
});

test('dashboard service time: opened → paid in minutes, median, per type / hour / staff, buckets, order-taking time from Done', function () {
    if (!CashSessions::current()) CashSessions::open(0);
    $sid = (int) CashSessions::current()['id'];
    $mk = function (string $type, string $start, int $minutes) use ($sid) {
        return DB::insert('tickets', ['ticket_no' => 'SV' . mt_rand(), 'receipt_no' => 'SVR' . mt_rand(), 'cash_session_id' => $sid, 'business_date' => '2031-05-05',
            'order_type' => $type, 'pax' => 2, 'status' => 'paid', 'subtotal' => 100, 'total' => 100, 'created_by' => 1,
            'created_at' => "2031-05-05 $start:00", 'paid_at' => date('Y-m-d H:i:s', strtotime("2031-05-05 $start:00 +$minutes minutes"))]);
    };
    $mk('dine_in', '12:00', 30); $mk('dine_in', '12:10', 70); $mk('takeout', '13:00', 10); $mk('dine_in', '19:00', 50);
    $mk('dine_in', '09:00', 600);                       // left open 10 hours: not counted
    $t = $mk('takeout', '19:30', 20);
    DB::insert('ticket_items', ['ticket_id' => $t, 'item_id' => item_id('Coke'), 'name' => 'Coke', 'qty' => 1, 'price' => 100, 'line_total' => 100,
        'status' => 'active', 'kitchen_sent' => 1, 'sent_at' => '2031-05-05 19:34:00', 'created_by' => 1, 'created_at' => '2031-05-05 19:30:00']);
    $s = App\Services\Reports\Dashboard::serviceTimes('2031-05-05', '2031-05-05');
    eq(5, $s['count']);
    eq(1, $s['skipped']);
    eq(36.0, $s['avg'], '(30 + 70 + 10 + 50 + 20) / 5');
    eq(30.0, $s['median']);
    eq(70.0, $s['max']);
    eq(20.0, $s['slow_pct'], '1 of 5 over an hour');
    eq(50.0, $s['by_type']['dine_in']['avg']);
    eq(15.0, $s['by_type']['takeout']['avg']);
    eq(['count' => 2, 'avg' => 50.0], ['count' => $s['by_hour'][12]['count'], 'avg' => $s['by_hour'][12]['avg']]);
    eq([1, 1, 1, 1, 1, 0], array_column($s['buckets'], 'count'));
    eq(4.0, $s['taking_avg'], 'opened 19:30, Done 19:34');
    eq(1, $s['taking_count']);
    // Done records when lines were sent
    $o = Tickets::create(['order_type' => 'takeout']);
    Tickets::addItem($o['id'], item_id('Coke'), 1);
    Tickets::done($o['id']);
    ok(DB::value('SELECT sent_at FROM ticket_items WHERE ticket_id = ?', [$o['id']]) !== null, 'sent_at set on Done');
});
