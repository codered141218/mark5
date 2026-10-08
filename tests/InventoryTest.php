<?php
use App\Core\DB;
use App\Services\Inventory;
use App\Services\InventoryCounts;
use App\Services\InventoryDocs;
use App\Services\Items;
use App\Services\Ledger;

$uom = fn (string $abbr) => (int) DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr]);
$avg = fn (string $name) => (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [item_id($name)]);
$supplier = (int) DB::value("SELECT id FROM suppliers WHERE name = 'Metro Meat Supply'");

test('delivery on credit: stock, moving average, A/P bill with due date, balanced GL', function () use ($uom, $avg, $supplier) {
    $id = InventoryDocs::create('RECEIVE', [
        'doc_date' => '2026-10-01', 'supplier_id' => $supplier, 'invoice_no' => 'SI-1001', 'payment_mode' => 'credit', 'post' => 1,
        'lines' => [
            ['item_id' => item_id('Chicken'), 'qty' => 20, 'uom_id' => $uom('kg'), 'line_total' => 4000],
            ['item_id' => item_id('Rice'), 'qty' => 2, 'uom_id' => $uom('sack'), 'line_total' => 5500],
            ['item_id' => item_id('Coke'), 'qty' => 2, 'uom_id' => $uom('case'), 'unit_cost' => 912],
        ],
    ]);
    $d = InventoryDocs::find($id);
    eq('posted', $d['status']);
    ok(str_starts_with($d['doc_no'], 'RR-'), 'RR- document number');
    eq(100, stock('Rice'), 'rice kg (2 sacks x 50)');
    eq(55, $avg('Rice'));
    eq(200, $avg('Chicken'));
    eq(48, stock('Coke'));
    eq(38, $avg('Coke'));
    $bill = DB::one('SELECT * FROM ap_bills WHERE id = ?', [$d['ap_bill_id']]);
    eq(11324, (float) $bill['amount']);
    eq('2026-10-16', $bill['due_date'], 'due = date + 15 days terms');
    eq('inv_receive', $bill['source_type']);
    eq('open', $bill['status']);
    eq(11324, acct_balance('inventory'));
    eq(-11324, acct_balance('ap'));
    eq(0, acct_balance('input_vat'));
    assert_books_balance();
});

test('VAT-inclusive cash delivery splits input VAT', function () use ($avg) {
    $id = InventoryDocs::create('RECEIVE', [
        'payment_mode' => 'cash', 'vat_inclusive' => 1, 'post' => 1,
        'lines' => [['item_id' => item_id('Egg'), 'qty' => 30, 'line_total' => 336]],
    ]);
    eq(null, InventoryDocs::find($id)['ap_bill_id']);
    eq(36, acct_balance('input_vat'));
    eq(-336, acct_balance('cash_on_hand'));
    eq(10, $avg('Egg'), 'egg cost net of VAT');
    eq(11624, acct_balance('inventory'));
    assert_books_balance();
});

test('issuance explodes a menu item into its ingredients and charges the expense account', function () {
    $before = acct_balance('inventory');
    $id = InventoryDocs::create('ISSUE', [
        'issued_to' => 'Kitchen', 'post' => 1,
        'lines' => [['item_id' => item_id('Chicken Adobo'), 'qty' => 2]],
    ]);
    $d = InventoryDocs::find($id);
    ok(str_starts_with($d['doc_no'], 'IS-'), 'IS- number');
    eq(Ledger::account('supplies'), (int) $d['expense_account_id'], 'default expense account');
    eq(19.5, stock('Chicken'), 'chicken 20 - 2 x 250 g');
    eq(-0.08, stock('Soy Sauce'), 'soy 2 x 40 ml');
    // 0.5 kg x 200 + 0.08 L x 60 + 0.06 L x 45 + 0.03 kg x 140 + 0.03 L x 95
    eq(114.55, (float) $d['total_cost']);
    eq(114.55, acct_balance('supplies'));
    eq(r2($before - 114.55), acct_balance('inventory'));
    assert_books_balance();
});

test('wastage of raw and composite items books Spoilage & Wastage', function () {
    $id = InventoryDocs::create('WASTE', [
        'reason' => 'expired', 'post' => 1,
        'lines' => [['item_id' => item_id('Chicken'), 'qty' => 1], ['item_id' => item_id('Plain Rice'), 'qty' => 10]],
    ]);
    $d = InventoryDocs::find($id);
    ok(str_starts_with($d['doc_no'], 'WS-'), 'WS- number');
    eq(18.5, stock('Chicken'));
    eq(98.8, stock('Rice'), 'rice 100 - 10 x 120 g');
    eq(266, (float) $d['total_cost'], '1 kg x 200 + 1.2 kg x 55');
    eq(266, acct_balance('wastage'));
    throws(fn () => InventoryDocs::create('WASTE', ['reason' => 'stolen', 'lines' => [['item_id' => item_id('Egg'), 'qty' => 1]]]), 'reason');
    assert_books_balance();
});

