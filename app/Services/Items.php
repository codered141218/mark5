<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;

/**
 * Item master: create / update / delete items together with their recipe (item_components)
 * and purchase units (item_uoms), plus bulk unit-cost and food-cost figures for list screens.
 */
class Items
{
    public const TYPES = [
        'raw' => 'Raw material / Ingredient',
        'composite' => 'Composite / Menu item (recipe)',
        'retail' => 'Retail (stocked & sold as-is)',
        'non_inventory' => 'Non-inventory / Service',
    ];
    public const TYPE_SHORT = ['raw' => 'Raw', 'composite' => 'Composite', 'retail' => 'Retail', 'non_inventory' => 'Non-inv'];
    public const TYPE_HINTS = [
        'raw' => 'Ingredient you buy and keep in stock (e.g. pork, rice, cooking oil). Not usually sold directly.',
        'composite' => 'Menu item or sub-recipe made from other items. Not stocked itself: selling it deducts its recipe ingredients.',
        'retail' => 'Bought and sold as-is and tracked in stock (e.g. bottled softdrinks, chips).',
        'non_inventory' => 'Sold but not tracked in stock (e.g. service fee, corkage, gift wrap).',
    ];

    /**
     * Create an item. $data holds the item fields plus optional
     * 'components' => [['component_id', 'qty', 'uom_id'], ...] and 'uoms' => [['uom_id', 'factor'], ...].
     */
    public static function create(array $data): int
    {
        required($data, 'name', 'item_type', 'base_uom_id');
        $row = self::fields($data);
        if (empty($row['sku'])) $row['sku'] = Sequence::next('SKU', 'SKU', 5);
        if (DB::value('SELECT id FROM items WHERE sku = ?', [$row['sku']])) throw HttpException::bad('SKU already exists');
        $cost = in_array($row['item_type'], Inventory::STOCKED, true) ? max(num($data['avg_cost'] ?? 0), 0) : 0;

        $id = DB::transaction(function () use ($row, $cost, $data) {
            $id = DB::insert('items', $row + ['avg_cost' => $cost, 'last_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
            self::saveChildren($id, $data);
            return $id;
        });
        Audit::log('create', 'item', $id, $row['name']);
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        $item = Inventory::item($id);
        required($data, 'name', 'item_type', 'base_uom_id');
        $row = self::fields($data);
        if (empty($row['sku'])) unset($row['sku']);
        elseif (DB::value('SELECT id FROM items WHERE sku = ? AND id <> ?', [$row['sku'], $id])) throw HttpException::bad('SKU already exists');

        if ($row['item_type'] !== $item['item_type'] && abs((float) $item['stock_qty']) > 0.0001) {
            throw HttpException::bad('Cannot change item type while it has stock on hand. Adjust stock to zero first.');
        }
        $hasMovements = (bool) DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id]);
        if ((int) $row['base_uom_id'] !== (int) $item['base_uom_id'] && $hasMovements) {
            throw HttpException::bad('Cannot change the base unit of an item that already has stock movements');
        }
        // A standard cost can be set directly only while the item has no stock history (initial setup).
        if (isset($data['avg_cost']) && $data['avg_cost'] !== '' && !$hasMovements && in_array($row['item_type'], Inventory::STOCKED, true)) {
            $row['avg_cost'] = max(num($data['avg_cost']), 0);
        }

        DB::transaction(function () use ($id, $row, $data) {
            DB::update('items', $id, $row + ['updated_at' => now()]);
            self::saveChildren($id, $data);
        });
        Audit::log('update', 'item', $id, $row);
    }

    /**
     * Delete an item, or deactivate it when it already has history (sales, stock, documents, recipes).
     * Returns true if it was deleted, false if it was deactivated.
     */
    public static function delete(int $id): bool
    {
        $item = Inventory::item($id);
        $used = DB::value(
            'SELECT 1 FROM stock_movements WHERE item_id = ? UNION SELECT 1 FROM ticket_items WHERE item_id = ?
             UNION SELECT 1 FROM inv_doc_lines WHERE item_id = ? UNION SELECT 1 FROM item_components WHERE component_id = ?
             UNION SELECT 1 FROM count_lines WHERE item_id = ? LIMIT 1',
            [$id, $id, $id, $id, $id]
        );
        if ($used) {
            DB::run('UPDATE items SET active = 0, sellable = 0, updated_at = ? WHERE id = ?', [now(), $id]);
            Audit::log('deactivate', 'item', $id, $item['name']);
            return false;
        }
        DB::run('DELETE FROM items WHERE id = ?', [$id]);
        Audit::log('delete', 'item', $id, $item['name']);
        return true;
    }

