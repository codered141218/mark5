<?php
/**
 * Payroll: ZKTeco attendance import (.xls report, attlog .dat), the template's pay rules, bonuses / deductions,
 * cash advances, posting to the books, salary payouts, void, employees' own payslips.
 */
use App\Core\Auth;
use App\Core\DB;
use App\Core\SheetReader;
use App\Core\XlsReader;
use App\Services\Finance\CashAdvances;
use App\Services\Finance\Employees;
use App\Services\Ledger;
use App\Services\Payroll\Attendance;
use App\Services\Payroll\Payroll;

as_user('admin');
$fixture = __DIR__ . '/fixtures/zkteco_report_sample.xls';

/** Attendance rows for compute(): [date => row] from 'HH:MM-HH:MM' strings ('' = no punches). */
function att_days(string $from, array $spans): array
{
    $out = [];
    $d = $from;
    foreach ($spans as $s) {
        if ($s !== '') {
            [$in, $out2] = array_pad(explode('-', $s), 2, null);
            $out[$d] = ['time_in' => $in ? "$in:00" : null, 'time_out' => $out2 ? "$out2:00" : null, 'day_type' => 'auto', 'note' => null];
        }
        $d = add_days($d, 1);
    }
    return $out;
}

test('xls reader: ZKTeco report sheets and cells (also through SheetReader for .xls item imports)', function () use ($fixture) {
    $s = XlsReader::sheets($fixture);
    eq(['Att. Stat.', 'Att.log report'], array_keys($s));
    $log = $s['Att.log report'];
    eq('Attendance Record Report', $log[0][0]);
    eq('2026-09-26 ~ 2026-10-10', $log[2][2]);
    eq(['26', '27', '28'], array_slice($log[3], 0, 3), 'day numbers as text without .0');
    eq('09:5622:32', $log[5][0]);
    eq('Statistical Report of Attendance', SheetReader::read($fixture, 'x.xls')[0][0]);
    throws(fn () => XlsReader::sheets(__FILE__), 'not an Excel 97-2003');
});

test('attendance file: punches per day, single punches kept, after-midnight punch joins the previous shift, attlog .dat', function () use ($fixture) {
    $p = Attendance::parse($fixture, '1_report_1.xls');
    eq(['2026-09-26', '2026-10-10'], [$p['from'], $p['to']]);
    eq(['5', '7', '9', '12'], array_map('strval', array_keys($p['people'])));
    eq('Ana', $p['people'][5]['name']);
    eq(['09:56', '22:32'], $p['people'][5]['days']['2026-09-26']);
    eq(['10:16'], $p['people'][5]['days']['2026-10-04'], 'one punch');
    eq(['09:57', '21:55', '21:55'], $p['people'][12]['days']['2026-10-08']);
    eq([], $p['people'][9]['days'], 'no punches at all');
    $dat = tempnam(sys_get_temp_dir(), 'att');
    file_put_contents($dat, "     5\t2026-09-26 09:56:22\t1\t0\t1\t0\n     5\t2026-09-26 22:32:10\t1\t1\t1\t0\n     5\t2026-09-27 10:02:00\t1\t0\t1\t0\n     5\t2026-09-28 00:40:00\t1\t1\t1\t0\n");
    $q = Attendance::parse($dat, '1_attlog.dat');
    eq(['09:56', '22:32'], $q['people'][5]['days']['2026-09-26']);
    eq(['10:02', '00:40'], $q['people'][5]['days']['2026-09-27'], '00:40 closes the shift of the 27th');
    ok(!isset($q['people'][5]['days']['2026-09-28']));
    unlink($dat);
});