test('draft documents: validation, edit, delete; posted ones are locked', function () use ($supplier) {
    throws(fn () => InventoryDocs::create('RECEIVE', ['payment_mode' => 'credit', 'lines' => [['item_id' => item_id('Egg'), 'qty' => 1]]]), 'Supplier is required');
    throws(fn () => InventoryDocs::create('RECEIVE', ['payment_mode' => 'cash', 'lines' => [['item_id' => item_id('Chicken Adobo'), 'qty' => 1]]]), 'not a stocked item');
    throws(fn () => InventoryDocs::create('ISSUE', ['lines' => [['item_id' => item_id('Egg'), 'qty' => 0]]]), 'greater than zero');
    throws(fn () => InventoryDocs::create('ISSUE', ['lines' => []]), 'at least one item');

    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'lines' => [['item_id' => item_id('Egg'), 'qty' => 10, 'unit_cost' => 9]]]);
    eq('draft', InventoryDocs::find($id)['status']);
    eq(90, (float) InventoryDocs::find($id)['total_cost']);
    InventoryDocs::update($id, ['supplier_id' => $supplier, 'lines' => [['item_id' => item_id('Egg'), 'qty' => 12, 'unit_cost' => 9]]]);
    eq(108, (float) InventoryDocs::find($id)['total_cost']);
    InventoryDocs::delete($id);
    throws(fn () => InventoryDocs::find($id), 'not found');

    $posted = (int) DB::value("SELECT id FROM inv_docs WHERE status = 'posted' ORDER BY id LIMIT 1");
    throws(fn () => InventoryDocs::update($posted, ['lines' => []]), 'Only draft');
    throws(fn () => InventoryDocs::delete($posted), 'voided');
});

test('only users with inventory.post can post', function () {
    $role = (int) DB::value("SELECT id FROM roles WHERE name = 'Inventory Clerk'");
    DB::insert('users', ['username' => 'clerk', 'full_name' => 'Stock Clerk', 'password_hash' => 'x', 'role_id' => $role, 'active' => 1]);
    as_user('clerk');
    try {
        $id = InventoryDocs::create('WASTE', ['lines' => [['item_id' => item_id('Egg'), 'qty' => 1]]]);
        throws(fn () => InventoryDocs::post($id), 'permission');
        eq('draft', InventoryDocs::find($id)['status']);
        InventoryDocs::delete($id);
    } finally {
        as_user('admin');
    }
});

test('voiding a delivery restores stock and average cost and voids the payable', function () use ($avg, $supplier) {
    $stock = stock('Chicken');
    $cost = $avg('Chicken');
    $inv = acct_balance('inventory');
    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'post' => 1, 'lines' => [['item_id' => item_id('Chicken'), 'qty' => 10, 'line_total' => 2500]]]);
    eq($stock + 10, stock('Chicken'));
    ok(abs($avg('Chicken') - $cost) > 1, 'average moved after delivery');
    throws(fn () => InventoryDocs::void($id, ''), 'reason');

    InventoryDocs::void($id, 'Wrong supplier');
    $d = InventoryDocs::find($id);
    eq('cancelled', $d['status']);
    eq($stock, stock('Chicken'));
    ok(abs($cost - $avg('Chicken')) < 0.001, 'average cost restored (within 4-decimal rounding)');
    eq('void', DB::value('SELECT status FROM ap_bills WHERE id = ?', [$d['ap_bill_id']]));
    eq($inv, acct_balance('inventory'));
    eq(-11324, acct_balance('ap'));
    throws(fn () => InventoryDocs::void($id, 'again'), 'Only posted');
    assert_books_balance();
});

