<?php
use App\Core\DB;
use App\Services\Discounts;
use App\Services\Migrations;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;

as_user('admin');
CashSessions::open(1000);
$preset = fn (string $name) => (int) DB::value('SELECT id FROM discounts WHERE name = ?', [$name]);
$line = fn (array $t, string $item) => array_values(array_filter($t['items'], fn ($i) => $i['item_id'] === item_id($item) && $i['status'] === 'active'))[0]['id'];

test('default discount presets are installed', function () {
    $names = array_column(Discounts::all(true), 'name');
    foreach (['Senior Citizen', 'PWD', 'Employee 10%', 'Open discount %', 'Complimentary (free)'] as $n) ok(in_array($n, $names, true), "missing $n");
});

test('SC tagged on the senior’s own meal + employee 10% on another item', function () use ($preset, $line) {
    $t = Tickets::create(['pax' => 2]);
    Tickets::addItem($t['id'], item_id('Adobo Rice Meal'), 1);
    $t = Tickets::addItem($t['id'], item_id('Sisig Rice Meal'), 1);
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Adobo Rice Meal'), ['discount_id' => $preset('Senior Citizen')]), 'name and ID');
    $t = Tickets::discountLine($t['id'], $line($t, 'Adobo Rice Meal'), ['discount_id' => $preset('Senior Citizen'), 'sc_person' => ['name' => 'Lola Nena', 'id_no' => 'OSCA-1']]);
    // 199 is VAT-exempt: 199 / 1.12 = 177.68, 20% = 35.54; the sisig meal stays VAT-able
    eq(177.68, $t['vat_exempt_sales']);
    eq(35.54, $t['sc_discount']);
    eq(23.46, $t['vat_amount']);
    eq(361.14, $t['total']);
    $t = Tickets::discountLine($t['id'], $line($t, 'Sisig Rice Meal'), ['discount_id' => $preset('Employee 10%')]);
    eq(21.90, $t['promo_discount']);
    eq(57.44, $t['discount_amount']);
    eq(21.12, $t['vat_amount']);
    eq(339.24, $t['total']);
    $amounts = array_column(array_filter($t['items'], fn ($i) => $i['discount_kind']), 'discount_amount');
    eq(57.44, array_sum($amounts), 'line discounts add up to the receipt discount');
    eq('Lola Nena', $t['sc_details'][0]['name']);

    // A whole-receipt SC discount cannot be stacked on item discounts
    throws(fn () => Tickets::discount($t['id'], ['discount_id' => $preset('Senior Citizen'), 'sc_count' => 1, 'sc_details' => [['name' => 'X', 'id_no' => '1']]]), 'Remove the item discounts');
    $paid = Tickets::pay($t['id'], [['method' => 'cash', 'amount' => 400]]);
    eq(60.76, $paid['change_amount']);
    assert_books_balance();
    // Sales discounts booked net of VAT: 35.54 + 21.90 / 1.12 = 55.09
    eq(55.09, acct_balance('sales_discounts'));
});

test('receipt-level promo applies after item discounts; removing the SC item forgets the names', function () use ($preset, $line) {
    $t = Tickets::create(['pax' => 1]);
    $t = Tickets::addItem($t['id'], item_id('Chicken Adobo'), 2);    // 370
    $l = $line($t, 'Chicken Adobo');
    Tickets::discountLine($t['id'], $l, ['discount_id' => $preset('Open discount ₱'), 'value' => 70]);   // 300 left
    $t = Tickets::discount($t['id'], ['discount_id' => $preset('Open discount %'), 'value' => 10]);       // 10% of 300
    eq(100, $t['promo_discount']);
    eq(270, $t['total']);
    eq('Open discount %', $t['discount_name']);
    $t = Tickets::discountLine($t['id'], $l, ['discount_id' => $preset('Senior Citizen'), 'sc_person' => ['name' => 'Lolo Ben', 'id_no' => 'OSCA-2']]);
    ok(count($t['sc_details']) === 1, 'SC person stored');
    $t = Tickets::discountLine($t['id'], $l, ['discount_type' => 'none']);
    eq([], $t['sc_details']);
    Tickets::void($t['id'], 'test');
});

