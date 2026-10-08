<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Inventory;
use App\Services\Items;
use App\Services\Settings;

/**
 * Items & recipes: the item master list and the full-page item form
 * (general fields, stock settings, purchase units, recipe with live food-cost %).
 */
class ItemController
{
    public function index(Request $req)
    {
        $f = [
            'type' => (string) $req->query('type', ''),
            'category_id' => (string) $req->query('category_id', ''),
            'q' => trim((string) $req->query('q', '')),
            'inactive' => $req->query('inactive') === '1',
            'low' => $req->query('low') === '1',
        ];
        $where = [];
        $params = [];
        if (isset(Items::TYPES[$f['type']])) { $where[] = 'i.item_type = ?'; $params[] = $f['type']; }
        if ($f['category_id'] !== '') { $where[] = 'i.category_id = ?'; $params[] = (int) $f['category_id']; }
        if (!$f['inactive']) $where[] = 'i.active = 1';
        if ($f['q'] !== '') {
            $where[] = '(i.name LIKE ? OR i.sku LIKE ? OR i.barcode = ?)';
            array_push($params, "%{$f['q']}%", "%{$f['q']}%", $f['q']);
        }
        $rows = DB::all(
            'SELECT i.*, c.name AS category_name, u.abbr AS uom FROM items i
             LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id '
            . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.sort_order, c.name, i.sort_order, i.name',
            $params
        );

        $costs = Items::unitCosts();
        foreach ($rows as &$r) {
            $stocked = in_array($r['item_type'], Inventory::STOCKED, true);
            $cost = $costs[(int) $r['id']] ?? 0.0;
            $showFc = $r['sellable'] && in_array($r['item_type'], ['composite', 'retail'], true) && (float) $r['price'] > 0;
            $r += [
                'type_short' => Items::TYPE_SHORT[$r['item_type']],
                'unit_cost' => $cost,
                'food_cost_pct' => $showFc ? Items::foodCostPct($cost, (float) $r['price']) : null,
                'on_hand' => $stocked ? (float) $r['stock_qty'] : null,
                'reorder' => $stocked ? (float) $r['reorder_point'] : null,
                'stock_value' => $stocked ? r2((float) $r['stock_qty'] * (float) $r['avg_cost']) : null,
                'status' => Items::status($r),
            ];
        }
        unset($r);

        $stocked = array_filter($rows, fn ($r) => $r['on_hand'] !== null && $r['active']);
        $isLow = fn ($r) => in_array($r['status'], ['REORDER', 'NEGATIVE'], true);
        $stats = [
            'total' => count($rows),
            'stocked' => count($stocked),
            'low' => count(array_filter($stocked, $isLow)),
            'value' => array_sum(array_column($stocked, 'stock_value')),
        ];
        if ($f['low']) $rows = array_values(array_filter($rows, $isLow));

        $columns = [
            ['key' => 'sku', 'label' => 'SKU', 'html' => fn ($r) => '<span class="nowrap muted">' . e($r['sku']) . '</span>'],
            ['key' => 'name', 'label' => 'Name', 'html' => fn ($r) => '<span class="' . ($r['active'] ? 'bold' : 'muted') . '">' . e($r['name']) . '</span>'],
            ['key' => 'category_name', 'label' => 'Category'],
            ['key' => 'type_short', 'label' => 'Type'],
            ['key' => 'uom', 'label' => 'Unit'],
            ['key' => 'price', 'label' => 'Price', 'type' => 'money', 'html' => fn ($r) => (float) $r['price'] ? money($r['price']) : '<span class="muted">—</span>'],
            ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'money', 'html' => fn ($r) => Items::cost4($r['unit_cost'])],
            ['key' => 'food_cost_pct', 'label' => 'Food cost %', 'type' => 'percent', 'html' => fn ($r) => $r['food_cost_pct'] === null ? ''
                : '<span class="' . self::foodCostClass($r['food_cost_pct']) . '">' . number_format($r['food_cost_pct'], 2) . '%</span>'],
            ['key' => 'on_hand', 'label' => 'On hand', 'type' => 'qty', 'html' => fn ($r) => $r['on_hand'] === null ? ''
                : '<span class="' . ($r['on_hand'] < 0 ? 'text-red bold' : '') . '">' . qty($r['on_hand']) . '</span>'],
            ['key' => 'reorder', 'label' => 'Reorder pt', 'type' => 'qty'],
            ['key' => 'stock_value', 'label' => 'Stock value', 'type' => 'money', 'total' => true],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
        ];
        $subtitle = $f['inactive'] ? 'Including inactive items' : 'Active items';
        if ($x = Table::export($req, 'items', 'Item Master List', $subtitle, $columns, $rows)) return $x;