test('pay rules of the template: half month, absences x daily, undertime / overtime x hourly, late minutes, past midnight', function () {
    $from = '2026-09-11';                                              // 15-day cut-off
    $full = array_fill(0, 13, '10:00-22:00');
    // Angel: 9,600 a month, 13 days present, 2 absent -> 4,800 - 640 = 4,160 (template: 4,160 before the 1,500 deduction)
    $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 9600, 'daily_rate' => 0], $from, '2026-09-25', att_days($from, array_merge($full, ['', ''])));
    eq([13, 2], [$c['days_present'], $c['days_absent']]);
    eq([320.0, 4800.0, 640.0, 0.0, 0.0], [round($c['daily_rate'], 2), $c['basic_pay'], $c['absence_deduction'], $c['undertime_deduction'], $c['ot_pay']]);
    // Jerald: 8,300, one 6-hour day -> 6 hours undertime = 138.33, 2 absent = 553.33 (template 3,458.33)
    $j = $full; $j[3] = '10:00-16:00';
    $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 8300, 'daily_rate' => 0], $from, '2026-09-25', att_days($from, array_merge($j, ['', ''])));
    eq([4150.0, 553.33, 138.33, 6.0], [$c['basic_pay'], $c['absence_deduction'], $c['undertime_deduction'], $c['undertime_hours']]);
    eq(3458.34, r2($c['basic_pay'] - $c['absence_deduction'] - $c['undertime_deduction']), 'each line rounded to the centavo (template: 3,458.33 unrounded)');
    // late but stayed 12 hours: late minutes, no undertime; one hour over: OT; out after midnight
    $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 7800, 'daily_rate' => 0], $from, '2026-09-13',
        att_days($from, ['10:30-22:30', '10:00-23:00', '10:00-00:30']));
    eq([30, 0.0, 3.5], [$c['late_minutes'], $c['undertime_hours'], $c['ot_hours']], 'OT 1 + 2.5 hours');
    eq(r2(3.5 * 7800 / 30 / 12), $c['ot_pay']);
    // daily-paid: days present x daily rate, no absence deduction
    $c = Payroll::compute(['pay_type' => 'daily', 'monthly_rate' => 0, 'daily_rate' => 500], $from, '2026-09-25', att_days($from, array_merge($full, ['', ''])));
    eq([6500.0, 0.0], [$c['basic_pay'], $c['absence_deduction']]);
    // absences never take more than the basic pay
    $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 9000, 'daily_rate' => 0], '2026-08-26', '2026-09-10', []);
    eq([16, 4500.0, 4500.0], [$c['days_absent'], $c['basic_pay'], $c['absence_deduction']]);
});

test('settings: paid day-offs, OT minimum, undertime grace, overtime rate; day off / leave / manual present', function () {
    App\Services\Settings::set('payroll.paid_rest_days', '1');
    App\Services\Settings::set('payroll.ot_min_minutes', '30');
    App\Services\Settings::set('payroll.grace_minutes', '10');
    App\Services\Settings::set('payroll.ot_rate', '1.25');
    $from = '2026-09-11';
    $days = att_days($from, array_merge(['10:00-22:05', '10:05-22:00', '10:00-23:00'], array_fill(0, 10, '10:00-22:00'), ['', '']));
    $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 9600, 'daily_rate' => 0], $from, '2026-09-25', $days);
    eq([0, 2], [$c['days_absent'], $c['days_rest']], '1 day-off a week: 2 in 15 days are not absences');
    eq([0.0, 1.0], [$c['undertime_hours'], $c['ot_hours']], '5 minutes OT and 5 minutes undertime ignored');
    eq(r2(1 * 9600 / 30 / 12 * 1.25), $c['ot_pay']);
    $days['2026-09-24'] = ['time_in' => null, 'time_out' => null, 'day_type' => 'leave', 'note' => null];
    $days['2026-09-25'] = ['time_in' => null, 'time_out' => null, 'day_type' => 'present', 'note' => 'forgot to punch'];
    $c = Payroll::compute(['pay_type' => 'daily', 'monthly_rate' => 0, 'daily_rate' => 500], $from, '2026-09-25', $days);
    eq([14, 1, 7500.0], [$c['days_present'], $c['days_leave'], $c['basic_pay']], 'leave paid, manual present counts');
    foreach (['paid_rest_days' => '0', 'ot_min_minutes' => '0', 'grace_minutes' => '0', 'ot_rate' => '1'] as $k => $v) App\Services\Settings::set('payroll.' . $k, $v);
});

test('cut-offs: 26-10 paid on the 15th, 11-25 paid on the 30th (28th in February)', function () {
    eq([['from' => '2026-09-26', 'to' => '2026-10-10', 'pay_date' => '2026-10-15'], ['from' => '2026-10-11', 'to' => '2026-10-25', 'pay_date' => '2026-10-30']],
        Payroll::cutoffs('2026-10-01'));
    eq('2027-02-28', Payroll::cutoffs('2027-02-01')[1]['pay_date']);
});

