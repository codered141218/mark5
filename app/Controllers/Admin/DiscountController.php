<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Discounts;

/** Discount presets shown on the POS (Administration → Discounts). */
class DiscountController
{
    public function index(Request $req)
    {
        $rows = Discounts::all();
        $columns = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'kind', 'label' => 'Type', 'value' => fn ($r) => Discounts::KINDS[$r['kind']], 'html' => fn ($r) => e(Discounts::KINDS[$r['kind']])],
            ['key' => 'value', 'label' => 'Value', 'value' => fn ($r) => self::valueLabel($r), 'html' => fn ($r) => e(self::valueLabel($r))],
            ['key' => 'scope', 'label' => 'Can be applied to', 'value' => fn ($r) => Discounts::SCOPES[$r['scope']], 'html' => fn ($r) => e(Discounts::SCOPES[$r['scope']])],
            ['key' => 'requires_approval', 'label' => 'Manager approval', 'value' => fn ($r) => $r['requires_approval'] ? 'Yes' : 'No', 'html' => fn ($r) => $r['requires_approval'] ? badge('required', 'amber') : badge('not needed', 'gray')],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => view('admin/discounts/_actions', ['r' => $r], null)],
        ];
        if ($x = Table::export($req, 'discounts', 'Discount presets', '', $columns, $rows)) return $x;
        return view('admin/discounts/index', ['title' => 'Discounts', 'rows' => $rows, 'columns' => $columns,
            'bulk' => ['actions' => [['key' => 'delete', 'label' => 'Remove', 'url' => url('/admin/discounts/bulk'), 'danger' => true,
                'confirm' => 'Remove {n} discount(s)? Discounts already used on receipts are deactivated instead.']]]]);
    }

    public static function valueLabel(array $r): string
    {
        if (in_array($r['kind'], ['sc', 'pwd'], true)) return \App\Services\Settings::get('sc_discount_rate', 20) . '% + VAT-exempt';
        if ($r['value'] === null) return 'Cashier enters';
        return $r['kind'] === 'percent' ? rtrim(rtrim(number_format($r['value'], 2), '0'), '.') . '%' : peso($r['value']);
    }

    public function save(Request $req): Response
    {
        Discounts::save($req->all());
        flash('success', 'Discount saved.');
        return redirect('/admin/discounts');
    }

    public function bulk(Request $req): Response
    {
        bulk_apply((array) $req->input('ids', []), 'discount', fn (int $id) => Discounts::delete($id));
        return redirect('/admin/discounts');
    }

    public function delete(Request $req, string $id): Response
    {
        Discounts::delete((int) $id);
        flash('success', 'Discount removed (kept as inactive if it was already used).');
        return redirect('/admin/discounts');
    }
}