    /** The editable item columns from submitted data, validated and typed. */
    private static function fields(array $d): array
    {
        if (!isset(self::TYPES[$d['item_type']])) throw HttpException::bad('Unknown item type');
        if (!DB::value('SELECT id FROM uoms WHERE id = ?', [(int) $d['base_uom_id']])) throw HttpException::bad('Choose the base unit');
        if (num($d['price'] ?? 0) < 0) throw HttpException::bad('Price cannot be negative');
        $text = fn ($k, $len) => isset($d[$k]) && trim((string) $d[$k]) !== '' ? mb_substr(trim((string) $d[$k]), 0, $len) : null;
        return [
            'sku' => $text('sku', 40),
            'name' => mb_substr(trim($d['name']), 0, 120),
            'category_id' => !empty($d['category_id']) ? (int) $d['category_id'] : null,
            'item_type' => $d['item_type'],
            'base_uom_id' => (int) $d['base_uom_id'],
            'price' => r2(num($d['price'] ?? 0)),
            'reorder_point' => r4(max(num($d['reorder_point'] ?? 0), 0)),
            'reorder_qty' => r4(max(num($d['reorder_qty'] ?? 0), 0)),
            'sellable' => !empty($d['sellable']) ? 1 : 0,
            'active' => !empty($d['active']) ? 1 : 0,
            'color' => $text('color', 9),
            'barcode' => $text('barcode', 60),
            'description' => $text('description', 255),
            'sort_order' => (int) ($d['sort_order'] ?? 0),
        ];
    }

    /**
     * Replace the recipe (composites only; other types lose any old recipe) and the purchase units
     * (stocked items only), then check the recipe converts and is not circular.
     */
    private static function saveChildren(int $id, array $data): void
    {
        $item = Inventory::item($id);
        DB::run('DELETE FROM item_components WHERE parent_id = ?', [$id]);
        if ($item['item_type'] === 'composite') {
            $seen = [];
            foreach ($data['components'] ?? [] as $c) {
                if (empty($c['component_id']) || num($c['qty'] ?? 0) <= 0) continue;
                $compId = (int) $c['component_id'];
                $comp = Inventory::item($compId);
                if ($compId === $id) throw HttpException::bad('An item cannot be a component of itself');
                if ($comp['item_type'] === 'non_inventory') throw HttpException::bad("\"{$comp['name']}\" is a non-inventory item and cannot be a recipe component");
                if (isset($seen[$compId])) throw HttpException::bad("\"{$comp['name']}\" is listed twice in the recipe");
                $seen[$compId] = true;
                DB::insert('item_components', ['parent_id' => $id, 'component_id' => $compId, 'qty' => r4($c['qty']),
                    'uom_id' => !empty($c['uom_id']) ? (int) $c['uom_id'] : (int) $comp['base_uom_id']]);
            }
            // Throws on a missing unit conversion or a circular recipe (A uses B uses A).
            Inventory::explode($id, 1);
        }

        if (Inventory::isStocked($item)) {
            DB::run('DELETE FROM item_uoms WHERE item_id = ?', [$id]);
            $seen = [];
            foreach ($data['uoms'] ?? [] as $u) {
                if (empty($u['uom_id']) || num($u['factor'] ?? 0) <= 0) continue;
                $uomId = (int) $u['uom_id'];
                if ($uomId === (int) $item['base_uom_id']) throw HttpException::bad('An alternate unit cannot be the same as the base unit');
                if (isset($seen[$uomId])) throw HttpException::bad('The same alternate unit is listed twice');
                $seen[$uomId] = true;
                DB::insert('item_uoms', ['item_id' => $id, 'uom_id' => $uomId, 'factor' => (float) $u['factor']]);
            }
        }
    }

