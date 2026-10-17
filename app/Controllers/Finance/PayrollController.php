<?php
namespace App\Controllers\Finance;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Banks;
use App\Services\Finance\Employees;
use App\Services\Finance\Payroll;

/** Payroll: ZKTeco attendance import, payroll runs (draft -> posted), payslips. */
class PayrollController
{
    public function index(Request $req)
    {
        $rows = Payroll::list();
        foreach ($rows as &$r) if ($r['status'] === 'draft') $r['_class'] = 'muted-row';
        unset($r);
        $columns = [
            ['key' => 'doc_no', 'label' => 'Payroll no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['doc_no']) . '</span>'],
            ['key' => 'period_from', 'label' => 'Period', 'value' => fn ($r) => fmt_date($r['period_from']) . ' – ' . fmt_date($r['period_to']),
                'html' => fn ($r) => e(fmt_date($r['period_from']) . ' – ' . fmt_date($r['period_to']))],
            ['key' => 'pay_date', 'label' => 'Pay date', 'type' => 'date'],
            ['key' => 'employees', 'label' => 'Employees', 'type' => 'int'],
            ['key' => 'total_gross', 'label' => 'Gross pay', 'type' => 'money', 'total' => true],
            ['key' => 'total_ca', 'label' => 'Cash advances', 'type' => 'money', 'total' => true],
            ['key' => 'total_other', 'label' => 'Gov’t deductions', 'type' => 'money', 'total' => true],
            ['key' => 'total_net', 'label' => 'Net pay', 'type' => 'money', 'total' => true, 'html' => fn ($r) => '<span class="bold">' . peso($r['total_net']) . '</span>'],
            ['key' => 'method', 'label' => 'Paid via', 'value' => fn ($r) => Payroll::PAY_METHODS[$r['method']] ?? $r['method'], 'html' => fn ($r) => e(Payroll::PAY_METHODS[$r['method']] ?? $r['method'])],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'Prepared by'],
        ];
        if ($x = Table::export($req, 'payroll', 'Payroll runs', '', $columns, $rows)) return $x;
        $last = DB::one("SELECT MAX(period_to) t FROM payroll_runs WHERE status = 'posted'");
        $from = $last && $last['t'] ? add_days($last['t'], 1) : date('Y-m-01');
        $to = (int) date('d', strtotime($from)) <= 15 ? date('Y-m-15', strtotime($from)) : date('Y-m-t', strtotime($from));
        return view('people/payroll/index', ['title' => 'Payroll', 'rows' => $rows, 'columns' => $columns, 'banks' => Banks::options(),
            'suggest' => [$from, $to], 'rated' => (int) DB::value('SELECT COUNT(*) FROM employees WHERE active = 1 AND daily_rate > 0')]);
    }

    public function store(Request $req): Response
    {
        $id = Payroll::createRun($req->all());
        flash('success', 'Payroll computed from the attendance log. Review the deductions, then post.');
        return redirect('/payroll/' . $id);
    }

