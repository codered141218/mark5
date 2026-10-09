<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Services\Inventory;
use App\Services\Settings;

/**
 * Inventory report queries. Quantities are in each item's base unit.
 * Movement types: RECEIVE, SALE, ISSUE, WASTE, COUNT and their *_VOID reversals.
 */
class InventoryReports
{
    public const MOVE_LABELS = ['RECEIVE' => 'Stock in', 'SALE' => 'Sale', 'ISSUE' => 'Issuance', 'WASTE' => 'Wastage', 'COUNT' => 'Count adj.', 'BEGINNING' => 'Beginning'];

    public const ITEM_TYPES = ['raw' => 'Ingredient', 'composite' => 'Menu (recipe)', 'retail' => 'Retail', 'non_inventory' => 'Non-inventory'];

    /** "Sale (void)" style label for a movement type. */
    public static function moveLabel(string $type): string
    {
        $base = str_replace('_VOID', '', $type);
        return (self::MOVE_LABELS[$base] ?? $base) . (str_ends_with($type, '_VOID') ? ' (void)' : '');
    }

    public static function onHand(?int $categoryId = null): array
    {
        $params = [];
        $where = '';
        if ($categoryId) { $where = 'AND i.category_id = ?'; $params[] = $categoryId; }
        return DB::all(
            "SELECT i.id, i.sku, i.name item, COALESCE(c.name, '') category, i.item_type type, u.abbr uom, i.stock_qty on_hand,
                    i.avg_cost, i.last_cost, ROUND(i.stock_qty * i.avg_cost, 2) value, i.reorder_point, i.reorder_qty,
                    CASE WHEN i.stock_qty < 0 THEN 'NEGATIVE' WHEN i.reorder_point > 0 AND i.stock_qty <= i.reorder_point THEN 'REORDER' ELSE 'OK' END status
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type IN ('raw','retail') $where ORDER BY c.name, i.name", $params
        );
    }