test('import: match device users (by name, new, skip), remembered next time; days fixed by hand survive a re-import', function () use ($fixture) {
    $ben = Employees::save(['full_name' => 'Benjamin Cruz', 'position' => 'Cook', 'pay_type' => 'monthly', 'monthly_rate' => 9600, 'sss' => 300]);
    $p = Attendance::parse($fixture, 'report.xls');
    $prev = array_column(Attendance::preview($p), null, 'device_id');
    eq($ben, $prev['7']['employee_id'], 'Ben -> Benjamin Cruz by name');
    eq('name', $prev['7']['matched_by']);
    eq(null, $prev['5']['employee_id']);
    $r = Attendance::save($p, ['5' => 'new', '7' => (string) $ben, '9' => 'skip', '12' => 'new'], 'report.xls');
    eq(3, $r['employees']);
    eq('7', DB::value('SELECT biometric_id FROM employees WHERE id = ?', [$ben]));
    $ana = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Ana'");
    ok($ana > 0, 'Ana created');
    eq(11, (int) DB::value('SELECT COUNT(*) FROM attendance WHERE employee_id = ?', [$ana]));
    eq(['09:56:00', '22:32:00'], array_values(DB::one("SELECT time_in, time_out FROM attendance WHERE employee_id = ? AND work_date = '2026-09-26'", [$ana])));
    // fix Ana's single punch on Oct 4 by hand, then upload the same file again
    Attendance::setDay($ana, '2026-10-04', ['time_in' => '10:16', 'time_out' => '22:00', 'day_type' => 'auto', 'note' => 'forgot to punch out']);
    $prev = array_column(Attendance::preview($p), null, 'device_id');
    eq([$ana, 'device ID'], [$prev['5']['employee_id'], $prev['5']['matched_by']]);
    Attendance::save($p, ['5' => (string) $ana, '7' => (string) $ben], 'report.xls');
    eq('22:00:00', DB::value("SELECT time_out FROM attendance WHERE employee_id = ? AND work_date = '2026-10-04'", [$ana]));
    throws(fn () => Attendance::setDay($ana, '2026-10-05', ['time_in' => '25:00']), 'Enter times like');
});

