<?php
/**
 * Round 3 changes: GL account setup, VAT-exclusive prices, table required before Done, prep stations and order slips,
 * moving items between orders, bulk actions on lists, menu arrangement, sortable / selectable tables.
 */
use App\Core\DB;
use App\Core\Request;
use App\Core\Table;
use App\Services\GlSetup;
use App\Services\Inventory;
use App\Services\InventoryDocs;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Positions;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;
use App\Services\Settings;
use App\Services\Stations;

as_user('admin');
CashSessions::open(1000);

/** Call a controller action with a POST body (as a browser form would). */
function post_action(object $controller, string $method, array $body, ...$args)
{
    $_POST = $body;
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/test';
    $_SERVER['HTTP_REFERER'] = '/test';
    $GLOBALS['__request'] = $req = new Request();
    return $controller->$method($req, ...$args);
}

/** Journal lines of a POS sale: [account code => debit - credit]. */
function sale_lines(int $ticketId): array
{
    $out = [];
    foreach (DB::all("SELECT a.code, SUM(l.debit - l.credit) amt FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
                      JOIN accounts a ON a.id = l.account_id WHERE e.source_type = 'pos_sale' AND e.source_id = ? GROUP BY a.code", [$ticketId]) as $r) {
        $out[$r['code']] = round((float) $r['amt'], 2);
    }
    return $out;
}

$newAccount = fn (string $code, string $name, string $type) => DB::insert('accounts', ['code' => $code, 'name' => $name, 'type' => $type, 'active' => 1]);

// ---------------------------------------------------------------- GL account setup
test('GL setup: default roles, validation, and a changed cash / GCash account', function () use ($newAccount) {
    $rows = GlSetup::rows();
    ok(count($rows) >= 20, 'roles listed');
    $inv = array_values(array_filter($rows, fn ($r) => $r['role'] === 'inventory'))[0];
    eq((int) DB::value("SELECT id FROM accounts WHERE code = '1200'"), $inv['account_id']);
    $wallet = $newAccount('1046', 'GCash Wallet', 'asset');
    throws(fn () => GlSetup::save(['sales' => $wallet]), 'must be');
    GlSetup::save(['pay.gcash' => $wallet]);
    eq($wallet, Ledger::account('pay.gcash'));
    eq((int) DB::value("SELECT id FROM accounts WHERE system_key = 'ewallet_clearing'"), Ledger::account('pay.maya'), 'unchanged roles keep the default');
    // choosing the default again removes the override
    GlSetup::save(['pay.gcash' => (int) DB::value("SELECT id FROM accounts WHERE system_key = 'ewallet_clearing'")]);
    eq(null, DB::value("SELECT `value` FROM settings WHERE `key` = 'gl.pay.gcash'"));
    GlSetup::save(['pay.gcash' => $wallet]);

    $t = Tickets::create(['order_type' => 'takeout']);
    $t = Tickets::addItem($t['id'], item_id('Coke'), 1);
    Tickets::pay($t['id'], [['method' => 'gcash', 'amount' => 65, 'reference' => 'G1']]);
    eq(65, sale_lines($t['id'])['1046'] ?? null, 'GCash sale debits the chosen account');
    assert_books_balance();
});

test('GL setup: per-category sales, COGS and inventory accounts', function () use ($newAccount) {
    $bevSales = $newAccount('4010', 'Beverage Sales', 'income');
    $meatInv = $newAccount('1210', 'Meat Inventory', 'asset');
    $foodCogs = $newAccount('5010', 'Food Cost', 'expense');
    throws(fn () => GlSetup::categoryFields(['sales_account_id' => $meatInv]), 'must be');
    DB::update('categories', (int) DB::value("SELECT id FROM categories WHERE name = 'Beverages'"), GlSetup::categoryFields(['sales_account_id' => $bevSales]));
    DB::update('categories', (int) DB::value("SELECT id FROM categories WHERE name = 'Meat & Poultry'"), GlSetup::categoryFields(['inventory_account_id' => $meatInv]));
    DB::update('categories', (int) DB::value("SELECT id FROM categories WHERE name = 'Ulam / Viands'"), GlSetup::categoryFields(['cogs_account_id' => $foodCogs]));
    Ledger::clearCache();

    // Delivery of chicken goes to Meat Inventory, rice to the default Inventory
    InventoryDocs::create('RECEIVE', ['payment_mode' => 'cash', 'post' => 1, 'lines' => [
        ['item_id' => item_id('Chicken'), 'qty' => 2, 'line_total' => 400],
        ['item_id' => item_id('Rice (Dinorado)'), 'qty' => 10, 'line_total' => 500],
    ]]);
    eq(400, Ledger::balance($meatInv));

    // Sale: Chicken Adobo (ulam -> Food Cost COGS, chicken from Meat Inventory) + Coke (Beverage Sales)
    $t = Tickets::create(['order_type' => 'takeout']);
    Tickets::addItem($t['id'], item_id('Chicken Adobo'), 1);
    $t = Tickets::addItem($t['id'], item_id('Coke'), 1);
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 250]]);
    $l = sale_lines($t['id']);
    eq(-58.03, $l['4010'], 'Coke share of 250 / 1.12 = 223.21 to Beverage Sales');
    eq(-165.18, $l['4000'], 'Adobo 185 / 1.12 to Food Sales');
    ok(($l['5010'] ?? 0) > 0, 'adobo cost in Food Cost');
    ok(($l['1210'] ?? 0) < 0, 'chicken out of Meat Inventory');
    eq(-r2((float) $paid['cogs']), r2(($l['1210'] ?? 0) + ($l['1200'] ?? 0)), 'inventory credits = COGS');
    eq(r2((float) $paid['cogs']), r2(($l['5010'] ?? 0) + ($l['5000'] ?? 0)), 'COGS debits = COGS');
    assert_books_balance();
});

