<?php
namespace App\Controllers\People;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Payroll\Attendance;
use App\Services\Payroll\Payroll;
use App\Services\Payroll\StaffNotes;

/** Biometric attendance: upload the device file, match device users to employees, view / fix days. */
class AttendanceController
{
    public function index(Request $req)
    {
        $s = Payroll::suggestPeriod();
        $last = DB::one('SELECT * FROM attendance_imports ORDER BY id DESC LIMIT 1');
        $from = is_date($req->query('from')) ? $req->query('from') : ($last['period_from'] ?? $s['from']);
        $to = is_date($req->query('to')) ? $req->query('to') : ($last['period_to'] ?? $s['to']);
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) / 86400 > 45) $to = add_days($from, 45);
        $emps = DB::all('SELECT id, emp_no, full_name, position, biometric_id, work_days, paid_rest_days, shift_start, shift_hours FROM employees WHERE active = 1 ORDER BY full_name');
        $rows = Attendance::rows($from, $to);
        $cfg = Payroll::settings();
        $grid = [];
        foreach ($emps as $e) {
            $c = Payroll::compute(['pay_type' => 'monthly', 'monthly_rate' => 0, 'daily_rate' => 0] + $e, $from, $to, $rows[(int) $e['id']] ?? [], $cfg);
            $off = Payroll::schedule($e, $cfg)['off'];
            $grid[] = ['employee' => $e, 'days' => $c['days'], 'sum' => $c, 'off' => implode(', ', array_map(fn ($d) => Payroll::WEEKDAYS[$d], $off))];
        }
        return view('payroll/attendance', ['title' => 'Attendance', 'from' => $from, 'to' => $to, 'grid' => $grid, 'cfg' => $cfg,
            'imports' => DB::all('SELECT i.*, u.full_name AS by_name FROM attendance_imports i LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT 5'),
            'notes' => StaffNotes::byDay($from, $to),
            'unmapped' => (int) DB::value("SELECT COUNT(*) FROM employees WHERE active = 1 AND (biometric_id IS NULL OR biometric_id = '')")]);
    }

    public function upload(Request $req): Response
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw HttpException::bad('Choose the attendance file from the biometric device');
        if ($f['size'] > 10 * 1024 * 1024) throw HttpException::bad('The file is too big (10 MB at most)');
        $parsed = Attendance::parse($f['tmp_name'], (string) $f['name']);
        $_SESSION['att_import'] = ['parsed' => $parsed, 'file' => (string) $f['name']];
        return redirect('/payroll/attendance/preview');
    }

    public function preview(Request $req)
    {
        $imp = $_SESSION['att_import'] ?? null;
        if (!$imp) return redirect('/payroll/attendance');
        return view('payroll/attendance_preview', ['title' => 'Import attendance', 'imp' => $imp, 'people' => Attendance::preview($imp['parsed']),
            'employees' => DB::all('SELECT id, emp_no, full_name, biometric_id, active FROM employees ORDER BY active DESC, full_name')]);
    }

    public function import(Request $req): Response
    {
        $imp = $_SESSION['att_import'] ?? null;
        if (!$imp) throw HttpException::bad('Upload the file again');
        $r = Attendance::save($imp['parsed'], (array) $req->input('map', []), $imp['file']);
        unset($_SESSION['att_import']);
        $names = array_column(DB::all('SELECT id, full_name FROM employees'), 'full_name', 'id');
        $each = implode(', ', array_map(fn ($l) => ($names[$l['employee_id']] ?? '?') . " (device #{$l['device_id']}: {$l['days']} day" . ($l['days'] === 1 ? '' : 's') . ')', $r['linked']));
        flash('success', "Attendance imported for " . range_label($imp['parsed']['from'], $imp['parsed']['to']) . ": $each.");
        return redirect('/payroll/attendance?' . http_build_query(['from' => $imp['parsed']['from'], 'to' => $imp['parsed']['to']]));
    }

    public function day(Request $req): Response
    {
        Attendance::setDay((int) $req->input('employee_id'), (string) $req->input('work_date'), $req->all());
        flash('success', 'Attendance updated. Recompute any draft payroll of this period.');
        return back();
    }
}
