<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Stock ledger, unit conversions, recipe explosion and moving-average costing.
 *
 * Key ideas:
 *  - Every item has a base unit (kg, pc, L ...). Stock is always kept in the base unit.
 *  - Other units convert to the base unit through item_uoms (1 sack = 50 kg, item specific)
 *    or uom_conversions (1 kg = 1000 g, global).
 *  - Composite items (menu items) are not stocked; selling one deducts its recipe components,
 *    and components can themselves be composites (sub-recipes).
 *  - Every stock change is written to stock_movements and items.stock_qty is updated.
 */
class Inventory
{
    public const STOCKED = ['raw', 'retail'];

    public static function item(int $id): array
    {
        $it = DB::one('SELECT * FROM items WHERE id = ?', [$id]);
        if (!$it) throw HttpException::bad("Item #$id not found");
        return $it;
    }

    public static function isStocked(array $item): bool
    {
        return in_array($item['item_type'], self::STOCKED, true);
    }

    /** How many base units are in 1 [$uomId] of this item. */
    public static function factorToBase(array $item, $uomId): float
    {
        if (!$uomId || (int) $uomId === (int) $item['base_uom_id']) return 1.0;
        $f = DB::value('SELECT factor FROM item_uoms WHERE item_id = ? AND uom_id = ?', [$item['id'], $uomId]);
        if ($f) return (float) $f;
        $f = DB::value('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', [$uomId, $item['base_uom_id']]);
        if ($f) return (float) $f;
        $f = DB::value('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', [$item['base_uom_id'], $uomId]);
        if ($f) return 1 / (float) $f;
        $u = DB::value('SELECT abbr FROM uoms WHERE id = ?', [$uomId]);
        $b = DB::value('SELECT abbr FROM uoms WHERE id = ?', [$item['base_uom_id']]);
        throw HttpException::bad("No conversion from {$u} to {$b} for \"{$item['name']}\". Add it under the item's units or Units & Conversions.");
    }

    public static function toBase(array $item, $qty, $uomId): float
    {
        return r4((float) $qty * self::factorToBase($item, $uomId));
    }

    /** Units an item can be entered in: [['uom_id'=>, 'abbr'=>, 'factor'=>], ...] (factor = base units per 1). */
    public static function units(int $itemId): array
    {
        $item = self::item($itemId);
        $out = [];
        $base = DB::one('SELECT id, abbr FROM uoms WHERE id = ?', [$item['base_uom_id']]);
        if ($base) $out[(int) $base['id']] = ['uom_id' => (int) $base['id'], 'abbr' => $base['abbr'], 'factor' => 1.0];
        foreach (DB::all('SELECT iu.uom_id, u.abbr, iu.factor FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.item_id = ?', [$itemId]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => (float) $r['factor']];
        }
        foreach (DB::all('SELECT c.from_uom_id AS uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.from_uom_id WHERE c.to_uom_id = ?', [$item['base_uom_id']]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => (float) $r['factor']];
        }
        foreach (DB::all('SELECT c.to_uom_id AS uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.to_uom_id WHERE c.from_uom_id = ?', [$item['base_uom_id']]) as $r) {
            $out[(int) $r['uom_id']] ??= ['uom_id' => (int) $r['uom_id'], 'abbr' => $r['abbr'], 'factor' => 1 / (float) $r['factor']];
        }
        return array_values($out);
    }

    /**
     * Expand an item into the stocked items it consumes.
     * Returns [item_id => base qty]. Composite items explode recursively.
     */
    public static function explode(int $itemId, float $qty, array &$acc = [], int $depth = 0): array
    {
        if ($depth > 8) throw HttpException::bad('Recipe nesting is too deep (possible circular recipe)');
        $item = self::item($itemId);
        if (self::isStocked($item)) {
            $acc[$itemId] = r4(($acc[$itemId] ?? 0) + $qty);
        } elseif ($item['item_type'] === 'composite') {
            foreach (DB::all('SELECT * FROM item_components WHERE parent_id = ?', [$itemId]) as $c) {
                $comp = self::item((int) $c['component_id']);
                self::explode((int) $comp['id'], self::toBase($comp, $c['qty'], $c['uom_id']) * $qty, $acc, $depth + 1);
            }
        }
        return $acc;
    }

    /** Cost of one base unit (for composites: one serving, computed from the recipe). */
    public static function unitCost(int $itemId, int $depth = 0): float
    {
        if ($depth > 8) return 0.0;
        $item = self::item($itemId);
        if ($item['item_type'] !== 'composite') return (float) $item['avg_cost'];
        $total = 0.0;
        foreach (DB::all('SELECT * FROM item_components WHERE parent_id = ?', [$itemId]) as $c) {
            $comp = self::item((int) $c['component_id']);
            try { $f = self::factorToBase($comp, $c['uom_id']); } catch (HttpException $e) { $f = 0; }
            $total += (float) $c['qty'] * $f * self::unitCost((int) $comp['id'], $depth + 1);
        }
        return r4($total);
    }

