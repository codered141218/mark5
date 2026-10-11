<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Employees;
use App\Services\Payroll\Payroll;

/** Employee records: list (optionally with inactive), add / edit dialog. */
class EmployeeController
{
    public function index(Request $req)
    {
        $showAll = (bool) $req->query('all');
        $rows = Employees::list($showAll);
        $cfg = Payroll::settings();
        foreach ($rows as &$r) {
            if (!$r['active']) $r['_class'] = 'muted-row';
            $r['work_days'] = $r['work_days'] ?: '1234567';
            $r['shift_start'] = $r['shift_start'] ?? '';
            $r['shift_hours'] = $r['shift_hours'] !== null ? rtrim(rtrim((string) $r['shift_hours'], '0'), '.') : '';
            $r['schedule'] = Payroll::scheduleLabel($r, $cfg);
        }
        unset($r);
        $columns = [
            ['key' => 'emp_no', 'label' => 'Emp no'],
            ['key' => 'full_name', 'label' => 'Full name', 'html' => fn ($r) => '<span class="bold">' . e($r['full_name']) . '</span>'],
            ['key' => 'position', 'label' => 'Position'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'date_hired', 'label' => 'Date hired', 'type' => 'date'],
            ['key' => 'monthly_rate', 'label' => 'Rate', 'value' => fn ($r) => $r['pay_type'] === 'daily' ? (float) $r['daily_rate'] : (float) $r['monthly_rate'],
                'html' => fn ($r) => ((float) ($r['pay_type'] === 'daily' ? $r['daily_rate'] : $r['monthly_rate']))
                    ? money($r['pay_type'] === 'daily' ? $r['daily_rate'] : $r['monthly_rate']) . ' <span class="muted small">/' . ($r['pay_type'] === 'daily' ? 'day' : 'month') . '</span>'
                    : '<span class="muted small">—</span>'],
            ['key' => 'biometric_id', 'label' => 'Device ID'],
            ['key' => 'schedule', 'label' => 'Schedule', 'html' => fn ($r) => '<span class="small">' . e($r['schedule']) . '</span>'],
            ['key' => 'ca_balance', 'label' => 'Cash advance bal.', 'type' => 'money', 'total' => true],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => '<div class="actions"><button class="btn btn-sm" type="button" data-open="dlg-employee" data-title="Edit employee — ' . e($r['full_name']) . '" data-fill=\''
                . e(json_encode(array_intersect_key($r, array_flip(['id', 'emp_no', 'full_name', 'position', 'department', 'phone', 'date_hired', 'notes', 'active',
                    'biometric_id', 'pay_type', 'monthly_rate', 'daily_rate', 'sss', 'philhealth', 'pagibig', 'wtax', 'ca_deduction',
                    'work_days', 'paid_rest_days', 'shift_start', 'shift_hours'])))) . '\'>Edit</button></div>'],
        ];
        if ($x = Table::export($req, 'employees', 'Employees', $showAll ? 'Including inactive' : 'Active', $columns, $rows)) return $x;
        return view('people/employees', ['title' => 'Employees', 'rows' => $rows, 'columns' => $columns, 'showAll' => $showAll, 'cfg' => $cfg]);
    }

    public function save(Request $req): Response
    {
        $id = (int) $req->input('id');
        Employees::save($req->all());
        flash('success', $id ? 'Employee updated.' : 'Employee added.');
        return back();
    }
}
