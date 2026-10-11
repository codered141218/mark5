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