test('payroll run: computed from attendance, standing deductions + cash advance, bonus, approve -> books, pay out, undo, void', function () {
    $ben = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Benjamin Cruz'");
    $ca = CashAdvances::request(['employee_id' => $ben, 'amount' => 1500, 'reason' => 'Rent']);
    CashAdvances::approve($ca, ['release_method' => 'cash']);
    DB::run('UPDATE employees SET active = 0 WHERE full_name IN (\'Ana\', \'Dino\')');   // no rates: they are not paid anyway
    $run = Payroll::create('2026-09-26', '2026-10-10', '2026-10-15');
    throws(fn () => Payroll::create('2026-10-01', '2026-10-12', '2026-10-15'), 'already covers');
    $slips = Payroll::slips($run);
    eq(1, count(array_filter($slips, fn ($s) => (int) $s['employee_id'] === $ben)));
    $s = Payroll::slip((int) array_values(array_filter($slips, fn ($s) => (int) $s['employee_id'] === $ben))[0]['id']);
    // Ben: 13 full days of 15 -> 2 absent at 320 = 640 off the 4,800 half-month
    eq([13.0, 2.0, 4800.0, 640.0], [(float) $s['days_present'], (float) $s['days_absent'], (float) $s['basic_pay'], (float) $s['absence_deduction']]);
    eq(['sss' => 300.0, 'cash_advance' => 1500.0], array_column(array_map(fn ($l) => ['t' => $l['type'], 'a' => (float) $l['amount']], $s['lines']), 'a', 't'));
    Payroll::addLine((int) $s['id'], ['kind' => 'bonus', 'type' => 'incentive', 'amount' => 260, 'description' => 'Perfect attendance']);
    Payroll::addLine((int) $s['id'], ['kind' => 'deduction', 'type' => 'other', 'amount' => 100, 'description' => 'Broken plates']);
    $caLine = array_values(array_filter($s['lines'], fn ($l) => $l['type'] === 'cash_advance'))[0];
    throws(fn () => Payroll::updateLine((int) $caLine['id'], 2000), 'More than the cash advance balance');
    Payroll::updateLine((int) $caLine['id'], 1000);
    $s = Payroll::slip((int) $s['id']);
    eq([4420.0, 1400.0, 3020.0], [(float) $s['gross_pay'], (float) $s['deduction_total'], (float) $s['net_pay']], '4800-640+260 ; 300+1000+100');

    $salaries = acct_balance('salaries');
    $cash = acct_balance('cash_on_hand');
    $adv = acct_balance('emp_advances');
    Payroll::post($run);
    $r = Payroll::find($run);
    eq('posted', $r['status']);
    ok($r['journal_entry_id']);
    // booked on the cut-off's last day (not the future pay-out date), so it shows in the journal list up to today
    $jeDate = DB::value('SELECT entry_date FROM journal_entries WHERE id = ?', [$r['journal_entry_id']]);
    eq(min('2026-10-10', today()), $jeDate);
    ok(in_array((int) $r['journal_entry_id'], array_map('intval', array_column(App\Services\Finance\Journals::list('2026-09-01', today(), 'payroll'), 'id')), true), 'in the journal list');
    // upgrade step 10 re-dates payrolls approved before this fix (booked on the pay-out date)
    DB::run("UPDATE journal_entries SET entry_date = '2099-01-15' WHERE id = ?", [$r['journal_entry_id']]);
    DB::run("UPDATE settings SET value = '9' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    eq($jeDate, DB::value('SELECT entry_date FROM journal_entries WHERE id = ?', [$r['journal_entry_id']]));
    eq(r2($salaries + 4420 - 100), acct_balance('salaries'), 'gross to salaries; other deductions reduce it');
    eq(-3020.0, acct_balance('salaries_payable'));
    eq(-300.0, acct_balance('statutory_payable'));
    eq(r2($adv - 1000), acct_balance('emp_advances'));
    eq(500.0, (float) DB::value('SELECT balance FROM cash_advances WHERE id = ?', [$ca]));
    $rep = DB::one("SELECT * FROM ca_repayments WHERE advance_id = ? AND method = 'payroll'", [$ca]);
    eq(1000.0, (float) $rep['amount']);
    throws(fn () => CashAdvances::voidRepayment((int) $rep['id'], 'x'), 'Void that payroll instead');
    throws(fn () => Payroll::addLine((int) $s['id'], ['kind' => 'bonus', 'amount' => 10]), 'cannot be changed');
    assert_books_balance();

    Payroll::payout($run, [], 'cash', null, '2026-10-15');
    eq('paid', Payroll::find($run)['status']);
    eq(0.0, acct_balance('salaries_payable'));
    eq(r2($cash - 3020), acct_balance('cash_on_hand'));
    throws(fn () => Payroll::payout($run, [], 'cash'), 'Nothing left to pay');
    throws(fn () => Payroll::void($run, 'x'), 'Undo the payouts first');
    eq(1, Payroll::undoPayout((int) $s['id'], 'wrong day'));
    eq('posted', Payroll::find($run)['status']);
    eq(-3020.0, acct_balance('salaries_payable'));
    Payroll::void($run, 'redo');
    eq('void', Payroll::find($run)['status']);
    eq(1500.0, (float) DB::value('SELECT balance FROM cash_advances WHERE id = ?', [$ca]), 'cash advance deduction undone');
    eq('void', DB::value('SELECT status FROM ca_repayments WHERE id = ?', [$rep['id']]));
    eq(0.0, acct_balance('salaries_payable'));
    eq(r2($adv), acct_balance('emp_advances'));
    assert_books_balance();
    $again = Payroll::create('2026-09-26', '2026-10-10', '2026-10-15');
    ok($again > $run, 'a voided cut-off can be done again');
    Payroll::delete($again);
});

test('payout from the bank shows in the bank register; from petty cash while the fund is at the POS it is a POS payout', function () {
    $bank = App\Services\Finance\Banks::create(['bank_name' => 'BDO', 'account_no' => '9999', 'opening_balance' => 20000]);
    $dan = Employees::save(['full_name' => 'Dan Daily', 'pay_type' => 'daily', 'daily_rate' => 500]);
    foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $d) Attendance::setDay($dan, $d, ['time_in' => '10:00', 'time_out' => '22:00']);
    $run = Payroll::create('2026-10-11', '2026-10-25', '2026-10-30');
    // Ben has no attendance in this cut-off: earns 0, so his SSS and cash advance are not deducted (net never below zero)
    $benSlip = Payroll::slip((int) DB::value("SELECT s.id FROM payslips s JOIN employees e ON e.id = s.employee_id WHERE s.run_id = ? AND e.full_name = 'Benjamin Cruz'", [$run]));
    eq([0.0, 0.0, []], [(float) $benSlip['gross_pay'], (float) $benSlip['net_pay'], $benSlip['lines']]);
    $slips = array_values(array_filter(Payroll::slips($run), fn ($s) => (float) $s['net_pay'] > 0));
    eq(['Dan Daily', 1500.0], [$slips[0]['full_name'], (float) $slips[0]['net_pay']], '3 days x 500');
    Payroll::post($run);
    $id = (int) $slips[0]['id'];
    Payroll::payout($run, [$id], 'bank', $bank, '2026-10-30', 'TRX-1');
    $bt = DB::one("SELECT * FROM bank_txns WHERE bank_account_id = ? AND txn_type = 'withdrawal' ORDER BY id DESC LIMIT 1", [$bank]);
    eq((float) $slips[0]['net_pay'], (float) $bt['amount']);
    eq('TRX-1', $bt['reference']);
    Payroll::undoPayout($id, 'x');
    eq('void', DB::value('SELECT status FROM bank_txns WHERE id = ?', [$bt['id']]));
    // petty cash fund in the POS drawer today: the salary is a payout of the business day
    App\Services\Finance\PettyCash::record(['txn_type' => 'replenish', 'amount' => 5000, 'from_method' => 'cash']);
    if (App\Services\Pos\CashSessions::current()) { DB::run("UPDATE tickets SET status = 'void' WHERE status = 'open'"); App\Services\Pos\CashSessions::close([], 0); }
    $s = App\Services\Pos\CashSessions::open(3000, null, null, null, 'petty_cash');
    Payroll::payout($run, [$id], 'petty_cash');
    $x = App\Services\Pos\CashSessions::report((int) $s['id']);
    eq(1500.0, $x['cash']['payouts']);
    eq(1500.0, $x['cash']['fund_return'], '3,000 beginning cash - 1,500 salary');
    eq('payroll', DB::value("SELECT ref_type FROM petty_cash_txns WHERE cash_session_id = ? AND source = 'drawer'", [$s['id']]));
    Payroll::undoPayout($id, 'x');
    eq(0.0, App\Services\Pos\CashSessions::report((int) $s['id'])['cash']['payouts'], 'undo removes the POS payout');
    App\Services\Pos\CashSessions::close([], 3000);
    assert_books_balance();
});

