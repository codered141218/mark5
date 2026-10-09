<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\SheetReader;

/**
 * Import items from Excel / CSV (Inventory → Items & Recipes → Import from Excel).
 *
 * The first row holds the column names (any order, upper / lower case, see COLUMNS for the accepted names).
 * Only "Name" is required. Rows are matched to existing items by SKU, else by name: existing items are updated
 * (only the columns present in the file), new ones are created. Missing categories, units and prep stations are
 * created. "Stock on hand" becomes an opening-balance delivery so the books show the stock value.
 * Recipes are not imported (add them on the item page).
 */
class ItemImport
{
    /** field => accepted header names (lower case) */
    public const COLUMNS = [
        'name' => ['name', 'item', 'item name', 'product', 'product name'],
        'type' => ['type', 'item type'],
        'category' => ['category', 'group'],
        'unit' => ['unit', 'uom', 'unit of measure', 'base unit'],
        'price' => ['price', 'selling price', 'srp', 'retail price', 'menu price'],
        'cost' => ['cost', 'unit cost', 'purchase cost', 'cost price'],
        'stock' => ['stock', 'stock on hand', 'on hand', 'quantity', 'qty', 'beginning stock', 'opening stock'],
        'reorder_point' => ['reorder point', 'reorder level', 'min stock', 'minimum'],
        'reorder_qty' => ['reorder qty', 'reorder quantity', 'order qty'],
        'sku' => ['sku', 'code', 'item code', 'product code'],
        'barcode' => ['barcode', 'upc', 'ean'],
        'sellable' => ['sellable', 'sell on pos', 'for sale', 'pos'],
        'station' => ['station', 'prep station', 'kitchen station'],
        'active' => ['active', 'status'],
    ];

    /** Words accepted in the Type column. */
    public const TYPE_WORDS = [
        'raw' => 'raw', 'ingredient' => 'raw', 'raw material' => 'raw', 'inventory' => 'raw',
        'composite' => 'composite', 'menu' => 'composite', 'menu item' => 'composite', 'recipe' => 'composite', 'dish' => 'composite',
        'retail' => 'retail', 'resale' => 'retail', 'goods' => 'retail',
        'non_inventory' => 'non_inventory', 'non-inventory' => 'non_inventory', 'non inventory' => 'non_inventory', 'service' => 'non_inventory',
    ];

    /** Example rows for the template download. */
    public const TEMPLATE = [
        ['Name', 'Type', 'Category', 'Unit', 'Selling price', 'Cost', 'Stock on hand', 'Reorder point', 'Reorder qty', 'SKU', 'Barcode', 'Sellable', 'Station'],
        ['Pork Liempo', 'Ingredient', 'Meat & Poultry', 'kg', '', '320', '10', '5', '10', '', '', 'No', ''],
        ['Chicken Inasal', 'Menu', 'Grilled', 'srv', '165', '', '', '', '', '', '', 'Yes', 'Grill'],
        ['Coke in Can', 'Retail', 'Beverages', 'can', '65', '38', '48', '24', '48', '', '4801981116102', 'Yes', ''],
        ['Corkage Fee', 'Service', 'Others', 'pc', '150', '', '', '', '', '', '', 'Yes', ''],
    ];

    /** Read a file into header-mapped rows: [['_line' => 2, 'name' => .., 'price' => .., ...], ...]. */
    public static function parse(string $path, string $originalName): array
    {
        $rows = SheetReader::read($path, $originalName);
        // The header row is the first of the first 10 rows that has a "Name" column (title rows above it are ignored)
        $map = [];
        $headerAt = null;
        foreach (array_slice($rows, 0, 10) as $n => $cells) {
            $try = [];
            foreach ($cells as $i => $h) {
                $h = strtolower(trim(preg_replace('/[\s_*()]+/', ' ', (string) $h)));
                foreach (self::COLUMNS as $field => $names) {
                    if (in_array($h, $names, true) && !in_array($field, $try, true)) { $try[$i] = $field; break; }
                }
            }
            if (in_array('name', $try, true)) { $map = $try; $headerAt = $n; break; }
        }
        if ($headerAt === null) throw HttpException::bad('No "Name" column found. The first row must hold the column names — download the template to see them.');
        $rows = array_slice($rows, $headerAt);
        if (count($rows) < 2) throw HttpException::bad('The file has no item rows below the column names');
        $out = [];
        foreach (array_slice($rows, 1, 5000) as $n => $r) {
            $row = ['_line' => $n + 2];
            foreach ($map as $i => $field) $row[$field] = trim((string) ($r[$i] ?? ''));
            if (($row['name'] ?? '') === '') continue;
            $out[] = $row;
        }
        if (!$out) throw HttpException::bad('No rows with a name were found');
        return $out;
    }

