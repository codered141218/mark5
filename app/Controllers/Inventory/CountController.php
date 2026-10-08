<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\InventoryCounts;
use App\Services\Settings;

/** Physical inventory counts: session list, count sheet (enter / save / post / cancel), printable sheet and Excel export. */
class CountController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $rows = DB::all(
            'SELECT s.*, c.name AS category_name, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM count_lines l WHERE l.session_id = s.id) AS line_count,
                    (SELECT COUNT(*) FROM count_lines l WHERE l.session_id = s.id AND l.counted_qty IS NOT NULL) AS counted_count
             FROM count_sessions s LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN users u ON u.id = s.created_by
             WHERE s.count_date BETWEEN ? AND ? ORDER BY s.count_date DESC, s.id DESC',
            [$from, $to]
        );
        foreach ($rows as &$r) {
            $r['scope'] = $r['category_name'] ?: 'All stocked items';
            $r['progress'] = "{$r['counted_count']} / {$r['line_count']}";
            $r['variance'] = $r['status'] === 'posted' ? (float) $r['total_variance_value'] : null;
            $r['_class'] = $r['status'] === 'cancelled' ? 'muted-row' : '';
        }
        unset($r);

        $columns = [
            ['key' => 'doc_no', 'label' => 'Count no.', 'html' => fn ($r) => '<b>' . e($r['doc_no']) . '</b>'],
            ['key' => 'count_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'scope', 'label' => 'Scope', 'html' => fn ($r) => $r['category_name'] ? e($r['category_name']) : '<span class="muted">All stocked items</span>'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'progress', 'label' => 'Counted', 'align' => 'right', 'html' => fn ($r) => '<span class="'
                . ($r['line_count'] && $r['counted_count'] === $r['line_count'] ? 'text-green bold' : '') . '">' . e($r['progress']) . '</span>'],
            ['key' => 'variance', 'label' => 'Variance value', 'type' => 'money', 'total' => true, 'html' => fn ($r) => $r['variance'] === null
                ? '<span class="muted">—</span>' : '<span class="' . self::tone($r['variance']) . '">' . money($r['variance']) . '</span>'],
            ['key' => 'created_by_name', 'label' => 'Started by'],
            ['key' => 'notes', 'label' => 'Notes', 'html' => fn ($r) => '<span class="muted small">' . e($r['notes']) . '</span>'],
        ];
        if ($x = Table::export($req, 'inventory-counts', 'Inventory Count Sessions', range_label($from, $to), $columns, $rows)) return $x;

        return view('inventory/counts/index', [
            'title' => 'Inventory Count', 'rows' => $rows, 'columns' => $columns, 'from' => $from, 'to' => $to,
            'categories' => array_column(DB::all('SELECT id, name FROM categories WHERE active = 1 ORDER BY sort_order, name'), 'name', 'id'),
        ]);
    }

    public function start(Request $req): Response
    {
        $id = InventoryCounts::start($req->input('count_date') ?: null, ((int) $req->input('category_id')) ?: null, $req->input('notes'));
        $s = InventoryCounts::find($id);
        flash('success', "{$s['doc_no']} started with " . count($s['lines']) . ' items.');
        return redirect("/inventory/counts/$id");
    }

    public function show(Request $req, string $id)
    {
        $s = InventoryCounts::find((int) $id);
        $open = $s['status'] === 'open';
        $rows = [];
        $totals = ['counted' => 0, 'short' => 0.0, 'over' => 0.0, 'net' => 0.0, 'sys_value' => 0.0];
        foreach ($s['lines'] as $l) {
            // While open, variance is measured against live stock; once posted, the stored snapshot is shown.
            $sys = (float) ($open ? $l['current_qty'] : $l['system_qty']);
            $cost = (float) ($open ? $l['current_cost'] : $l['unit_cost']);
            $counted = $l['counted_qty'] === null ? null : (float) $l['counted_qty'];
            $var = $counted === null ? null : ($open ? r4($counted - $sys) : (float) $l['variance']);
            $value = $var === null ? null : ($open ? r2($var * $cost) : (float) $l['variance_value']);
            $rows[] = $l + ['category' => $l['category_name'] ?: 'Uncategorized', 'sys' => $sys, 'cost' => $cost, 'counted' => $counted, 'var' => $var, 'value' => $value];
            if ($counted !== null) $totals['counted']++;
            if ($value !== null) {
                $totals[$value < 0 ? 'short' : 'over'] += $value;
                $totals['net'] += $value;
            }
            $totals['sys_value'] += $sys * $cost;
        }

        $columns = [
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'item_name', 'label' => 'Item'],
            ['key' => 'uom', 'label' => 'Unit'],
            ['key' => 'sys', 'label' => 'System qty', 'type' => 'qty'],
            ['key' => 'counted', 'label' => 'Counted', 'type' => 'qty'],
            ['key' => 'var', 'label' => 'Variance', 'type' => 'qty'],
            ['key' => 'cost', 'label' => 'Unit cost', 'type' => 'money'],
            ['key' => 'value', 'label' => 'Variance value', 'type' => 'money', 'total' => true],
        ];
        $subtitle = fmt_date($s['count_date']) . ' · ' . ($s['category_name'] ?: 'All stocked items') . ' · ' . $s['status'];
        if ($x = Table::export($req, "count-{$s['doc_no']}", "Inventory Count {$s['doc_no']}", $subtitle, $columns, $rows)) return $x;

        return view('inventory/counts/sheet', [
            'title' => $s['doc_no'], 's' => $s, 'rows' => $rows, 'totals' => $totals, 'open' => $open,
            'business' => Settings::get('business_name'),
        ]);
    }

    public function save(Request $req, string $id): Response
    {
        InventoryCounts::save((int) $id, (array) $req->input('counted', []));
        flash('success', 'Count saved.');
        return redirect("/inventory/counts/$id");
    }

    /** Saves the quantities on the sheet, then posts the count. */
    public function post(Request $req, string $id): Response
    {
        $counted = $req->input('counted');
        if (is_array($counted)) InventoryCounts::save((int) $id, $counted);
        InventoryCounts::post((int) $id);
        flash('success', 'Count posted. Stock was adjusted to the counted quantities.');
        return redirect("/inventory/counts/$id");
    }

    public function cancel(Request $req, string $id): Response
    {
        InventoryCounts::cancel((int) $id);
        flash('success', 'Count session cancelled. Stock was not adjusted.');
        return redirect("/inventory/counts/$id");
    }

    /** CSS class for a signed variance: red for shortage, green for overage. */
    public static function tone(?float $v): string
    {
        if ($v === null || abs($v) < 0.00005) return '';
        return $v < 0 ? 'text-red' : 'text-green';
    }
}
