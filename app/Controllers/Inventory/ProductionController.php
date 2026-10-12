<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Inventory;
use App\Services\Items;
use App\Services\Production;

/** Production orders and BOM templates (Inventory → Production, BOM Templates). */
class ProductionController
{
    // ------------------------------------------------------------------ production orders
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $status = isset(Production::STATUSES[$req->query('status')]) ? $req->query('status') : null;
        $rows = Production::list($from, $to, $status);
        foreach ($rows as &$r) if ($r['status'] === 'cancelled') $r['_class'] = 'muted-row';
        unset($r);
        $columns = [
            ['key' => 'doc_no', 'label' => 'No.', 'html' => fn ($r) => '<b>' . e($r['doc_no']) . '</b>'],
            ['key' => 'prod_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'output_name', 'label' => 'Finished product', 'html' => fn ($r) => e($r['output_name']) . ($r['template_name'] ? '<div class="muted small">' . e($r['template_name']) . '</div>' : '')],
            ['key' => 'planned_qty', 'label' => 'Planned', 'type' => 'qty', 'html' => fn ($r) => qty($r['planned_qty']) . ' ' . e($r['output_uom'])],
            ['key' => 'actual_qty', 'label' => 'Produced', 'type' => 'qty', 'html' => fn ($r) => $r['actual_qty'] !== null ? qty($r['actual_qty']) . ' ' . e($r['output_uom']) : '<span class="muted">—</span>'],
            ['key' => 'material_cost', 'label' => 'Materials cost', 'type' => 'money', 'total' => true],
            ['key' => 'status', 'label' => 'Status', 'value' => fn ($r) => Production::STATUSES[$r['status']] ?? $r['status'],
                'html' => fn ($r) => badge(Production::STATUSES[$r['status']] ?? $r['status'], ['planned' => 'amber', 'released' => 'green', 'cancelled' => 'gray'][$r['status']] ?? null)],
            ['key' => 'created_by_name', 'label' => 'Prepared by'],
        ];
        if ($x = Table::export($req, 'production', 'Production', range_label($from, $to), $columns, $rows)) return $x;
        return view('inventory/production/index', ['title' => 'Production', 'from' => $from, 'to' => $to, 'status' => $status, 'rows' => $rows, 'columns' => $columns]);
    }

    public function create(Request $req)
    {
        return $this->form(null, ['template_id' => (int) $req->query('template') ?: null]);
    }

    public function store(Request $req): Response
    {
        $id = Production::save(null, $req->all());
        flash('success', 'Production planned. Check the materials, then release it to inventory once produced.');
        return redirect('/inventory/production/' . $id);
    }

    public function show(Request $req, string $id)
    {
        return $this->form(Production::find((int) $id), []);
    }

    public function update(Request $req, string $id): Response
    {
        Production::save((int) $id, $req->all());
        flash('success', 'Production plan saved.');
        return redirect('/inventory/production/' . (int) $id);
    }

    public function release(Request $req, string $id): Response
    {
        Production::release((int) $id, $req->input('actual_qty'));
        $o = Production::find((int) $id);
        flash('success', "{$o['doc_no']} released: " . qty($o['actual_qty']) . " {$o['output_uom']} {$o['output_name']} added to stock at " . peso($o['material_cost']) . '.');
        return redirect('/inventory/production/' . (int) $id);
    }

    public function cancel(Request $req, string $id): Response
    {
        Production::cancel((int) $id, (string) $req->input('reason', $req->input('v', '')));
        flash('success', 'Production cancelled.');
        return redirect('/inventory/production/' . (int) $id);
    }

    public function delete(Request $req, string $id): Response
    {
        Production::delete((int) $id);
        flash('success', 'Production plan deleted.');
        return redirect('/inventory/production');
    }

    private function form(?array $order, array $defaults)
    {
        $bom = Production::seesBom();
        $editable = !$order || $order['status'] === 'planned';
        $o = $order ? Production::forViewer($order) : null;
        $lines = $o['lines'] ?? [];
        foreach ($lines as &$l) $l['units'] = Inventory::units((int) $l['item_id']);
        unset($l);
        $templates = Production::templates(true);
        if ($order && $order['template_id'] && !in_array((int) $order['template_id'], array_map('intval', array_column($templates, 'id')), true)) {
            $templates[] = Production::template((int) $order['template_id'], false) + ['output_name' => $order['output_name'], 'yield_uom' => ''];
        }
        $outputId = (int) ($order['output_item_id'] ?? 0);
        $tplId = $order['template_id'] ?? $defaults['template_id'] ?? null;
        if (!$outputId && $tplId) $outputId = (int) DB::value('SELECT output_item_id FROM bom_templates WHERE id = ?', [$tplId]);
        return view('inventory/production/edit', [
            'title' => $order ? $order['doc_no'] : 'New production',
            'o' => $o, 'editable' => $editable, 'bom' => $bom, 'lines' => $lines, 'templates' => $templates, 'tplId' => $tplId,
            'outputs' => self::outputOptions(), 'outputUnits' => $outputId ? Inventory::units($outputId) : [],
            'templateData' => array_column(array_map(fn ($t) => ['id' => $t['id'], 'd' => ['output' => (int) $t['output_item_id'], 'yield' => (float) $t['yield_qty'],
                'uom' => (int) $t['yield_uom_id'], 'uom_abbr' => $t['yield_uom'] ?? '', 'output_name' => $t['output_name']]], $templates), 'd', 'id'),
        ] + self::materialOptions(array_map('intval', array_column($lines, 'item_id'))));
    }

