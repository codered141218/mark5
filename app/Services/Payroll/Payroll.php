<?php
namespace App\Services\Payroll;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Finance\PettyCash;
use App\Services\Ledger;
use App\Services\Sequence;
use App\Services\Settings;

/**
 * Payroll per cut-off (default 26–10 paid on the 15th, 11–25 paid on the 30th), computed like the restaurant's template:
 *   daily rate = monthly rate / 30, hourly rate = daily rate / 12 (regular hours a day)
 *   monthly-paid: basic = monthly / 2, minus absent days x daily rate      daily-paid: basic = days present x daily rate
 *   undertime (hours short of 12) x hourly rate is deducted; overtime (hours over 12) x hourly x OT rate is added
 *   gross = basic − absences − undertime + overtime + bonuses; net = gross − deductions
 * Deductions: cash advances (linked to Cash Advances), SSS / PhilHealth / Pag-IBIG / tax (amounts from the employee setup)
 * and any other deduction added by hand. Posting books the payroll; paying out moves the cash.
 *
 *   Post:    Dr Salaries & wages (gross)  / Cr Salaries payable (net), Employee advances, SSS/PhilHealth/Pag-IBIG payable,
 *                                             Withholding tax payable, Other payroll deductions
 *   Pay out: Dr Salaries payable / Cr Cash on hand, petty cash or bank
 */
class Payroll
{
    public const DEDUCTION_TYPES = ['cash_advance' => 'Cash advance', 'sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG',
        'tax' => 'Withholding tax', 'other' => 'Other deduction'];
    public const BONUS_TYPES = ['bonus' => 'Bonus', 'incentive' => 'Incentive', 'allowance' => 'Allowance', 'thirteenth' => '13th month pay',
        'adjustment' => 'Pay adjustment'];
    public const PAY_METHODS = ['cash' => 'Cash on hand', 'petty_cash' => 'Petty cash', 'bank' => 'Bank / e-wallet transfer'];
    public const STATUSES = ['draft' => 'Draft', 'posted' => 'Approved', 'paid' => 'Paid', 'void' => 'Void'];

    /** Payroll settings, typed. */
    public static function settings(): array
    {
        $g = fn ($k) => Settings::get('payroll.' . $k, Settings::DEFAULTS['payroll.' . $k]);
        return [
            'shift_start' => substr($g('shift_start'), 0, 5), 'shift_hours' => max(1.0, (float) $g('shift_hours')),
            'days_per_month' => max(1.0, (float) $g('days_per_month')), 'ot_rate' => max(0.0, (float) $g('ot_rate')),
            'ot_min_minutes' => max(0, (int) $g('ot_min_minutes')), 'grace_minutes' => max(0, (int) $g('grace_minutes')),
            'paid_rest_days' => max(0, min(7, (int) $g('paid_rest_days'))), 'day_starts' => substr($g('day_starts'), 0, 5),
        ];
    }

