<?php
/**
 * Staff notes recorded at the POS (absent / leave / undertime) for payroll, and production (BOM templates with hidden
 * ingredients, production plans, release to inventory, void).
 */
use App\Core\DB;
use App\Services\Finance\Employees;
use App\Services\InventoryDocs;
use App\Services\Payroll\Attendance;
use App\Services\Payroll\Payroll;
use App\Services\Payroll\StaffNotes;
use App\Services\Production;
use App\Services\Reports\InventoryReports;

as_user('admin');
$uom = fn (string $abbr) => (int) DB::value('SELECT id FROM uoms WHERE abbr = ?', [$abbr]);

/** A user with exactly these permissions, logged in. */
function as_role_with(string $username, array $perms): int
{
    $roleId = DB::insert('roles', ['name' => 'Role ' . $username, 'description' => 'test', 'permissions' => json_encode($perms), 'is_system' => 0]);
    $id = DB::insert('users', ['username' => $username, 'full_name' => ucfirst($username), 'password_hash' => password_hash('secret1', PASSWORD_DEFAULT),
        'role_id' => $roleId, 'active' => 1, 'created_at' => now()]);
    App\Core\Auth::actAs($id);
    return $id;
}

test('staff notes: the cashier records absent / undertime; payroll sees them per cut-off and applies absent to the attendance', function () {
    $ana = Employees::save(['full_name' => 'Ana Reyes', 'pay_type' => 'monthly', 'monthly_rate' => 12000]);
    $ben = Employees::save(['full_name' => 'Ben Cruz']);
    $cashier = as_role_with('cashier7', ['pos.access', 'staffnotes.record']);
    $d = add_days(today(), -1);
    $absent = StaffNotes::record(['employee_id' => $ana, 'note_date' => $d, 'kind' => 'absent', 'note' => 'sick']);
    $ut = StaffNotes::record(['employee_id' => $ben, 'note_date' => $d, 'kind' => 'undertime', 'hours' => '2', 'note' => 'family emergency']);
    throws(fn () => StaffNotes::record(['employee_id' => $ben, 'kind' => 'undertime']), 'how many hours');
    throws(fn () => StaffNotes::record(['employee_id' => $ben, 'kind' => 'other']), 'Write the note');
    throws(fn () => StaffNotes::record(['employee_id' => $ben, 'kind' => 'absent', 'note_date' => add_days(today(), 5)]), 'future');
    throws(fn () => StaffNotes::apply($absent), 'permission');
    eq('Undertime 2 h: family emergency', StaffNotes::label(StaffNotes::find($ut)));

    // another cashier cannot remove it; the one who wrote it can, the same day
    as_role_with('cashier8', ['pos.access', 'staffnotes.record']);
    throws(fn () => StaffNotes::void($ut), 'Only payroll staff');
    App\Core\Auth::actAs($cashier);
    $tmp = StaffNotes::record(['employee_id' => $ben, 'kind' => 'late', 'hours' => 0.5]);
    StaffNotes::void($tmp);
    eq('void', StaffNotes::find($tmp)['status']);

    as_user('admin');
    $c = Payroll::cutoffFor($d);
    ok($c['from'] <= $d && $d <= $c['to'], 'cut-off of the date');
    $list = StaffNotes::list($c['from'], $c['to']);
    eq(2, count($list), 'voided note not listed');
    $sum = StaffNotes::summary($c['from'], $c['to']);
    eq([1, 2.0], [$sum[$ana]['absent'], (float) $sum[$ben]['undertime']]);
    eq(1, count(StaffNotes::byDay($c['from'], $c['to'])[$ana][$d]));

    // apply: the day becomes "absent" in the attendance (device times kept), the note is marked applied
    DB::insert('attendance', ['employee_id' => $ana, 'work_date' => $d, 'time_in' => '10:00:00', 'time_out' => null, 'day_type' => 'auto', 'source' => 'device']);
    StaffNotes::apply($absent);
    $row = DB::one('SELECT * FROM attendance WHERE employee_id = ? AND work_date = ?', [$ana, $d]);
    eq(['absent', 'manual', '10:00:00'], [$row['day_type'], $row['source'], $row['time_in']]);
    ok(str_contains($row['note'], 'sick'));
    eq('applied', StaffNotes::find($absent)['status']);
    throws(fn () => StaffNotes::apply($absent), 'Only open notes');
    throws(fn () => StaffNotes::apply($ut), 'reference only');
});

