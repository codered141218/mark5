<?php
namespace App\Controllers\Finance;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Accounts;
use App\Services\Finance\Banks;
use App\Services\Finance\PettyCash;

/** Petty cash fund and POS drawer payouts: list, record expense / replenishment, void. */
class PettyCashController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $rows = PettyCash::list($from, $to);
        $stats = ['fund' => 0.0, 'drawer' => 0.0, 'replenish' => 0.0];
        foreach ($rows as &$r) {
            if ($r['status'] === 'void') { $r['_class'] = 'muted-row'; continue; }
            $stats[$r['txn_type'] === 'replenish' ? 'replenish' : $r['source']] += $r['amount'];
        }
        unset($r);

        $manage = Auth::can('pettycash.manage');
        $posted = fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r['amount'], $rows));
        $account = fn ($r) => $r['account_code'] ? $r['account_code'] . ' · ' . $r['account_name'] : '';
        $description = fn ($r) => $r['description'] ?: ($r['txn_type'] === 'replenish' ? 'Fund replenishment' . ($r['bank_name'] ? ' from ' . $r['bank_name'] : '') : '');
        $columns = [
            ['key' => 'txn_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'doc_no', 'label' => 'PCV no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['doc_no']) . '</span>'],
            ['key' => 'txn_type', 'label' => 'Type', 'html' => fn ($r) => badge($r['txn_type'], $r['txn_type'] === 'replenish' ? 'blue' : 'gray')],
            ['key' => 'source', 'label' => 'Source', 'value' => fn ($r) => PettyCash::SOURCES[$r['source']], 'html' => fn ($r) => e(PettyCash::SOURCES[$r['source']])],
            ['key' => 'payee', 'label' => 'Payee'],
            ['key' => 'description', 'label' => 'Description', 'value' => $description, 'html' => fn ($r) => e($description($r))],
            ['key' => 'account_name', 'label' => 'Expense account', 'value' => $account, 'html' => fn ($r) => e($account($r))],
            ['key' => 'or_no', 'label' => 'OR / receipt no'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'total' => $posted],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'Recorded by'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => $r['status'] === 'posted' && ($manage || (int) $r['created_by'] === Auth::id())
                ? view('finance/partials/void', ['action' => "/petty-cash/{$r['id']}/void", 'title' => "Void {$r['doc_no']} ({$r['txn_type']} of " . peso($r['amount']) . ')? The GL posting will be reversed.', 'pin' => !$manage], null)
                : ''],
        ];
        if ($x = Table::export($req, 'petty-cash', 'Petty Cash Transactions', range_label($from, $to), $columns, $rows)) return $x;

        return view('finance/petty_cash', [
            'title' => 'Petty Cash', 'from' => $from, 'to' => $to, 'rows' => $rows, 'columns' => $columns, 'stats' => $stats,
            'fundBalance' => PettyCash::fundBalance(), 'expenseAccounts' => Accounts::grouped(['expense']), 'banks' => Banks::options(),
        ]);
    }

    public function store(Request $req): Response
    {
        $type = $req->input('txn_type', 'expense');
        PettyCash::record($req->all());
        flash('success', $type === 'replenish' ? 'Petty cash fund replenished.' : 'Expense recorded and posted to the GL.');
        return back();
    }

    public function void(Request $req, string $id): Response
    {
        PettyCash::void((int) $id, $req->input('reason'), $req->input('pin'));
        flash('success', 'Petty cash transaction voided.');
        return back();
    }
}
