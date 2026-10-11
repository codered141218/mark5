<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Sequence;

/** Employee records (used for cash advances and for linking user accounts). */
class Employees
{
    private const FIELDS = ['emp_no', 'full_name', 'position', 'department', 'phone', 'date_hired', 'notes', 'biometric_id'];
    /** Pay setup (People → Employees → Pay): amounts per pay-out. */
    private const MONEY = ['monthly_rate', 'daily_rate', 'sss', 'philhealth', 'pagibig', 'wtax', 'ca_deduction'];

    /** Employees with their outstanding cash advance balance. */
    public static function list(bool $includeInactive = false): array
    {
        return DB::all(
            "SELECT e.*, (SELECT COALESCE(SUM(balance), 0) FROM cash_advances c WHERE c.employee_id = e.id AND c.status = 'approved') AS ca_balance
             FROM employees e" . ($includeInactive ? '' : ' WHERE e.active = 1') . ' ORDER BY e.full_name'
        );
    }

    /** Employees the current user may file a cash advance for: everyone for approvers/HR, otherwise only their own record. */
    public static function forRequests(): array
    {
        $rows = self::list();
        if (CashAdvances::seesAll() || Auth::can('employees.manage')) return $rows;
        $own = Auth::user()['employee_id'] ?? null;
        return array_values(array_filter($rows, fn ($r) => (int) $r['id'] === $own));
    }

    /** Create ($d['id'] empty; blank emp no = next EMP-### number) or update. Returns the id. */
    public static function save(array $d): int
    {
        required($d, 'full_name');
        $row = array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, pick($d, self::FIELDS) + array_fill_keys(self::FIELDS, null));
        if ($row['date_hired'] && !is_date($row['date_hired'])) throw HttpException::bad('Enter a valid date hired');
        foreach (self::MONEY as $f) if (array_key_exists($f, $d)) $row[$f] = max(0, r2(num($d[$f])));
        if (array_key_exists('pay_type', $d)) $row['pay_type'] = $d['pay_type'] === 'daily' ? 'daily' : 'monthly';
        // work schedule: blank = the payroll defaults
        if (array_key_exists('work_days', $d)) {
            $wd = array_unique(preg_split('//', preg_replace('/[^1-7]/', '', is_array($d['work_days']) ? implode('', $d['work_days']) : (string) $d['work_days']), -1, PREG_SPLIT_NO_EMPTY));
            sort($wd);
            if (array_key_exists('work_days_set', $d) && !$wd) throw HttpException::bad('Tick at least one working day');
            $row['work_days'] = $wd && count($wd) < 7 ? implode('', $wd) : null;
        }
        if (array_key_exists('paid_rest_days', $d)) {
            $v = trim((string) $d['paid_rest_days']);
            if ($v !== '' && (!is_numeric($v) || (int) $v < 0 || (int) $v > 7)) throw HttpException::bad('Paid day-offs a week: 0 to 7 (blank = the payroll default)');
            $row['paid_rest_days'] = $v === '' ? null : (int) $v;
        }
        if (array_key_exists('shift_start', $d)) {
            $v = trim((string) $d['shift_start']);
            if ($v !== '' && (!preg_match('/^(\d{1,2}):(\d{2})/', $v, $m) || (int) $m[1] > 23 || (int) $m[2] > 59)) throw HttpException::bad('Enter the shift start like 10:00');
            $row['shift_start'] = $v === '' ? null : sprintf('%02d:%02d', $m[1], $m[2]);
        }
        if (array_key_exists('shift_hours', $d)) {
            $v = trim((string) $d['shift_hours']);
            if ($v !== '' && (!is_numeric($v) || (float) $v < 1 || (float) $v > 24)) throw HttpException::bad('Hours a day: 1 to 24 (blank = the payroll default)');
            $row['shift_hours'] = $v === '' ? null : (float) $v;
        }
        if ($row['biometric_id'] !== null) $row['biometric_id'] = ltrim((string) $row['biometric_id'], '0') ?: '0';   // device user IDs have no leading zeros
        if ($row['biometric_id'] && DB::value('SELECT id FROM employees WHERE biometric_id = ? AND id <> ?', [$row['biometric_id'], (int) ($d['id'] ?? 0)])) {
            throw HttpException::bad('Another employee already has biometric ID ' . $row['biometric_id']);
        }
        $id = (int) ($d['id'] ?? 0);
        if ($row['emp_no'] && DB::value('SELECT id FROM employees WHERE emp_no = ? AND id <> ?', [$row['emp_no'], $id])) {
            throw HttpException::bad('Employee number already exists');
        }
        if ($id) {
            if (!DB::value('SELECT id FROM employees WHERE id = ?', [$id])) throw HttpException::notFound('Employee');
            $row['active'] = !empty($d['active']) ? 1 : 0;
            if (!$row['emp_no']) unset($row['emp_no']);
            DB::update('employees', $id, $row);
            Audit::log('update', 'employee', $id, $row);
        } else {
            if (!$row['emp_no']) $row['emp_no'] = Sequence::next('EMP', 'EMP', 3);
            $id = DB::insert('employees', $row + ['active' => 1]);
            Audit::log('create', 'employee', $id, $row);
        }
        return $id;
    }
}
