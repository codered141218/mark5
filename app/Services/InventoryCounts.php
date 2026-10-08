<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Physical inventory counts.
 * start() snapshots every active raw/retail item (optionally one category) into a count sheet,
 * save() stores the counted quantities, post() sets stock to what was counted (COUNT movements at
 * average cost) and books the over/short in one journal entry: Inventory vs Inventory Variance.
 * Items left blank (counted_qty NULL) are not adjusted.
 */
class InventoryCounts
{
    public static function find(int $id): array
    {
        $s = DB::one(
            'SELECT s.*, c.name AS category_name, u.full_name AS created_by_name, pu.full_name AS posted_by_name, je.entry_no
             FROM count_sessions s LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN users u ON u.id = s.created_by
             LEFT JOIN users pu ON pu.id = s.posted_by LEFT JOIN journal_entries je ON je.id = s.journal_entry_id WHERE s.id = ?',
            [$id]
        );
        if (!$s) throw HttpException::notFound('Count session');
        $s['lines'] = DB::all(
            'SELECT l.*, i.name AS item_name, i.sku, i.stock_qty AS current_qty, i.avg_cost AS current_cost, u.abbr AS uom, c.name AS category_name
             FROM count_lines l JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = i.base_uom_id
             LEFT JOIN categories c ON c.id = i.category_id WHERE l.session_id = ? ORDER BY c.sort_order, c.name, i.name',
            [$id]
        );
        return $s;
    }

    /** Start a count session. Returns its id. */
    public static function start(?string $date, ?int $categoryId, ?string $notes): int
    {
        if ($date && !is_date($date)) throw HttpException::bad('Invalid count date');
        $id = DB::transaction(function () use ($date, $categoryId, $notes) {
            $sql = "SELECT id, stock_qty, avg_cost FROM items WHERE active = 1 AND item_type IN ('raw','retail')";
            $items = $categoryId ? DB::all("$sql AND category_id = ?", [$categoryId]) : DB::all($sql);
            if (!$items) throw HttpException::bad('No stocked items to count');
            $id = DB::insert('count_sessions', [
                'doc_no' => Sequence::next('CNT', 'CNT'), 'count_date' => $date ?: today(), 'status' => 'open',
                'category_id' => $categoryId ?: null, 'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($items as $it) {
                DB::insert('count_lines', ['session_id' => $id, 'item_id' => $it['id'], 'system_qty' => $it['stock_qty'], 'unit_cost' => $it['avg_cost']]);
            }
            return $id;
        });
        Audit::log('create', 'count', $id);
        return $id;
    }

    /** Save counted quantities: [line_id => qty], where '' or null means "not counted". */
    public static function save(int $id, array $counted): void
    {
        $s = self::find($id);
        if ($s['status'] !== 'open') throw HttpException::bad('Count session is already closed');
        DB::transaction(function () use ($id, $counted) {
            foreach ($counted as $lineId => $v) {
                $v = $v === '' || $v === null ? null : r4(num($v));
                if ($v !== null && $v < 0) throw HttpException::bad('Counted quantities cannot be negative');
                DB::run('UPDATE count_lines SET counted_qty = ? WHERE id = ? AND session_id = ?', [$v, (int) $lineId, $id]);
            }
        });
    }

    /** Post: adjust stock to the counted quantities and book the variance. */
    public static function post(int $id): void
    {
        Auth::require('inventory.post');
        DB::transaction(function () use ($id) {
            $status = DB::value('SELECT status FROM count_sessions WHERE id = ? FOR UPDATE', [$id]);
            if ($status !== 'open') throw HttpException::bad('Count session is already closed');
            $s = self::find($id);
            $counted = array_filter($s['lines'], fn ($l) => $l['counted_qty'] !== null);
            if (!$counted) throw HttpException::bad('Enter at least one counted quantity before posting');

            $gain = 0.0;
            $loss = 0.0;
            foreach ($counted as $l) {
                $item = Inventory::item((int) $l['item_id']);
                $onHand = (float) $item['stock_qty'];
                $cost = (float) $item['avg_cost'];
                $variance = r4((float) $l['counted_qty'] - $onHand);
                $value = r2($variance * $cost);
                DB::run('UPDATE count_lines SET system_qty = ?, variance = ?, unit_cost = ?, variance_value = ? WHERE id = ?',
                    [$onHand, $variance, $cost, $value, $l['id']]);
                if ($variance == 0) continue;
                Inventory::move(['item_id' => (int) $item['id'], 'qty' => $variance, 'mtype' => 'COUNT', 'unit_cost' => $cost,
                    'ref_type' => 'count', 'ref_id' => $id, 'ref_no' => $s['doc_no'], 'bdate' => $s['count_date']]);
                if ($value > 0) $gain += $value;
                else $loss -= $value;
            }
            $gain = r2($gain);
            $loss = r2($loss);
            // Overages: Dr Inventory / Cr Variance.  Shortages: Dr Variance / Cr Inventory.
            $jeId = Ledger::post($s['count_date'], "Inventory count {$s['doc_no']} variance", [
                ['key' => 'inventory', 'debit' => $gain, 'memo' => 'Count overage'],
                ['key' => 'inv_variance', 'credit' => $gain, 'memo' => 'Count overage'],
                ['key' => 'inv_variance', 'debit' => $loss, 'memo' => 'Count shortage'],
                ['key' => 'inventory', 'credit' => $loss, 'memo' => 'Count shortage'],
            ], 'inv_count', $id, $s['doc_no']);
            DB::update('count_sessions', $id, ['status' => 'posted', 'posted_by' => Auth::id(), 'posted_at' => now(),
                'journal_entry_id' => $jeId, 'total_variance_value' => r2($gain - $loss)]);
            Audit::log('post', 'count', $id, $s['doc_no']);
        });
    }

    public static function cancel(int $id): void
    {
        $s = self::find($id);
        if ($s['status'] !== 'open') throw HttpException::bad('Only open count sessions can be cancelled');
        DB::update('count_sessions', $id, ['status' => 'cancelled']);
        Audit::log('cancel', 'count', $id, $s['doc_no']);
    }
}
