<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Banks;
use App\Services\Finance\CashAdvances;
use App\Services\Finance\Employees;

/** Cash advances (vale): list with status tabs, request, detail with approve / reject / cancel / repayments. */
class CashAdvanceController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        if (!is_date($req->query('from'))) $from = date('Y-01-01'); // default: this year
        $status = isset(CashAdvances::STATUSES[$req->query('status')]) ? $req->query('status') : '';
        $seeAll = CashAdvances::seesAll();
        $employeeId = $seeAll ? ((int) $req->query('employee_id') ?: null) : null;

        $inPeriod = CashAdvances::list(['from' => $from, 'to' => $to, 'employee_id' => $employeeId]);
        $counts = array_count_values(array_column($inPeriod, 'status'));
        $rows = array_values(array_filter($inPeriod, fn ($r) => !$status || $r['status'] === $status));
        foreach ($rows as &$r) if (in_array($r['status'], ['rejected', 'cancelled'], true)) $r['_class'] = 'muted-row';
        unset($r);
        $pending = CashAdvances::list(['status' => 'pending']);
        $outstanding = CashAdvances::list(['status' => 'approved']);
        $released = array_filter($inPeriod, fn ($r) => in_array($r['status'], ['approved', 'settled'], true));
        $stats = [
            'pending' => count($pending), 'pending_amount' => array_sum(array_column($pending, 'amount')),
            'outstanding' => array_sum(array_column($outstanding, 'balance')), 'outstanding_n' => count($outstanding),
            'released' => array_sum(array_column($released, 'amount')),
        ];

        $columns = [
            ['key' => 'doc_no', 'label' => 'Doc no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['doc_no']) . '</span>'],
            ['key' => 'request_date', 'label' => 'Request date', 'type' => 'date'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money',
                'total' => fn ($rows) => array_sum(array_map(fn ($r) => in_array($r['status'], ['rejected', 'cancelled'], true) ? 0 : (float) $r['amount'], $rows))],
            ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'repayment_terms', 'label' => 'Repayment terms'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'total' => true,
                'html' => fn ($r) => $r['status'] === 'approved' ? '<span class="bold">' . peso($r['balance']) . '</span>' : ((float) $r['balance'] ? peso($r['balance']) : '')],
            ['key' => 'requested_by_name', 'label' => 'Requested by'],
            ['key' => 'approved_by_name', 'label' => 'Approved by', 'value' => fn ($r) => in_array($r['status'], ['approved', 'settled', 'rejected'], true) ? $r['approved_by_name'] : '',
                'html' => fn ($r) => in_array($r['status'], ['approved', 'settled', 'rejected'], true) ? e($r['approved_by_name']) : ''],
        ];
        $subtitle = range_label($from, $to) . ($status ? ' · ' . CashAdvances::STATUSES[$status] : '');
        if ($x = Table::export($req, 'cash-advances', 'Cash Advances', $subtitle, $columns, $rows)) return $x;

        return view('people/cash_advances/index', [
            'title' => 'Cash Advances', 'rows' => $rows, 'columns' => $columns, 'from' => $from, 'to' => $to, 'status' => $status,
            'counts' => $counts, 'total' => count($inPeriod), 'stats' => $stats, 'seeAll' => $seeAll, 'employeeId' => $employeeId,
            'employees' => $seeAll ? Employees::list() : [], 'requestFor' => Employees::forRequests(),
        ]);
    }

    public function store(Request $req): Response
    {
        $id = CashAdvances::request($req->all());
        flash('success', 'Request filed — waiting for approval.');
        return redirect('/cash-advances/' . $id);
    }

    public function show(Request $req, string $id)
    {
        $c = CashAdvances::find((int) $id);
        $repayments = $c['repayments'];
        foreach ($repayments as &$r) if ($r['status'] === 'void') $r['_class'] = 'muted-row';
        unset($r);
        $method = fn ($r) => (CashAdvances::REPAY_METHODS[$r['method']] ?? $r['method']) . ($r['bank_name'] ? ' · ' . $r['bank_name'] : '');
        $columns = [
            ['key' => 'doc_no', 'label' => 'Doc no'],
            ['key' => 'pay_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'method', 'label' => 'Method', 'value' => $method, 'html' => fn ($r) => e($method($r))],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'total' => fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r['amount'], $rows))],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'By'],
        ];
        if (can('ca.manage')) {
            $columns[] = ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => $r['status'] === 'posted'
                ? view('finance/partials/void', ['action' => "/cash-advances/repayments/{$r['id']}/void", 'title' => "Void {$r['doc_no']} (repayment of " . peso($r['amount']) . ')? The advance balance goes back up.'], null)
                : ''];
        }
        if ($x = Table::export($req, $c['doc_no'], "Cash advance {$c['doc_no']} — repayments", $c['employee_name'], $columns, $repayments)) return $x;
        return view('people/cash_advances/show', ['title' => 'Cash advance ' . $c['doc_no'], 'c' => $c, 'repayments' => $repayments,
            'columns' => $columns, 'banks' => can('ca.approve', 'ca.manage') ? Banks::options() : []]);
    }

    public function approve(Request $req, string $id): Response
    {
        CashAdvances::approve((int) $id, $req->all());
        flash('success', 'Cash advance approved and released.');
        return redirect('/cash-advances/' . $id);
    }

    public function reject(Request $req, string $id): Response
    {
        CashAdvances::reject((int) $id, $req->input('remarks'));
        flash('success', 'Request rejected.');
        return redirect('/cash-advances/' . $id);
    }

    public function cancel(Request $req, string $id): Response
    {
        CashAdvances::cancel((int) $id);
        flash('success', 'Request cancelled.');
        return redirect('/cash-advances/' . $id);
    }

    public function repay(Request $req, string $id): Response
    {
        CashAdvances::repay((int) $id, $req->all());
        flash('success', 'Repayment recorded.');
        return redirect('/cash-advances/' . $id);
    }

    public function voidRepayment(Request $req, string $id): Response
    {
        CashAdvances::voidRepayment((int) $id, $req->input('reason'));
        flash('success', 'Repayment voided.');
        return back();
    }
}