    /** Check every row: adds '_action' (create|update|skip), '_errors', '_notes' and the resolved values. */
    public static function analyze(array $rows, bool $updateExisting = true): array
    {
        $num = function ($v) { $v = str_replace([',', '₱', 'P ', 'PHP', ' '], '', (string) $v); return $v === '' ? null : (is_numeric($v) ? (float) $v : false); };
        $yes = fn ($v, bool $default) => $v === '' || $v === null ? $default : in_array(strtolower(trim($v)), ['y', 'yes', '1', 'true', 'x', 'oo', 'active', '✓'], true);
        $seen = [];
        foreach ($rows as &$r) {
            $errors = [];
            $notes = [];
            foreach (['price', 'cost', 'stock', 'reorder_point', 'reorder_qty'] as $f) {
                if (!array_key_exists($f, $r)) continue;
                $v = $num($r[$f]);
                if ($v === false) $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' "' . $r[$f] . '" is not a number';
                elseif ($v !== null && $v < 0) $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' cannot be negative';
                $r['_' . $f] = $v === false ? null : $v;
            }
            $existing = null;
            if (($r['sku'] ?? '') !== '') $existing = DB::one('SELECT * FROM items WHERE sku = ?', [$r['sku']]);
            if (!$existing) $existing = DB::one('SELECT * FROM items WHERE LOWER(name) = LOWER(?) ORDER BY id LIMIT 1', [$r['name']]);
            $key = strtolower($r['name']);
            if (isset($seen[$key])) $errors[] = 'Same name as line ' . $seen[$key];
            $seen[$key] = $r['_line'];

            $typeWord = strtolower(trim($r['type'] ?? ''));
            if ($typeWord !== '' && !isset(self::TYPE_WORDS[$typeWord])) $errors[] = "Unknown type \"{$r['type']}\" (use Ingredient, Menu, Retail or Service)";
            $type = self::TYPE_WORDS[$typeWord] ?? ($existing['item_type'] ?? (($r['_price'] ?? 0) > 0 ? 'composite' : 'raw'));
            $r['_type'] = $type;
            $r['_sellable'] = $yes($r['sellable'] ?? '', $existing ? (bool) $existing['sellable'] : ($type !== 'raw' && ($r['_price'] ?? 0) > 0));
            $r['_active'] = $yes($r['active'] ?? '', true);
            if (($r['unit'] ?? '') === '' && !$existing) $notes[] = 'no unit given: ' . ($type === 'composite' ? 'srv (serving)' : 'pc (piece)');
            if ($r['_stock'] ?? null) {
                if (!in_array($type, Inventory::STOCKED, true)) $notes[] = 'stock ignored (only ingredients and retail items are stocked)';
                elseif ($existing && DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$existing['id']])) $notes[] = 'stock ignored: the item already has stock history (use Inventory Count)';
            }
            if ($existing && $existing['item_type'] !== $type && abs((float) $existing['stock_qty']) > 0.0001) $errors[] = 'Cannot change the type of an item that has stock';
            if (($r['category'] ?? '') !== '' && !DB::value('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)', [$r['category']])) $notes[] = 'new category "' . $r['category'] . '"';
            if (($r['unit'] ?? '') !== '' && !self::uomId($r['unit'], false)) $notes[] = 'new unit "' . $r['unit'] . '"';
            if (($r['station'] ?? '') !== '' && !DB::value('SELECT id FROM prep_stations WHERE LOWER(name) = LOWER(?)', [$r['station']])) $notes[] = 'new station "' . $r['station'] . '"';

