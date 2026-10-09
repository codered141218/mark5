<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Accounts;

/** Chart of accounts: list by type with natural-sign balances, add / edit dialog, delete. */
class AccountController
{
    public function index(Request $req)
    {
        $all = Accounts::all();
        $type = isset(Accounts::TYPES[$req->query('type')]) ? $req->query('type') : '';
        $rows = array_values(array_filter($all, fn ($a) => !$type || $a['type'] === $type));
        foreach ($rows as &$r) if (!$r['active']) $r['_class'] = 'muted-row';
        unset($r);
        $counts = array_count_values(array_column($all, 'type'));

        $columns = [
            ['key' => 'code', 'label' => 'Code', 'html' => fn ($r) => '<span class="bold mono">' . e($r['code']) . '</span>'],
            ['key' => 'name', 'label' => 'Account name', 'html' => fn ($r) => e($r['name']) . ($r['description'] ? '<div class="muted small">' . e($r['description']) . '</div>' : '')],
            ['key' => 'type', 'label' => 'Type', 'value' => fn ($r) => Accounts::SINGULAR[$r['type']], 'html' => fn ($r) => e(Accounts::SINGULAR[$r['type']])],
            ['key' => 'subtype', 'label' => 'Subtype', 'html' => fn ($r) => '<span class="muted">' . e(str_replace('_', ' ', (string) $r['subtype'])) . '</span>'],
            ['key' => 'is_system', 'label' => 'System', 'value' => fn ($r) => $r['is_system'] ? 'Yes' : '', 'html' => fn ($r) => $r['is_system'] ? badge('system', 'blue') : ''],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'natural_balance', 'label' => 'Balance', 'type' => 'money', 'total' => $type ? true : null,
                'html' => fn ($r) => '<span class="' . ($r['natural_balance'] < 0 ? 'text-red' : '') . '">' . money($r['natural_balance']) . '</span>'],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('finance/accounts/_actions', ['r' => $r], null)],
        ];
        if ($x = Table::export($req, 'chart-of-accounts', 'Chart of Accounts', $type ? Accounts::TYPES[$type] : 'All accounts', $columns, $rows)) return $x;

        return view('finance/accounts/index', ['title' => 'Chart of Accounts', 'rows' => $rows, 'columns' => $columns, 'type' => $type,
            'counts' => $counts, 'total' => count($all), 'nextCodes' => Accounts::nextCodes()]);
    }

    public function save(Request $req): Response
    {
        $id = (int) $req->input('id');
        if ($id) Accounts::update($id, $req->all());
        else Accounts::create($req->all());
        flash('success', $id ? 'Account saved.' : 'Account created' . (trim((string) $req->input('code')) === '' ? ' with the next free code.' : '.'));
        return back();
    }

    public function delete(Request $req, string $id): Response
    {
        Accounts::delete((int) $id);
        flash('success', 'Account deleted.');
        return back();
    }
}