    public static function saveSettings(array $in): void
    {
        Auth::require('payroll.manage');
        $time = fn ($v, $def) => preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $v), $m) && (int) $m[1] < 24 && (int) $m[2] < 60 ? sprintf('%02d:%02d', $m[1], $m[2]) : $def;
        $s = self::settings();
        $vals = [
            'shift_start' => $time($in['shift_start'] ?? '', $s['shift_start']),
            'shift_hours' => (string) max(1, min(24, (float) ($in['shift_hours'] ?? $s['shift_hours']))),
            'days_per_month' => (string) max(1, min(31, (float) ($in['days_per_month'] ?? $s['days_per_month']))),
            'ot_rate' => (string) max(0, min(5, (float) ($in['ot_rate'] ?? $s['ot_rate']))),
            'ot_min_minutes' => (string) max(0, min(600, (int) ($in['ot_min_minutes'] ?? $s['ot_min_minutes']))),
            'grace_minutes' => (string) max(0, min(600, (int) ($in['grace_minutes'] ?? $s['grace_minutes']))),
            'paid_rest_days' => (string) max(0, min(7, (int) ($in['paid_rest_days'] ?? $s['paid_rest_days']))),
            'day_starts' => $time($in['day_starts'] ?? '', $s['day_starts']),
        ];
        foreach ($vals as $k => $v) Settings::set('payroll.' . $k, $v);
        Audit::log('update', 'settings', null, ['payroll' => $vals]);
    }

    // ------------------------------------------------------------------ periods
    /** The two cut-offs of a month: 26 (previous month) – 10 paid on the 15th, and 11 – 25 paid on the 30th (or the month's last day). */
    public static function cutoffs(string $month): array
    {
        $first = date('Y-m-01', strtotime($month));
        $prev = date('Y-m-26', strtotime($first . ' -1 month'));
        $last = (int) date('t', strtotime($first));
        return [
            ['from' => $prev, 'to' => date('Y-m-10', strtotime($first)), 'pay_date' => date('Y-m-15', strtotime($first))],
            ['from' => date('Y-m-11', strtotime($first)), 'to' => date('Y-m-25', strtotime($first)), 'pay_date' => date('Y-m-', strtotime($first)) . min(30, $last)],
        ];
    }

    /** Suggested next cut-off: the one after the last payroll, else the latest one that has ended. */
    public static function suggestPeriod(): array
    {
        $last = DB::value("SELECT MAX(period_to) FROM payroll_runs WHERE status <> 'void'");
        $months = [date('Y-m-01', strtotime('-1 month')), date('Y-m-01'), date('Y-m-01', strtotime('+1 month'))];
        if ($last) $months = [date('Y-m-01', strtotime($last)), date('Y-m-01', strtotime($last . ' +1 month')), date('Y-m-01', strtotime($last . ' +2 months'))];
        $all = [];
        foreach ($months as $m) foreach (self::cutoffs($m) as $c) $all[] = $c;
        if ($last) {
            foreach ($all as $c) if ($c['from'] > $last) return $c;
        }
        $ended = array_values(array_filter($all, fn ($c) => $c['to'] <= today()));
        return $ended ? end($ended) : $all[0];
    }

    // ------------------------------------------------------------------ computation
    private static function minutes(?string $t): ?int
    {
        if (!$t) return null;
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h * 60 + $m;
    }

    /** Rates of an employee: [daily, hourly]. */
    public static function rates(array $e, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::settings();
        $daily = $e['pay_type'] === 'daily' && (float) $e['daily_rate'] > 0 ? (float) $e['daily_rate'] : (float) $e['monthly_rate'] / $cfg['days_per_month'];
        return [$daily, $daily / $cfg['shift_hours']];
    }

    /**
     * Attendance and pay of one employee for a period (no bonuses / deductions). Returns the payslip fields + 'days'.
     */
    public static function compute(array $e, string $from, string $to, array $att, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::settings();
        $shiftMin = (int) round($cfg['shift_hours'] * 60);
        $start = self::minutes($cfg['shift_start']);
        $days = [];
        $n = ['present' => 0, 'absent' => 0, 'rest' => 0, 'leave' => 0];
        $mins = ['worked' => 0, 'late' => 0, 'ut' => 0, 'ot' => 0];
        for ($d = $from; $d <= $to; $d = add_days($d, 1)) {
            $r = $att[$d] ?? null;
            $type = $r['day_type'] ?? 'auto';
            $in = self::minutes($r['time_in'] ?? null);
            $out = self::minutes($r['time_out'] ?? null);
            $row = ['date' => $d, 'in' => !empty($r['time_in']) ? substr($r['time_in'], 0, 5) : null, 'out' => !empty($r['time_out']) ? substr($r['time_out'], 0, 5) : null,
                'hours' => 0, 'late' => 0, 'ut' => 0, 'ot' => 0, 'status' => '', 'note' => $r['note'] ?? null, 'type' => $type];
            if ($type === 'rest') {
                $n['rest']++; $row['status'] = 'Day off';
            } elseif ($type === 'leave') {
                $n['leave']++; $row['status'] = 'Paid leave';
            } elseif ($type === 'absent') {
                $n['absent']++; $row['status'] = 'Absent';
            } elseif ($type === 'present' && ($in === null || $out === null)) {
                $n['present']++; $row['status'] = 'Present'; $row['hours'] = $shiftMin; $mins['worked'] += $shiftMin;
            } elseif ($in !== null && $out !== null && $out !== $in) {
                $worked = $out > $in ? $out - $in : $out + 1440 - $in;          // past midnight
                $late = $start !== null && $in > $start ? $in - $start : 0;
                $ut = max(0, $shiftMin - $worked);
                if ($ut <= $cfg['grace_minutes']) $ut = 0;
                $ot = max(0, $worked - $shiftMin);
                if ($ot < $cfg['ot_min_minutes']) $ot = 0;
                $n['present']++;
                $mins['worked'] += $worked; $mins['late'] += $late; $mins['ut'] += $ut; $mins['ot'] += $ot;
                $row = ['hours' => $worked, 'late' => $late, 'ut' => $ut, 'ot' => $ot, 'status' => 'Present'] + $row;
            } else {
                $n['absent']++;
                $row['status'] = $in !== null || $out !== null ? 'Incomplete punch' : 'Absent';
            }
            $days[] = $row;
        }
        // day-offs that are not deducted: e.g. 1 a week -> 2 in a 15-day cut-off (days marked "day off" count first)
        $daysIn = count($days);
        $allowed = intdiv($daysIn * $cfg['paid_rest_days'], 7);
        $convert = min($n['absent'], max(0, $allowed - $n['rest']));
        if ($convert > 0) {
            $n['absent'] -= $convert;
            $n['rest'] += $convert;
            foreach ($days as &$row) {                                              // label the first unworked days as day-offs
                if ($convert > 0 && $row['status'] === 'Absent') { $row['status'] = 'Day off (allowed)'; $convert--; }
            }
            unset($row);
        }

        [$daily, $hourly] = self::rates($e, $cfg);
        if ($e['pay_type'] === 'daily') {
            $basic = r2(($n['present'] + $n['leave']) * $daily);
            $absence = 0.0;
        } else {
            $basic = r2((float) $e['monthly_rate'] / 2);
            $absence = min($basic, r2($n['absent'] * $daily));
        }
        $ut = min(r2($mins['ut'] / 60 * $hourly), max(0, $basic - $absence));
        $ot = r2($mins['ot'] / 60 * $hourly * $cfg['ot_rate']);
        return [
            'pay_type' => $e['pay_type'], 'monthly_rate' => (float) $e['monthly_rate'], 'daily_rate' => round($daily, 4), 'hourly_rate' => round($hourly, 4),
            'days_in_period' => $daysIn, 'days_present' => $n['present'], 'days_absent' => $n['absent'], 'days_rest' => $n['rest'], 'days_leave' => $n['leave'],
            'hours_worked' => round($mins['worked'] / 60, 2), 'late_minutes' => $mins['late'],
            'undertime_hours' => round($mins['ut'] / 60, 2), 'ot_hours' => round($mins['ot'] / 60, 2),
            'basic_pay' => $basic, 'absence_deduction' => $absence, 'undertime_deduction' => $ut, 'ot_pay' => $ot,
            'days' => $days,
        ];
    }

    // ------------------------------------------------------------------ runs
    public static function find(int $id): array
    {
        $r = DB::one('SELECT r.*, u.full_name AS created_by_name, p.full_name AS posted_by_name FROM payroll_runs r
                      LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users p ON p.id = r.posted_by WHERE r.id = ?', [$id]);
        if (!$r) throw HttpException::notFound('Payroll');
        return $r;
    }

    public static function list(): array
    {
        return DB::all("SELECT r.*, (SELECT COUNT(*) FROM payslips s WHERE s.run_id = r.id) employees,
                               (SELECT COUNT(*) FROM payslips s WHERE s.run_id = r.id AND s.paid_at IS NOT NULL) paid
                        FROM payroll_runs r ORDER BY r.period_from DESC, r.id DESC");
    }

    public static function slips(int $runId): array
    {
        return DB::all('SELECT s.*, e.biometric_id FROM payslips s LEFT JOIN employees e ON e.id = s.employee_id WHERE s.run_id = ? ORDER BY s.full_name', [$runId]);
    }

    public static function slip(int $id): array
    {
        $s = DB::one('SELECT s.*, r.run_no, r.period_from, r.period_to, r.pay_date, r.status AS run_status, b.bank_name
                      FROM payslips s JOIN payroll_runs r ON r.id = s.run_id LEFT JOIN bank_accounts b ON b.id = s.bank_account_id WHERE s.id = ?', [$id]);
        if (!$s) throw HttpException::notFound('Payslip');
        $s['lines'] = DB::all('SELECT * FROM payslip_lines WHERE payslip_id = ? ORDER BY kind, id', [$id]);
        $s['days'] = json_decode($s['days_json'] ?? '[]', true) ?: [];
        return $s;
    }

    /** Employees to pay: active, with a rate. */
    private static function payableEmployees(?array $ids = null): array
    {
        $rows = DB::all('SELECT * FROM employees WHERE active = 1 AND (monthly_rate > 0 OR daily_rate > 0) ORDER BY full_name');
        return $ids === null ? $rows : array_values(array_filter($rows, fn ($e) => in_array((int) $e['id'], $ids, true)));
    }

    /** Create a draft payroll for a cut-off, with a payslip for every active employee who has a rate. */
    public static function create(string $from, string $to, string $payDate, ?string $notes = null): int
    {
        Auth::require('payroll.manage');
        if (!is_date($from) || !is_date($to) || $to < $from) throw HttpException::bad('Enter the cut-off period');
        if (!is_date($payDate)) throw HttpException::bad('Enter the pay-out date');
        if ((strtotime($to) - strtotime($from)) / 86400 > 40) throw HttpException::bad('A cut-off can be at most 40 days');
        if ($dup = DB::value("SELECT run_no FROM payroll_runs WHERE status <> 'void' AND period_from <= ? AND period_to >= ?", [$to, $from])) {
            throw HttpException::bad("Payroll $dup already covers part of this period. Void it first or choose another cut-off.");
        }
        $emps = self::payableEmployees();
        if (!$emps) throw HttpException::bad('No employee has a salary rate yet. Set the monthly or daily rate under People → Employees.');
        $id = DB::transaction(function () use ($from, $to, $payDate, $notes, $emps) {
            $id = DB::insert('payroll_runs', ['run_no' => Sequence::next('PAY', 'PR'), 'period_from' => $from, 'period_to' => $to, 'pay_date' => $payDate,
                'status' => 'draft', 'notes' => trim((string) $notes) ?: null, 'created_by' => Auth::id(), 'created_at' => now()]);
            foreach ($emps as $e) self::addSlip($id, $e, $from, $to);
            self::totals($id);
            return $id;
        });
        Audit::log('create', 'payroll', $id, ['from' => $from, 'to' => $to]);
        return $id;
    }

    /** One payslip: computed attendance + the employee's standing deductions + outstanding cash advances. */
    private static function addSlip(int $runId, array $e, string $from, string $to): int
    {
        $att = Attendance::rows($from, $to, (int) $e['id'])[(int) $e['id']] ?? [];
        $c = self::compute($e, $from, $to, $att);
        $days = $c['days'];
        unset($c['days']);
        $slipId = DB::insert('payslips', $c + ['run_id' => $runId, 'employee_id' => (int) $e['id'], 'emp_no' => $e['emp_no'], 'full_name' => $e['full_name'],
            'position' => $e['position'], 'days_json' => json_encode($days)]);
        // automatic deductions never take the net pay below zero (e.g. a cut-off without attendance)
        $room = r2($c['basic_pay'] - $c['absence_deduction'] - $c['undertime_deduction'] + $c['ot_pay']);
        foreach (['sss' => 'SSS contribution', 'philhealth' => 'PhilHealth contribution', 'pagibig' => 'Pag-IBIG contribution', 'wtax' => 'Withholding tax'] as $f => $label) {
            $amt = min(r2($e[$f]), $room);
            if ($amt <= 0) continue;
            DB::insert('payslip_lines', ['payslip_id' => $slipId, 'kind' => 'deduction', 'type' => $f === 'wtax' ? 'tax' : $f,
                'description' => $label, 'amount' => $amt, 'auto' => 1, 'created_by' => Auth::id()]);
            $room = r2($room - $amt);
        }
        $perPay = (float) $e['ca_deduction'];
        foreach (DB::all("SELECT id, doc_no, balance FROM cash_advances WHERE employee_id = ? AND status = 'approved' AND balance > 0 ORDER BY release_date, id", [(int) $e['id']]) as $ca) {
            $amt = $perPay > 0 ? min($perPay, (float) $ca['balance']) : (float) $ca['balance'];
            if ($perPay > 0) $perPay -= $amt;
            $amt = min(r2($amt), $room);
            $room = r2($room - $amt);
            if ($amt <= 0) continue;
            DB::insert('payslip_lines', ['payslip_id' => $slipId, 'kind' => 'deduction', 'type' => 'cash_advance', 'description' => "Cash advance {$ca['doc_no']}",
                'amount' => r2($amt), 'advance_id' => (int) $ca['id'], 'auto' => 1, 'created_by' => Auth::id()]);
            if ((float) $e['ca_deduction'] > 0 && $perPay <= 0) break;
        }
        self::slipTotals($slipId);
        return $slipId;
    }

    private static function draft(int $runId): array
    {
        $r = self::find($runId);
        if ($r['status'] !== 'draft') throw HttpException::bad("Payroll {$r['run_no']} is already " . strtolower(self::STATUSES[$r['status']] ?? $r['status']) . ' and cannot be changed');
        return $r;
    }

    /** Recompute attendance and rates of a draft (after fixing attendance or rates); bonuses and deductions stay. New employees are added. */
    public static function recompute(int $runId): void
    {
        Auth::require('payroll.manage');
        $r = self::draft($runId);
        DB::transaction(function () use ($r) {
            $have = array_map('intval', array_column(DB::all('SELECT employee_id FROM payslips WHERE run_id = ?', [$r['id']]), 'employee_id'));
            foreach (self::payableEmployees() as $e) {
                if (!in_array((int) $e['id'], $have, true)) { self::addSlip((int) $r['id'], $e, $r['period_from'], $r['period_to']); continue; }
                $att = Attendance::rows($r['period_from'], $r['period_to'], (int) $e['id'])[(int) $e['id']] ?? [];
                $c = self::compute($e, $r['period_from'], $r['period_to'], $att);
                $days = $c['days'];
                unset($c['days']);
                $slipId = (int) DB::value('SELECT id FROM payslips WHERE run_id = ? AND employee_id = ?', [$r['id'], $e['id']]);
                DB::update('payslips', $slipId, $c + ['full_name' => $e['full_name'], 'emp_no' => $e['emp_no'], 'position' => $e['position'], 'days_json' => json_encode($days)]);
                self::slipTotals($slipId);
            }
            self::totals((int) $r['id']);
        });
        Audit::log('recompute', 'payroll', $runId);
    }

    /** Remove one employee from a draft payroll. */
    public static function removeSlip(int $slipId): void
    {
        Auth::require('payroll.manage');
        $s = self::slip($slipId);
        self::draft((int) $s['run_id']);
        DB::run('DELETE FROM payslip_lines WHERE payslip_id = ?', [$slipId]);
        DB::run('DELETE FROM payslips WHERE id = ?', [$slipId]);
        self::totals((int) $s['run_id']);
    }

    /** Add a bonus or a deduction to a payslip of a draft payroll. */
    public static function addLine(int $slipId, array $d): int
    {
        Auth::require('payroll.manage');
        $s = self::slip($slipId);
        self::draft((int) $s['run_id']);
        $kind = ($d['kind'] ?? '') === 'bonus' ? 'bonus' : 'deduction';
        $types = $kind === 'bonus' ? self::BONUS_TYPES : self::DEDUCTION_TYPES;
        $type = isset($types[$d['type'] ?? '']) ? $d['type'] : ($kind === 'bonus' ? 'bonus' : 'other');
        $amount = r2(num($d['amount'] ?? 0));
        if (!($amount > 0)) throw HttpException::bad('Enter the amount');
        $advance = null;
        if ($type === 'cash_advance') {
            $advance = DB::one("SELECT id, doc_no, balance FROM cash_advances WHERE id = ? AND employee_id = ? AND status = 'approved'", [(int) ($d['advance_id'] ?? 0), (int) $s['employee_id']]);
            if (!$advance) throw HttpException::bad('Choose the cash advance to deduct');
            $already = (float) DB::value('SELECT COALESCE(SUM(amount),0) FROM payslip_lines WHERE payslip_id = ? AND advance_id = ?', [$slipId, $advance['id']]);
            if ($amount + $already > (float) $advance['balance'] + 0.001) throw HttpException::bad('More than the balance of ' . $advance['doc_no'] . ' (' . number_format((float) $advance['balance'], 2) . ')');
        }
        $desc = trim((string) ($d['description'] ?? '')) ?: ($advance ? "Cash advance {$advance['doc_no']}" : $types[$type]);
        $id = DB::insert('payslip_lines', ['payslip_id' => $slipId, 'kind' => $kind, 'type' => $type, 'description' => mb_substr($desc, 0, 190),
            'amount' => $amount, 'advance_id' => $advance['id'] ?? null, 'auto' => 0, 'created_by' => Auth::id()]);
        self::slipTotals($slipId);
        self::totals((int) $s['run_id']);
        Audit::log('create', 'payslip_line', $id, ['payslip' => $slipId, 'kind' => $kind, 'type' => $type, 'amount' => $amount]);
        return $id;
    }

    public static function updateLine(int $lineId, float $amount): void
    {
        Auth::require('payroll.manage');
        $l = DB::one('SELECT * FROM payslip_lines WHERE id = ?', [$lineId]);
        if (!$l) throw HttpException::notFound('Line');
        $s = self::slip((int) $l['payslip_id']);
        self::draft((int) $s['run_id']);
        $amount = r2($amount);
        if (!($amount > 0)) throw HttpException::bad('Enter the amount (remove the line to drop it)');
        if ($l['advance_id']) {
            $bal = (float) DB::value('SELECT balance FROM cash_advances WHERE id = ?', [$l['advance_id']]);
            if ($amount > $bal + 0.001) throw HttpException::bad('More than the cash advance balance of ' . number_format($bal, 2));
        }
        DB::update('payslip_lines', $lineId, ['amount' => $amount]);
        self::slipTotals((int) $l['payslip_id']);
        self::totals((int) $s['run_id']);
    }

    public static function removeLine(int $lineId): void
    {
        Auth::require('payroll.manage');
        $l = DB::one('SELECT * FROM payslip_lines WHERE id = ?', [$lineId]);
        if (!$l) throw HttpException::notFound('Line');
        $s = self::slip((int) $l['payslip_id']);
        self::draft((int) $s['run_id']);
        DB::run('DELETE FROM payslip_lines WHERE id = ?', [$lineId]);
        self::slipTotals((int) $l['payslip_id']);
        self::totals((int) $s['run_id']);
    }

    private static function slipTotals(int $slipId): void
    {
        $s = DB::one('SELECT * FROM payslips WHERE id = ?', [$slipId]);
        $bonus = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM payslip_lines WHERE payslip_id = ? AND kind = 'bonus'", [$slipId]);
        $ded = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM payslip_lines WHERE payslip_id = ? AND kind = 'deduction'", [$slipId]);
        $gross = r2((float) $s['basic_pay'] - (float) $s['absence_deduction'] - (float) $s['undertime_deduction'] + (float) $s['ot_pay'] + $bonus);
        DB::update('payslips', $slipId, ['bonus_total' => r2($bonus), 'gross_pay' => $gross, 'deduction_total' => r2($ded), 'net_pay' => r2($gross - $ded)]);
    }

    private static function totals(int $runId): void
    {
        $t = DB::one('SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(deduction_total),0) d, COALESCE(SUM(net_pay),0) n FROM payslips WHERE run_id = ?', [$runId]);
        DB::update('payroll_runs', $runId, ['total_gross' => r2($t['g']), 'total_deductions' => r2($t['d']), 'total_net' => r2($t['n'])]);
    }

    // ------------------------------------------------------------------ approve, pay out, void
    /** Approve (post) a draft: journal entry, cash advance repayments; payslips become visible to the employees. */
    public static function post(int $runId): void
    {
        Auth::require('payroll.post');
        $r = self::draft($runId);
        $slips = self::slips($runId);
        if (!$slips) throw HttpException::bad('The payroll has no employees');
        foreach ($slips as $s) {
            if ((float) $s['net_pay'] < 0) throw HttpException::bad("Net pay of {$s['full_name']} is negative (" . number_format((float) $s['net_pay'], 2) . '). Lower the deductions first.');
        }
        $lines = DB::all('SELECT l.*, s.employee_id, s.full_name FROM payslip_lines l JOIN payslips s ON s.id = l.payslip_id WHERE s.run_id = ? AND l.kind = \'deduction\'', [$runId]);
        foreach ($lines as $l) {
            if ($l['type'] !== 'cash_advance') continue;
            $bal = (float) DB::value("SELECT balance FROM cash_advances WHERE id = ? AND status = 'approved'", [$l['advance_id']]);
            if ((float) $l['amount'] > $bal + 0.001) throw HttpException::bad("{$l['full_name']}: {$l['description']} is more than its balance (" . number_format($bal, 2) . ')');
        }
        DB::transaction(function () use ($r, $slips, $lines) {
            $gross = r2(array_sum(array_map(fn ($s) => (float) $s['gross_pay'], $slips)));
            $net = r2(array_sum(array_map(fn ($s) => (float) $s['net_pay'], $slips)));
            $by = ['statutory' => 0.0, 'tax' => 0.0, 'other' => 0.0];
            $je = [['key' => 'salaries', 'debit' => $gross, 'memo' => 'Gross pay ' . range_label($r['period_from'], $r['period_to'])]];
            if ($net > 0) $je[] = ['key' => 'salaries_payable', 'credit' => $net, 'memo' => 'Net pay'];
            foreach ($lines as $l) {
                $a = (float) $l['amount'];
                if ($l['type'] === 'cash_advance') $je[] = ['key' => 'emp_advances', 'credit' => $a, 'memo' => $l['description'], 'party_type' => 'employee', 'party_id' => (int) $l['employee_id']];
                elseif (in_array($l['type'], ['sss', 'philhealth', 'pagibig'], true)) $by['statutory'] += $a;
                elseif ($l['type'] === 'tax') $by['tax'] += $a;
                else $by['other'] += $a;
            }
            if ($by['statutory'] > 0) $je[] = ['key' => 'statutory_payable', 'credit' => r2($by['statutory']), 'memo' => 'SSS / PhilHealth / Pag-IBIG'];
            if ($by['tax'] > 0) $je[] = ['key' => 'wtax_payable', 'credit' => r2($by['tax']), 'memo' => 'Withholding tax'];
            if ($by['other'] > 0) $je[] = ['key' => 'payroll_other', 'credit' => r2($by['other']), 'memo' => 'Other payroll deductions'];
            $jeId = $gross > 0 ? Ledger::post($r['pay_date'], "Payroll {$r['run_no']} · " . range_label($r['period_from'], $r['period_to']), $je, 'payroll', (int) $r['id'], $r['run_no']) : null;
            // cash advances repaid by salary deduction (the payroll entry above already credits Employee advances)
            foreach ($lines as $l) {
                if ($l['type'] !== 'cash_advance') continue;
                $rid = DB::insert('ca_repayments', ['doc_no' => Sequence::next('CAR', 'CAR'), 'advance_id' => (int) $l['advance_id'], 'pay_date' => $r['pay_date'],
                    'amount' => (float) $l['amount'], 'method' => 'payroll', 'reference' => $r['run_no'], 'status' => 'posted', 'journal_entry_id' => $jeId,
                    'created_by' => Auth::id(), 'created_at' => now()]);
                DB::update('payslip_lines', (int) $l['id'], ['repayment_id' => $rid]);
                DB::run("UPDATE cash_advances SET balance = GREATEST(balance - ?, 0), status = IF(balance - ? <= 0.001, 'settled', status) WHERE id = ?",
                    [(float) $l['amount'], (float) $l['amount'], (int) $l['advance_id']]);
            }
            DB::update('payroll_runs', (int) $r['id'], ['status' => 'posted', 'journal_entry_id' => $jeId, 'posted_by' => Auth::id(), 'posted_at' => now()]);
            // nothing to pay (all net zero): already settled
            foreach ($slips as $s) if ((float) $s['net_pay'] <= 0) DB::update('payslips', (int) $s['id'], ['paid_at' => now(), 'paid_method' => null]);
            self::refreshPaid((int) $r['id']);
        });
        Audit::log('post', 'payroll', $runId, ['net' => $r['total_net']]);
    }

    /**
     * Pay out the net pay of some (or all unpaid) payslips of an approved payroll from cash on hand, petty cash or a bank.
     * Returns the number of payslips paid.
     */
    public static function payout(int $runId, array $slipIds, string $method, ?int $bankId = null, ?string $date = null, ?string $reference = null): int
    {
        Auth::require('payroll.post');
        $r = self::find($runId);
        if (!in_array($r['status'], ['posted', 'paid'], true)) throw HttpException::bad('Approve the payroll before paying out');
        if (!isset(self::PAY_METHODS[$method])) throw HttpException::bad('Choose where the money comes from');
        $date = is_date($date) ? $date : today();
        $ids = array_map('intval', $slipIds);
        $slips = array_values(array_filter(self::slips($runId), fn ($s) => $s['paid_at'] === null && (float) $s['net_pay'] > 0 && (!$ids || in_array((int) $s['id'], $ids, true))));
        if (!$slips) throw HttpException::bad('Nothing left to pay on this payroll');
        $total = r2(array_sum(array_map(fn ($s) => (float) $s['net_pay'], $slips)));
        $bank = null;
        if ($method === 'bank') {
            $bank = DB::one('SELECT * FROM bank_accounts WHERE id = ?', [(int) $bankId]);
            if (!$bank) throw HttpException::bad('Choose the bank account');
        }
        $source = match ($method) { 'bank' => (int) $bank['gl_account_id'], 'petty_cash' => PettyCash::payAccount($date), default => Ledger::account('cash_on_hand') };
        $reference = trim((string) $reference) ?: null;
        DB::transaction(function () use ($r, $slips, $total, $method, $bank, $source, $date, $reference) {
            $names = count($slips) === 1 ? $slips[0]['full_name'] : count($slips) . ' employees';
            $memo = "Salary payout {$r['run_no']} - $names";
            $je = [];
            foreach ($slips as $s) $je[] = ['key' => 'salaries_payable', 'debit' => (float) $s['net_pay'], 'memo' => $s['full_name'], 'party_type' => 'employee', 'party_id' => (int) $s['employee_id']];
            $je[] = ['account_id' => $source, 'credit' => $total, 'bank_account_id' => $bank['id'] ?? null];
            $jeId = Ledger::post($date, $memo, $je, 'payroll_payout', (int) $r['id'], $reference ?: $r['run_no']);
            if ($bank) {
                DB::insert('bank_txns', ['doc_no' => Sequence::next('BT', 'BT'), 'bank_account_id' => (int) $bank['id'], 'txn_date' => $date, 'txn_type' => 'withdrawal',
                    'amount' => $total, 'bank_charges' => 0, 'counter_account_id' => Ledger::account('salaries_payable'), 'reference' => $reference ?: $r['run_no'],
                    'description' => $memo, 'status' => 'posted', 'journal_entry_id' => $jeId, 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            if ($method === 'petty_cash') {
                PettyCash::linkPayout('payroll', (int) $r['id'], $date, $total, $memo, $names, Ledger::account('salaries_payable'), $r['run_no'], $jeId);
            }
            foreach ($slips as $s) {
                DB::update('payslips', (int) $s['id'], ['paid_at' => $date . ' ' . date('H:i:s'), 'paid_method' => $method, 'bank_account_id' => $bank['id'] ?? null, 'payout_je_id' => $jeId]);
            }
            self::refreshPaid((int) $r['id']);
        });
        Audit::log('payout', 'payroll', $runId, ['method' => $method, 'amount' => $total, 'payslips' => count($slips)]);
        return count($slips);
    }

    private static function refreshPaid(int $runId): void
    {
        $left = (int) DB::value('SELECT COUNT(*) FROM payslips WHERE run_id = ? AND paid_at IS NULL', [$runId]);
        DB::run("UPDATE payroll_runs SET status = ? WHERE id = ? AND status IN ('posted','paid')", [$left ? 'posted' : 'paid', $runId]);
    }

    /** Void: a draft is simply dropped; an approved payroll is reversed (no payouts yet) and its cash advance deductions undone. */
    public static function void(int $runId, string $reason): void
    {
        Auth::require('payroll.post');
        $r = self::find($runId);
        if ($r['status'] === 'void') throw HttpException::bad('Already void');
        if (trim($reason) === '') throw HttpException::bad('Give a reason');
        if ((int) DB::value('SELECT COUNT(*) FROM payslips WHERE run_id = ? AND payout_je_id IS NOT NULL', [$runId])) {
            throw HttpException::bad('Salaries of this payroll were already paid out. Undo the payouts first.');
        }
        DB::transaction(function () use ($r, $reason) {
            if ($r['journal_entry_id']) Ledger::reverse((int) $r['journal_entry_id'], today(), "Void payroll {$r['run_no']}: $reason");
            foreach (DB::all('SELECT l.* FROM payslip_lines l JOIN payslips s ON s.id = l.payslip_id WHERE s.run_id = ? AND l.repayment_id IS NOT NULL', [$r['id']]) as $l) {
                DB::run("UPDATE ca_repayments SET status = 'void' WHERE id = ?", [$l['repayment_id']]);
                DB::run("UPDATE cash_advances SET balance = balance + ?, status = 'approved' WHERE id = ?", [(float) $l['amount'], (int) $l['advance_id']]);
                DB::update('payslip_lines', (int) $l['id'], ['repayment_id' => null]);
            }
            DB::update('payroll_runs', (int) $r['id'], ['status' => 'void', 'notes' => trim(($r['notes'] ? $r['notes'] . ' | ' : '') . 'VOID: ' . $reason)]);
        });
        Audit::log('void', 'payroll', $runId, $reason);
    }

    /** Undo the payout of one payslip (wrong amount / not given yet): reverses its payout entry for every payslip paid with it. */
    public static function undoPayout(int $slipId, string $reason): int
    {
        Auth::require('payroll.post');
        $s = self::slip($slipId);
        if (!$s['payout_je_id']) throw HttpException::bad('This payslip has not been paid out');
        $je = (int) $s['payout_je_id'];
        $count = 0;
        DB::transaction(function () use ($je, $s, $reason, &$count) {
            Ledger::reverse($je, today(), "Undo salary payout {$s['run_no']}: $reason");
            DB::run("UPDATE bank_txns SET status = 'void' WHERE journal_entry_id = ?", [$je]);
            DB::run("UPDATE petty_cash_txns SET status = 'void' WHERE ref_type = 'payroll' AND journal_entry_id = ?", [$je]);
            $count = DB::run('UPDATE payslips SET paid_at = NULL, paid_method = NULL, bank_account_id = NULL, payout_je_id = NULL WHERE payout_je_id = ?', [$je]);
            self::refreshPaid((int) $s['run_id']);
        });
        Audit::log('undo_payout', 'payroll', (int) $s['run_id'], ['payslip' => $slipId, 'reason' => $reason]);
        return $count;
    }

    /** Delete a draft payroll completely. */
    public static function delete(int $runId): void
    {
        Auth::require('payroll.manage');
        $r = self::draft($runId);
        DB::transaction(function () use ($r) {
            DB::run('DELETE l FROM payslip_lines l JOIN payslips s ON s.id = l.payslip_id WHERE s.run_id = ?', [$r['id']]);
            DB::run('DELETE FROM payslips WHERE run_id = ?', [$r['id']]);
            DB::run('DELETE FROM payroll_runs WHERE id = ?', [$r['id']]);
        });
        Audit::log('delete', 'payroll', $runId, $r['run_no']);
    }

    // ------------------------------------------------------------------ employees' own payslips
    /** Approved / paid payslips of the logged-in employee (users.employee_id). */
    public static function mine(): array
    {
        $emp = Auth::user()['employee_id'] ?? null;
        if (!$emp) return [];
        return DB::all("SELECT s.id, s.net_pay, s.gross_pay, s.deduction_total, s.paid_at, r.run_no, r.period_from, r.period_to, r.pay_date, r.status
                        FROM payslips s JOIN payroll_runs r ON r.id = s.run_id
                        WHERE s.employee_id = ? AND r.status IN ('posted','paid') ORDER BY r.period_from DESC", [(int) $emp]);
    }

    /** May the current user see this payslip? Payroll staff: any; employees: their own once approved. */
    public static function canSee(array $slip): bool
    {
        if (Auth::can('payroll.view', 'payroll.manage', 'payroll.post')) return true;
        return Auth::can('payroll.self') && (int) (Auth::user()['employee_id'] ?? 0) === (int) $slip['employee_id']
            && in_array($slip['run_status'], ['posted', 'paid'], true);
    }
}
