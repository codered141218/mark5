<?php
namespace App\Controllers\People;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Banks;
use App\Services\Payroll\Payroll;
use App\Services\Payroll\StaffNotes;
use App\Services\Settings;

/** Payroll runs (cut-offs), payslips with bonuses / deductions, approval, salary payouts, settings and "My payslips". */
class PayrollController
{
    public function index(Request $req)
    {
        $rows = Payroll::list();
        $columns = [
            ['key' => 'run_no', 'label' => 'Payroll no', 'html' => fn ($r) => '<span class="bold">' . e($r['run_no']) . '</span>'],
            ['key' => 'period_from', 'label' => 'Cut-off', 'value' => fn ($r) => range_label($r['period_from'], $r['period_to']), 'html' => fn ($r) => e(range_label($r['period_from'], $r['period_to']))],
            ['key' => 'pay_date', 'label' => 'Pay-out', 'type' => 'date'],
            ['key' => 'employees', 'label' => 'Employees', 'type' => 'int'],
            ['key' => 'total_gross', 'label' => 'Gross pay', 'type' => 'money', 'total' => true],
            ['key' => 'total_deductions', 'label' => 'Deductions', 'type' => 'money', 'total' => true],
            ['key' => 'total_net', 'label' => 'Net pay', 'type' => 'money', 'total' => true],
            ['key' => 'paid', 'label' => 'Paid out', 'value' => fn ($r) => $r['paid'] . ' / ' . $r['employees']],
            ['key' => 'status', 'label' => 'Status', 'value' => fn ($r) => Payroll::STATUSES[$r['status']] ?? $r['status'],
                'html' => fn ($r) => badge(Payroll::STATUSES[$r['status']] ?? $r['status'], ['draft' => 'amber', 'posted' => 'blue', 'paid' => 'green', 'void' => 'gray'][$r['status']] ?? 'gray')],
        ];
        if ($x = Table::export($req, 'payroll', 'Payroll', 'All cut-offs', $columns, $rows)) return $x;
        return view('payroll/index', ['title' => 'Payroll', 'rows' => $rows, 'columns' => $columns,
            'link' => fn ($r) => url('/payroll/' . $r['id']), 'suggest' => Payroll::suggestPeriod()]);
    }

    public function create(Request $req)
    {
        $s = Payroll::suggestPeriod();
        $emps = DB::all('SELECT id, full_name, position, pay_type, monthly_rate, daily_rate, biometric_id FROM employees WHERE active = 1 ORDER BY full_name');
        return view('payroll/new', ['title' => 'New payroll', 'suggest' => $s, 'employees' => $emps,
            'months' => array_merge(Payroll::cutoffs(date('Y-m-01', strtotime('-1 month'))), Payroll::cutoffs(date('Y-m-01')), Payroll::cutoffs(date('Y-m-01', strtotime('+1 month'))))]);
    }

    public function store(Request $req): Response
    {
        $id = Payroll::create((string) $req->input('period_from'), (string) $req->input('period_to'), (string) $req->input('pay_date'), $req->input('notes'));
        flash('success', 'Payroll computed from the attendance. Check each employee, add bonuses or deductions, then approve it.');
        return redirect('/payroll/' . $id);
    }