// ---------------------------------------------------------------- VAT-exclusive prices
test('VAT-exclusive prices: VAT is added on top; SC and promo discounts; GL', function () {
    $before = Tickets::create(['order_type' => 'takeout']);     // started while prices include VAT
    Settings::set('prices_include_vat', '0');
    $t = Tickets::create(['order_type' => 'takeout']);
    eq(0, $t['vat_inclusive']);
    $t = Tickets::addItem($t['id'], item_id('Coke'), 1);
    eq(65, $t['subtotal']);
    eq(7.80, $t['vat_amount']);
    eq(72.80, $t['total']);
    // Senior's meal (VAT-exempt, 20% off the price) + employee 10% on the Coke
    $t = Tickets::addItem($t['id'], item_id('Adobo Rice Meal'), 1);
    $line = fn ($t, $name) => array_values(array_filter($t['items'], fn ($i) => $i['item_id'] === item_id($name)))[0]['id'];
    $t = Tickets::discountLine($t['id'], $line($t, 'Adobo Rice Meal'), ['discount_type' => 'sc', 'sc_person' => ['name' => 'Lolo', 'id_no' => 'SC-9']]);
    $t = Tickets::discountLine($t['id'], $line($t, 'Coke'), ['discount_type' => 'percent', 'discount_rate' => 10]);
    eq(264, $t['subtotal']);
    eq(199, $t['vat_exempt_sales']);
    eq(39.80, $t['sc_discount']);
    eq(6.50, $t['promo_discount']);
    eq(58.50, $t['vatable_sales']);
    eq(7.02, $t['vat_amount']);
    eq(224.72, $t['total']);
    eq(r2($t['subtotal'] - $t['discount_amount'] + $t['vat_amount']), $t['total'], 'Subtotal - discounts + VAT = total');
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 300]]);
    $l = sale_lines($paid['id']);
    eq(-7.02, $l['2100'], 'output VAT');
    eq(46.30, $l['4100'], 'discounts net of VAT');
    eq(-199, $l['4000'], 'meal: food sales');
    eq(-65, $l['4010'], 'Coke: beverage sales (category account from the test above)');
    assert_books_balance();
    // The order started before the change keeps VAT-inclusive prices
    $b = Tickets::addItem($before['id'], item_id('Coke'), 1);
    eq(65, $b['total']);
    throws(fn () => Tickets::merge($b['id'], Tickets::addItem(Tickets::create(['order_type' => 'takeout'])['id'], item_id('Coke'), 1)['id']), 'different VAT');
    Settings::set('prices_include_vat', '1');
    eq(1.0, Items::vatDivisor() > 1 ? 1.0 : 0.0, 'menu costing divides by 1.12 again');
});