    // ------------------------------------------------------------------ costing for list screens

    /**
     * Unit cost of every item at once: avg cost for stocked items, recipe cost for composites.
     * Same rules as Inventory::unitCost(), but computed from a handful of queries so a list of
     * hundreds of menu items does not run thousands of queries. Returns [item_id => cost].
     */
    public static function unitCosts(): array
    {
        $items = [];
        foreach (DB::all('SELECT id, item_type, avg_cost, base_uom_id FROM items') as $i) $items[(int) $i['id']] = $i;
        $recipes = [];
        foreach (DB::all('SELECT parent_id, component_id, qty, uom_id FROM item_components') as $c) $recipes[(int) $c['parent_id']][] = $c;
        $itemUnits = [];
        foreach (DB::all('SELECT item_id, uom_id, factor FROM item_uoms') as $u) $itemUnits[(int) $u['item_id']][(int) $u['uom_id']] = (float) $u['factor'];
        $conv = [];
        foreach (DB::all('SELECT from_uom_id, to_uom_id, factor FROM uom_conversions') as $c) $conv[(int) $c['from_uom_id']][(int) $c['to_uom_id']] = (float) $c['factor'];

        // Mirrors Inventory::factorToBase(); an unknown conversion counts as 0 (like unitCost()).
        $factor = function (array $item, $uomId) use ($itemUnits, $conv): float {
            $base = (int) $item['base_uom_id'];
            $uomId = (int) $uomId;
            if (!$uomId || $uomId === $base) return 1.0;
            if (isset($itemUnits[(int) $item['id']][$uomId])) return $itemUnits[(int) $item['id']][$uomId];
            if (isset($conv[$uomId][$base])) return $conv[$uomId][$base];
            if (!empty($conv[$base][$uomId])) return 1 / $conv[$base][$uomId];
            return 0.0;
        };
        $costs = [];
        $cost = function (int $id, int $depth = 0) use (&$cost, &$costs, $items, $recipes, $factor): float {
            if (isset($costs[$id])) return $costs[$id];
            if ($depth > 8 || !isset($items[$id])) return 0.0;
            $item = $items[$id];
            if ($item['item_type'] !== 'composite') return $costs[$id] = (float) $item['avg_cost'];
            $total = 0.0;
            foreach ($recipes[$id] ?? [] as $c) {
                $comp = $items[(int) $c['component_id']] ?? null;
                if ($comp) $total += (float) $c['qty'] * $factor($comp, $c['uom_id']) * $cost((int) $comp['id'], $depth + 1);
            }
            return $costs[$id] = r4($total);
        };
        foreach (array_keys($items) as $id) $cost($id);
        return $costs;
    }

    /** VAT divisor for selling prices (prices are VAT-inclusive): 1.12, or 1 when not VAT-registered. */
    public static function vatDivisor(): float
    {
        return 1 + Settings::tax()['vatRate'];
    }

    /** Food cost % = unit cost / selling price net of VAT. Null when there is no price. */
    public static function foodCostPct(float $cost, float $price): ?float
    {
        return $price > 0 ? r2($cost / ($price / self::vatDivisor()) * 100) : null;
    }

    /** Unit cost with 2 to 4 decimals (costs per gram or ml are tiny), e.g. 0.055 or 190.00. */
    public static function cost4($n): string
    {
        if ($n === null || $n === '') return '';
        return preg_replace('/(\.\d{2}\d*?)0+$/', '$1', number_format((float) $n, 4));
    }

    /** Stock status shown on the item list: inactive / NEGATIVE / REORDER / OK ('' for non-stocked). */
    public static function status(array $i): string
    {
        $stocked = in_array($i['item_type'], Inventory::STOCKED, true);
        if (!$i['active']) return 'inactive';
        if ($stocked && (float) $i['stock_qty'] < 0) return 'NEGATIVE';
        if ($stocked && (float) $i['reorder_point'] > 0 && (float) $i['stock_qty'] <= (float) $i['reorder_point']) return 'REORDER';
        return $stocked ? 'OK' : '';
    }
}