        return view('inventory/items/index', [
            'title' => 'Items & Recipes', 'rows' => $rows, 'columns' => $columns, 'stats' => $stats, 'f' => $f,
            'categories' => DB::all('SELECT id, name FROM categories ORDER BY sort_order, name'),
        ]);
    }

    public function create(Request $req)
    {
        return $this->form(null);
    }

    public function show(Request $req, string $id)
    {
        $item = DB::one('SELECT i.*, u.abbr AS uom FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.id = ?', [(int) $id]);
        if (!$item) throw HttpException::notFound('Item');
        return $this->form($item);
    }

    public function store(Request $req): Response
    {
        $id = Items::create($this->input($req));
        flash('success', 'Item created.');
        return redirect("/inventory/items/$id");
    }

    public function update(Request $req, string $id): Response
    {
        $data = $this->input($req);
        Items::update((int) $id, $data);
        $item = Inventory::item((int) $id);
        $costIgnored = isset($data['avg_cost']) && $data['avg_cost'] !== '' && abs((float) $item['avg_cost'] - num($data['avg_cost'])) > 0.00005
            && Inventory::isStocked($item);
        flash('success', $costIgnored ? 'Saved. Cost was not changed because the item already has stock history.' : 'Item saved.');
        return redirect("/inventory/items/$id");
    }

    public function delete(Request $req, string $id): Response
    {
        $deleted = Items::delete((int) $id);
        flash('success', $deleted ? 'Item deleted.' : 'Item has history, so it was deactivated instead of deleted.');
        return redirect('/inventory/items');
    }

    /** JSON: units an item can be entered in, e.g. [{"uom_id":2,"abbr":"kg","factor":1}, {"uom_id":10,"abbr":"sack","factor":50}] */
    public function units(Request $req, string $id): Response
    {
        return json(Inventory::units((int) $id));
    }

    /** Submitted form fields; the color only counts when "custom color" is ticked. */
    private function input(Request $req): array
    {
        $data = $req->all();
        if (empty($data['use_color'])) $data['color'] = null;
        $data['components'] = array_values($data['components'] ?? []);
        $data['uoms'] = array_values($data['uoms'] ?? []);
        return $data;
    }

    /** The item page. After a failed save the submitted values (old input) are shown again. */
    private function form(?array $item): string
    {
        $id = $item ? (int) $item['id'] : 0;
        $old = old('_form') === 'item' ? $GLOBALS['__old'] : null;
        $values = $old ?? $item ?? ['item_type' => 'raw', 'active' => 1, 'sellable' => 0, 'sort_order' => 0, 'price' => '', 'avg_cost' => ''];
        if ($old) $values += ['active' => 0, 'sellable' => 0];

        $components = $old ? array_values($old['components'] ?? [])
            : DB::all('SELECT component_id, qty, uom_id FROM item_components ic JOIN items i ON i.id = ic.component_id WHERE ic.parent_id = ? ORDER BY i.name', [$id]);
        $altUnits = $old ? array_values($old['uoms'] ?? []) : DB::all('SELECT uom_id, factor FROM item_uoms WHERE item_id = ?', [$id]);
        $compUnits = [];
        foreach ($components as $c) {
            if (!empty($c['component_id']) && DB::value('SELECT id FROM items WHERE id = ?', [(int) $c['component_id']])) {
                $compUnits[(int) $c['component_id']] = Inventory::units((int) $c['component_id']);
            }
        }

        $costs = Items::unitCosts();
        $all = DB::all('SELECT i.id, i.name, i.sku, i.item_type, i.active, u.abbr AS uom FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id ORDER BY i.name');
        $selected = array_map('intval', array_column($components, 'component_id'));
        $itemData = [];
        $compOptions = [];
        foreach ($all as $i) {
            $iid = (int) $i['id'];
            $itemData[$iid] = ['name' => $i['name'], 'type' => $i['item_type'], 'cost' => $costs[$iid] ?? 0, 'uom' => $i['uom'], 'active' => (int) $i['active']];
            $usable = $iid !== $id && $i['item_type'] !== 'non_inventory' && $i['active'];
            if ($usable || in_array($iid, $selected, true)) {
                $compOptions[$iid] = $i['name'] . ' · ' . Items::TYPE_SHORT[$i['item_type']] . ($i['sku'] ? ' · ' . $i['sku'] : '') . ($i['active'] ? '' : ' (inactive)');
            }
        }
        $uoms = DB::all('SELECT id, name, abbr FROM uoms ORDER BY name');

        return view('inventory/items/edit', [
            'title' => $item ? $item['name'] : 'New item',
            'item' => $item,
            'v' => $values,
            'components' => $components,
            'altUnits' => $altUnits,
            'compUnits' => $compUnits,
            'compOptions' => $compOptions,
            'itemData' => $itemData,
            'uomOptions' => array_column(array_map(fn ($u) => ['id' => $u['id'], 'label' => "{$u['name']} ({$u['abbr']})"], $uoms), 'label', 'id'),
            'uomAbbr' => array_column($uoms, 'abbr', 'id'),
            'categories' => array_column(DB::all('SELECT id, name FROM categories ORDER BY sort_order, name'), 'name', 'id'),
            'usedIn' => DB::all('SELECT p.id, p.name FROM item_components ic JOIN items p ON p.id = ic.parent_id WHERE ic.component_id = ? ORDER BY p.name', [$id]),
            'hasMovements' => $id && DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id]),
            'unitCost' => $costs[$id] ?? 0,
            'readonly' => !can('inventory.manage'),
            'vatRate' => Settings::tax()['vatRate'],
        ]);
    }

    /** Text color for a food cost %: above 45% is too high, above 35% is a warning. */
    public static function foodCostClass(?float $pct): string
    {
        if ($pct === null) return '';
        return $pct > 45 ? 'text-red bold' : ($pct > 35 ? 'text-amber' : 'text-green');
    }
}