test('voiding is refused when the supplier bill has payments', function () use ($supplier) {
    $id = InventoryDocs::create('RECEIVE', ['supplier_id' => $supplier, 'post' => 1, 'lines' => [['item_id' => item_id('Egg'), 'qty' => 30, 'line_total' => 300]]]);
    $billId = (int) InventoryDocs::find($id)['ap_bill_id'];
    DB::run("UPDATE ap_bills SET paid_amount = 100, status = 'partial' WHERE id = ?", [$billId]);
    $egg = stock('Egg');
    throws(fn () => InventoryDocs::void($id, 'Damaged'), 'already has payments');
    eq('posted', InventoryDocs::find($id)['status']);
    eq($egg, stock('Egg'), 'stock untouched');
    eq('partial', DB::value('SELECT status FROM ap_bills WHERE id = ?', [$billId]));
    assert_books_balance();
});

test('voiding an issuance puts the ingredients back', function () {
    $chicken = stock('Chicken');
    $supplies = acct_balance('supplies');
    $id = InventoryDocs::create('ISSUE', ['post' => 1, 'lines' => [['item_id' => item_id('Adobo Rice Meal'), 'qty' => 4]]]);
    eq($chicken - 1, stock('Chicken'));
    InventoryDocs::void($id, 'Cancelled event');
    eq($chicken, stock('Chicken'));
    eq($supplies, acct_balance('supplies'));
    assert_books_balance();
});