test('recent cut-offs follow the schedule, newest first, without gaps', function () {
    $cs = Payroll::recentCutoffs(4);
    eq(4, count($cs));
    ok($cs[0]['from'] <= today() && today() <= $cs[0]['to']);
    for ($i = 1; $i < 4; $i++) eq(add_days($cs[$i]['to'], 1), $cs[$i - 1]['from']);
});

test('BOM template: ingredients per batch; hidden from users without the BOM permission; validation', function () use ($uom) {
    $out = App\Services\Items::create(['name' => 'Chicken BBQ (marinated)', 'item_type' => 'raw', 'base_uom_id' => $uom('pc'), 'active' => 1]);
    $chicken = item_id('Chicken (whole');
    $soy = item_id('Soy Sauce');
    $garlic = item_id('Garlic');
    // stock: 10 kg chicken @190, 2 L soy @60, 1 kg garlic @140
    InventoryDocs::create('RECEIVE', ['payment_mode' => 'cash', 'post' => 1, 'lines' => [
        ['item_id' => $chicken, 'qty' => 10, 'line_total' => 1900], ['item_id' => $soy, 'qty' => 2, 'line_total' => 120], ['item_id' => $garlic, 'qty' => 1, 'line_total' => 140]]]);
    $id = Production::saveTemplate(null, ['name' => 'Chicken BBQ marinade', 'output_item_id' => $out, 'yield_qty' => 20, 'yield_uom_id' => $uom('pc'), 'lines' => [
        ['item_id' => $chicken, 'qty' => 2, 'uom_id' => $uom('kg')], ['item_id' => $soy, 'qty' => 250, 'uom_id' => $uom('mL')], ['item_id' => $garlic, 'qty' => 100, 'uom_id' => $uom('g')], ['item_id' => '']]]);
    $t = Production::template($id);
    ok(str_starts_with($t['code'], 'BOM-'));
    eq(3, count($t['lines']));
    throws(fn () => Production::saveTemplate(null, ['name' => 'x', 'output_item_id' => item_id('Chicken Adobo'), 'yield_qty' => 1, 'lines' => [['item_id' => $soy, 'qty' => 1]]]), 'not a stocked item');
    throws(fn () => Production::saveTemplate(null, ['name' => 'x', 'output_item_id' => $out, 'yield_qty' => 1, 'lines' => [['item_id' => $out, 'qty' => 1]]]), 'ingredient of itself');
    throws(fn () => Production::saveTemplate(null, ['name' => 'x', 'output_item_id' => $out, 'yield_qty' => 1, 'lines' => []]), 'at least one ingredient');

    as_role_with('clerk7', ['production.view', 'production.manage', 'inventory.view']);
    eq([], Production::template($id)['lines'], 'ingredients hidden');
    eq(false, Production::seesBom());
    throws(fn () => Production::saveTemplate($id, ['name' => 'x']), 'permission');
    as_user('admin');
});

