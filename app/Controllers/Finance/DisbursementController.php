<?php
namespace App\Controllers\Finance;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Accounts;
use App\Services\Finance\Banks;
use App\Services\Finance\Disbursements;

/** Cash & Finance → Payments & Expenses (record / void) and Cash & Bank Position. */
class DisbursementController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $source = (string) $req->query('source', '');
        $rows = Disbursements::list($from, $to, $source);
        foreach ($rows as &$r) if ($r['status'] === 'void') $r['_class'] = 'muted-row';
        unset($r);
        $posted = array_filter($rows, fn ($r) => $r['status'] === 'posted');
        $bySource = [];
        foreach ($posted as $r) $bySource[$r['pay_from']] = ($bySource[$r['pay_from']] ?? 0) + (float) $r['amount'];
        $paidFrom = fn ($r) => $r['pay_from'] === 'bank' ? ($r['bank_name'] ?: 'Bank') : Disbursements::SOURCES[$r['pay_from']];
        $columns = [
            ['key' => 'txn_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'doc_no', 'label' => 'Voucher', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['doc_no']) . '</span>'],
            ['key' => 'payee', 'label' => 'Paid to', 'html' => fn ($r) => '<b>' . e($r['payee'] ?: '—') . '</b>' . ($r['description'] ? '<div class="muted small">' . e($r['description']) . '</div>' : '')],
            ['key' => 'account_name', 'label' => 'For (account)', 'value' => fn ($r) => "{$r['account_code']} · {$r['account_name']}", 'html' => fn ($r) => e("{$r['account_code']} · {$r['account_name']}")],
            ['key' => 'pay_from', 'label' => 'Paid from', 'value' => $paidFrom, 'html' => fn ($r) => e($paidFrom($r))],
            ['key' => 'reference', 'label' => 'OR / ref no'],
            ['key' => 'vat_amount', 'label' => 'Input VAT', 'type' => 'money'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'total' => fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r['amount'], $rows))],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'Recorded by'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => $r['status'] === 'posted'
                ? view('finance/partials/void', ['action' => "/finance/payments/{$r['id']}/void", 'title' => "Void {$r['doc_no']} (" . peso($r['amount']) . ')? The GL posting will be reversed.'], null) : ''],
        ];
        if ($x = Table::export($req, 'payments-expenses', 'Payments & Expenses', range_label($from, $to), $columns, $rows)) return $x;
        return view('finance/payments', [
            'title' => 'Payments & Expenses', 'from' => $from, 'to' => $to, 'source' => $source, 'rows' => $rows, 'columns' => $columns,
            'bySource' => $bySource, 'total' => array_sum(array_column($posted, 'amount')),
            'accounts' => Accounts::grouped(['expense', 'asset', 'liability', 'equity']), 'banks' => Banks::options(),
            'suppliers' => array_column(DB::all('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name'), 'name', 'id'),
        ]);
    }

    public function store(Request $req): Response
    {
        $id = Disbursements::create($req->all());
        flash('success', 'Payment recorded (' . DB::value('SELECT doc_no FROM disbursements WHERE id = ?', [$id]) . ') and posted to the books.');
        return back();
    }

    public function void(Request $req, string $id): Response
    {
        Disbursements::void((int) $id, (string) $req->input('reason', ''));
        flash('success', 'Payment voided; the GL posting was reversed.');
        return back();
    }

    public function position(Request $req)
    {
        $asOf = is_date($req->query('as_of')) ? $req->query('as_of') : today();
        return view('finance/cash_position', ['title' => 'Cash & Bank Position', 'asOf' => $asOf, 'p' => Disbursements::position($asOf)]);
    }
}
