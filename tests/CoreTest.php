<?php
use App\Core\DB;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Sequence;

test('sequence numbers are consecutive', function () {
    $a = Sequence::next('TST', 'TST', 4);
    $b = Sequence::next('TST', 'TST', 4);
    eq('TST-0001', $a);
    eq('TST-0002', $b);
});

test('ledger rejects unbalanced entries and reverses balanced ones', function () {
    throws(fn () => Ledger::post(today(), 'bad', [['key' => 'cash_on_hand', 'debit' => 10], ['key' => 'capital', 'credit' => 9]]), 'not balanced');
    $id = Ledger::post(today(), 'Owner investment', [['key' => 'cash_on_hand', 'debit' => 5000], ['key' => 'capital', 'credit' => 5000]]);
    eq(5000, acct_balance('cash_on_hand'));
    Ledger::reverse($id);
    eq(0, acct_balance('cash_on_hand'));
    assert_books_balance();
});

test('unit conversions: global (g -> kg) and item specific (sack -> kg)', function () {
    $rice = Inventory::item(item_id('Rice'));
    $g = (int) DB::value("SELECT id FROM uoms WHERE abbr = 'g'");
    $sack = (int) DB::value("SELECT id FROM uoms WHERE abbr = 'sack'");
    eq(0.12, Inventory::toBase($rice, 120, $g));
    eq(100, Inventory::toBase($rice, 2, $sack));
});

test('recipe explosion handles sub-recipes', function () {
    $need = Inventory::explode(item_id('Adobo Rice Meal'), 2);
    eq(0.5, $need[item_id('Chicken')], 'chicken kg');
    eq(0.24, $need[item_id('Rice')], 'rice kg');
});

test('moving average cost on receipt', function () {
    $egg = item_id('Egg');
    Inventory::move(['item_id' => $egg, 'qty' => 30, 'mtype' => 'RECEIVE', 'unit_cost' => 8]);
    Inventory::move(['item_id' => $egg, 'qty' => 30, 'mtype' => 'RECEIVE', 'unit_cost' => 10]);
    eq(9, (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [$egg]));
    eq(60, stock('Egg'));
});