test('preset scope, open value and approval rules', function () use ($preset, $line) {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Halo-halo Special'), 1);
    throws(fn () => Tickets::discount($t['id'], ['discount_id' => $preset('Complimentary (free)')]), 'single items');
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Halo-halo Special'), ['discount_id' => $preset('Open discount %')]), 'Enter the discount value');
    // A cashier needs a manager PIN for presets that require approval, but not for ones that don't
    DB::insert('users', ['username' => 'cash2', 'full_name' => 'Cashier Two', 'password_hash' => password_hash('secret1', PASSWORD_DEFAULT),
        'role_id' => (int) DB::value("SELECT id FROM roles WHERE name = 'Cashier'"), 'active' => 1, 'created_at' => now()]);
    $free = Discounts::save(['name' => 'Birthday freebie', 'kind' => 'percent', 'value' => 100, 'scope' => 'item', 'requires_approval' => 0, 'active' => 1]);
    as_user('cash2');
    throws(fn () => Tickets::discountLine($t['id'], $line($t, 'Halo-halo Special'), ['discount_id' => $preset('Complimentary (free)')]), 'Manager authorization');
    $t2 = Tickets::discountLine($t['id'], $line($t, 'Halo-halo Special'), ['discount_id' => $free]);
    eq(0, $t2['total']);
    $t3 = Tickets::discountLine($t['id'], $line($t, 'Halo-halo Special'), ['discount_id' => $preset('Complimentary (free)')], '1234');
    eq('Complimentary (free)', $t3['items'][0]['discount_name']);
    as_user('admin');
    Tickets::void($t['id'], 'test');
});

test('split shares a fixed item discount by quantity', function () use ($preset, $line) {
    $t = Tickets::create([]);
    $t = Tickets::addItem($t['id'], item_id('Chicken Adobo'), 4);   // 740
    $t = Tickets::discountLine($t['id'], $line($t, 'Chicken Adobo'), ['discount_id' => $preset('Open discount ₱'), 'value' => 100]);
    $r = Tickets::split($t['id'], [['id' => $line($t, 'Chicken Adobo'), 'qty' => 1]]);
    eq(555 - 75, $r['source']['total']);
    eq(185 - 25, $r['target']['total']);
    Tickets::void($r['source']['id'], 'test');
    Tickets::void($r['target']['id'], 'test');
});

test('Z-reading lists discounts by name', function () {
    $z = CashSessions::report((int) CashSessions::current()['id']);
    $labels = array_column($z['discounts'], 'amount', 'label');
    eq(35.54, $labels['Senior Citizen']);
    eq(21.90, $labels['Employee 10%']);
    assert_books_balance();
});

test('database upgrade steps can run again safely', function () {
    App\Services\Settings::set('db_version', 0);
    Migrations::run();
    eq(Migrations::latest(), Migrations::current());
    ok(DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ticket_items' AND column_name = 'discount_kind'") == 1);
});

test('discount book lists item discounts with the SC/PWD names', function () {
    $rows = App\Services\Reports\SalesReports::discounts('2000-01-01', '2100-01-01');
    eq(1, count($rows), 'one paid receipt with discounts');
    ok(str_contains($rows[0]['discount_type'], 'Senior Citizen (Adobo Rice Meal)'), $rows[0]['discount_type']);
    ok(str_contains($rows[0]['discount_type'], 'Employee 10% (Sisig Rice Meal)'), $rows[0]['discount_type']);
    eq('Lola Nena', $rows[0]['names']);
    eq(35.54, $rows[0]['sc_discount']);
    eq(21.90, $rows[0]['promo_discount']);
});
