<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Payroll: attendance punches imported from ZKTeco biometric devices -> days / hours per employee ->
 * payroll run (daily rate x days + overtime, less cash advance and government deductions) -> posted to the books.
 *
 * Posting a run (one journal entry):
 *   Dr Salaries & Wages (gross)
 *   Cr Salaries Payable (cash advance deductions)   -- cleared by the CA repayments (Dr Salaries Payable / Cr Advances to Employees)
 *   Cr SSS/PhilHealth/Pag-IBIG Payable (other deductions)
 *   Cr Cash on Hand / bank (net pay)
 */
class Payroll
{
    public const REGULAR_HOURS = 8;        // hours per day before overtime
    public const LUNCH_BREAK_HOURS = 1;    // deducted when the day spans more than MIN_SPAN_FOR_BREAK
    public const MIN_SPAN_FOR_BREAK = 5;
    public const OT_MULTIPLIER = 1.25;     // Philippine Labor Code: +25% on ordinary days
    public const CA_DEDUCTION_PCT = 0.25;  // default cash advance deduction when the terms do not say an amount
    public const PAY_METHODS = ['cash' => 'Cash on Hand', 'bank' => 'Bank account'];

    // ------------------------------------------------------------------ attendance import
    /**
     * Import punches from a ZKTeco export. Accepts the raw device log (attlog.dat / 1_attlog.dat:
     * "userid<TAB>YYYY-MM-DD HH:MM:SS<TAB>verify<TAB>status<TAB>workcode") and CSV/TSV exports from
     * ZKTime / ZKAccess / BioTime (any column named AC-No / User ID / Emp No / Badge / PIN + a Date/Time column).
     * Employees are matched on their biometric ID (Employees -> Biometric / device ID), falling back to the employee no.
     * Duplicate punches are ignored. Returns ['imported', 'duplicates', 'unknown' => [ids], 'from', 'to', 'employees'].
     */
    public static function importAttendance(string $content, ?string $device = null): array
    {
        Auth::require('payroll.manage');
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);   // UTF-8 BOM
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $map = self::employeeMap();
        $idCol = null; $timeCol = null; $dateCol = null;
        $imported = 0; $dup = 0; $unknown = []; $from = null; $to = null; $emps = [];
        $rows = [];
        foreach ($lines as $n => $line) {
            if (trim($line) === '') continue;
            $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',');
            $cells = array_map(fn ($c) => trim(trim($c), '"'), $sep === ',' ? str_getcsv($line) : explode($sep, $line));
            // Header row of a CSV export: find the id and time columns
            if ($idCol === null && !self::looksLikeTime($cells[1] ?? '') && !is_numeric($cells[0])) {
                foreach ($cells as $i => $c) {
                    $k = strtolower($c);
                    if ($idCol === null && preg_match('/ac.?no|user.?id|emp.?(no|id)|badge|^pin$|^id$|enroll/', $k)) $idCol = $i;
                    elseif ($timeCol === null && preg_match('/time|clock|punch/', $k)) $timeCol = $i;
                    elseif ($dateCol === null && preg_match('/^date$/', $k)) $dateCol = $i;
                }
                if ($idCol === null) throw HttpException::bad('Line ' . ($n + 1) . ': cannot find the user ID column in the file header');
                if ($timeCol === null) throw HttpException::bad('Line ' . ($n + 1) . ': cannot find the date / time column in the file header');
                continue;
            }
            [$id, $when] = $idCol === null
                ? [$cells[0], $cells[1] ?? '']                                                     // attlog.dat
                : [$cells[$idCol] ?? '', trim(($dateCol !== null ? ($cells[$dateCol] ?? '') . ' ' : '') . ($cells[$timeCol] ?? ''))];
            $ts = self::parseTime($when);
            if (!$ts) continue;   // not a punch line
            $id = ltrim(trim($id), '0') ?: '0';
            if (!isset($map[$id])) { $unknown[$id] = true; continue; }
            $rows[] = [$map[$id], $ts];
        }
        if (!$rows) throw HttpException::bad('No attendance punches found in the file. Export the attendance log from the ZKTeco device or software (attlog.dat or CSV).');
        DB::transaction(function () use ($rows, $device, &$imported, &$dup, &$from, &$to, &$emps) {
            foreach ($rows as [$empId, $ts]) {
                $n = DB::run('INSERT IGNORE INTO attendance_logs (employee_id, punch_at, source, device, imported_by, imported_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$empId, $ts, 'zkteco', $device, Auth::id(), now()]);
                if ($n) $imported++; else $dup++;
                $d = substr($ts, 0, 10);
                if ($from === null || $d < $from) $from = $d;
                if ($to === null || $d > $to) $to = $d;
                $emps[$empId] = true;
            }
        });
        $result = ['imported' => $imported, 'duplicates' => $dup, 'unknown' => array_map('strval', array_keys($unknown)), 'from' => $from, 'to' => $to, 'employees' => count($emps)];
        Audit::log('import_attendance', 'attendance', null, $result + ['device' => $device]);
        return $result;
    }

    /** biometric id (and employee no, digits only) -> employee id, active employees only */
    private static function employeeMap(): array
    {
        $map = [];
        foreach (DB::all('SELECT id, emp_no, biometric_id FROM employees WHERE active = 1') as $e) {
            if ($e['biometric_id'] !== null && $e['biometric_id'] !== '') $map[ltrim(trim($e['biometric_id']), '0') ?: '0'] = (int) $e['id'];
            $digits = ltrim(preg_replace('/\D+/', '', (string) $e['emp_no']), '0');
            if ($digits !== '' && !isset($map[$digits])) $map[$digits] = (int) $e['id'];
        }
        return $map;
    }

    private static function looksLikeTime(string $s): bool
    {
        return (bool) preg_match('/\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}.*\d{1,2}:\d{2}/', $s);
    }

    /** "2026-10-01 08:02:11", "10/01/2026 8:02", "01.10.2026 08:02:11", "2026-10-01T08:02:11" -> MySQL datetime or null */
    public static function parseTime(string $s): ?string
    {
        $s = trim($s);
        if (!self::looksLikeTime($s)) return null;
        $s = str_replace('T', ' ', $s);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'm/d/Y H:i:s', 'm/d/Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i', 'Y-m-d H:i:s.u', 'm/d/Y h:i:s A', 'm/d/Y h:i A'] as $f) {
            $dt = \DateTime::createFromFormat('!' . $f, $s);
            if ($dt && $dt->format('Y') > 2000) return $dt->format('Y-m-d H:i:s');
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** Punches of a period grouped per employee and day: [employee_id => [date => ['in', 'out', 'punches', 'hours', 'ot']]] */
    public static function attendance(string $from, string $to, ?int $employeeId = null): array
    {
        $params = [$from, $to];
        if ($employeeId) $params[] = $employeeId;
        $rows = DB::all('SELECT employee_id, punch_at FROM attendance_logs WHERE DATE(punch_at) BETWEEN ? AND ?' . ($employeeId ? ' AND employee_id = ?' : '') . ' ORDER BY employee_id, punch_at', $params);
        $days = [];
        foreach ($rows as $r) {
            $d = substr($r['punch_at'], 0, 10);
            $e = (int) $r['employee_id'];
            $days[$e][$d] ??= ['in' => $r['punch_at'], 'out' => $r['punch_at'], 'punches' => 0, 'hours' => 0.0, 'ot' => 0.0];
            $days[$e][$d]['out'] = $r['punch_at'];
            $days[$e][$d]['punches']++;
        }
        foreach ($days as &$byDay) {
            foreach ($byDay as &$day) {
                $span = (strtotime($day['out']) - strtotime($day['in'])) / 3600;
                $hours = $span > self::MIN_SPAN_FOR_BREAK ? $span - self::LUNCH_BREAK_HOURS : $span;
                $day['hours'] = round(max($hours, 0), 2);
                $day['ot'] = round(max($day['hours'] - self::REGULAR_HOURS, 0), 2);
            }
        }
        return $days;
    }

    // ------------------------------------------------------------------ payroll runs
    public static function list(?int $limit = null): array
    {
        return DB::all('SELECT r.*, u.full_name AS created_by_name, (SELECT COUNT(*) FROM payroll_lines l WHERE l.run_id = r.id) AS employees
             FROM payroll_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.period_to DESC, r.id DESC' . ($limit ? " LIMIT $limit" : ''));
    }

    public static function find(int $id): array
    {
        $r = DB::one('SELECT r.*, u.full_name AS created_by_name, p.full_name AS posted_by_name, b.bank_name
                      FROM payroll_runs r LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users p ON p.id = r.posted_by
                      LEFT JOIN bank_accounts b ON b.id = r.bank_account_id WHERE r.id = ?', [$id]);
        if (!$r) throw HttpException::notFound('Payroll run');
        $r['lines'] = DB::all('SELECT l.*, e.full_name AS employee_name, e.emp_no, e.position, e.department,
                 (SELECT COALESCE(SUM(balance), 0) FROM cash_advances c WHERE c.employee_id = e.id AND c.status = \'approved\') AS ca_balance
             FROM payroll_lines l JOIN employees e ON e.id = l.employee_id WHERE l.run_id = ? ORDER BY e.full_name', [$id]);
        return $r;
    }

    /** $d: period_from, period_to, pay_date, method (cash|bank), bank_account_id, notes. Returns the run id (draft). */
    public static function createRun(array $d): int
    {
        Auth::require('payroll.manage');
        required($d, 'period_from', 'period_to');
        if (!is_date($d['period_from']) || !is_date($d['period_to']) || $d['period_from'] > $d['period_to']) throw HttpException::bad('Enter a valid pay period');
        $method = $d['method'] ?? 'cash';
        if (!isset(self::PAY_METHODS[$method])) throw HttpException::bad('Choose how salaries are paid');
        $bankId = $method === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;
        if ($method === 'bank' && !$bankId) throw HttpException::bad('Please select the bank account');
        $payDate = is_date($d['pay_date'] ?? null) ? $d['pay_date'] : $d['period_to'];
        if (DB::value("SELECT id FROM payroll_runs WHERE status = 'posted' AND period_from <= ? AND period_to >= ?", [$d['period_to'], $d['period_from']])) {
            throw HttpException::bad('A posted payroll already covers part of this period');
        }
        $employees = DB::all("SELECT e.*, (SELECT COALESCE(SUM(balance), 0) FROM cash_advances c WHERE c.employee_id = e.id AND c.status = 'approved') AS ca_balance,
                 (SELECT repayment_terms FROM cash_advances c WHERE c.employee_id = e.id AND c.status = 'approved' ORDER BY release_date LIMIT 1) AS ca_terms
             FROM employees e WHERE e.active = 1 AND e.daily_rate > 0 ORDER BY e.full_name");
        if (!$employees) throw HttpException::bad('No active employees with a daily rate. Set the daily rate under People → Employees.');
        $att = self::attendance($d['period_from'], $d['period_to']);

        return DB::transaction(function () use ($d, $employees, $att, $method, $bankId, $payDate) {
            $id = DB::insert('payroll_runs', [
                'doc_no' => Sequence::next('PAY', 'PAY'), 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'pay_date' => $payDate,
                'method' => $method, 'bank_account_id' => $bankId, 'status' => 'draft', 'notes' => trim((string) ($d['notes'] ?? '')) ?: null,
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($employees as $e) {
                $days = $att[(int) $e['id']] ?? [];
                $rate = (float) $e['daily_rate'];
                $hourly = $rate / self::REGULAR_HOURS;
                $worked = count($days);
                $hours = r2(array_sum(array_column($days, 'hours')));
                $ot = r2(array_sum(array_column($days, 'ot')));
                $basic = r2($worked * $rate);
                $otPay = r2($ot * $hourly * self::OT_MULTIPLIER);
                $gross = r2($basic + $otPay);
                $ca = self::defaultDeduction((float) $e['ca_balance'], $e['ca_terms'], $gross);
                DB::insert('payroll_lines', [
                    'run_id' => $id, 'employee_id' => (int) $e['id'], 'daily_rate' => $rate, 'days_worked' => $worked, 'hours_worked' => $hours,
                    'ot_hours' => $ot, 'basic_pay' => $basic, 'ot_pay' => $otPay, 'gross_pay' => $gross,
                    'ca_deduction' => $ca, 'other_deductions' => 0, 'net_pay' => r2($gross - $ca),
                ]);
            }
            self::recalc($id);
            Audit::log('create', 'payroll_run', $id, ['period' => [$d['period_from'], $d['period_to']]]);
            return $id;
        });
    }

    /** Cash advance deduction suggested for this cutoff: the amount in the repayment terms ("₱500 per payroll"), else 25% of gross, never above the balance. */
    public static function defaultDeduction(float $balance, ?string $terms, float $gross): float
    {
        if ($balance <= 0 || $gross <= 0) return 0.0;
        $amount = preg_match('/(\d[\d,]*(?:\.\d+)?)/', (string) $terms, $m) ? (float) str_replace(',', '', $m[1]) : 0.0;
        if ($amount < 1) $amount = floor($gross * self::CA_DEDUCTION_PCT / 50) * 50;
        return r2(min($amount, $balance, $gross));
    }

    /** Edit the deductions of a draft: $lines = [line_id => ['ca_deduction' => x, 'other_deductions' => y]] */
    public static function saveLines(int $id, array $lines): void
    {
        Auth::require('payroll.manage');
        $run = self::find($id);
        if ($run['status'] !== 'draft') throw HttpException::bad('Only draft payrolls can be changed');
        DB::transaction(function () use ($run, $lines) {
            foreach ($run['lines'] as $l) {
                $in = $lines[$l['id']] ?? null;
                if ($in === null) continue;
                $ca = r2(num($in['ca_deduction'] ?? $l['ca_deduction']));
                $other = r2(num($in['other_deductions'] ?? $l['other_deductions']));
                if ($ca < 0 || $other < 0) throw HttpException::bad('Deductions cannot be negative');
                if ($ca > (float) $l['ca_balance'] + 0.001) throw HttpException::bad("{$l['employee_name']}: cash advance deduction exceeds the balance of " . number_format($l['ca_balance'], 2));
                if ($ca + $other > (float) $l['gross_pay'] + 0.001) throw HttpException::bad("{$l['employee_name']}: deductions exceed the gross pay");
                DB::update('payroll_lines', (int) $l['id'], ['ca_deduction' => $ca, 'other_deductions' => $other, 'net_pay' => r2($l['gross_pay'] - $ca - $other)]);
            }
            self::recalc((int) $run['id']);
        });
    }

    private static function recalc(int $id): void
    {
        $t = DB::one('SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(ca_deduction),0) c, COALESCE(SUM(other_deductions),0) o, COALESCE(SUM(net_pay),0) n FROM payroll_lines WHERE run_id = ?', [$id]);
        DB::update('payroll_runs', $id, ['total_gross' => r2($t['g']), 'total_ca' => r2($t['c']), 'total_other' => r2($t['o']), 'total_net' => r2($t['n'])]);
    }

    /** Post the run: cash advance repayments, then one journal entry for salaries, deductions and the net pay. */
    public static function post(int $id): void
    {
        Auth::require('payroll.manage');
        $run = self::find($id);
        if ($run['status'] !== 'draft') throw HttpException::bad('This payroll is already posted');
        if ((float) $run['total_gross'] <= 0) throw HttpException::bad('Nothing to pay: no employee has days worked in this period. Import the attendance first.');
        DB::transaction(function () use ($run) {
            $paid = Ledger::sourceAccount($run['method'], $run['bank_account_id']);
            foreach ($run['lines'] as $l) {
                $left = (float) $l['ca_deduction'];
                $advances = DB::all("SELECT id FROM cash_advances WHERE employee_id = ? AND status = 'approved' AND balance > 0 ORDER BY release_date, id", [$l['employee_id']]);
                foreach ($advances as $a) {
                    if ($left <= 0.001) break;
                    $bal = (float) DB::value('SELECT balance FROM cash_advances WHERE id = ?', [$a['id']]);
                    $amt = r2(min($left, $bal));
                    $rid = CashAdvances::repay((int) $a['id'], ['amount' => $amt, 'method' => 'payroll', 'pay_date' => $run['pay_date'], 'reference' => $run['doc_no']]);
                    DB::update('payroll_lines', (int) $l['id'], ['ca_repayment_id' => $rid]);
                    $left = r2($left - $amt);
                }
                if ($left > 0.001) throw HttpException::bad("{$l['employee_name']}: cash advance deduction exceeds the outstanding advances");
            }
            $sss = (int) DB::value("SELECT id FROM accounts WHERE code = '2210'");
            $je = Ledger::post($run['pay_date'], "Payroll {$run['doc_no']} " . fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']), [
                ['key' => 'salaries', 'debit' => (float) $run['total_gross']],
                ['key' => 'salaries_payable', 'credit' => (float) $run['total_ca'], 'memo' => 'Cash advance deductions'],
                ['account_id' => $sss ?: Ledger::account('salaries_payable'), 'credit' => (float) $run['total_other'], 'memo' => 'Government deductions'],
                ['account_id' => $paid, 'credit' => (float) $run['total_net'], 'bank_account_id' => $run['bank_account_id'], 'memo' => 'Net pay'],
            ], 'payroll', (int) $run['id'], $run['doc_no']);
            DB::update('payroll_runs', (int) $run['id'], ['status' => 'posted', 'journal_entry_id' => $je, 'posted_by' => Auth::id(), 'posted_at' => now()]);
        });
        Audit::log('post', 'payroll_run', $id, ['gross' => $run['total_gross'], 'net' => $run['total_net']]);
    }

    public static function delete(int $id): void
    {
        Auth::require('payroll.manage');
        $run = self::find($id);
        if ($run['status'] !== 'draft') throw HttpException::bad('Posted payrolls cannot be deleted');
        DB::transaction(function () use ($id) {
            DB::run('DELETE FROM payroll_lines WHERE run_id = ?', [$id]);
            DB::run('DELETE FROM payroll_runs WHERE id = ?', [$id]);
        });
        Audit::log('delete', 'payroll_run', $id);
    }

    // ------------------------------------------------------------------ employee self-service ("My Pay")
    /** Posted payslips of one employee, newest first (payroll line + run header). */
    public static function payslipsFor(int $employeeId): array
    {
        return DB::all('SELECT l.*, r.doc_no, r.period_from, r.period_to, r.pay_date, r.method, r.posted_at
             FROM payroll_lines l JOIN payroll_runs r ON r.id = l.run_id
             WHERE l.employee_id = ? AND r.status = \'posted\' ORDER BY r.period_to DESC, r.id DESC', [$employeeId]);
    }

    /** One posted payslip with its employee and run, or null. */
    public static function payslip(int $lineId): ?array
    {
        return DB::one('SELECT l.*, r.doc_no, r.period_from, r.period_to, r.pay_date, r.method, r.posted_at,
                    e.full_name AS employee_name, e.emp_no, e.position, e.department
             FROM payroll_lines l JOIN payroll_runs r ON r.id = l.run_id JOIN employees e ON e.id = l.employee_id
             WHERE l.id = ? AND r.status = \'posted\'', [$lineId]);
    }
}