    /**
     * Record a stock movement for a stocked item (non-stocked items are ignored).
     * $qty is signed and in base units. Inbound movements with a unit cost update the moving average.
     * Returns the signed cost value of the movement.
     *
     * $m = ['item_id', 'qty', 'mtype', 'unit_cost' (optional), 'ref_type', 'ref_id', 'ref_no', 'bdate', 'notes']
     */
    public static function move(array $m): float
    {
        $item = DB::one('SELECT * FROM items WHERE id = ? FOR UPDATE', [$m['item_id']]);
        if (!$item) throw HttpException::bad('Item not found');
        if (!self::isStocked($item)) return 0.0;
        $qty = r4($m['qty']);
        if ($qty == 0) return 0.0;
        $onHand = (float) $item['stock_qty'];
        $avg = (float) $item['avg_cost'];
        $hasCost = $qty > 0 && isset($m['unit_cost']);
        $cost = $hasCost ? (float) $m['unit_cost'] : $avg;
        $newAvg = $avg;
        if ($hasCost) {
            $base = max($onHand, 0);
            $newAvg = $base + $qty > 0 ? ($base * $avg + $qty * $cost) / ($base + $qty) : $cost;
        }
        $balance = r4($onHand + $qty);
        $total = r2($qty * $cost);
        DB::insert('stock_movements', [
            'item_id' => $item['id'], 'ts' => now(), 'bdate' => $m['bdate'] ?? today(), 'mtype' => $m['mtype'],
            'qty' => $qty, 'unit_cost' => r4($cost), 'total_cost' => $total, 'balance_after' => $balance,
            'ref_type' => $m['ref_type'] ?? null, 'ref_id' => $m['ref_id'] ?? null, 'ref_no' => $m['ref_no'] ?? null,
            'notes' => isset($m['notes']) ? mb_substr((string) $m['notes'], 0, 255) : null, 'user_id' => Auth::id(),
        ]);
        $upd = ['stock_qty' => $balance, 'avg_cost' => r4($newAvg), 'updated_at' => now()];
        if ($hasCost) $upd['last_cost'] = r4($cost);
        DB::update('items', (int) $item['id'], $upd);
        return $total;
    }

    /**
     * Deduct (sign -1) the stock used by sold lines [['item_id'=>, 'qty'=>], ...].
     * Returns the total cost (positive) = cost of goods sold.
     * $detail (optional) receives ['stock' => [stocked item id => cost], 'lines' => [line key => cost]] so the
     * caller can post to per-category inventory / COGS accounts; the parts add up to the returned total.
     */
    public static function consume(array $lines, string $mtype, array $ref, int $sign = -1, ?array &$detail = null): float
    {
        $need = [];
        $perLine = [];
        foreach ($lines as $k => $l) {
            self::explode((int) $l['item_id'], (float) $l['qty'], $need);
            $one = [];
            $perLine[$k] = self::explode((int) $l['item_id'], (float) $l['qty'], $one);
        }
        $total = 0.0;
        $stock = [];
        foreach ($need as $itemId => $q) {
            $stock[$itemId] = abs(self::move(['item_id' => $itemId, 'qty' => $sign * $q, 'mtype' => $mtype] + $ref));
            $total += $stock[$itemId];
        }
        $total = r2($total);
        // Cost of each sold line = its share of every ingredient's cost
        $lineCost = [];
        foreach ($perLine as $k => $one) {
            $c = 0.0;
            foreach ($one as $itemId => $q) $c += $need[$itemId] > 0 ? $stock[$itemId] * $q / $need[$itemId] : 0;
            $lineCost[$k] = $c;
        }
        $detail = ['stock' => $stock, 'lines' => $lineCost ? Ledger::allocate($total, $lineCost) : []];
        return $total;
    }

    /**
     * Undo every movement made by a document (used when voiding) at the original cost.
     * Returns the (signed) total value restored.
     */
    public static function reverseMovements(string $refType, int $refId, ?string $bdate = null, ?string $notes = null): float
    {
        $total = 0.0;
        foreach (DB::all('SELECT * FROM stock_movements WHERE ref_type = ? AND ref_id = ? ORDER BY id', [$refType, $refId]) as $m) {
            if (str_ends_with($m['mtype'], '_VOID')) continue;
            $item = DB::one('SELECT * FROM items WHERE id = ? FOR UPDATE', [$m['item_id']]);
            $qty = -(float) $m['qty'];
            $onHand = (float) $item['stock_qty'];
            $balance = r4($onHand + $qty);
            DB::insert('stock_movements', [
                'item_id' => $m['item_id'], 'ts' => now(), 'bdate' => $bdate ?? today(), 'mtype' => $m['mtype'] . '_VOID',
                'qty' => $qty, 'unit_cost' => $m['unit_cost'], 'total_cost' => r2($qty * (float) $m['unit_cost']), 'balance_after' => $balance,
                'ref_type' => $refType, 'ref_id' => $refId, 'ref_no' => $m['ref_no'], 'notes' => $notes, 'user_id' => Auth::id(),
            ]);
            $upd = ['stock_qty' => $balance, 'updated_at' => now()];
            if ((float) $m['qty'] > 0 && $balance > 0) {
                // Backing out a receipt: remove its value from the moving average.
                $value = max($onHand, 0) * (float) $item['avg_cost'] - (float) $m['qty'] * (float) $m['unit_cost'];
                $upd['avg_cost'] = r4(max($value / $balance, 0));
            }
            DB::update('items', (int) $m['item_id'], $upd);
            $total += $qty * (float) $m['unit_cost'];
        }
        return r2($total);
    }
}