    /** Items at or below their reorder point, with a suggested order quantity. */
    public static function reorder(): array
    {
        return DB::all(
            "SELECT i.id, i.sku, i.name item, COALESCE(c.name, '') category, u.abbr uom, i.stock_qty on_hand, i.reorder_point, i.reorder_qty,
                    GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) suggested_order, i.last_cost,
                    ROUND(GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) * i.last_cost, 2) est_cost
             FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type IN ('raw','retail') AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point
             ORDER BY c.name, i.name"
        );
    }

    /** Per item: beginning balance, movements in the period by type, ending balance. */
    public static function movement(string $from, string $to): array
    {
        $in = fn (string ...$types) => "COALESCE(SUM(CASE WHEN m.bdate >= ? AND m.mtype IN ('" . implode("','", $types) . "') THEN m.qty END), 0)";
        $rows = DB::all(
            "SELECT i.id, i.sku, i.name item, u.abbr uom, i.avg_cost,
                    COALESCE(SUM(CASE WHEN m.bdate < ? THEN m.qty END), 0) beginning,
                    {$in('RECEIVE', 'RECEIVE_VOID')} received,
                    -{$in('SALE', 'SALE_VOID')} sold,
                    -{$in('ISSUE', 'ISSUE_VOID')} issued,
                    -{$in('WASTE', 'WASTE_VOID')} wasted,
                    {$in('COUNT')} count_adj,
                    COALESCE(SUM(m.qty), 0) ending
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             LEFT JOIN stock_movements m ON m.item_id = i.id AND m.bdate <= ?
             WHERE i.item_type IN ('raw','retail') AND i.active = 1
             GROUP BY i.id, i.sku, i.name, u.abbr, i.avg_cost ORDER BY i.name",
            [$from, $from, $from, $from, $from, $from, $to]
        );
        foreach ($rows as &$r) $r['ending_value'] = r2($r['ending'] * $r['avg_cost']);
        return $rows;
    }

    /** Stock card: beginning balance, then every movement with a running balance. */
    public static function stockCard(int $itemId, string $from, string $to): array
    {
        $balance = (float) DB::value('SELECT COALESCE(SUM(qty), 0) FROM stock_movements WHERE item_id = ? AND bdate < ?', [$itemId, $from]);
        $moves = DB::all(
            'SELECT m.bdate date, m.ts, m.mtype type, m.ref_no, m.notes, m.qty, m.unit_cost, m.total_cost, u.full_name user
             FROM stock_movements m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.item_id = ? AND m.bdate BETWEEN ? AND ? ORDER BY m.bdate, m.id', [$itemId, $from, $to]
        );
        $rows = [['date' => $from, 'type' => 'BEGINNING', 'ref_no' => '', 'qty_in' => null, 'qty_out' => null, 'balance' => r4($balance), '_bold' => true]];
        foreach ($moves as $m) {
            $q = (float) $m['qty'];
            $balance += $q;
            $rows[] = $m + ['qty_in' => $q > 0 ? $q : null, 'qty_out' => $q < 0 ? -$q : null, 'balance' => r4($balance)];
        }
        return $rows;
    }

    /** Line-level listing of posted RECEIVE / ISSUE / WASTE documents. */
    public static function documents(string $type, string $from, string $to): array
    {
        return DB::all(
            "SELECT d.id doc_id, d.doc_date date, d.doc_no, s.name supplier, d.invoice_no, d.payment_mode, d.issued_to, a.name expense_account,
                    COALESCE(l.reason, d.reason) reason, i.sku, i.name item, l.qty, u.abbr uom, l.base_qty, bu.abbr base_uom, l.unit_cost,
                    l.line_total amount, d.status, cu.full_name prepared_by
             FROM inv_doc_lines l JOIN inv_docs d ON d.id = l.doc_id JOIN items i ON i.id = l.item_id
             LEFT JOIN uoms u ON u.id = l.uom_id LEFT JOIN uoms bu ON bu.id = i.base_uom_id
             LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN accounts a ON a.id = d.expense_account_id
             LEFT JOIN users cu ON cu.id = d.created_by
             WHERE d.doc_type = ? AND d.status = 'posted' AND d.doc_date BETWEEN ? AND ? ORDER BY d.doc_date, d.doc_no, l.id",
            [$type, $from, $to]
        );
    }

    public static function counts(string $from, string $to): array
    {
        return DB::all(
            "SELECT s.count_date date, s.doc_no, i.sku, i.name item, u.abbr uom, l.system_qty, l.counted_qty, l.variance, l.unit_cost, l.variance_value
             FROM count_lines l JOIN count_sessions s ON s.id = l.session_id JOIN items i ON i.id = l.item_id
             LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE s.status = 'posted' AND l.counted_qty IS NOT NULL AND s.count_date BETWEEN ? AND ?
             ORDER BY s.count_date, s.doc_no, i.name", [$from, $to]
        );
    }

    /** Theoretical ingredient usage from sales (per recipes) next to wastage and count shortages. */
    public static function usage(string $from, string $to): array
    {
        return DB::all(
            "SELECT i.sku, i.name item, u.abbr uom,
                    -SUM(CASE WHEN m.mtype IN ('SALE','SALE_VOID') THEN m.qty ELSE 0 END) sold_usage,
                    -SUM(CASE WHEN m.mtype IN ('SALE','SALE_VOID') THEN m.total_cost ELSE 0 END) sold_cost,
                    -SUM(CASE WHEN m.mtype IN ('WASTE','WASTE_VOID') THEN m.qty ELSE 0 END) wasted,
                    -SUM(CASE WHEN m.mtype = 'COUNT' THEN m.qty ELSE 0 END) count_shortage,
                    -SUM(CASE WHEN m.mtype = 'COUNT' THEN m.total_cost ELSE 0 END) shortage_cost
             FROM stock_movements m JOIN items i ON i.id = m.item_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE m.bdate BETWEEN ? AND ? GROUP BY i.id, i.sku, i.name, u.abbr ORDER BY sold_cost DESC", [$from, $to]
        );
    }

    /** Menu costing: recipe cost vs. selling price net of VAT (food cost %). */
    public static function recipeCosting(): array
    {
        $div = \App\Services\Items::vatDivisor();
        $rows = DB::all(
            "SELECT i.id, i.sku, i.name item, COALESCE(c.name, '') category, i.item_type type, i.price
             FROM items i LEFT JOIN categories c ON c.id = i.category_id
             WHERE i.active = 1 AND i.sellable = 1 AND i.item_type <> 'non_inventory' ORDER BY c.name, i.name"
        );
        foreach ($rows as &$r) {
            $cost = Inventory::unitCost((int) $r['id']);
            $net = r2($r['price'] / $div);
            $r['price_net_of_vat'] = $net;
            $r['cost'] = $cost;
            $r['margin'] = r2($net - $cost);
            $r['food_cost_pct'] = $net ? r2($cost / $net * 100) : null;
        }
        return $rows;
    }
}