// ---------------------------------------------------------------- table required, Done, stations
test('Done: dine-in needs a table; slips list each line with its prep station', function () {
    $t = Tickets::create(['order_type' => 'dine_in', 'pax' => 2]);
    Tickets::addItem($t['id'], item_id('Sisig Rice Meal'), 1);
    Tickets::addItem($t['id'], item_id('Chicken Adobo'), 1);
    $t = Tickets::addItem($t['id'], item_id('Coke'), 2);
    throws(fn () => Tickets::done($t['id']), 'table');
    throws(fn () => Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 1000]]), 'table');
    Tickets::setTable($t['id'], '8');
    $r = Tickets::done($t['id']);
    eq(3, count($r['sent']));
    $st = array_column($r['sent'], 'station_id', 'name');
    $grill = (int) DB::value("SELECT id FROM prep_stations WHERE name = 'Grill'");
    $kitchen = (int) DB::value("SELECT id FROM prep_stations WHERE name = 'Kitchen'");
    eq($grill, $st['Sisig Rice Meal'], 'item tagged Grill');
    eq($kitchen, $st['Chicken Adobo'], 'category Kitchen');
    eq(null, $st['Coke in Can'], 'drinks: no station');
    eq(0, count(Tickets::done($t['id'])['sent']), 'nothing new the second time');
    $t = Tickets::addItem($t['id'], item_id('Extra Egg'), 1);
    eq(1, count(Tickets::done($t['id'])['sent']), 'only the new item');
    // take-out needs no table; the setting can be switched off
    $to = Tickets::create(['order_type' => 'takeout']);
    $to = Tickets::addItem($to['id'], item_id('Coke'), 1);
    eq(1, count(Tickets::done($to['id'])['sent']));
    Settings::set('require_table_dine_in', '0');
    $d = Tickets::addItem(Tickets::create(['order_type' => 'dine_in'])['id'], item_id('Coke'), 1);
    eq(1, count(Tickets::done($d['id'])['sent']));
    Settings::set('require_table_dine_in', '1');
    $GLOBALS['t8'] = $t['id'];
});

test('move selected items (part of a line) to another table’s order', function () {
    $t5 = Tickets::create(['order_type' => 'dine_in', 'table_label' => '5']);
    $t5 = Tickets::addItem($t5['id'], item_id('Coke'), 3);
    $t9 = Tickets::create(['order_type' => 'dine_in', 'table_label' => '9']);
    $t9 = Tickets::addItem($t9['id'], item_id('Chicken Adobo'), 1);
    $coke = array_values(array_filter($t5['items'], fn ($i) => $i['status'] === 'active'))[0];
    $r = Tickets::split($t5['id'], [['id' => $coke['id'], 'qty' => 2]], $t9['id']);
    eq(65, $r['source']['total']);
    eq(185 + 130, $r['target']['total']);
    eq('9', $r['target']['table_label']);
    eq(2, count(array_filter($r['target']['items'], fn ($i) => $i['status'] === 'active')));
    assert_books_balance();
});

test('prep stations: create, rename, delete clears the tags', function () {
    $bar = Stations::save(['name' => 'Bar', 'active' => 1]);
    throws(fn () => Stations::save(['name' => 'Bar', 'active' => 1]), 'already exists');
    $last = (int) DB::value('SELECT MAX(sort_order) FROM prep_stations');
    eq($last, (int) DB::value('SELECT sort_order FROM prep_stations WHERE id = ?', [$bar]), 'new station goes last');
    DB::run("UPDATE categories SET station_id = ? WHERE name = 'Beverages'", [$bar]);
    eq(1, count(array_filter(Stations::all(), fn ($s) => $s['name'] === 'Bar')));
    Stations::delete($bar);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM categories WHERE station_id = ?', [$bar]));
});

