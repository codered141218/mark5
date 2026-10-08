<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Accounts;
use App\Services\Finance\Banks;

/** Banks & e-wallets: balance cards, add / edit bank, transactions (money in / out / transfer) and voids. */
class BankController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $banks = Banks::all();
        $bankId = (int) $req->query('bank_id') ?: null;
        $selected = $bankId ? current(array_filter($banks, fn ($b) => (int) $b['id'] === $bankId)) ?: null : null;
        $rows = Banks::txns($from, $to, $bankId);
        foreach ($rows as &$t) {
            [$t['money_in'], $t['money_out']] = Banks::moneyInOut($t, $bankId);
            $t['bank'] = $t['txn_type'] === 'transfer' ? "{$t['bank_name']} → {$t['transfer_bank_name']}" : $t['bank_name'];
            $t['detail'] = implode(' — ', array_filter([$t['txn_type'] === 'transfer' ? 'Bank transfer' : $t['counter_account_name'], $t['description']]));
            if ($t['status'] === 'void') $t['_class'] = 'muted-row';
        }
        unset($t);

        $posted = fn ($key) => fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r[$key], $rows));
        $colors = ['deposit' => 'green', 'withdrawal' => 'amber', 'transfer' => 'blue'];
        $columns = [
            ['key' => 'txn_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'doc_no', 'label' => 'Doc no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['doc_no']) . '</span>'],
            ['key' => 'bank', 'label' => 'Bank'],
            ['key' => 'txn_type', 'label' => 'Type', 'value' => fn ($r) => Banks::TXN_TYPES[$r['txn_type']], 'html' => fn ($r) => badge(Banks::TXN_TYPES[$r['txn_type']], $colors[$r['txn_type']])],
            ['key' => 'detail', 'label' => 'Description / counter account'],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'money_in', 'label' => 'Money in', 'type' => 'money', 'total' => $posted('money_in'), 'html' => fn ($r) => $r['money_in'] ? '<span class="text-green">' . peso($r['money_in']) . '</span>' : ''],
            ['key' => 'money_out', 'label' => 'Money out', 'type' => 'money', 'total' => $posted('money_out'), 'html' => fn ($r) => $r['money_out'] ? '<span class="text-red">' . peso($r['money_out']) . '</span>' : ''],
            ['key' => 'bank_charges', 'label' => 'Charges', 'type' => 'money', 'total' => $posted('bank_charges'), 'html' => fn ($r) => (float) $r['bank_charges'] ? money($r['bank_charges']) : ''],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => $r['status'] === 'posted' && can('finance.banks')
                ? view('finance/partials/void', ['action' => "/finance/banks/txns/{$r['id']}/void", 'title' => "Void {$r['doc_no']} (" . strtolower(Banks::TXN_TYPES[$r['txn_type']]) . ' of ' . peso($r['amount']) . ')? The journal entry will be reversed.'], null)
                : ''],
        ];
        $subtitle = range_label($from, $to) . ($selected ? ' · ' . Banks::label($selected) : '');
        if ($x = Table::export($req, 'bank-transactions', 'Bank Transactions', $subtitle, $columns, $rows)) return $x;

        return view('finance/banks', [
            'title' => 'Banks', 'banks' => $banks, 'bankId' => $bankId, 'rows' => $rows, 'columns' => $columns, 'from' => $from, 'to' => $to,
            'bankOptions' => Banks::options(), 'accounts' => Accounts::grouped(),
        ]);
    }

    public function save(Request $req): Response
    {
        $id = (int) $req->input('id');
        if ($id) Banks::update($id, $req->all());
        else Banks::create($req->all());
        flash('success', $id ? 'Bank account saved.' : 'Bank account added.');
        return back();
    }

    public function storeTxn(Request $req): Response
    {
        Banks::postTxn($req->all());
        flash('success', 'Bank transaction posted.');
        return back();
    }

    public function voidTxn(Request $req, string $id): Response
    {
        Banks::voidTxn((int) $id, $req->input('reason'));
        flash('success', 'Bank transaction voided.');
        return back();
    }
}
