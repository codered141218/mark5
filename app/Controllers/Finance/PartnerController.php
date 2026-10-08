<?php
namespace App\Controllers\Finance;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Partners;

/** Suppliers and customers: list with outstanding balance, add / edit, deactivate / reactivate. */
class PartnerController
{
    private const KINDS = [
        'suppliers' => ['one' => 'Supplier', 'title' => 'Suppliers', 'terms' => 0, 'termsHint' => '0 = cash / COD',
            'subtitle' => 'Suppliers you buy from (deliveries and payables). The balance is what is still unpaid.'],
        'customers' => ['one' => 'Customer', 'title' => 'Customers', 'terms' => 30, 'termsHint' => 'Days before an invoice is due',
            'subtitle' => 'Charge-account customers (receivables). The balance is what is still uncollected.'],
    ];

    public function suppliers(Request $req)
    {
        return $this->index($req, 'suppliers');
    }

    public function customers(Request $req)
    {
        return $this->index($req, 'customers');
    }

    /** Create, or update (editing needs partners.manage; adding is also open to AP/AR and receiving staff). */
    public function save(Request $req): Response
    {
        $kind = $this->kind($req);
        if ($req->input('id') && !Auth::can('partners.manage')) throw HttpException::forbidden();
        Partners::save($kind, $req->all());
        flash('success', self::KINDS[$kind]['one'] . ' saved.');
        return back();
    }

    public function setActive(Request $req, string $id): Response
    {
        $kind = $this->kind($req);
        $active = (bool) $req->input('active');
        Partners::setActive($kind, (int) $id, $active);
        flash('success', self::KINDS[$kind]['one'] . ($active ? ' reactivated.' : ' deactivated.'));
        return back();
    }

    private function index(Request $req, string $kind)
    {
        $k = self::KINDS[$kind];
        $showAll = (bool) $req->query('all');
        $rows = Partners::list($kind, $showAll);
        foreach ($rows as &$r) if (!$r['active']) $r['_class'] = 'muted-row';
        unset($r);
        $canEdit = Auth::can('partners.manage');

        $columns = [
            ['key' => 'name', 'label' => 'Name', 'html' => fn ($r) => '<span class="bold">' . e($r['name']) . '</span>'],
            ['key' => 'contact_person', 'label' => 'Contact person'],
            ['key' => 'phone', 'label' => 'Phone'],
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'address', 'label' => 'Address'],
            ['key' => 'tin', 'label' => 'TIN'],
            ['key' => 'terms_days', 'label' => 'Terms (days)', 'type' => 'int'],
        ];
        if ($kind === 'customers') $columns[] = ['key' => 'credit_limit', 'label' => 'Credit limit', 'type' => 'money'];
        $columns[] = ['key' => 'notes', 'label' => 'Notes'];
        $columns[] = ['key' => 'balance', 'label' => 'Balance outstanding', 'type' => 'money', 'total' => true,
            'html' => fn ($r) => '<span class="' . ((float) $r['balance'] > 0 ? 'bold' : 'muted') . '">' . money($r['balance']) . '</span>'];
        if ($showAll) $columns[] = ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')];
        if ($canEdit) $columns[] = ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('finance/partners/_actions', ['r' => $r, 'kind' => $kind], null)];
        if ($x = Table::export($req, $kind, $k['title'], $showAll ? 'Including inactive' : 'Active', $columns, $rows)) return $x;

        return view('finance/partners/index', ['title' => $k['title'], 'k' => $k, 'kind' => $kind, 'rows' => $rows, 'columns' => $columns, 'showAll' => $showAll]);
    }

    private function kind(Request $req): string
    {
        return str_starts_with($req->path, '/finance/customers') ? 'customers' : 'suppliers';
    }
}
