<?php
use App\Core\Auth;
use App\Core\DB;
use App\Services\Admin\Roles;
use App\Services\Admin\SettingsForm;
use App\Services\Admin\Users;
use App\Services\Backup;
use App\Services\Settings;

// Keep test backups out of storage/backups.
Backup::$dir = sys_get_temp_dir() . '/mark5_admin_test_' . getmypid();

// ------------------------------------------------------------------ roles
test('role create/update keeps only known permissions', function () {
    $id = Roles::save(['name' => 'Head Cook', 'description' => 'Kitchen lead', 'permissions' => ['inventory.view', 'hack.everything', 'inventory.waste']]);
    eq(['inventory.view', 'inventory.waste'], Roles::find($id)['permissions']);
    Roles::save(['id' => $id, 'name' => 'Head Cook', 'permissions' => ['inventory.count', 'bogus']]);
    $r = Roles::find($id);
    eq(['inventory.count'], $r['permissions']);
    eq(null, $r['description'], 'blank description');
    throws(fn () => Roles::save(['name' => 'Head Cook']), 'already exists');
    throws(fn () => Roles::save(['name' => '  ']), 'name is required');
});

test('Administrator role keeps full access and cannot be deleted; roles in use cannot be deleted', function () {
    $admin = (int) DB::value("SELECT id FROM roles WHERE name = 'Administrator'");
    Roles::save(['id' => $admin, 'name' => 'Renamed', 'description' => 'Owner', 'permissions' => ['pos.access']]);
    $r = Roles::find($admin);
    eq('Administrator', $r['name']);
    eq(['*'], $r['permissions']);
    throws(fn () => Roles::delete($admin), 'cannot be deleted');
    $cashier = (int) DB::value("SELECT id FROM roles WHERE name = 'Cashier'");
    Users::create(['username' => 'cash1', 'full_name' => 'Cashier One', 'password' => 'secret1', 'role_id' => $cashier]);
    throws(fn () => Roles::delete($cashier), 'still assigned');
    $temp = Roles::save(['name' => 'Temp role', 'permissions' => []]);
    Roles::delete($temp);
    ok(!Roles::find($temp), 'deleted');
});

// ------------------------------------------------------------------ users
test('user create validates password, PIN, role and duplicate username', function () {
    $role = (int) DB::value("SELECT id FROM roles WHERE name = 'Waiter'");
    $base = ['username' => 'waiter1', 'full_name' => 'Waiter One', 'password' => 'secret1', 'role_id' => $role];
    throws(fn () => Users::create(['password' => 'x'] + $base), 'at least 6');
    throws(fn () => Users::create(['pin' => '12a4'] + $base), 'PIN must be 4-8 digits');
    throws(fn () => Users::create(['pin' => '123'] + $base), 'PIN must be 4-8 digits');
    throws(fn () => Users::create(['role_id' => 99999] + $base), 'Choose a role');
    throws(fn () => Users::create(['full_name' => ''] + $base), 'Full name is required');
    $emp = (int) DB::value('SELECT id FROM employees ORDER BY id LIMIT 1');
    $id = Users::create(['pin' => '4321', 'employee_id' => $emp] + $base);
    $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
    ok(password_verify('secret1', $u['password_hash']), 'password hashed');
    ok(password_verify('4321', $u['pin_hash']), 'PIN hashed');
    eq($emp, (int) $u['employee_id']);
    throws(fn () => Users::create($base), 'already exists');
});

test('user update: blank password/PIN unchanged, PIN works for overrides', function () {
    $id = (int) DB::value("SELECT id FROM users WHERE username = 'waiter1'");
    $before = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
    $manager = (int) DB::value("SELECT id FROM roles WHERE name = 'Manager'");
    Users::update($id, ['full_name' => 'Waiter Promoted', 'role_id' => $manager, 'password' => '', 'pin' => '', 'employee_id' => '', 'active' => '1']);
    $after = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
    eq($before['password_hash'], $after['password_hash']);
    eq($before['pin_hash'], $after['pin_hash']);
    eq(null, $after['employee_id']);
    eq('Waiter Promoted', $after['full_name']);
    Users::update($id, ['full_name' => 'Waiter Promoted', 'role_id' => $manager, 'password' => 'newpass', 'pin' => '987654', 'active' => '1']);
    ok(password_verify('newpass', DB::value('SELECT password_hash FROM users WHERE id = ?', [$id])), 'new password');
    as_user('cash1');
    eq($id, Auth::authorize('pos.void_receipt', '987654'), 'manager PIN override');
    as_user('admin');
});