    public function show(Request $req, string $id)
    {
        $run = Payroll::find((int) $id);
        $draft = $run['status'] === 'draft';
        $money = fn ($name, $max) => fn ($r) => $draft
            ? '<input class="input right" type="number" step="0.01" min="0" max="' . e($max($r)) . '" name="lines[' . $r['id'] . '][' . $name . ']" value="' . e($r[$name]) . '" form="lines-form" style="width:120px">'
            : money($r[$name]);
        $columns = [
            ['key' => 'employee_name', 'label' => 'Employee', 'html' => fn ($r) => '<span class="bold">' . e($r['employee_name']) . '</span><div class="muted small">' . e($r['emp_no'] . ($r['position'] ? ' · ' . $r['position'] : '')) . '</div>'],
            ['key' => 'daily_rate', 'label' => 'Daily rate', 'type' => 'money'],
            ['key' => 'days_worked', 'label' => 'Days', 'type' => 'int', 'total' => true],
            ['key' => 'hours_worked', 'label' => 'Hours', 'type' => 'qty'],
            ['key' => 'ot_hours', 'label' => 'OT hrs', 'type' => 'qty', 'total' => true],
            ['key' => 'basic_pay', 'label' => 'Basic pay', 'type' => 'money', 'total' => true],
            ['key' => 'ot_pay', 'label' => 'Overtime', 'type' => 'money', 'total' => true],
            ['key' => 'gross_pay', 'label' => 'Gross', 'type' => 'money', 'total' => true],
            ['key' => 'ca_deduction', 'label' => 'Cash advance', 'type' => 'money', 'total' => true, 'html' => $money('ca_deduction', fn ($r) => $r['ca_balance'])],
            ['key' => 'other_deductions', 'label' => 'SSS / PhilHealth / Pag-IBIG', 'type' => 'money', 'total' => true, 'html' => $money('other_deductions', fn ($r) => $r['gross_pay'])],
            ['key' => 'net_pay', 'label' => 'Net pay', 'type' => 'money', 'total' => true, 'html' => fn ($r) => '<span class="bold" data-net>' . peso($r['net_pay']) . '</span>'],
        ];
        if ($x = Table::export($req, $run['doc_no'], 'Payroll ' . $run['doc_no'], fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']), $columns, $run['lines'])) return $x;
        return view('people/payroll/show', ['title' => 'Payroll ' . $run['doc_no'], 'run' => $run, 'columns' => $columns, 'draft' => $draft]);
    }

    public function save(Request $req, string $id): Response
    {
        Payroll::saveLines((int) $id, (array) $req->input('lines', []));
        flash('success', 'Deductions saved.');
        return redirect('/payroll/' . $id);
    }

    public function post(Request $req, string $id): Response
    {
        Payroll::post((int) $id);
        flash('success', 'Payroll posted to the books: salaries, cash advance repayments and the net pay.');
        return redirect('/payroll/' . $id);
    }

    public function delete(Request $req, string $id): Response
    {
        Payroll::delete((int) $id);
        flash('success', 'Draft payroll deleted.');
        return redirect('/payroll');
    }

    /** Printable payslips, one per employee. */
    public function payslips(Request $req, string $id)
    {
        $run = Payroll::find((int) $id);
        $att = Payroll::attendance($run['period_from'], $run['period_to']);
        return view('people/payroll/payslips', ['title' => 'Payslips ' . $run['doc_no'], 'run' => $run, 'att' => $att,
            'business' => \App\Services\Settings::get('business_name', 'M5')], 'blank');
    }

    // ------------------------------------------------------------------ attendance
    public function attendance(Request $req)
    {
        [$from, $to] = date_range($req);
        $employeeId = (int) $req->query('employee_id') ?: null;
        $days = Payroll::attendance($from, $to, $employeeId);
        $employees = array_column(Employees::list(true), null, 'id');
        $rows = [];
        foreach ($days as $empId => $byDay) {
            foreach ($byDay as $date => $d) {
                $rows[] = ['date' => $date, 'employee_name' => $employees[$empId]['full_name'] ?? "#$empId", 'emp_no' => $employees[$empId]['emp_no'] ?? '',
                    'biometric_id' => $employees[$empId]['biometric_id'] ?? '', 'in' => $d['in'], 'out' => $d['out'], 'punches' => $d['punches'], 'hours' => $d['hours'], 'ot' => $d['ot'],
                    '_class' => $d['punches'] < 2 ? 'muted-row' : ''];
            }
        }
        usort($rows, fn ($a, $b) => [$b['date'], $a['employee_name']] <=> [$a['date'], $b['employee_name']]);
        $columns = [
            ['key' => 'date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'employee_name', 'label' => 'Employee', 'html' => fn ($r) => '<span class="bold">' . e($r['employee_name']) . '</span> <span class="muted small">' . e($r['emp_no']) . '</span>'],
            ['key' => 'biometric_id', 'label' => 'Device ID'],
            ['key' => 'in', 'label' => 'First punch (in)', 'value' => fn ($r) => substr($r['in'], 11, 5), 'html' => fn ($r) => e(substr($r['in'], 11, 5))],
            ['key' => 'out', 'label' => 'Last punch (out)', 'value' => fn ($r) => substr($r['out'], 11, 5), 'html' => fn ($r) => $r['punches'] < 2 ? '<span class="text-amber">missing</span>' : e(substr($r['out'], 11, 5))],
            ['key' => 'punches', 'label' => 'Punches', 'type' => 'int'],
            ['key' => 'hours', 'label' => 'Hours', 'type' => 'qty', 'total' => true],
            ['key' => 'ot', 'label' => 'OT hrs', 'type' => 'qty', 'total' => true],
        ];
        if ($x = Table::export($req, 'attendance', 'Attendance', range_label($from, $to), $columns, $rows)) return $x;
        $stats = ['days' => count($rows), 'employees' => count($days), 'hours' => array_sum(array_column($rows, 'hours')),
            'unmatched' => (int) DB::value("SELECT COUNT(*) FROM employees WHERE active = 1 AND (biometric_id IS NULL OR biometric_id = '')")];
        return view('people/payroll/attendance', ['title' => 'Attendance', 'rows' => $rows, 'columns' => $columns, 'from' => $from, 'to' => $to,
            'employeeId' => $employeeId, 'employees' => $employees, 'stats' => $stats, 'uploadLimit' => ini_get('upload_max_filesize')]);
    }

    public function import(Request $req): Response
    {
        $file = $req->file('attlog');
        $content = $file ? (string) file_get_contents($file['tmp_name']) : (string) $req->input('pasted', '');
        if (trim($content) === '') { flash('error', 'Choose the attendance file exported from the ZKTeco device (attlog.dat or CSV).'); return back(); }
        $r = Payroll::importAttendance($content, trim((string) $req->input('device', '')) ?: ($file['name'] ?? null));
        $msg = "Imported {$r['imported']} punch(es) for {$r['employees']} employee(s), " . fmt_date($r['from']) . ' – ' . fmt_date($r['to'])
            . ($r['duplicates'] ? " ({$r['duplicates']} already on file)" : '') . '.';
        if ($r['unknown']) $msg .= ' Unknown device IDs skipped: ' . implode(', ', array_slice($r['unknown'], 0, 10)) . ' — set them under Employees → Biometric ID.';
        flash($r['unknown'] ? 'error' : 'success', $msg);
        return redirect('/payroll/attendance?from=' . $r['from'] . '&to=' . $r['to']);
    }
}