test('employees see only their own approved payslips', function () {
    $ben = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Benjamin Cruz'");
    $role = (int) DB::value("SELECT id FROM roles WHERE name = 'Waiter'");
    $uid = App\Services\Admin\Users::create(['username' => 'ben', 'full_name' => 'Benjamin Cruz', 'password' => 'secret123', 'role_id' => $role, 'employee_id' => $ben]);
    $slip = DB::one("SELECT s.id FROM payslips s JOIN payroll_runs r ON r.id = s.run_id WHERE s.employee_id = ? AND r.status = 'posted'", [$ben]);
    $draftRun = Payroll::create('2026-10-26', '2026-11-10', '2026-11-15');
    Auth::actAs($uid);
    ok(Auth::can('payroll.self'), 'waiters may see their own payslips');
    ok(!Auth::can('payroll.view'));
    $mine = Payroll::mine();
    eq([(int) $slip['id']], array_map(fn ($r) => (int) $r['id'], $mine), 'only the approved one, not the draft');
    ok(Payroll::canSee(Payroll::slip((int) $slip['id'])));
    $draftSlip = (int) DB::value('SELECT id FROM payslips WHERE run_id = ? AND employee_id = ?', [$draftRun, $ben]);
    ok(!Payroll::canSee(Payroll::slip($draftSlip)), 'draft not visible');
    $other = (int) DB::value('SELECT id FROM payslips WHERE employee_id <> ? LIMIT 1', [$ben]);
    if ($other) ok(!Payroll::canSee(Payroll::slip($other)), "someone else's payslip not visible");
    as_user('admin');
});