            $r['_existing_id'] = $existing ? (int) $existing['id'] : null;
            $r['_action'] = $errors ? 'error' : ($existing ? ($updateExisting ? 'update' : 'skip') : 'create');
            $r['_errors'] = $errors;
            $r['_notes'] = $notes;
        }
        return $rows;
    }

    /** Import analyzed rows (rows with errors are skipped). Returns counts. */
    public static function import(array $rows, bool $updateExisting = true): array
    {
        $rows = self::analyze($rows, $updateExisting);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'stock_lines' => 0];
        DB::transaction(function () use ($rows, &$counts) {
            $opening = [];
            foreach ($rows as $r) {
                if ($r['_action'] === 'error') { $counts['errors']++; continue; }
                if ($r['_action'] === 'skip') { $counts['skipped']++; continue; }
                $f = [];
                if (array_key_exists('category', $r)) $f['category_id'] = $r['category'] === '' ? null : self::categoryId($r['category'], $r['_sellable']);
                if (array_key_exists('unit', $r) && $r['unit'] !== '') $f['base_uom_id'] = self::uomId($r['unit'], true);
                if (array_key_exists('station', $r)) $f['station_id'] = $r['station'] === '' ? null : self::stationId($r['station']);
                foreach (['price' => 'price', 'reorder_point' => 'reorder_point', 'reorder_qty' => 'reorder_qty'] as $src => $col) {
                    if (($r['_' . $src] ?? null) !== null) $f[$col] = $src === 'price' ? r2($r['_' . $src]) : r4($r['_' . $src]);
                }
                foreach (['sku', 'barcode'] as $col) if (($r[$col] ?? '') !== '') $f[$col] = mb_substr($r[$col], 0, $col === 'sku' ? 40 : 60);
                if (array_key_exists('sellable', $r) || $r['_action'] === 'create') $f['sellable'] = $r['_sellable'] ? 1 : 0;
                if (array_key_exists('active', $r)) $f['active'] = $r['_active'] ? 1 : 0;
                $stocked = in_array($r['_type'], Inventory::STOCKED, true);

                if ($r['_action'] === 'update') {
                    $id = $r['_existing_id'];
                    $item = Inventory::item($id);
                    if (array_key_exists('type', $r) && $r['type'] !== '') $f['item_type'] = $r['_type'];
                    $hasMoves = (bool) DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id]);
                    if (isset($f['base_uom_id']) && $hasMoves && (int) $f['base_uom_id'] !== (int) $item['base_uom_id']) unset($f['base_uom_id']);   // unit is fixed once stock moved
                    if (($r['_cost'] ?? null) !== null && $stocked && !$hasMoves) { $f['avg_cost'] = r4($r['_cost']); $f['last_cost'] = r4($r['_cost']); }
                    if (isset($f['sku']) && DB::value('SELECT id FROM items WHERE sku = ? AND id <> ?', [$f['sku'], $id])) unset($f['sku']);
                    DB::update('items', $id, $f + ['name' => mb_substr($r['name'], 0, 120), 'updated_at' => now()]);
                    $counts['updated']++;
                } else {
                    if (empty($f['base_uom_id'])) $f['base_uom_id'] = self::uomId($r['_type'] === 'composite' ? 'srv' : 'pc', true);
                    if (!empty($f['sku']) && DB::value('SELECT id FROM items WHERE sku = ?', [$f['sku']])) unset($f['sku']);
                    $cost = $stocked ? (float) ($r['_cost'] ?? 0) : 0.0;
                    $f += ['name' => mb_substr($r['name'], 0, 120), 'item_type' => $r['_type'], 'active' => 1, 'price' => 0];
                    $f['sku'] = $f['sku'] ?? Sequence::next('SKU', 'SKU', 5);
                    $f['sort_order'] = Positions::next('items', 'category_id <=> ?', [$f['category_id'] ?? null]);
                    $id = DB::insert('items', $f + ['avg_cost' => r4($cost), 'last_cost' => r4($cost), 'created_at' => now(), 'updated_at' => now()]);
                    $counts['created']++;
                }
                $qty = (float) ($r['_stock'] ?? 0);
                if ($qty > 0 && $stocked && !DB::value('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', [$id])) {
                    $unitCost = ($r['_cost'] ?? null) !== null ? (float) $r['_cost'] : (float) Inventory::item($id)['avg_cost'];
                    $opening[] = ['item_id' => $id, 'qty' => $qty, 'line_total' => r2($qty * $unitCost)];
                }
            }
            if ($opening) {
                // Beginning stock: Dr Inventory / Cr Opening Balance Equity, through a posted delivery document
                InventoryDocs::create('RECEIVE', ['payment_mode' => 'opening', 'post' => 1, 'notes' => 'Beginning stock from item import', 'lines' => $opening]);
                $counts['stock_lines'] = count($opening);
            }
        });
        Audit::log('import', 'items', null, $counts);
        return $counts;
    }

    private static function categoryId(string $name, bool $menu): int
    {
        $id = DB::value('SELECT id FROM categories WHERE LOWER(name) = LOWER(?)', [$name]);
        if ($id) return (int) $id;
        return DB::insert('categories', ['name' => mb_substr($name, 0, 80), 'kind' => $menu ? 'menu' : 'inventory', 'active' => 1,
            'sort_order' => Positions::next('categories')]);
    }

    private static function uomId(string $unit, bool $create): ?int
    {
        $id = DB::value('SELECT id FROM uoms WHERE LOWER(abbr) = LOWER(?) OR LOWER(name) = LOWER(?) ORDER BY abbr = ? DESC LIMIT 1', [$unit, $unit, $unit]);
        if ($id || !$create) return $id ? (int) $id : null;
        $names = ['pc' => 'Piece', 'srv' => 'Serving', 'kg' => 'Kilogram', 'g' => 'Gram', 'L' => 'Liter', 'ml' => 'Milliliter'];
        return DB::insert('uoms', ['name' => mb_substr($names[$unit] ?? ucfirst($unit), 0, 40), 'abbr' => mb_substr($unit, 0, 12)]);
    }

    private static function stationId(string $name): int
    {
        $id = DB::value('SELECT id FROM prep_stations WHERE LOWER(name) = LOWER(?)', [$name]);
        return $id ? (int) $id : Stations::save(['name' => $name, 'active' => 1]);
    }
}
