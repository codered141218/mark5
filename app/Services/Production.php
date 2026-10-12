<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Production (commissary): turn raw materials into a stocked finished product, e.g. marinated pork BBQ sticks, sauces.
 *
 *  - BOM templates: a finished product, how much one batch yields, and the ingredients per batch. Only users with
 *    production.bom see or edit the ingredients; everyone else sees the template name and yield only (secret recipes).
 *  - Production orders: planned (editable) -> released -> (voided). A plan from a template scales the ingredients to the
 *    planned quantity. Releasing takes the ingredients out of stock at average cost and puts the actual quantity produced
 *    into stock at that cost (unit cost = materials cost / quantity produced).
 *
 *   Release:  Dr Inventory (finished product's account)  /  Cr Inventory (materials' accounts)
 *   Void:     stock movements and journal entry reversed
 */
class Production
{
    public const STATUSES = ['planned' => 'Planned', 'released' => 'Released to inventory', 'cancelled' => 'Cancelled'];

    /** May the current user see BOM ingredients? */
    public static function seesBom(): bool
    {
        return Auth::can('production.bom');
    }

    // ------------------------------------------------------------------ BOM templates
    public static function template(int $id, bool $withLines = true): array
    {
        $t = DB::one('SELECT t.*, i.name AS output_name, i.sku AS output_sku, i.base_uom_id AS output_base_uom, u.abbr AS yield_uom, bu.abbr AS output_base_abbr
                      FROM bom_templates t JOIN items i ON i.id = t.output_item_id LEFT JOIN uoms u ON u.id = t.yield_uom_id
                      LEFT JOIN uoms bu ON bu.id = i.base_uom_id WHERE t.id = ?', [$id]);
        if (!$t) throw HttpException::notFound('BOM template');
        $t['lines'] = $withLines && self::seesBom() ? self::templateLines($id) : [];
        return $t;
    }

    private static function templateLines(int $id): array
    {
        return DB::all('SELECT l.*, i.name AS item_name, i.sku, i.item_type, u.abbr AS uom FROM bom_lines l JOIN items i ON i.id = l.item_id
                        LEFT JOIN uoms u ON u.id = l.uom_id WHERE l.template_id = ? ORDER BY l.id', [$id]);
    }

    public static function templates(bool $activeOnly = false): array
    {
        return DB::all('SELECT t.*, i.name AS output_name, u.abbr AS yield_uom, (SELECT COUNT(*) FROM bom_lines l WHERE l.template_id = t.id) AS ingredients
                        FROM bom_templates t JOIN items i ON i.id = t.output_item_id LEFT JOIN uoms u ON u.id = t.yield_uom_id'
            . ($activeOnly ? ' WHERE t.active = 1' : '') . ' ORDER BY t.active DESC, t.name');
    }

    /** Create ($id null) or update a template. Returns the id. */
    public static function saveTemplate(?int $id, array $d): int
    {
        Auth::require('production.bom');
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') throw HttpException::bad('Enter the template name');
        $out = self::outputItem((int) ($d['output_item_id'] ?? 0));
        $yield = num($d['yield_qty'] ?? 0);
        if ($yield <= 0) throw HttpException::bad('Enter how much one batch makes');
        $yieldUom = !empty($d['yield_uom_id']) ? (int) $d['yield_uom_id'] : (int) $out['base_uom_id'];
        Inventory::toBase($out, 1, $yieldUom);   // the unit must convert to the product's base unit
        $lines = self::cleanLines($d['lines'] ?? [], (int) $out['id']);
        if (!$lines) throw HttpException::bad('Add at least one ingredient');
        $row = ['name' => mb_substr($name, 0, 120), 'output_item_id' => (int) $out['id'], 'yield_qty' => r4($yield), 'yield_uom_id' => $yieldUom,
            'notes' => trim((string) ($d['notes'] ?? '')) ?: null, 'active' => !empty($d['active']) || $id === null ? 1 : 0, 'updated_at' => now()];
        return DB::transaction(function () use ($id, $row, $lines) {
            if ($id) {
                self::template($id, false);
                DB::update('bom_templates', $id, $row);
                DB::run('DELETE FROM bom_lines WHERE template_id = ?', [$id]);
            } else {
                $id = DB::insert('bom_templates', $row + ['code' => Sequence::next('BOM', 'BOM', 3), 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            foreach ($lines as $l) DB::insert('bom_lines', ['template_id' => $id, 'item_id' => $l['item_id'], 'qty' => $l['qty'], 'uom_id' => $l['uom_id'], 'notes' => $l['notes']]);
            Audit::log('save', 'bom_template', $id, ['name' => $row['name'], 'ingredients' => count($lines)]);
            return $id;
        });
    }

    public static function deleteTemplate(int $id): void
    {
        Auth::require('production.bom');
        $t = self::template($id, false);
        if (DB::value('SELECT id FROM production_orders WHERE template_id = ? LIMIT 1', [$id])) {
            DB::update('bom_templates', $id, ['active' => 0]);
            Audit::log('deactivate', 'bom_template', $id, $t['name']);
            return;
        }
        DB::run('DELETE FROM bom_lines WHERE template_id = ?', [$id]);
        DB::run('DELETE FROM bom_templates WHERE id = ?', [$id]);
        Audit::log('delete', 'bom_template', $id, $t['name']);
    }

    /** The finished product: an active stocked item. */
    private static function outputItem(int $id): array
    {
        if (!$id) throw HttpException::bad('Choose the finished product');
        $item = Inventory::item($id);
        if (!Inventory::isStocked($item)) throw HttpException::bad("\"{$item['name']}\" is not a stocked item. The finished product must be a raw material or retail item so it can be kept in stock.");
        return $item;
    }

    /** Validated material lines: [['item_id', 'qty', 'uom_id', 'base_qty', 'notes']]. */
    private static function cleanLines(array $lines, int $outputId): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (empty($l['item_id'])) continue;
            $item = Inventory::item((int) $l['item_id']);
            if ((int) $item['id'] === $outputId) throw HttpException::bad("\"{$item['name']}\" cannot be an ingredient of itself");
            if ($item['item_type'] === 'non_inventory') throw HttpException::bad("\"{$item['name']}\" is a non-inventory item");
            $qty = num($l['qty'] ?? 0);
            if ($qty <= 0) throw HttpException::bad("Quantity for \"{$item['name']}\" must be greater than zero");
            $uomId = !empty($l['uom_id']) ? (int) $l['uom_id'] : (int) $item['base_uom_id'];
            $out[] = ['item_id' => (int) $item['id'], 'qty' => r4($qty), 'uom_id' => $uomId, 'base_qty' => Inventory::toBase($item, $qty, $uomId),
                'notes' => isset($l['notes']) && trim((string) $l['notes']) !== '' ? mb_substr(trim((string) $l['notes']), 0, 255) : null];
        }
        return $out;
    }

    // ------------------------------------------------------------------ production orders
    public static function find(int $id): array
    {
        $o = DB::one('SELECT o.*, i.name AS output_name, i.sku AS output_sku, i.base_uom_id AS output_base_uom, i.stock_qty AS output_stock,
                             u.abbr AS output_uom, bu.abbr AS output_base_abbr, t.name AS template_name, t.code AS template_code,
                             cu.full_name AS created_by_name, ru.full_name AS released_by_name, je.entry_no
                      FROM production_orders o JOIN items i ON i.id = o.output_item_id LEFT JOIN uoms u ON u.id = o.output_uom_id
                      LEFT JOIN uoms bu ON bu.id = i.base_uom_id LEFT JOIN bom_templates t ON t.id = o.template_id
                      LEFT JOIN users cu ON cu.id = o.created_by LEFT JOIN users ru ON ru.id = o.released_by
                      LEFT JOIN journal_entries je ON je.id = o.journal_entry_id WHERE o.id = ?', [$id]);
        if (!$o) throw HttpException::notFound('Production order');
        $o['lines'] = DB::all('SELECT l.*, i.name AS item_name, i.sku, i.item_type, i.stock_qty, u.abbr AS uom, bu.abbr AS base_uom
                               FROM production_lines l JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = l.uom_id
                               LEFT JOIN uoms bu ON bu.id = i.base_uom_id WHERE l.order_id = ? ORDER BY l.id', [$id]);
        return $o;
    }

    /** The order for the current user: ingredient lines removed unless they may see the BOM (count and totals stay). */
    public static function forViewer(array $o): array
    {
        $o['line_count'] = count($o['lines']);
        $o['short'] = count(array_filter($o['lines'], fn ($l) => $o['status'] === 'planned' && self::stockedNeed($l) > 0.00005));
        if (!self::seesBom()) $o['lines'] = [];
        return $o;
    }

    /** Base quantity of a line still missing from stock (0 when enough; recipes are broken down). */
    private static function stockedNeed(array $l): float
    {
        $short = 0.0;
        foreach (Inventory::explode((int) $l['item_id'], (float) $l['base_qty']) as $itemId => $q) {
            $have = (float) DB::value('SELECT stock_qty FROM items WHERE id = ?', [$itemId]);
            $short += max(0, $q - $have);
        }
        return $short;
    }

    public static function list(string $from, string $to, ?string $status = null): array
    {
        return DB::all('SELECT o.*, i.name AS output_name, u.abbr AS output_uom, t.name AS template_name, cu.full_name AS created_by_name
                        FROM production_orders o JOIN items i ON i.id = o.output_item_id LEFT JOIN uoms u ON u.id = o.output_uom_id
                        LEFT JOIN bom_templates t ON t.id = o.template_id LEFT JOIN users cu ON cu.id = o.created_by
                        WHERE o.prod_date BETWEEN ? AND ?' . ($status ? ' AND o.status = ?' : '') . ' ORDER BY o.prod_date DESC, o.id DESC',
            $status ? [$from, $to, $status] : [$from, $to]);
    }

    /**
     * Create ($id null) or update a planned order. From a template, the ingredients are the template's scaled to the planned
     * quantity; users who may see the BOM can change them. Without a template, the materials are entered by hand (BOM users).
     */
    public static function save(?int $id, array $d): int
    {
        Auth::require('production.manage');
        $date = (string) ($d['prod_date'] ?? '') ?: today();
        if (!is_date($date)) throw HttpException::bad('Enter the production date');
        $templateId = (int) ($d['template_id'] ?? 0) ?: null;
        $tpl = $templateId ? DB::one('SELECT * FROM bom_templates WHERE id = ?', [$templateId]) : null;
        if ($templateId && !$tpl) throw HttpException::notFound('BOM template');
        $out = self::outputItem($tpl ? (int) $tpl['output_item_id'] : (int) ($d['output_item_id'] ?? 0));
        $planned = num($d['planned_qty'] ?? 0);
        if ($planned <= 0) throw HttpException::bad('Enter how much to produce');
        $uomId = !empty($d['output_uom_id']) ? (int) $d['output_uom_id'] : (int) ($tpl['yield_uom_id'] ?? $out['base_uom_id']);
        $plannedBase = Inventory::toBase($out, $planned, $uomId);
        $existing = $id ? self::find($id) : null;
        if ($existing && $existing['status'] !== 'planned') throw HttpException::bad("{$existing['doc_no']} is already " . strtolower(self::STATUSES[$existing['status']]));

        $editLines = self::seesBom() && array_key_exists('lines', $d) && !empty($d['lines_edited']);
        if ($tpl && !$editLines) {
            $lines = self::scaled($tpl, $out, $plannedBase);
        } elseif ($tpl || self::seesBom()) {
            $lines = self::cleanLines($d['lines'] ?? [], (int) $out['id']);
        } else {
            throw HttpException::bad('Choose a BOM template (only users who may see BOM ingredients can enter the materials by hand)');
        }
        if (!$lines) throw HttpException::bad('Add at least one material');
        $row = ['prod_date' => $date, 'template_id' => $templateId, 'output_item_id' => (int) $out['id'], 'planned_qty' => r4($planned),
            'output_uom_id' => $uomId, 'notes' => trim((string) ($d['notes'] ?? '')) ?: null];
        return DB::transaction(function () use ($id, $row, $lines) {
            if ($id) {
                DB::update('production_orders', $id, $row);
                DB::run('DELETE FROM production_lines WHERE order_id = ?', [$id]);
            } else {
                $id = DB::insert('production_orders', $row + ['doc_no' => Sequence::next('PROD', 'PRD'), 'status' => 'planned', 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            $total = 0.0;
            foreach ($lines as $l) {
                $est = r2($l['base_qty'] * Inventory::unitCost($l['item_id']));
                $total += $est;
                DB::insert('production_lines', ['order_id' => $id, 'item_id' => $l['item_id'], 'qty' => $l['qty'], 'uom_id' => $l['uom_id'],
                    'base_qty' => $l['base_qty'], 'line_total' => $est, 'notes' => $l['notes']]);
            }
            DB::update('production_orders', $id, ['material_cost' => r2($total)]);
            Audit::log('save', 'production', $id, ['output' => $row['output_item_id'], 'qty' => $row['planned_qty']]);
            return $id;
        });
    }

    /** Template ingredients for a planned output (base units): batches = planned / yield. */
    private static function scaled(array $tpl, array $out, float $plannedBase): array
    {
        $yieldBase = Inventory::toBase($out, (float) $tpl['yield_qty'], $tpl['yield_uom_id']);
        if ($yieldBase <= 0) throw HttpException::bad('The template has no yield');
        $batches = $plannedBase / $yieldBase;
        $lines = [];
        foreach (self::templateLines((int) $tpl['id']) as $l) {
            $item = Inventory::item((int) $l['item_id']);
            $qty = r4((float) $l['qty'] * $batches);
            $lines[] = ['item_id' => (int) $l['item_id'], 'qty' => $qty, 'uom_id' => (int) $l['uom_id'] ?: (int) $item['base_uom_id'],
                'base_qty' => Inventory::toBase($item, $qty, $l['uom_id'] ?: $item['base_uom_id']), 'notes' => $l['notes']];
        }
        return $lines;
    }

    /**
     * Release a planned order to inventory: materials out of stock at average cost, the quantity actually produced (in the
     * order's unit) into stock at materials cost / quantity. Returns the journal entry id (null when nothing had a cost).
     */
    public static function release(int $id, $actualQty = null): ?int
    {
        Auth::require('production.manage');
        return DB::transaction(function () use ($id, $actualQty) {
            $status = DB::value('SELECT status FROM production_orders WHERE id = ? FOR UPDATE', [$id]);
            if ($status !== 'planned') throw HttpException::bad('Only planned production can be released');
            $o = self::find($id);
            if (!$o['lines']) throw HttpException::bad('The production has no materials');
            $out = Inventory::item((int) $o['output_item_id']);
            $actual = $actualQty === null || $actualQty === '' ? (float) $o['planned_qty'] : num($actualQty);
            if ($actual <= 0) throw HttpException::bad('Enter how much was produced');
            $actualBase = Inventory::toBase($out, $actual, $o['output_uom_id']);
            $ref = ['ref_type' => 'production', 'ref_id' => $id, 'ref_no' => $o['doc_no'], 'bdate' => $o['prod_date']];
            $total = 0.0;
            $byAccount = [];
            foreach ($o['lines'] as $l) {
                $lineCost = 0.0;
                foreach (Inventory::explode((int) $l['item_id'], (float) $l['base_qty']) as $itemId => $q) {
                    $cost = abs(Inventory::move($ref + ['item_id' => $itemId, 'qty' => -$q, 'mtype' => 'PROD_OUT', 'notes' => 'Used for ' . $o['output_name']]));
                    $lineCost += $cost;
                    $acct = Ledger::itemAccount((int) $itemId, 'inventory');
                    $byAccount[$acct] = ($byAccount[$acct] ?? 0) + $cost;
                }
                DB::update('production_lines', (int) $l['id'], ['line_total' => r2($lineCost)]);
                $total += $lineCost;
            }
            $total = r2($total);
            $unit = r4($total / $actualBase);
            Inventory::move($ref + ['item_id' => (int) $out['id'], 'qty' => $actualBase, 'mtype' => 'PROD_IN', 'unit_cost' => $unit, 'notes' => 'Produced']);
            $jeId = $total > 0 ? Ledger::post($o['prod_date'], "Production {$o['doc_no']} - " . qty($actual) . " {$o['output_uom']} {$o['output_name']}", [
                ['account_id' => Ledger::itemAccount((int) $out['id'], 'inventory'), 'debit' => $total, 'memo' => $o['output_name']],
                ...Ledger::linesByAccount($byAccount, 'credit', 'Materials'),
            ], 'production', $id, $o['doc_no']) : null;
            DB::update('production_orders', $id, ['status' => 'released', 'actual_qty' => r4($actual), 'actual_base' => $actualBase, 'material_cost' => $total,
                'unit_cost' => $unit, 'journal_entry_id' => $jeId, 'released_by' => Auth::id(), 'released_at' => now()]);
            Audit::log('release', 'production', $id, ['doc' => $o['doc_no'], 'produced' => $actual, 'cost' => $total]);
            return $jeId;
        });
    }

    /** Cancel a planned order, or void a released one (stock and journal entry reversed). */
    public static function cancel(int $id, string $reason): void
    {
        Auth::require('production.manage');
        $reason = trim($reason);
        if ($reason === '') throw HttpException::bad('Give a reason');
        DB::transaction(function () use ($id, $reason) {
            $status = DB::value('SELECT status FROM production_orders WHERE id = ? FOR UPDATE', [$id]);
            $o = self::find($id);
            if ($status === 'cancelled') throw HttpException::bad('Already cancelled');
            if ($status === 'released') {
                Inventory::reverseMovements('production', $id, today(), "Void {$o['doc_no']}");
                Ledger::reverse($o['journal_entry_id'] ? (int) $o['journal_entry_id'] : null, today(), "Void production {$o['doc_no']}: $reason");
            }
            DB::update('production_orders', $id, ['status' => 'cancelled', 'notes' => implode(' | ', array_filter([$o['notes'], ($status === 'released' ? 'VOID: ' : 'CANCELLED: ') . $reason]))]);
            Audit::log($status === 'released' ? 'void' : 'cancel', 'production', $id, $reason);
        });
    }

    public static function delete(int $id): void
    {
        Auth::require('production.manage');
        $o = self::find($id);
        if ($o['status'] !== 'planned') throw HttpException::bad('Only planned production can be deleted');
        DB::run('DELETE FROM production_lines WHERE order_id = ?', [$id]);
        DB::run('DELETE FROM production_orders WHERE id = ?', [$id]);
        Audit::log('delete', 'production', $id, $o['doc_no']);
    }
}