// ---------------------------------------------------------------- bulk actions, positions, tables
test('bulk delete: unused items deleted, used ones deactivated, categories with items kept', function () {
    $uom = (int) DB::value("SELECT id FROM uoms WHERE abbr = 'pc'");
    $a = Items::create(['name' => 'Test Item A', 'item_type' => 'non_inventory', 'base_uom_id' => $uom, 'price' => 10, 'sellable' => 1, 'active' => 1]);
    $b = Items::create(['name' => 'Test Item B', 'item_type' => 'non_inventory', 'base_uom_id' => $uom, 'price' => 10, 'sellable' => 1, 'active' => 1]);
    $_SESSION = [];
    post_action(new App\Controllers\Inventory\ItemController(), 'bulk', ['action' => 'delete', 'ids' => [$a, $b, item_id('Coke')]]);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM items WHERE id IN (?, ?)', [$a, $b]), 'unused items deleted');
    eq(0, (int) DB::value('SELECT active FROM items WHERE id = ?', [item_id('Coke')]), 'Coke has history: deactivated');
    ok(str_contains($_SESSION['flash']['success'] ?? '', '2 items deleted'), $_SESSION['flash']['success'] ?? 'no message');
    post_action(new App\Controllers\Inventory\ItemController(), 'bulk', ['action' => 'activate', 'ids' => [item_id('Coke')]]);
    eq(1, (int) DB::value('SELECT active FROM items WHERE id = ?', [item_id('Coke')]));

    $empty = DB::insert('categories', ['name' => 'Empty Cat', 'kind' => 'menu', 'active' => 1]);
    $_SESSION = [];
    post_action(new App\Controllers\Inventory\CategoryController(), 'bulk', ['action' => 'delete', 'ids' => [$empty, (int) DB::value("SELECT id FROM categories WHERE name = 'Beverages'")]]);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM categories WHERE id = ?', [$empty]));
    ok((bool) DB::value("SELECT id FROM categories WHERE name = 'Beverages'"), 'category with items kept');
    ok(str_contains($_SESSION['flash']['error'] ?? '', 'still has items'), 'reason shown');
});

test('positions: new items go last in their category; arranging swaps positions', function () {
    $drinks = (int) DB::value("SELECT id FROM categories WHERE name = 'Beverages'");
    $uom = (int) DB::value("SELECT id FROM uoms WHERE abbr = 'pc'");
    $n = Items::create(['name' => 'Calamansi Juice', 'item_type' => 'non_inventory', 'category_id' => $drinks, 'base_uom_id' => $uom, 'price' => 45, 'sellable' => 1, 'active' => 1]);
    $ids = array_map('intval', array_column(DB::all('SELECT id FROM items WHERE category_id = ? ORDER BY sort_order', [$drinks]), 'id'));
    eq($n, end($ids), 'new item is last');
    $reversed = array_reverse($ids);
    $r = post_action(new App\Controllers\Inventory\ArrangeController(), 'save', ['list' => 'items', 'ids' => $reversed]);
    eq($reversed, array_map('intval', array_column(DB::all('SELECT id FROM items WHERE category_id = ? ORDER BY sort_order', [$drinks]), 'id')));
    $cats = array_map('intval', array_column(DB::all("SELECT id FROM categories ORDER BY sort_order"), 'id'));
    Positions::save('categories', array_reverse($cats));
    eq(array_reverse($cats), array_map('intval', array_column(DB::all("SELECT id FROM categories ORDER BY sort_order"), 'id')));
    throws(fn () => Positions::save('users', [1]), 'Unknown list');
});

test('tables: sort dropdown, bulk checkboxes and the POS menu order setting', function () {
    $cols = [['key' => 'name', 'label' => 'Name'], ['key' => 'price', 'label' => 'Price', 'type' => 'money'], ['key' => 'id', 'label' => '']];
    $rows = [['id' => 1, 'name' => 'B', 'price' => 2], ['id' => 2, 'name' => 'A', 'price' => 1, '_nobulk' => true]];
    $h = Table::html($cols, $rows, ['bulk' => ['actions' => [['key' => 'delete', 'label' => 'Delete', 'url' => '/x', 'danger' => true]]]]);
    ok(str_contains($h, 'data-table-sort'), 'sort dropdown');
    ok(str_contains($h, 'data-bulk-all') && substr_count($h, 'data-bulk-id') === 1, 'one checkbox (second row opted out)');
    ok(str_contains($h, 'data-nosort>') || str_contains($h, 'data-nosort'), 'action column not sortable');
    Settings::set('pos_menu_sort', 'price_desc');
    $_GET = [];
    $menu = json_decode((new App\Controllers\Pos\PosApiController())->menu(new Request())->body, true);
    $prices = array_column($menu['items'], 'price');
    $sorted = $prices;
    rsort($sorted);
    eq($sorted, $prices, 'POS menu sorted by price, high to low');
    ok(array_key_exists('station_id', $menu['items'][0]), 'menu items carry their station');
    Settings::set('pos_menu_sort', 'custom');
});

test('database upgrade step 2 is re-runnable', function () {
    App\Services\Settings::set('db_version', 1);
    App\Services\Migrations::run();
    eq(App\Services\Migrations::latest(), App\Services\Migrations::current());
    eq(2, (int) DB::value('SELECT COUNT(*) FROM prep_stations WHERE name IN (\'Kitchen\', \'Grill\')'));
    assert_books_balance();
});
