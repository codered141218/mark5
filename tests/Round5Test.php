<?php
/**
 * Automatic account codes, payments & expenses, cash & bank position, item import from Excel / CSV,
 * end-of-day detail (denominations, payouts), setup wizard.
 */
use App\Core\DB;
use App\Core\Excel;
use App\Services\Admin\SetupWizard;
use App\Services\Finance\Accounts;
use App\Services\Finance\Banks;
use App\Services\Finance\Disbursements;
use App\Services\Finance\PettyCash;
use App\Services\ItemImport;
use App\Services\Ledger;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;
use App\Services\Reports\SalesReports;
use App\Services\Settings;

as_user('admin');
$tmp = sys_get_temp_dir() . '/mark5_r5_' . getmypid();
@mkdir($tmp);

test('account codes: next free code per type (cost of sales in the 5000s); typed codes still work', function () {
    // default chart: highest asset 1590, liability 2500, equity 3900, income 4800, expense 6900, cost of sales 5120
    eq('1600', Accounts::nextCode('asset'));
    eq('2510', Accounts::nextCode('liability'));
    eq('6910', Accounts::nextCode('expense'));
    eq('5130', Accounts::nextCode('expense', 'cogs'));
    $id = Accounts::create(['code' => '', 'name' => 'Gas & Fuel', 'type' => 'expense']);
    eq('6910', DB::value('SELECT code FROM accounts WHERE id = ?', [$id]));
    eq('6920', Accounts::nextCode('expense'), 'the next one moves on');
    $own = Accounts::create(['code' => '6155', 'name' => 'Laundry', 'type' => 'expense']);
    eq('6155', DB::value('SELECT code FROM accounts WHERE id = ?', [$own]));
    throws(fn () => Accounts::create(['code' => '6155', 'name' => 'Again', 'type' => 'expense']), 'already exists');
});

test('payments & expenses: cash with VAT, from a bank (shows in the bank register), void reverses', function () {
    $rent = (int) DB::value("SELECT id FROM accounts WHERE code = '6100'");
    $cashBefore = acct_balance('cash_on_hand');
    $id = Disbursements::create(['pay_from' => 'cash', 'amount' => 1120, 'account_id' => $rent, 'payee' => 'Landlord', 'with_vat' => 1, 'reference' => 'OR-55']);
    $d = DB::one('SELECT * FROM disbursements WHERE id = ?', [$id]);
    eq(120, $d['vat_amount']);
    eq(1000, Ledger::balance($rent));
    eq(120, acct_balance('input_vat'));
    eq(r2($cashBefore - 1120), acct_balance('cash_on_hand'));
    ok(str_starts_with($d['doc_no'], 'DV-'), $d['doc_no']);
    throws(fn () => Disbursements::create(['pay_from' => 'cash', 'amount' => 10, 'account_id' => Ledger::account('cash_on_hand')]), 'same account');

    $bank = Banks::create(['bank_name' => 'BDO', 'account_no' => '1234567890', 'opening_balance' => 50000]);
    $gl = (int) DB::value('SELECT gl_account_id FROM bank_accounts WHERE id = ?', [$bank]);
    $util = (int) DB::value("SELECT id FROM accounts WHERE code = '6200'");
    $b = Disbursements::create(['pay_from' => 'bank', 'bank_account_id' => $bank, 'amount' => 3500, 'account_id' => $util, 'payee' => 'Meralco']);
    eq(46500, Ledger::balance($gl));
    $txn = DB::one('SELECT * FROM bank_txns WHERE id = (SELECT bank_txn_id FROM disbursements WHERE id = ?)', [$b]);
    eq('withdrawal', $txn['txn_type']);
    eq(3500, $txn['amount']);
    Disbursements::void($b, 'wrong bank');
    eq(50000, Ledger::balance($gl));
    eq('void', DB::value('SELECT status FROM bank_txns WHERE id = ?', [$txn['id']]));
    throws(fn () => Disbursements::void($b, 'again'), 'already void');
    assert_books_balance();
});

test('cash & bank position: cash on hand, petty cash, banks, unsettled card sales', function () {
    $p = Disbursements::position(today());
    $labels = array_column($p['cash'], 'balance', 'label');
    eq(acct_balance('cash_on_hand'), $labels['Cash on hand (incl. the cash drawer)']);
    $bdo = array_values(array_filter($p['banks'], fn ($b) => str_starts_with($b['label'], 'BDO')));
    eq(50000, $bdo[0]['balance']);
    ok(count($p['banks']) === 2, 'BDO + the standard Cash in Bank account');
    eq(r2($p['totals']['cash'] + $p['totals']['banks']), $p['totals']['all']);
    eq(1, count(array_filter($p['pending'], fn ($r) => str_contains($r['label'], 'Card'))), 'card receivable listed');
});