    public function show(Request $req, string $id)
    {
        $r = Payroll::find((int) $id);
        $slips = Payroll::slips((int) $id);
        $lines = [];
        foreach (DB::all('SELECT l.* FROM payslip_lines l JOIN payslips s ON s.id = l.payslip_id WHERE s.run_id = ?', [$id]) as $l) {
            $lines[(int) $l['payslip_id']][$l['type'] === 'cash_advance' ? 'ca' : ($l['kind'] === 'bonus' ? 'bonus' : (in_array($l['type'], ['sss', 'philhealth', 'pagibig', 'tax'], true) ? 'gov' : 'other'))][] = (float) $l['amount'];
        }
        foreach ($slips as &$s) {
            $g = $lines[(int) $s['id']] ?? [];
            $s['ca'] = array_sum($g['ca'] ?? []);
            $s['gov'] = array_sum($g['gov'] ?? []);
            $s['other'] = array_sum($g['other'] ?? []);
            if ($s['paid_at']) $s['_class'] = 'paid-row';
        }
        unset($s);
        $hm = fn ($v) => (float) $v ? rtrim(rtrim(number_format((float) $v, 2), '0'), '.') : '';
        $columns = [
            ['key' => 'full_name', 'label' => 'Employee', 'html' => fn ($s) => '<span class="bold">' . e($s['full_name']) . '</span><div class="muted small">' . e(trim(($s['position'] ?? '') . ' · ' . ($s['pay_type'] === 'daily' ? 'daily ' . money($s['daily_rate']) : 'monthly ' . money($s['monthly_rate'])), ' ·')) . '</div>'],
            ['key' => 'days_present', 'label' => 'Days', 'value' => fn ($s) => (float) $s['days_present'], 'html' => fn ($s) => '<span title="present">' . $hm($s['days_present']) . '</span>' . ((float) $s['days_absent'] ? ' <span class="text-red small" title="absent">−' . $hm($s['days_absent']) . ' abs</span>' : '')],
            ['key' => 'late_minutes', 'label' => 'Late (min)', 'type' => 'int'],
            ['key' => 'undertime_hours', 'label' => 'UT (hrs)', 'value' => fn ($s) => (float) $s['undertime_hours'], 'html' => fn ($s) => e($hm($s['undertime_hours']))],
            ['key' => 'ot_hours', 'label' => 'OT (hrs)', 'value' => fn ($s) => (float) $s['ot_hours'], 'html' => fn ($s) => e($hm($s['ot_hours']))],
            ['key' => 'basic_pay', 'label' => 'Basic pay', 'type' => 'money', 'total' => true],
            ['key' => 'absence_deduction', 'label' => 'Absences', 'type' => 'money', 'total' => true],
            ['key' => 'undertime_deduction', 'label' => 'Undertime', 'type' => 'money', 'total' => true],
            ['key' => 'ot_pay', 'label' => 'OT pay', 'type' => 'money', 'total' => true],
            ['key' => 'bonus_total', 'label' => 'Bonus', 'type' => 'money', 'total' => true],
            ['key' => 'gross_pay', 'label' => 'Gross', 'type' => 'money', 'total' => true],
            ['key' => 'ca', 'label' => 'Cash adv.', 'type' => 'money', 'total' => true],
            ['key' => 'gov', 'label' => 'SSS/PH/HDMF/tax', 'type' => 'money', 'total' => true],
            ['key' => 'other', 'label' => 'Other ded.', 'type' => 'money', 'total' => true],
            ['key' => 'net_pay', 'label' => 'Net pay', 'type' => 'money', 'total' => true, 'html' => fn ($s) => '<b class="' . ((float) $s['net_pay'] < 0 ? 'text-red' : '') . '">' . money($s['net_pay']) . '</b>'],
            ['key' => 'paid_at', 'label' => 'Paid', 'value' => fn ($s) => $s['paid_at'] ? fmt_date($s['paid_at']) . ' · ' . (Payroll::PAY_METHODS[$s['paid_method']] ?? '') : '',
                'html' => fn ($s) => $s['paid_at'] ? badge('paid ' . date('M j', strtotime($s['paid_at'])), 'green') : ''],
        ];
        $title = "Payroll {$r['run_no']}";
        if ($x = Table::export($req, 'payroll_' . $r['run_no'], "Payroll register {$r['run_no']}", range_label($r['period_from'], $r['period_to']) . ' · pay-out ' . fmt_date($r['pay_date']), $columns, $slips)) return $x;
        return view('payroll/show', ['title' => $title, 'r' => $r, 'slips' => $slips, 'columns' => $columns, 'banks' => Banks::options(),
            'unpaid' => array_values(array_filter($slips, fn ($s) => !$s['paid_at'] && (float) $s['net_pay'] > 0))]);
    }

