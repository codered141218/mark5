<?php
/** Payroll: ZKTeco attendance import, hours / overtime, payroll run with cash advance deduction, posting. */
use App\Core\DB;
use App\Services\Finance\CashAdvances;
use App\Services\Finance\Employees;
use App\Services\Finance\Payroll;
use App\Services\Ledger;

$pr = new stdClass();

test('employees carry a daily rate and a biometric id', function () use ($pr) {
    $pr->juan = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Juan Dela Cruz'");
    $pr->maria = (int) DB::value("SELECT id FROM employees WHERE full_name = 'Maria Santos'");
    Employees::save(['id' => $pr->juan, 'full_name' => 'Juan Dela Cruz', 'daily_rate' => '610', 'biometric_id' => '7', 'active' => 1]);
    Employees::save(['id' => $pr->maria, 'full_name' => 'Maria Santos', 'daily_rate' => '570', 'biometric_id' => '12', 'active' => 1]);
    throws(fn () => Employees::save(['full_name' => 'Dup', 'biometric_id' => '7']), 'already has that biometric');
    eq(610, DB::value('SELECT daily_rate FROM employees WHERE id = ?', [$pr->juan]));
});

test('import a ZKTeco attlog.dat and a ZKTime CSV export; duplicates are skipped', function () use ($pr) {
    $dat = implode("\n", [
        "7\t2026-10-01 07:58:12\t1\t0\t0",
        "7\t2026-10-01 18:31:40\t1\t1\t0",      // 10.56 h span - 1 h break = 9.56 h -> 1.56 h OT
        "12\t2026-10-01 09:02:00\t1\t0\t0",
        "12\t2026-10-01 17:00:00\t1\t1\t0",     // 7.97 h span - 1 h = 6.97 h
        "7\t2026-10-02 08:00:00\t1\t0\t0",
        "7\t2026-10-02 17:00:00\t1\t1\t0",      // 8 h exactly
        "12\t2026-10-02 09:00:00\t1\t0\t0",     // single punch: day counted, 0 h
        "99\t2026-10-02 09:00:00\t1\t0\t0",     // unknown device id
    ]);
    $r = Payroll::importAttendance($dat, 'K40');
    eq(7, $r['imported']);
    eq(['99'], $r['unknown']);
    eq('2026-10-01', $r['from']);
    $r = Payroll::importAttendance($dat);
    eq(0, $r['imported']);
    eq(7, $r['duplicates']);
    // CSV export with a header (ZKTime style) and US dates
    $csv = "AC-No.,Name,Time,State\n0007,Juan,10/03/2026 08:05:00,C/In\n0007,Juan,10/03/2026 12:30:00,C/Out\n0012,Maria,10/03/2026 07:55:00,C/In\n0012,Maria,10/03/2026 19:10:00,C/Out\n";
    $r = Payroll::importAttendance($csv);
    eq(4, $r['imported']);
    eq([], $r['unknown']);
    $att = Payroll::attendance('2026-10-01', '2026-10-03');
    eq(9.56, $att[$pr->juan]['2026-10-01']['hours']);
    eq(1.56, $att[$pr->juan]['2026-10-01']['ot']);
    eq(8, $att[$pr->juan]['2026-10-02']['hours']);
    eq(0, $att[$pr->juan]['2026-10-02']['ot']);
    eq(4.42, $att[$pr->juan]['2026-10-03']['hours'], 'short day: no break deducted');
    eq(0, $att[$pr->maria]['2026-10-02']['hours']);
    eq(1, $att[$pr->maria]['2026-10-02']['punches']);
    eq(10.25, $att[$pr->maria]['2026-10-03']['hours']);
    throws(fn () => Payroll::importAttendance("hello\nworld"), 'cannot find the user ID');
});