test('upgrade step 8 is re-runnable and gives existing roles payslip access', function () {
    DB::run("UPDATE settings SET value = '7' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    App\Services\Migrations::run();
    $p = json_decode(DB::value("SELECT permissions FROM roles WHERE name = 'Cashier'"), true);
    ok(in_array('payroll.self', $p, true));
    eq(1, count(array_keys($p, 'payroll.self')), 'not added twice');
});

test('import: one device user per employee; a device user without punches never wipes days; suggestions are one-to-one', function () {
    $eva = Employees::save(['full_name' => 'Eva Mallo', 'biometric_id' => '027', 'pay_type' => 'monthly', 'monthly_rate' => 15000]);
    eq('27', DB::value('SELECT biometric_id FROM employees WHERE id = ?', [$eva]), 'leading zeros dropped');
    $chan = Employees::save(['full_name' => 'Chan Lee', 'pay_type' => 'monthly', 'monthly_rate' => 9000]);
    $day = ['2026-10-11' => ['10:00', '22:00'], '2026-10-12' => ['10:05', '22:01']];
    // like the restaurant's device: "Eve" #1 (registered twice, no punches) and "Eva" #7; two users named Chan
    $p = ['format' => 'test', 'from' => '2026-10-11', 'to' => '2026-10-12', 'people' => [
        '23' => ['name' => 'Chan', 'days' => $day], '21' => ['name' => 'Eve', 'days' => []], '27' => ['name' => 'Eva', 'days' => $day], '29' => ['name' => 'Chan', 'days' => $day]]];
    $prev = array_column(Attendance::preview($p), null, 'device_id');
    eq([$eva, 'device ID'], [$prev['27']['employee_id'], $prev['27']['matched_by']]);
    eq(null, $prev['21']['employee_id'], 'Eve is not matched to Eva Mallo');
    eq($chan, $prev['23']['employee_id']);
    eq(null, $prev['29']['employee_id'], 'the second Chan is not suggested the same employee');
    throws(fn () => Attendance::save($p, ['21' => (string) $eva, '27' => (string) $eva], 'x.xls'), 'more than one device user');
    eq('27', DB::value('SELECT biometric_id FROM employees WHERE id = ?', [$eva]), 'nothing changed');
    $r = Attendance::save($p, ['21' => 'skip', '27' => (string) $eva, '23' => (string) $chan, '29' => 'skip'], 'x.xls');
    eq(2, $r['employees']);
    eq([$eva, '27', 2], [$r['linked'][1]['employee_id'], $r['linked'][1]['device_id'], $r['linked'][1]['days']]);
    eq(2, (int) DB::value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND time_in IS NOT NULL', [$eva]));
    // choosing the empty device user by mistake moves the link but keeps the punches already imported
    Attendance::save($p, ['21' => (string) $eva], 'x.xls');
    eq('21', DB::value('SELECT biometric_id FROM employees WHERE id = ?', [$eva]));
    eq(2, (int) DB::value('SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND time_in IS NOT NULL', [$eva]));
    Attendance::save($p, ['27' => (string) $eva, '21' => 'skip'], 'x.xls');
    eq('27', DB::value('SELECT biometric_id FROM employees WHERE id = ?', [$eva]));
});

test('cut-off schedule is configurable: any end / pay-out days, 1 to 4 a month, monthly basic = monthly / cut-offs', function () {
    $mk = fn ($json) => Payroll::cutoffs('2026-02-01', Payroll::cutoffDefs($json));
    eq([['from' => '2026-02-01', 'to' => '2026-02-15', 'pay_date' => '2026-02-20'], ['from' => '2026-02-16', 'to' => '2026-02-28', 'pay_date' => '2026-03-05']],
        $mk('[{"end":31,"pay":5},{"end":15,"pay":20}]'), '1-15 paid 20th, 16-end paid 5th of next month');
    eq([['from' => '2026-02-01', 'to' => '2026-02-28', 'pay_date' => '2026-02-28']], $mk('[{"end":31,"pay":31}]'), 'monthly');
    eq('2026-01-26', $mk('[{"end":10,"pay":15},{"end":25,"pay":30}]')[0]['from'], 'default 26-10');
    eq(['1 – 15, paid on the 20th', '16 – month end, paid on the 5th of the next month'], Payroll::cutoffLabels(Payroll::cutoffDefs('[{"end":15,"pay":20},{"end":31,"pay":5}]')));

    Payroll::saveSettings(['cut_end' => ['25', '10', ''], 'cut_pay' => ['30', '15', '']]);
    eq([['end' => 10, 'pay' => 15], ['end' => 25, 'pay' => 30]], Payroll::settings()['cutoffs'], 'sorted, blank row ignored');
    throws(fn () => Payroll::saveSettings(['cut_end' => ['10', '10'], 'cut_pay' => ['15', '20']]), 'Two cut-offs end on day 10');
    throws(fn () => Payroll::saveSettings(['cut_end' => ['10'], 'cut_pay' => ['0']]), 'pay-out day');
    throws(fn () => Payroll::saveSettings(['cut_end' => [''], 'cut_pay' => ['']]), 'at least one');

    $e = ['pay_type' => 'monthly', 'monthly_rate' => 15000, 'daily_rate' => 0];
    $cfg = Payroll::settings();
    eq(7500, Payroll::compute($e, '2026-10-11', '2026-10-11', att_days('2026-10-11', ['10:00-22:00']), $cfg)['basic_pay']);
    $cfg['cutoffs'] = Payroll::cutoffDefs('[{"end":31,"pay":5}]');
    eq(15000, Payroll::compute($e, '2026-10-11', '2026-10-11', att_days('2026-10-11', ['10:00-22:00']), $cfg)['basic_pay'], 'one cut-off a month');
    $cfg['cutoffs'] = Payroll::cutoffDefs('[{"end":7,"pay":10},{"end":15,"pay":18},{"end":22,"pay":25},{"end":31,"pay":3}]');
    eq(3750, Payroll::compute($e, '2026-10-11', '2026-10-11', att_days('2026-10-11', ['10:00-22:00']), $cfg)['basic_pay'], 'four a month');
});

test("employee's work schedule: fixed day-offs paid up to their paid day-offs a week, own shift, daily-paid get paid day-offs", function () {
    // Oct 11-25 2026: Sundays 11, 18, 25 (one in each calendar week); work Mon-Sat 10:00-22:00
    $spans = [];
    for ($d = '2026-10-11'; $d <= '2026-10-25'; $d = add_days($d, 1)) $spans[] = date('N', strtotime($d)) === '7' ? '' : '10:00-22:00';
    $att = att_days('2026-10-11', $spans);
    $cfg = Payroll::settings();
    $cfg['paid_rest_days'] = 0;
    $e = ['pay_type' => 'monthly', 'monthly_rate' => 15000, 'daily_rate' => 0, 'work_days' => '123456', 'paid_rest_days' => 1];
    $c = Payroll::compute($e, '2026-10-11', '2026-10-25', $att, $cfg);
    eq([12, 3, 0, 0.0], [$c['days_present'], $c['days_rest'], $c['days_absent'], $c['absence_deduction']], 'every Sunday off is paid');
    eq('Day off', $c['days'][0]['status']);
    $c = Payroll::compute(['paid_rest_days' => 0] + $e, '2026-10-11', '2026-10-25', $att, $cfg);
    eq([3, 1500.0, 'Day off (unpaid)'], [$c['days_absent'], $c['absence_deduction'], $c['days'][0]['status']], 'no paid day-off: Sundays deducted');
    // Saturday + Sunday off (Sat 17 / 24 not worked)
    $att2 = $att;
    unset($att2['2026-10-17'], $att2['2026-10-24']);
    $c = Payroll::compute(['work_days' => '12345'] + $e, '2026-10-11', '2026-10-25', $att2, $cfg);
    eq([3, 2], [$c['days_rest'], $c['days_absent']], 'Sat + Sun off, 1 paid a week: Saturday paid, Sunday unpaid');
    // worked on the day off
    $att3 = $att + att_days('2026-10-18', ['10:00-22:00']);
    $c = Payroll::compute($e, '2026-10-11', '2026-10-25', $att3, $cfg);
    eq([13, 2, 'Present (day off)'], [$c['days_present'], $c['days_rest'], $c['days'][7]['status']]);
    // worked on Sunday 18 but not on Wednesday 14 (same week): Wednesday becomes the paid day off
    $att4 = $att3;
    unset($att4['2026-10-14']);
    $c = Payroll::compute($e, '2026-10-11', '2026-10-25', $att4, $cfg);
    eq([12, 3, 0, 'Day off (moved)'], [$c['days_present'], $c['days_rest'], $c['days_absent'], $c['days'][3]['status']]);
    unset($att4['2026-10-15']);
    eq(1, Payroll::compute($e, '2026-10-11', '2026-10-25', $att4, $cfg)['days_absent'], 'only one day off a week moves');
    // flexible day-off (any day) from the employee's own setting, while the default is 0
    $flex = ['work_days' => null, 'paid_rest_days' => 1] + $e;
    $c = Payroll::compute($flex, '2026-10-11', '2026-10-25', $att, $cfg);
    eq([2, 1], [$c['days_rest'], $c['days_absent']], '1 a week = 2 in 15 days');
    // daily-paid: paid day-offs are paid
    $daily = ['pay_type' => 'daily', 'monthly_rate' => 0, 'daily_rate' => 600, 'work_days' => '123456', 'paid_rest_days' => 1];
    $c = Payroll::compute($daily, '2026-10-11', '2026-10-25', $att, $cfg);
    eq([15.0, 9000.0], [(float) $c['days_paid'], $c['basic_pay']]);
    $c = Payroll::compute(['paid_rest_days' => 0] + $daily, '2026-10-11', '2026-10-25', $att, $cfg);
    eq([12.0, 7200.0], [(float) $c['days_paid'], $c['basic_pay']]);
    // own shift: 09:00, 10 hours
    $own = ['shift_start' => '09:00', 'shift_hours' => 10, 'work_days' => null, 'paid_rest_days' => null] + $e;
    $c = Payroll::compute($own, '2026-10-12', '2026-10-12', att_days('2026-10-12', ['09:30-19:30']), $cfg);
    eq([30, 0.0, 0.0, 50.0], [$c['late_minutes'], $c['undertime_hours'], $c['ot_hours'], $c['hourly_rate']]);
});

test('employee schedule fields are saved and validated; upgrade step 9 adds them', function () {
    $id = Employees::save(['full_name' => 'Sched Test', 'work_days' => '6543 21', 'work_days_set' => '1', 'paid_rest_days' => '1', 'shift_start' => '9:00', 'shift_hours' => '10']);
    $row = DB::one('SELECT work_days, paid_rest_days, shift_start, shift_hours FROM employees WHERE id = ?', [$id]);
    eq(['123456', 1, '09:00', 10.0], [$row['work_days'], (int) $row['paid_rest_days'], $row['shift_start'], (float) $row['shift_hours']]);
    ok(str_contains(Payroll::scheduleLabel(DB::one('SELECT * FROM employees WHERE id = ?', [$id])), 'Sun off'));
    Employees::save(['id' => $id, 'full_name' => 'Sched Test', 'active' => 1, 'work_days' => '1234567', 'paid_rest_days' => '', 'shift_start' => '', 'shift_hours' => '']);
    eq([null, null, null, null], array_values(DB::one('SELECT work_days, paid_rest_days, shift_start, shift_hours FROM employees WHERE id = ?', [$id])), 'blank = defaults');
    throws(fn () => Employees::save(['id' => $id, 'full_name' => 'Sched Test', 'work_days' => '', 'work_days_set' => '1']), 'at least one working day');
    throws(fn () => Employees::save(['id' => $id, 'full_name' => 'Sched Test', 'paid_rest_days' => '9']), '0 to 7');
    throws(fn () => Employees::save(['id' => $id, 'full_name' => 'Sched Test', 'shift_start' => '25:00']), 'shift start');
    DB::run("UPDATE settings SET value = '8' WHERE `key` = 'db_version'");
    App\Services\Migrations::run();
    App\Services\Migrations::run();
    ok((int) DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payslips' AND column_name = 'days_paid'") === 1);
});