    public function payslip(Request $req, string $id)
    {
        $s = Payroll::slip((int) $id);
        if (!Payroll::canSee($s)) throw HttpException::forbidden();
        $edit = $s['run_status'] === 'draft' && Auth::can('payroll.manage');
        $emp = DB::one('SELECT * FROM employees WHERE id = ?', [(int) $s['employee_id']]) ?: [];
        $data = ['title' => 'Payslip · ' . $s['full_name'], 's' => $s, 'edit' => $edit, 'settings' => Settings::all(), 'cfg' => Payroll::settings(),
            'schedule' => Payroll::scheduleLabel($emp),
            'notes' => Auth::can('payroll.view', 'payroll.manage', 'payroll.post') ? StaffNotes::list($s['period_from'], $s['period_to'], (int) $s['employee_id']) : [],
            'advances' => $edit ? DB::all("SELECT id, doc_no, balance FROM cash_advances WHERE employee_id = ? AND status = 'approved' AND balance > 0", [$s['employee_id']]) : []];
        if ($req->query('print')) return view('payroll/payslip_print', $data + ['slips' => [$s]], 'blank');
        return view('payroll/payslip', $data);
    }

    /** Every payslip of a payroll, one per page (print / save as PDF). */
    public function printAll(Request $req, string $id)
    {
        $r = Payroll::find((int) $id);
        $slips = array_map(fn ($s) => Payroll::slip((int) $s['id']), Payroll::slips((int) $id));
        return view('payroll/payslip_print', ['title' => "Payslips {$r['run_no']}", 'slips' => $slips, 'settings' => Settings::all()], 'blank');
    }

    public function addLine(Request $req, string $id): Response
    {
        Payroll::addLine((int) $id, $req->all());
        flash('success', ($req->input('kind') === 'bonus' ? 'Bonus' : 'Deduction') . ' added.');
        return back();
    }

    public function updateLine(Request $req, string $id): Response
    {
        Payroll::updateLine((int) $id, num($req->input('amount')));
        flash('success', 'Amount changed.');
        return back();
    }

    public function removeLine(Request $req, string $id): Response
    {
        Payroll::removeLine((int) $id);
        flash('success', 'Line removed.');
        return back();
    }

    public function removeSlip(Request $req, string $id): Response
    {
        $s = Payroll::slip((int) $id);
        Payroll::removeSlip((int) $id);
        flash('success', "{$s['full_name']} removed from this payroll.");
        return redirect('/payroll/' . $s['run_id']);
    }

    public function undoPayout(Request $req, string $id): Response
    {
        $n = Payroll::undoPayout((int) $id, (string) $req->input('reason'));
        flash('success', "Payout undone ($n payslip(s) are unpaid again).");
        return back();
    }

    public function recompute(Request $req, string $id): Response
    {
        Payroll::recompute((int) $id);
        flash('success', 'Recomputed from the latest attendance and rates. Bonuses and deductions were kept.');
        return back();
    }

    public function post(Request $req, string $id): Response
    {
        Payroll::post((int) $id);
        flash('success', 'Payroll approved and posted to the books. Employees can now see their payslips. Next: pay out the salaries.');
        return back();
    }

    public function payout(Request $req, string $id): Response
    {
        $ids = array_filter(array_map('intval', (array) $req->input('payslips', [])));
        $n = Payroll::payout((int) $id, $ids, (string) $req->input('method'), (int) $req->input('bank_account_id') ?: null, $req->input('pay_date'), $req->input('reference'));
        flash('success', "Salaries paid out for $n employee(s).");
        return back();
    }

    public function void(Request $req, string $id): Response
    {
        Payroll::void((int) $id, (string) $req->input('reason'));
        flash('success', 'Payroll voided.');
        return back();
    }

    public function delete(Request $req, string $id): Response
    {
        Payroll::delete((int) $id);
        flash('success', 'Draft payroll deleted.');
        return redirect('/payroll');
    }

    public function settings(Request $req)
    {
        return view('payroll/settings', ['title' => 'Payroll settings', 'cfg' => Payroll::settings()]);
    }

    public function saveSettings(Request $req): Response
    {
        Payroll::saveSettings($req->all());
        flash('success', 'Payroll settings saved. Recompute draft payrolls to apply them.');
        return back();
    }

    public function mine(Request $req)
    {
        $emp = Auth::user()['employee_id'] ?? null;
        return view('payroll/mine', ['title' => 'My payslips', 'rows' => Payroll::mine(), 'linked' => (bool) $emp,
            'employee' => $emp ? DB::one('SELECT full_name, emp_no, position FROM employees WHERE id = ?', [$emp]) : null]);
    }
}
