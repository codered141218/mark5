<?php
namespace App\Controllers\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\Finance\CashAdvances;
use App\Services\Finance\Payroll;
use App\Services\Settings;

/** Employee self-service: my payslips and my cash advances (phone-friendly). Any signed-in user linked to an employee record. */
class MyPayController
{
    private function employee(): ?array
    {
        $id = (int) (Auth::user()['employee_id'] ?? 0);
        return $id ? DB::one('SELECT * FROM employees WHERE id = ?', [$id]) : null;
    }

    public function index(Request $req): string
    {
        $emp = $this->employee();
        if (!$emp) return view('people/my_pay/index', ['title' => 'My Pay', 'emp' => null, 'slips' => [], 'advances' => [], 'balance' => 0]);
        $slips = Payroll::payslipsFor((int) $emp['id']);
        $advances = DB::all('SELECT c.*, (SELECT COALESCE(SUM(amount), 0) FROM ca_repayments r WHERE r.advance_id = c.id AND r.status = \'posted\') AS repaid
             FROM cash_advances c WHERE c.employee_id = ? ORDER BY c.request_date DESC, c.id DESC LIMIT 12', [(int) $emp['id']]);
        $balance = (float) DB::value("SELECT COALESCE(SUM(balance), 0) FROM cash_advances WHERE employee_id = ? AND status = 'approved'", [(int) $emp['id']]);
        $ytd = (float) DB::value("SELECT COALESCE(SUM(l.net_pay), 0) FROM payroll_lines l JOIN payroll_runs r ON r.id = l.run_id WHERE l.employee_id = ? AND r.status = 'posted' AND YEAR(r.pay_date) = YEAR(CURDATE())", [(int) $emp['id']]);
        return view('people/my_pay/index', ['title' => 'My Pay', 'emp' => $emp, 'slips' => $slips, 'advances' => $advances, 'balance' => $balance, 'ytd' => $ytd,
            'statuses' => CashAdvances::STATUSES, 'canRequest' => Auth::can('ca.request')]);
    }

    public function payslip(Request $req, string $id): string
    {
        $emp = $this->employee();
        $l = Payroll::payslip((int) $id);
        if (!$emp || !$l || (int) $l['employee_id'] !== (int) $emp['id']) throw HttpException::notFound('Payslip');
        $days = Payroll::attendance($l['period_from'], $l['period_to'], (int) $emp['id'])[(int) $emp['id']] ?? [];
        return view('people/my_pay/payslip', ['title' => 'Payslip ' . $l['doc_no'], 'l' => $l, 'run' => $l, 'days' => $days,
            'business' => Settings::get('business_name', 'Mark5')], 'blank');
    }
}