test('production: plan from a template scales the ingredients; release takes materials out and puts the product in at their cost; void reverses', function () use ($uom) {
    $tpl = (int) DB::value("SELECT id FROM bom_templates WHERE name = 'Chicken BBQ marinade'");
    $out = item_id('Chicken BBQ (marinated)');
    // a production clerk without BOM access plans 50 pcs (2.5 batches)
    $clerk = (int) DB::value("SELECT id FROM users WHERE username = 'clerk7'");
    App\Core\Auth::actAs($clerk);
    $id = Production::save(null, ['template_id' => $tpl, 'planned_qty' => 50, 'prod_date' => today(), 'lines' => [['item_id' => item_id('Egg'), 'qty' => 99]], 'lines_edited' => 1]);
    $o = Production::find($id);
    eq('planned', $o['status']);
    ok(str_starts_with($o['doc_no'], 'PRD-'));
    $q = array_column($o['lines'], 'base_qty', 'item_id');
    eq([5.0, 0.625, 0.25], [(float) $q[item_id('Chicken (whole')], (float) $q[item_id('Soy Sauce')], (float) $q[item_id('Garlic')]], 'scaled x2.5 in base units; hand lines ignored without BOM access');
    eq(950 + 37.5 + 35, (float) $o['material_cost'], 'estimated cost');
    $v = Production::forViewer($o);
    eq([[], 3], [$v['lines'], $v['line_count']], 'clerk sees no ingredients');
    throws(fn () => Production::save(null, ['output_item_id' => $out, 'planned_qty' => 5, 'lines' => [['item_id' => item_id('Egg'), 'qty' => 1]]]), 'Choose a BOM template');

    // the clerk releases 48 pcs (2 short of plan): materials as planned, unit cost = 1022.50 / 48
    $inv = acct_balance('inventory');
    Production::release($id, 48);
    $o = Production::find($id);
    eq(['released', 48.0, 1022.5], [$o['status'], (float) $o['actual_qty'], (float) $o['material_cost']]);
    eq(round(1022.5 / 48, 4), (float) $o['unit_cost']);
    eq(48, stock('Chicken BBQ (marinated)'));
    eq(5, stock('Chicken (whole'));
    eq(round(1022.5 / 48, 4), (float) DB::value('SELECT avg_cost FROM items WHERE id = ?', [$out]));
    eq($inv, acct_balance('inventory'), 'inventory to inventory: total unchanged');
    ok($o['journal_entry_id'] > 0);
    eq('production', DB::value('SELECT source_type FROM journal_entries WHERE id = ?', [$o['journal_entry_id']]));
    assert_books_balance();
    throws(fn () => Production::release($id, 10), 'Only planned');
    $mv = array_column(InventoryReports::movement(today(), today()), null, 'id');
    eq([48.0, 0.0], [(float) $mv[$out]['produced'], (float) $mv[$out]['production_use']]);
    eq(5.0, (float) $mv[item_id('Chicken (whole')]['production_use']);
    eq('Produced', InventoryReports::moveLabel('PROD_IN'));

    // void: stock and books back
    Production::cancel($id, 'wrong batch');
    eq('cancelled', Production::find($id)['status']);
    eq(0, stock('Chicken BBQ (marinated)'));
    eq(10, stock('Chicken (whole'));
    assert_books_balance();
    throws(fn () => Production::cancel($id, 'again'), 'Already cancelled');
    as_user('admin');
});

test('production with BOM access: hand-edited materials, manual plan without template, cancel and delete plans, template in use is only deactivated', function () use ($uom) {
    $tpl = (int) DB::value("SELECT id FROM bom_templates WHERE name = 'Chicken BBQ marinade'");
    $out = item_id('Chicken BBQ (marinated)');
    $id = Production::save(null, ['template_id' => $tpl, 'planned_qty' => 20, 'lines_edited' => 1, 'lines' => [
        ['item_id' => item_id('Chicken (whole'), 'qty' => 2.2, 'uom_id' => $uom('kg')], ['item_id' => item_id('Soy Sauce'), 'qty' => 0.25, 'uom_id' => $uom('L')]]]);
    eq(2, count(Production::find($id)['lines']), 'kept as edited');
    // changing the quantity without touching the lines re-scales the template
    Production::save($id, ['template_id' => $tpl, 'planned_qty' => 40, 'lines' => []]);
    eq(3, count(Production::find($id)['lines']));
    Production::cancel($id, 'not today');
    throws(fn () => Production::save($id, ['template_id' => $tpl, 'planned_qty' => 10]), 'already cancelled');

    throws(fn () => Production::save(null, ['output_item_id' => $out, 'planned_qty' => 10, 'lines' => [['item_id' => item_id('Egg'), 'qty' => 0]]]), 'greater than zero');
    $manual = Production::save(null, ['output_item_id' => $out, 'planned_qty' => 10, 'output_uom_id' => $uom('pc'), 'lines' => [['item_id' => item_id('Chicken (whole'), 'qty' => 1]]]);
    eq(null, Production::find($manual)['template_id']);
    Production::delete($manual);
    throws(fn () => Production::find($manual), 'not found');

    Production::deleteTemplate($tpl);
    eq(0, (int) DB::value('SELECT active FROM bom_templates WHERE id = ?', [$tpl]), 'used by production: deactivated');
});

test('upgrade step 11 is re-runnable and gives roles the new permissions', function () {
    DB::run("UPDATE settings SET value = '10' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    App\Services\Migrations::run();
    $perms = fn ($role) => json_decode(DB::value('SELECT permissions FROM roles WHERE name = ?', [$role]), true);
    ok(in_array('staffnotes.record', $perms('Cashier'), true));
    ok(in_array('production.bom', $perms('Manager'), true));
    ok(in_array('production.manage', $perms('Inventory Clerk'), true) && !in_array('production.bom', $perms('Inventory Clerk'), true));
    eq(1, count(array_keys($perms('Cashier'), 'staffnotes.record')));
});