test('payroll run: days x rate + overtime, cash advance deducted per the terms, posted to the books', function () use ($pr) {
    // ₱3,000 advance to Juan, ₱500 per payroll
    $ca = CashAdvances::request(['employee_id' => $pr->juan, 'amount' => 3000, 'reason' => 'Tuition', 'repayment_terms' => '₱500 per payroll']);
    CashAdvances::approve($ca, ['release_method' => 'cash', 'release_date' => '2026-09-25']);
    $cashBefore = Ledger::balance(Ledger::account('cash_on_hand'));
    $id = Payroll::createRun(['period_from' => '2026-10-01', 'period_to' => '2026-10-15', 'pay_date' => '2026-10-15', 'method' => 'cash']);
    $run = Payroll::find($id);
    eq('draft', $run['status']);
    eq(2, count($run['lines']));
    $juan = array_values(array_filter($run['lines'], fn ($l) => (int) $l['employee_id'] === $pr->juan))[0];
    $maria = array_values(array_filter($run['lines'], fn ($l) => (int) $l['employee_id'] === $pr->maria))[0];
    eq(3, $juan['days_worked']);
    eq(1830, $juan['basic_pay']);
    eq(1.56, $juan['ot_hours']);
    eq(r2(1.56 * 610 / 8 * 1.25), $juan['ot_pay']);
    eq(r2(1830 + 1.56 * 610 / 8 * 1.25), $juan['gross_pay']);
    eq(500, $juan['ca_deduction'], 'from the repayment terms');
    eq(3, $maria['days_worked']);
    eq(2.25, $maria['ot_hours']);
    eq(0, $maria['ca_deduction']);
    eq(r2($juan['gross_pay'] + $maria['gross_pay']), $run['total_gross']);

    // government deductions are edited on the draft
    throws(fn () => Payroll::saveLines($id, [$juan['id'] => ['ca_deduction' => 5000]]), 'exceeds the balance');
    Payroll::saveLines($id, [$juan['id'] => ['other_deductions' => 150], $maria['id'] => ['other_deductions' => 120]]);
    $run = Payroll::find($id);
    eq(270, $run['total_other']);
    eq(r2($run['total_gross'] - 500 - 270), $run['total_net']);
    $draft2 = Payroll::createRun(['period_from' => '2026-10-10', 'period_to' => '2026-10-20']);   // overlapping drafts are allowed
    Payroll::delete($draft2);
    eq(0, DB::value('SELECT COUNT(*) FROM payroll_lines WHERE run_id = ?', [$draft2]));

    Payroll::post($id);
    $run = Payroll::find($id);
    eq('posted', $run['status']);
    ok($run['journal_entry_id'] > 0);
    eq(2500, DB::value('SELECT balance FROM cash_advances WHERE id = ?', [$ca]), 'advance reduced by the deduction');
    eq(r2($cashBefore - $run['total_net']), Ledger::balance(Ledger::account('cash_on_hand')), 'net pay leaves the cash');
    eq($run['total_gross'], Ledger::balance(Ledger::account('salaries')), 'gross salaries expensed');
    eq(0, Ledger::balance(Ledger::account('salaries_payable')), 'salaries payable cleared: CA repayment offsets the credit');
    eq(-270, Ledger::balance((int) DB::value("SELECT id FROM accounts WHERE code = '2210'")), 'government deductions withheld');
    throws(fn () => Payroll::post($id), 'already posted');
    throws(fn () => Payroll::createRun(['period_from' => '2026-10-10', 'period_to' => '2026-10-20']), 'already covers');
    assert_books_balance();
});

test('employee self-service: own payslips and cash advance balance', function () use ($pr) {
    $slips = Payroll::payslipsFor($pr->juan);
    eq(1, count($slips));
    eq('posted', DB::value('SELECT status FROM payroll_runs WHERE id = ?', [$slips[0]['run_id']]));
    eq(500, $slips[0]['ca_deduction']);
    $one = Payroll::payslip((int) $slips[0]['id']);
    eq('Juan Dela Cruz', $one['employee_name']);
    eq($slips[0]['net_pay'], $one['net_pay']);
    ok(Payroll::payslip(999999) === null, 'unknown payslip');
    // a draft run's lines are not payslips yet
    $draft = Payroll::createRun(['period_from' => '2026-10-16', 'period_to' => '2026-10-31']);
    eq(1, count(Payroll::payslipsFor($pr->juan)));
    Payroll::delete($draft);
});
