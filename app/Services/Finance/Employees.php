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
    private const FIELDS = ['emp_no', 'full_name', 'position', 'department', 'phone', 'date_hired', 'notes'];

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