    // ------------------------------------------------------------------ BOM templates
    public function templates(Request $req)
    {
        $rows = Production::templates();
        foreach ($rows as &$r) if (!$r['active']) $r['_class'] = 'muted-row';
        unset($r);
        $bom = Production::seesBom();
        $columns = [
            ['key' => 'code', 'label' => 'Code', 'html' => fn ($r) => '<b>' . e($r['code']) . '</b>'],
            ['key' => 'name', 'label' => 'Template'],
            ['key' => 'output_name', 'label' => 'Finished product'],
            ['key' => 'yield_qty', 'label' => 'One batch makes', 'type' => 'qty', 'html' => fn ($r) => qty($r['yield_qty']) . ' ' . e($r['yield_uom'])],
            ['key' => 'ingredients', 'label' => 'Ingredients', 'type' => 'int', 'html' => fn ($r) => $bom ? (int) $r['ingredients'] : '<span class="muted small">hidden</span>'],
            ['key' => 'active', 'label' => 'Status', 'value' => fn ($r) => $r['active'] ? 'active' : 'inactive', 'html' => fn ($r) => badge($r['active'] ? 'active' : 'inactive')],
            ['key' => 'id', 'label' => '', 'export' => false, 'html' => fn ($r) => can('production.manage') && $r['active']
                ? '<a class="btn btn-sm" href="' . url('/inventory/production/new', ['template' => $r['id']]) . '">Plan production</a>' : ''],
        ];
        if ($x = Table::export($req, 'bom-templates', 'BOM templates', '', $columns, $rows)) return $x;
        return view('inventory/production/templates', ['title' => 'BOM Templates', 'rows' => $rows, 'columns' => $columns, 'bom' => $bom]);
    }

    public function newTemplate(Request $req)
    {
        return $this->templateForm(null);
    }

    public function storeTemplate(Request $req): Response
    {
        $id = Production::saveTemplate(null, $req->all());
        flash('success', 'BOM template saved.');
        return redirect('/inventory/bom/' . $id);
    }

    public function showTemplate(Request $req, string $id)
    {
        return $this->templateForm(Production::template((int) $id));
    }

    public function updateTemplate(Request $req, string $id): Response
    {
        Production::saveTemplate((int) $id, $req->all());
        flash('success', 'BOM template saved.');
        return redirect('/inventory/bom/' . (int) $id);
    }

    public function deleteTemplate(Request $req, string $id): Response
    {
        Production::deleteTemplate((int) $id);
        flash('success', 'BOM template removed (deactivated when production already used it).');
        return redirect('/inventory/bom');
    }

    private function templateForm(?array $t)
    {
        $lines = $t['lines'] ?? [];
        foreach ($lines as &$l) $l['units'] = Inventory::units((int) $l['item_id']);
        unset($l);
        $usage = $t ? DB::all("SELECT id, doc_no, prod_date, status, actual_qty, planned_qty FROM production_orders WHERE template_id = ? ORDER BY id DESC LIMIT 10", [$t['id']]) : [];
        return view('inventory/production/template', [
            'title' => $t ? $t['name'] : 'New BOM template', 't' => $t, 'lines' => $lines, 'bom' => Production::seesBom(), 'usage' => $usage,
            'outputs' => self::outputOptions(), 'outputUnits' => $t ? Inventory::units((int) $t['output_item_id']) : [],
        ] + self::materialOptions(array_map('intval', array_column($lines, 'item_id'))));
    }

    // ------------------------------------------------------------------ helpers
    /** Finished products: active stocked items. */
    private static function outputOptions(): array
    {
        $out = [];
        foreach (DB::all("SELECT i.id, i.name, i.sku, i.stock_qty, u.abbr FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
                          WHERE i.item_type IN ('raw','retail') AND i.active = 1 ORDER BY i.name") as $i) {
            $out[$i['id']] = $i['name'] . ($i['sku'] ? " · {$i['sku']}" : '') . ' · ' . qty($i['stock_qty']) . " {$i['abbr']} on hand";
        }
        return $out;
    }

    /** Materials for the line editor (stocked items and recipes): ['itemOptions' => [id => label], 'itemData' => [id => [...]]]. */
    private static function materialOptions(array $keep): array
    {
        $items = DB::all(
            "SELECT i.id, i.name, i.sku, i.item_type, i.stock_qty, i.avg_cost, i.last_cost, i.base_uom_id, i.active, u.abbr AS uom
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.item_type IN ('raw','retail','composite') AND (i.active = 1 OR i.id IN (" . DB::placeholders($keep) . ')) ORDER BY i.name',
            $keep ?: [0]
        );
        $costs = Items::unitCosts();
        $opts = [];
        $data = [];
        foreach ($items as $i) {
            $stocked = $i['item_type'] !== 'composite';
            $opts[$i['id']] = $i['name'] . ($i['sku'] ? " · {$i['sku']}" : '') . ($stocked ? ' · ' . qty($i['stock_qty']) . " {$i['uom']} on hand" : ' · recipe') . ($i['active'] ? '' : ' (inactive)');
            $data[$i['id']] = ['type' => $i['item_type'], 'base' => (int) $i['base_uom_id'], 'cost' => (float) $i['last_cost'] ?: (float) $i['avg_cost'], 'unit_cost' => $costs[(int) $i['id']] ?? 0];
        }
        return ['itemOptions' => $opts, 'itemData' => $data];
    }

}