test('cannot disable yourself; disabling another user blocks sign-in', function () {
    $me = Auth::id();
    $role = (int) DB::value('SELECT role_id FROM users WHERE id = ?', [$me]);
    throws(fn () => Users::disable($me), 'your own account');
    throws(fn () => Users::update($me, ['full_name' => 'Admin', 'role_id' => $role]), 'your own account');
    eq(1, (int) DB::value('SELECT active FROM users WHERE id = ?', [$me]));
    $id = (int) DB::value("SELECT id FROM users WHERE username = 'cash1'");
    Users::disable($id);
    eq(0, (int) DB::value('SELECT active FROM users WHERE id = ?', [$id]));
    throws(fn () => Auth::attempt('cash1', 'secret1', '127.0.0.1'), 'disabled');
    eq('disable', DB::value("SELECT action FROM audit_log WHERE entity = 'user' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]));
});

// ------------------------------------------------------------------ settings
test('settings save: booleans as 1/0, rates validated', function () {
    SettingsForm::save('tax', ['vat_rate' => '12', 'sc_discount_rate' => '20', 'service_charge_rate' => '10', 'require_payment_ref' => '1']);
    eq('0', Settings::get('vat_registered'), 'unticked checkbox');
    eq('1', Settings::get('require_payment_ref'));
    eq('10', Settings::get('service_charge_rate'));
    ok(!Settings::tax()['vatRegistered'], 'tax() reflects non-VAT');
    throws(fn () => SettingsForm::save('tax', ['vat_rate' => '120', 'sc_discount_rate' => '20', 'service_charge_rate' => '0']), 'from 0 to 100');
    throws(fn () => SettingsForm::save('backup', ['auto_backup' => '1', 'backup_retention' => '0']), 'at least 1');
    SettingsForm::save('business', ['business_name' => '  Kusina ni Lola  ', 'receipt_prefix' => 'SI', 'receipt_footer' => 'Salamat po!']);
    eq('Kusina ni Lola', Settings::get('business_name'));
    eq('SI', Settings::get('receipt_prefix'));
    throws(fn () => SettingsForm::save('business', ['receipt_prefix' => 'WAYTOOLONG1']), 'at most 10');
    throws(fn () => SettingsForm::save('nope', []), 'Unknown');
    SettingsForm::save('tax', ['vat_registered' => '1', 'vat_rate' => '12', 'sc_discount_rate' => '20', 'service_charge_rate' => '0', 'service_charge_dine_in_only' => '1']);
});

// ------------------------------------------------------------------ backup & restore
test('statement splitter keeps ";\n" inside quoted strings', function () {
    $file = Backup::dir() . '/split.tmp';
    file_put_contents($file, "-- comment\nINSERT INTO t VALUES ('a;\nb', \"c;\n\", 'it''s;\n');\n\nSELECT 1;\nSELECT 'x\\';\n';\n");
    $parts = iterator_to_array(Backup::statements($file), false);
    unlink($file);
    eq(3, count($parts));
    eq("INSERT INTO t VALUES ('a;\nb', \"c;\n\", 'it''s;\n')", $parts[0]);
    eq("SELECT 'x\\';\n'", $parts[2]);
});

test('backup -> change data -> restore brings everything back (semicolons, NULLs, unicode)', function () {
    $tricky = "Line one;\nDROP TABLE users;\n'quoted' \"double\" back\\slash `tick`";
    Settings::set('test_tricky', $tricky);
    Settings::set('test_null', null);
    Settings::set('test_peso', '₱1,250.00 — Piña, ñ, 日本');
    $emp = DB::insert('employees', ['full_name' => 'José “Pepe” Rizal', 'notes' => null, 'position' => "Cook;\n"]);
    // More rows than one INSERT batch holds
    DB::run("INSERT INTO audit_log (ts, action, details) VALUES " . implode(',', array_fill(0, 1234, "(NOW(), 'bulk', 'row')")));
    $counts = fn () => [DB::value('SELECT COUNT(*) FROM journal_lines'), DB::value('SELECT COUNT(*) FROM items'), DB::value('SELECT COUNT(*) FROM users'),
        DB::value("SELECT COUNT(*) FROM audit_log WHERE action = 'bulk'")];
    $before = $counts();

    $b = Backup::create('manual');
    ok(str_starts_with(file_get_contents(Backup::path($b['name'])), "-- Mark5 backup\n"), 'header');
    eq('manual', $b['kind']);

    // Change data after the backup
    Settings::set('test_tricky', 'changed');
    DB::run("DELETE FROM settings WHERE `key` = 'test_peso'");
    DB::run('DELETE FROM employees WHERE id = ?', [$emp]);
    $role = (int) DB::value("SELECT id FROM roles WHERE name = 'Cashier'");
    Users::create(['username' => 'after_backup', 'full_name' => 'Created later', 'password' => 'secret1', 'role_id' => $role]);

    $result = Backup::restore(Backup::path($b['name']), $b['name']);
    ok(str_starts_with($result['safety_backup'], 'mark5_pre-restore_'), 'safety backup name');
    ok(is_file(Backup::path($result['safety_backup'])), 'safety backup exists');

    eq($tricky, Settings::get('test_tricky'));
    ok(DB::value("SELECT COUNT(*) FROM settings WHERE `key` = 'test_null' AND `value` IS NULL") == 1, 'NULL preserved');
    eq('₱1,250.00 — Piña, ñ, 日本', Settings::get('test_peso'));
    $e = DB::one('SELECT * FROM employees WHERE id = ?', [$emp]);
    eq('José “Pepe” Rizal', $e['full_name']);
    eq(null, $e['notes']);
    eq("Cook;\n", $e['position']);
    eq(null, DB::value("SELECT id FROM users WHERE username = 'after_backup'"));
    eq($before, $counts());
    eq(1, (int) DB::value('SELECT @@FOREIGN_KEY_CHECKS'), 'foreign key checks back on');
    eq('restore', DB::value('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    assert_books_balance();
});

test('restoring the safety backup undoes a restore', function () {
    Settings::set('test_tricky', 'value before undo');
    $b = Backup::create('manual');
    Settings::set('test_tricky', 'value after backup');
    $r = Backup::restore(Backup::path($b['name']), $b['name']);
    eq('value before undo', Settings::get('test_tricky'));
    Backup::restore(Backup::path($r['safety_backup']), $r['safety_backup']);
    eq('value after backup', Settings::get('test_tricky'));
});

test('uploaded backups: bad header, truncated file and wrong extension are rejected', function () {
    Settings::set('test_marker', 'untouched');
    $count = count(Backup::list());
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, "-- MySQL dump\nDROP TABLE IF EXISTS `settings`;\n");
    throws(fn () => Backup::restoreUpload(['name' => 'evil.sql', 'tmp_name' => $tmp]), 'not a Mark5 backup');

    $good = file_get_contents(Backup::path(Backup::create('manual')['name']));
    file_put_contents($tmp, substr($good, 0, (int) (strlen($good) / 2)));
    throws(fn () => Backup::restoreUpload(['name' => 'cut.sql', 'tmp_name' => $tmp]), 'incomplete');
    throws(fn () => Backup::restoreUpload(['name' => 'backup.db', 'tmp_name' => $tmp]), '.sql');
    throws(fn () => Backup::restoreUpload(null), 'Choose a backup file');
    unlink($tmp);
    eq('untouched', Settings::get('test_marker'));
    eq($count + 1, count(Backup::list()), 'no pre-restore backup was made for rejected files');
    throws(fn () => Backup::path('../config/config.php'), 'Invalid backup name');
});

test('valid uploaded backup restores', function () {
    $b = Backup::create('manual');
    Settings::set('test_marker', 'changed after download');
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    copy(Backup::path($b['name']), $tmp);
    Backup::restoreUpload(['name' => 'downloaded.sql', 'tmp_name' => $tmp]);
    unlink($tmp);
    eq('untouched', Settings::get('test_marker'));
});

test('automatic backup: once per day, old automatic copies pruned, manual kept', function () {
    Settings::set('auto_backup', '1');
    Settings::set('backup_retention', '2');
    Settings::set('last_auto_backup', add_days(today(), -1));
    ok(Backup::autoBackupDue(), 'due');
    ok(Backup::autoBackup() !== null, 'first run makes a backup');
    eq(null, Backup::autoBackup(), 'second run the same day does nothing');
    ok(!Backup::autoBackupDue(), 'not due any more');
    Backup::create('auto');
    $newest = Backup::create('auto')['name'];
    $autos = array_values(array_filter(Backup::list(), fn ($b) => $b['kind'] === 'auto'));
    eq(2, count($autos));
    eq($newest, $autos[0]['name'], 'newest kept');
    ok(count(array_filter(Backup::list(), fn ($b) => $b['kind'] === 'manual')) >= 3, 'manual backups untouched');
    Settings::set('auto_backup', '0');
    Settings::set('last_auto_backup', '');
    eq(null, Backup::autoBackup(), 'disabled');
});

// Clean up the temporary backup folder
array_map('unlink', glob(Backup::dir() . '/*'));
rmdir(Backup::dir());