test('end of day detail: count by denomination, every payout, voids', function () {
    CashSessions::open(1500, null, ['1000' => 1, '500' => 1]);
    $t = Tickets::create(['order_type' => 'takeout']);
    Tickets::pay(Tickets::addItem($t['id'], item_id('Chicken Adobo'), 1)['id'], [['method' => 'cash', 'amount' => 185]]);
    PettyCash::record(['txn_type' => 'expense', 'source' => 'drawer', 'amount' => 85, 'account_id' => DB::value("SELECT id FROM accounts WHERE code = '6210'"), 'payee' => 'Petron', 'description' => 'LPG']);
    $x = Tickets::create(['order_type' => 'takeout']);
    Tickets::void(Tickets::addItem($x['id'], item_id('Coke'), 1)['id'], 'customer left');
    $sid = (int) CashSessions::current()['id'];
    eq(1600, CashSessions::report($sid)['cash']['expected'], '1500 + 185 - 85');
    CashSessions::close(['1000' => 1, '500' => 1, '100' => 1]);
    $d = SalesReports::dayDetail($sid);
    $closing = array_column(array_filter($d['closing'], fn ($r) => $r['qty'] > 0), 'amount', 'denomination');
    eq([1000 => 1000.0, 500 => 500.0, 100 => 100.0], $closing);
    eq(2, count(array_filter($d['opening'], fn ($r) => $r['qty'] > 0)));
    eq('Petron', $d['payouts'][0]['payee']);
    eq('Cancelled order', $d['voids'][0]['kind']);
    eq(0, (float) DB::value('SELECT variance FROM cash_sessions WHERE id = ?', [$sid]));
});