test('count posting sets stock to counted and books the variance', function () use ($avg) {
    $id = InventoryCounts::start(null, null, 'Month-end');
    $s = InventoryCounts::find($id);
    ok(str_starts_with($s['doc_no'], 'CNT-'), 'CNT- number');
    $stocked = (int) DB::value("SELECT COUNT(*) FROM items WHERE active = 1 AND item_type IN ('raw','retail')");
    eq($stocked, count($s['lines']));
    $line = fn ($name) => (int) current(array_filter($s['lines'], fn ($l) => (int) $l['item_id'] === item_id($name)))['id'];

    $chickenCost = $avg('Chicken');
    $egg = stock('Egg');
    $rice = stock('Rice');
    InventoryCounts::save($id, [$line('Chicken') => 17.5, $line('Egg') => $egg + 5, $line('Rice') => '']);
    $variance = acct_balance('inv_variance');
    $inv = acct_balance('inventory');
    InventoryCounts::post($id);

    $s = InventoryCounts::find($id);
    eq('posted', $s['status']);
    eq(17.5, stock('Chicken'));
    eq($egg + 5, stock('Egg'));
    eq($rice, stock('Rice'), 'blank line not adjusted');
    $loss = r2(1 * $chickenCost);
    $gain = r2(5 * $avg('Egg'));
    eq(r2($gain - $loss), (float) $s['total_variance_value']);
    eq(r2($variance + $loss - $gain), acct_balance('inv_variance'));
    eq(r2($inv + $gain - $loss), acct_balance('inventory'));
    eq(2, (int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE ref_type = 'count' AND ref_id = ?", [$id]));
    throws(fn () => InventoryCounts::post($id), 'already closed');
    assert_books_balance();
});

test('count sessions: category scope, nothing counted, cancel', function () {
    $cat = (int) DB::value("SELECT id FROM categories WHERE name = 'Produce'");
    $id = InventoryCounts::start(today(), $cat, null);
    eq((int) DB::value("SELECT COUNT(*) FROM items WHERE active = 1 AND category_id = ? AND item_type IN ('raw','retail')", [$cat]), count(InventoryCounts::find($id)['lines']));
    throws(fn () => InventoryCounts::post($id), 'at least one counted');
    InventoryCounts::cancel($id);
    eq('cancelled', InventoryCounts::find($id)['status']);
    throws(fn () => InventoryCounts::save($id, []), 'closed');
});

test('item with recipe: auto SKU, recipe cost, food cost %', function () use ($uom) {
    $id = Items::create([
        'name' => 'Chicken Rice Bowl', 'item_type' => 'composite', 'base_uom_id' => $uom('srv'), 'price' => 112, 'sellable' => 1, 'active' => 1,
        'components' => [
            ['component_id' => item_id('Chicken'), 'qty' => 200, 'uom_id' => $uom('g')],
            ['component_id' => item_id('Plain Rice'), 'qty' => 1, 'uom_id' => $uom('srv')],
            ['component_id' => '', 'qty' => 1],
        ],
    ]);
    $item = Inventory::item($id);
    ok(preg_match('/^SKU-\d{5}$/', $item['sku']) === 1, 'auto SKU ' . $item['sku']);
    eq(2, (int) DB::value('SELECT COUNT(*) FROM item_components WHERE parent_id = ?', [$id]));
    $expected = r4(0.2 * (float) Inventory::item(item_id('Chicken'))['avg_cost'] + 0.12 * 55);
    eq($expected, Inventory::unitCost($id));
    eq($expected, Items::unitCosts()[$id], 'bulk costing matches');
    eq(r2($expected / 100 * 100), Items::foodCostPct($expected, 112), 'price 112 = 100 net of 12% VAT');
    throws(fn () => Items::create(['name' => 'Dup', 'sku' => $item['sku'], 'item_type' => 'raw', 'base_uom_id' => $uom('kg')]), 'SKU already exists');
});

test('recipe validation: self, circular, unknown unit', function () use ($uom) {
    $srv = $uom('srv');
    $a = Items::create(['name' => 'Sauce A', 'item_type' => 'composite', 'base_uom_id' => $srv,
        'components' => [['component_id' => item_id('Soy Sauce'), 'qty' => 10, 'uom_id' => $uom('ml')]]]);
    $b = Items::create(['name' => 'Sauce B', 'item_type' => 'composite', 'base_uom_id' => $srv,
        'components' => [['component_id' => $a, 'qty' => 1, 'uom_id' => $srv]]]);
    throws(fn () => Items::update($a, ['name' => 'Sauce A', 'item_type' => 'composite', 'base_uom_id' => $srv,
        'components' => [['component_id' => $b, 'qty' => 1, 'uom_id' => $srv]]]), 'circular');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM item_components WHERE parent_id = ? AND component_id = ?', [$a, item_id('Soy Sauce')]), 'recipe kept after rollback');
    throws(fn () => Items::update($a, ['name' => 'Sauce A', 'item_type' => 'composite', 'base_uom_id' => $srv,
        'components' => [['component_id' => $a, 'qty' => 1]]]), 'itself');
    throws(fn () => Items::create(['name' => 'Bad unit', 'item_type' => 'composite', 'base_uom_id' => $srv,
        'components' => [['component_id' => item_id('Egg'), 'qty' => 1, 'uom_id' => $uom('kg')]]]), 'No conversion');
});

test('item rules: type and base unit locks, purchase units, opening cost, delete vs deactivate', function () use ($uom) {
    $egg = Inventory::item(item_id('Egg'));
    $base = ['name' => $egg['name'], 'item_type' => 'raw', 'base_uom_id' => $egg['base_uom_id'], 'active' => 1];
    throws(fn () => Items::update((int) $egg['id'], ['item_type' => 'retail'] + $base), 'stock on hand');
    throws(fn () => Items::update((int) $egg['id'], ['base_uom_id' => $uom('kg')] + $base), 'base unit');
    Items::update((int) $egg['id'], $base + ['avg_cost' => 999, 'uoms' => [['uom_id' => $uom('tray'), 'factor' => 30], ['uom_id' => $uom('doz'), 'factor' => 12]]]);
    ok((float) Inventory::item((int) $egg['id'])['avg_cost'] < 999, 'cost not overwritten once there is stock history');
    eq(2, (int) DB::value('SELECT COUNT(*) FROM item_uoms WHERE item_id = ?', [$egg['id']]));
    eq(360, Inventory::toBase($egg, 12, $uom('tray')));
    throws(fn () => Items::update((int) $egg['id'], $base + ['uoms' => [['uom_id' => $egg['base_uom_id'], 'factor' => 2]]]), 'same as the base unit');

    $new = Items::create(['name' => 'Fish Sauce', 'item_type' => 'raw', 'base_uom_id' => $uom('L'), 'avg_cost' => 70, 'active' => 1]);
    eq(70, (float) Inventory::item($new)['avg_cost']);
    Items::update($new, ['name' => 'Fish Sauce (Patis)', 'item_type' => 'raw', 'base_uom_id' => $uom('ml'), 'avg_cost' => 72, 'active' => 1]);
    eq(72, (float) Inventory::item($new)['avg_cost'], 'cost editable without history');
    ok(Items::delete($new), 'unused item is deleted');
    ok(!DB::value('SELECT id FROM items WHERE id = ?', [$new]), 'gone');
    ok(!Items::delete((int) $egg['id']), 'item with history is deactivated');
    eq(0, (int) Inventory::item((int) $egg['id'])['active']);
    DB::run('UPDATE items SET active = 1 WHERE id = ?', [$egg['id']]);
});

test('units endpoint data: base, item-specific and global conversions', function () use ($uom) {
    $abbrs = array_column(Inventory::units(item_id('Rice')), 'factor', 'abbr');
    eq(1, $abbrs['kg']);
    eq(50, $abbrs['sack']);
    eq(0.001, $abbrs['g']);
    assert_books_balance();
});
