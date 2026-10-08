<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Table;
use App\Services\Finance\Accounts;
use App\Services\Finance\Aging;
use App\Services\Finance\Banks;
use App\Services\Finance\Partners;

/**
 * Shared list / aging / detail pages for accounts payable (bills) and receivable (invoices).
 * Subclasses provide the wording and field names in cfg() and the service class in $service;
 * both services offer list(), find(), stats() and aging().
 */
abstract class PartyDocController
{
    /** Service class: Payables::class or Receivables::class */
    protected string $service;

    abstract protected function cfg(): array;

    public function index(Request $req)
    {
        $cfg = $this->cfg();
        $s = $this->service;
        if ($req->query('tab') === 'aging') return $this->aging($req, $cfg);

        [$from, $to] = date_range($req);
        $status = $req->query('status', '');
        $partyId = (int) $req->query($cfg['partyId']) ?: null;
        $rows = $s::list(['from' => $from, 'to' => $to, 'status' => $status, $cfg['partyId'] => $partyId]);
        $today = today();
        foreach ($rows as &$r) {
            $r['overdue'] = in_array($r['status'], ['open', 'partial'], true) && $r['due_date'] && $r['due_date'] < $today;
            if ($r['status'] === 'void') $r['_class'] = 'muted-row';
        }
        unset($r);

        $posted = fn ($key) => fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r[$key], $rows));
        $columns = [
            ['key' => $cfg['no'], 'label' => $cfg['doc'] . ' no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r[$cfg['no']]) . '</span>'],
            ['key' => $cfg['date'], 'label' => 'Date', 'type' => 'date'],
            ['key' => 'due_date', 'label' => 'Due date', 'type' => 'date',
                'html' => fn ($r) => '<span class="' . ($r['overdue'] ? 'text-red bold' : '') . '">' . e(fmt_date($r['due_date'])) . ($r['overdue'] ? ' (overdue)' : '') . '</span>'],
            ['key' => $cfg['partyName'], 'label' => $cfg['party']],
            ['key' => 'ref_no', 'label' => 'Ref no'],
            ['key' => 'description', 'label' => 'Description',
                'html' => fn ($r) => $r['description'] ? e($r['description']) : ($r['source_type'] === $cfg['autoSource'] ? '<span class="muted">' . e($cfg['autoLabel']) . '</span>' : '')],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'total' => $posted('amount')],
            ['key' => 'paid_amount', 'label' => $cfg['paidLabel'], 'type' => 'money', 'total' => $posted('paid_amount')],
            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'total' => $posted('balance')],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
        ];
        if ($x = Table::export($req, $cfg['export'], $cfg['title'] . ' — ' . $cfg['docs'], range_label($from, $to), $columns, $rows)) return $x;

        return view('finance/docs/index', [
            'title' => $cfg['title'], 'cfg' => $cfg, 'rows' => $rows, 'columns' => $columns, 'from' => $from, 'to' => $to,
            'status' => $status, 'partyId' => $partyId, 'stats' => $s::stats(), 'parties' => Partners::list($cfg['partyTable']),
            'accounts' => Accounts::grouped($cfg['accountTypes']),
        ]);
    }

    public function show(Request $req, string $id)
    {
        $cfg = $this->cfg();
        $s = $this->service;
        $d = $s::find((int) $id);
        $payments = $d[$cfg['payments']];
        foreach ($payments as &$p) if ($p['status'] === 'void') $p['_class'] = 'muted-row';
        unset($p);
        $methods = $cfg['methods'];
        $columns = [
            ['key' => 'doc_no', 'label' => 'Doc no'],
            ['key' => $cfg['payDate'], 'label' => 'Date', 'type' => 'date'],
            ['key' => 'method', 'label' => 'Method', 'value' => fn ($p) => ($methods[$p['method']] ?? $p['method']) . ($p['bank_name'] ? ' · ' . $p['bank_name'] : ''),
                'html' => fn ($p) => e(($methods[$p['method']] ?? $p['method']) . ($p['bank_name'] ? ' · ' . $p['bank_name'] : ''))],
            ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'total' => fn ($rows) => array_sum(array_map(fn ($r) => $r['status'] === 'void' ? 0 : (float) $r['amount'], $rows))],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'By'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($p) => $p['status'] === 'posted'
                ? view('finance/partials/void', ['action' => $cfg['base'] . '/' . $cfg['paymentPath'] . '/' . $p['id'] . '/void',
                    'title' => "Void {$p['doc_no']} ({$cfg['paymentWord']} of " . peso($p['amount']) . ")? The {$cfg['docLower']} balance goes back up."], null)
                : ''],
        ];
        if ($x = Table::export($req, $d[$cfg['no']], $cfg['doc'] . ' ' . $d[$cfg['no']] . ' — ' . $cfg['paymentWord'] . 's', $d[$cfg['partyName']], $columns, $payments)) return $x;

        return view('finance/docs/show', ['title' => $cfg['doc'] . ' ' . $d[$cfg['no']], 'cfg' => $cfg, 'd' => $d, 'payments' => $payments,
            'columns' => $columns, 'banks' => Banks::options()]);
    }

    private function aging(Request $req, array $cfg)
    {
        $s = $this->service;
        [, $asOf] = date_range($req);
        $rows = $s::aging($asOf);
        $columns = [['key' => 'party', 'label' => $cfg['party']]];
        foreach (Aging::BUCKETS as $key => $label) $columns[] = ['key' => $key, 'label' => $label, 'type' => 'money', 'total' => true];
        $columns[] = ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'total' => true, 'html' => fn ($r) => '<span class="bold">' . money($r['total']) . '</span>'];
        if ($x = Table::export($req, $cfg['export'] . '-aging', $cfg['title'] . ' Aging', 'As of ' . fmt_date($asOf), $columns, $rows)) return $x;
        return view('finance/docs/aging', ['title' => $cfg['title'] . ' Aging', 'cfg' => $cfg, 'rows' => $rows, 'columns' => $columns, 'asOf' => $asOf]);
    }
}