test('item import from CSV: create, categories / units / stations, beginning stock posted', function () use ($tmp) {
    $csv = "Name,Type,Category,Unit,Selling price,Cost,Stock on hand,Station,Sellable\n"
        . "Pork BBQ,Menu,Grilled,stick,45,,,Grill,Yes\n"
        . "Calamansi Juice,Menu,Beverages,,60,,,,\n"
        . "Squid,Ingredient,Seafood,kg,,280,5,,No\n"
        . "Bad Row,Spaceship,,,abc,,,,\n";
    file_put_contents("$tmp/items.csv", $csv);
    $rows = ItemImport::analyze(ItemImport::parse("$tmp/items.csv", 'items.csv'));
    eq(['create', 'create', 'create', 'error'], array_column($rows, '_action'));
    ok(count($rows[3]['_errors']) === 2, implode(' | ', $rows[3]['_errors']));
    $invBefore = acct_balance('inventory');
    $c = ItemImport::import(ItemImport::parse("$tmp/items.csv", 'items.csv'));
    eq(3, $c['created']);
    eq(1, $c['errors']);
    eq(1, $c['stock_lines']);
    $bbq = DB::one('SELECT i.*, c.name cat, s.name station, u.abbr unit FROM items i LEFT JOIN categories c ON c.id = i.category_id
                    LEFT JOIN prep_stations s ON s.id = i.station_id LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.name = ?', ['Pork BBQ']);
    eq('composite', $bbq['item_type']);
    eq(45, $bbq['price']);
    eq('Grilled', $bbq['cat']);
    eq('Grill', $bbq['station']);
    eq('stick', $bbq['unit'], 'new unit created');
    eq(1, (int) $bbq['sellable']);
    eq(5, stock('Squid'));
    eq(r2($invBefore + 1400), acct_balance('inventory'), 'beginning stock 5 kg x 280 in the books');
    assert_books_balance();
});

test('item import from .xlsx: title rows above the header are skipped; existing items updated, recipes kept', function () use ($tmp) {
    $recipeBefore = (int) DB::value('SELECT COUNT(*) FROM item_components WHERE parent_id = ?', [item_id('Chicken Adobo')]);
    $cols = array_map(fn ($h) => ['key' => $h, 'label' => $h], ['Name', 'Selling price', 'Barcode']);
    $xlsx = Excel::xlsx('My Restaurant', 'Price update', 'October', $cols, [
        ['cells' => ['Chicken Adobo', 195, ''], 'bold' => false],
        ['cells' => ['Coke in Can', 70, '4801981116102'], 'bold' => false],
        ['cells' => ['Brand New Dish', 99.5, ''], 'bold' => false],
    ]);
    file_put_contents("$tmp/prices.xlsx", $xlsx);
    $rows = ItemImport::analyze(ItemImport::parse("$tmp/prices.xlsx", 'prices.xlsx'));
    eq(['update', 'update', 'create'], array_column($rows, '_action'));
    $c = ItemImport::import(ItemImport::parse("$tmp/prices.xlsx", 'prices.xlsx'));
    eq(2, $c['updated']);
    eq(195, DB::value('SELECT price FROM items WHERE id = ?', [item_id('Chicken Adobo')]));
    eq('4801981116102', DB::value('SELECT barcode FROM items WHERE id = ?', [item_id('Coke')]));
    eq($recipeBefore, (int) DB::value('SELECT COUNT(*) FROM item_components WHERE parent_id = ?', [item_id('Chicken Adobo')]), 'recipe untouched');
    eq('composite', DB::value('SELECT item_type FROM items WHERE name = ?', ['Brand New Dish']), 'has a price, no type: menu item');
    eq(99.5, DB::value('SELECT price FROM items WHERE name = ?', ['Brand New Dish']));
    // "skip existing" mode
    eq(['skip', 'skip', 'skip'], array_column(ItemImport::analyze(ItemImport::parse("$tmp/prices.xlsx", 'prices.xlsx'), false), '_action'));
    throws(fn () => ItemImport::parse("$tmp/prices.xlsx", 'prices.xls'), '.xls');
});

test('setup wizard: pending on a new install, steps save, finish hides it', function () {
    ok(SetupWizard::pending(), 'new installation starts with the wizard');
    SetupWizard::save('business', ['business_name' => 'Mama Gapos', 'business_address' => 'Quezon City', 'business_tin' => '123-456-789-00000',
        'receipt_title' => 'OFFICIAL RECEIPT', 'receipt_footer' => 'Salamat po!', 'receipt_prefix' => 'MG']);
    eq('Mama Gapos', Settings::get('business_name'));
    SetupWizard::save('taxes', ['vat_registered' => '1', 'prices_include_vat' => '0', 'vat_rate' => '12', 'sc_discount_rate' => '20', 'service_charge_rate' => '5']);
    eq('0', Settings::get('prices_include_vat'));
    eq('5', Settings::get('service_charge_rate'));
    SetupWizard::save('stations', ['stations' => "Kitchen\nBar\n\nDessert Station"]);
    eq(4, (int) DB::value('SELECT COUNT(*) FROM prep_stations'), 'Kitchen + Grill existed; Bar and Dessert Station added');
    $bar = (int) DB::value("SELECT id FROM prep_stations WHERE name = 'Bar'");
    SetupWizard::save('categories', ['cat' => [['name' => 'Cocktails', 'kind' => 'menu', 'station_id' => $bar, 'color' => '#2563eb'], ['name' => ''], ['name' => 'Beverages']]]);
    eq($bar, (int) DB::value("SELECT station_id FROM categories WHERE name = 'Cocktails'"));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM categories WHERE name = 'Beverages'"), 'existing names are not duplicated');
    SetupWizard::save('units', ['extra_units' => "Bundle (bdl)\nTali"]);
    eq('Bundle', DB::value("SELECT name FROM uoms WHERE abbr = 'bdl'"));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM uoms WHERE abbr = 'Tali'"));
    SetupWizard::save('pos', ['require_table_dine_in' => '1', 'pos_menu_sort' => 'name', 'blind_count' => '1']);
    eq('1', Settings::get('blind_count'));
    eq('name', Settings::get('pos_menu_sort'));
    SetupWizard::finish();
    ok(!SetupWizard::pending(), 'finished');
    SetupWizard::restart();
    ok(SetupWizard::pending(), 'can be run again');
    SetupWizard::finish();
    Settings::set('prices_include_vat', '1');
});

test('setup wizard on an empty system: standard chart, GL roles and roles come back', function () {
    App\Services\Backup::$dir = sys_get_temp_dir() . '/mark5_r5_bk_' . getmypid();
    $admin = (int) DB::value("SELECT id FROM users WHERE username = 'admin'");
    App\Services\Admin\Reset::startFresh($admin, 'admin123', 'DELETE ALL');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM accounts'));
    SetupWizard::save('accounts', ['chart' => 'standard']);
    ok((int) DB::value('SELECT COUNT(*) FROM accounts') > 40, 'standard chart loaded');
    eq(0, SetupWizard::counts()['gl_missing'], 'every automatic posting has its account');
    SetupWizard::save('units', ['standard_units' => '1']);
    eq(1000, (float) DB::value("SELECT c.factor FROM uom_conversions c JOIN uoms f ON f.id = c.from_uom_id JOIN uoms t ON t.id = c.to_uom_id WHERE f.abbr = 'kg' AND t.abbr = 'g'"));
    SetupWizard::save('pos', ['standard_discounts' => '1', 'standard_roles' => '1', 'pos_menu_sort' => 'custom']);
    ok((int) DB::value('SELECT COUNT(*) FROM discounts') >= 7, 'discounts');
    ok((bool) DB::value("SELECT id FROM roles WHERE name = 'Cashier'"), 'roles');
    array_map('unlink', glob(App\Services\Backup::dir() . '/*'));
    rmdir(App\Services\Backup::dir());
});

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
