<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * Inventory documents: RECEIVE (delivery / stock in), ISSUE (stock issuance), WASTE (spoilage & wastage).
 *
 * Life cycle: draft (editable) -> posted (stock moved + journal entry) -> cancelled (voided: both reversed).
 * Postings:
 *   RECEIVE  Dr Inventory (+ Dr Input VAT when VAT-inclusive)  / Cr cash, petty cash, bank, A/P or opening equity
 *            On credit an A/P bill is created, due after the supplier's terms.
 *   ISSUE    Dr chosen expense account / Cr Inventory     (menu items are exploded into their ingredients)
 *   WASTE    Dr Spoilage & Wastage    / Cr Inventory
 */
class InventoryDocs
{
    public const PERMS = ['RECEIVE' => 'inventory.receive', 'ISSUE' => 'inventory.issue', 'WASTE' => 'inventory.waste'];
    public const PREFIX = ['RECEIVE' => 'RR', 'ISSUE' => 'IS', 'WASTE' => 'WS'];
    public const PAYMENT_MODES = [
        'credit' => 'On credit (Accounts Payable)',
        'cash' => 'Paid cash (Cash on Hand)',
        'petty_cash' => 'Paid from petty cash',
        'bank' => 'Paid by bank / check',
        'opening' => 'Opening balance (beginning inventory)',
    ];
    public const WASTE_REASONS = ['spoilage', 'expired', 'damaged', 'preparation waste', 'customer return', 'other'];

    /** A document with its header joins and lines. */
    public static function find(int $id): array
    {
        $d = DB::one(
            'SELECT d.*, s.name AS supplier_name, a.name AS expense_account_name, a.code AS expense_account_code, b.bank_name,
                    u.full_name AS created_by_name, pu.full_name AS posted_by_name, je.entry_no
             FROM inv_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN accounts a ON a.id = d.expense_account_id
             LEFT JOIN bank_accounts b ON b.id = d.bank_account_id LEFT JOIN users u ON u.id = d.created_by
             LEFT JOIN users pu ON pu.id = d.posted_by LEFT JOIN journal_entries je ON je.id = d.journal_entry_id
             WHERE d.id = ?',
            [$id]
        );
        if (!$d) throw HttpException::notFound('Document');
        $d['lines'] = DB::all(
            'SELECT l.*, i.name AS item_name, i.sku, i.item_type, u.abbr AS uom, bu.abbr AS base_uom
             FROM inv_doc_lines l JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = l.uom_id
             LEFT JOIN uoms bu ON bu.id = i.base_uom_id WHERE l.doc_id = ? ORDER BY l.id',
            [$id]
        );
        return $d;
    }

    /** Create a draft (and post it right away when $data['post'] is set). Returns the new id. */
    public static function create(string $type, array $data): int
    {
        if (!isset(self::PERMS[$type])) throw HttpException::bad('Unknown document type');
        $id = DB::transaction(function () use ($type, $data) {
            $id = DB::insert('inv_docs', ['doc_type' => $type, 'doc_no' => Sequence::next($type, self::PREFIX[$type]), 'status' => 'draft']
                + self::header($type, $data) + ['created_by' => Auth::id(), 'created_at' => now()]);
            self::saveLines($id, $type, $data['lines'] ?? []);
            if (!empty($data['post'])) self::post($id);
            return $id;
        });
        Audit::log('create', "inv_doc:$type", $id);
        return $id;
    }

    /** Update a draft (and post it when $data['post'] is set). */
    public static function update(int $id, array $data): void
    {
        $d = self::find($id);
        if ($d['status'] !== 'draft') throw HttpException::bad('Only draft documents can be edited');
        DB::transaction(function () use ($d, $data) {
            DB::update('inv_docs', (int) $d['id'], self::header($d['doc_type'], $data));
            self::saveLines((int) $d['id'], $d['doc_type'], $data['lines'] ?? []);
            if (!empty($data['post'])) self::post((int) $d['id']);
        });
        Audit::log('update', "inv_doc:{$d['doc_type']}", $id);
    }

    public static function delete(int $id): void
    {
        $d = self::find($id);
        if ($d['status'] !== 'draft') throw HttpException::bad('Posted documents must be voided, not deleted');
        DB::run('DELETE FROM inv_docs WHERE id = ?', [$id]);
        Audit::log('delete', "inv_doc:{$d['doc_type']}", $id, $d['doc_no']);
    }

    /** Header columns for the document type, validated. */
    private static function header(string $type, array $b): array
    {
        $date = $b['doc_date'] ?? '';
        if ($date !== '' && !is_date($date)) throw HttpException::bad('Invalid document date');
        $text = fn ($k, $len = 120) => isset($b[$k]) && trim((string) $b[$k]) !== '' ? mb_substr(trim((string) $b[$k]), 0, $len) : null;
        $o = ['doc_date' => $date ?: today(), 'notes' => $text('notes', 2000)];
        if ($type === 'RECEIVE') {
            $mode = $b['payment_mode'] ?? 'credit';
            if (!isset(self::PAYMENT_MODES[$mode])) throw HttpException::bad('Unknown payment mode');
            $o += [
                'supplier_id' => !empty($b['supplier_id']) ? (int) $b['supplier_id'] : null,
                'invoice_no' => $text('invoice_no', 60),
                'payment_mode' => $mode,
                'bank_account_id' => $mode === 'bank' && !empty($b['bank_account_id']) ? (int) $b['bank_account_id'] : null,
                'vat_inclusive' => !empty($b['vat_inclusive']) ? 1 : 0,
            ];
            if ($mode === 'credit' && !$o['supplier_id']) throw HttpException::bad('Supplier is required for deliveries on credit (accounts payable)');
            if ($mode === 'bank' && !$o['bank_account_id']) throw HttpException::bad('Please select the bank account');
        } elseif ($type === 'ISSUE') {
            $acct = !empty($b['expense_account_id']) ? (int) $b['expense_account_id'] : Ledger::account('supplies');
            if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$acct])) throw HttpException::bad('Choose the expense account');
            $o += ['issued_to' => $text('issued_to'), 'reason' => $text('reason'), 'expense_account_id' => $acct];
        } else {
            $reason = $text('reason') ?? 'spoilage';
            if (!in_array($reason, self::WASTE_REASONS, true)) throw HttpException::bad('Choose a wastage reason');
            $o += ['reason' => $reason];
        }
        return $o;
    }

    /**
     * Replace the document lines. Quantities are converted to the item's base unit.
     * RECEIVE lines carry the supplier cost (line total wins over qty x unit cost);
     * ISSUE / WASTE lines are valued at the current average / recipe cost (final cost is set when posted).
     */
    private static function saveLines(int $docId, string $type, array $lines): void
    {
        DB::run('DELETE FROM inv_doc_lines WHERE doc_id = ?', [$docId]);
        $total = 0.0;
        $count = 0;
        foreach ($lines as $l) {
            if (empty($l['item_id'])) continue;
            $item = Inventory::item((int) $l['item_id']);
            $qty = num($l['qty'] ?? 0);
            if ($qty <= 0) throw HttpException::bad("Quantity for \"{$item['name']}\" must be greater than zero");
            if ($item['item_type'] === 'non_inventory') throw HttpException::bad("\"{$item['name']}\" is a non-inventory item");
            if ($type === 'RECEIVE' && !Inventory::isStocked($item)) throw HttpException::bad("\"{$item['name']}\" is not a stocked item and cannot be received");
            $uomId = !empty($l['uom_id']) ? (int) $l['uom_id'] : (int) $item['base_uom_id'];
            $baseQty = Inventory::toBase($item, $qty, $uomId);
            if ($type === 'RECEIVE') {
                $lineTotal = isset($l['line_total']) && $l['line_total'] !== '' ? r2($l['line_total']) : r2($qty * num($l['unit_cost'] ?? 0));
                if ($lineTotal < 0) throw HttpException::bad("Cost for \"{$item['name']}\" cannot be negative");
            } else {
                $lineTotal = r2($baseQty * Inventory::unitCost((int) $item['id']));
            }
            DB::insert('inv_doc_lines', [
                'doc_id' => $docId, 'item_id' => (int) $item['id'], 'qty' => r4($qty), 'uom_id' => $uomId, 'base_qty' => $baseQty,
                'unit_cost' => r4($lineTotal / $qty), 'line_total' => $lineTotal,
                'reason' => isset($l['reason']) && $l['reason'] !== '' ? mb_substr($l['reason'], 0, 120) : null,
                'notes' => isset($l['notes']) && $l['notes'] !== '' ? mb_substr($l['notes'], 0, 255) : null,
            ]);
            $total += $lineTotal;
            $count++;
        }
        if (!$count) throw HttpException::bad('Add at least one item');
        DB::update('inv_docs', $docId, ['total_cost' => r2($total)]);
    }

    /** Post a draft: move stock, book the journal entry and (on credit) the supplier payable. */
    public static function post(int $id): void
    {
        Auth::require('inventory.post');
        DB::transaction(function () use ($id) {
            $status = DB::value('SELECT status FROM inv_docs WHERE id = ? FOR UPDATE', [$id]);
            if ($status !== 'draft') throw HttpException::bad('Document is already posted');
            $d = self::find($id);
            if (!$d['lines']) throw HttpException::bad('Document has no lines');
            $ref = ['ref_type' => 'inv_doc', 'ref_id' => (int) $d['id'], 'ref_no' => $d['doc_no'], 'bdate' => $d['doc_date']];
            $upd = $d['doc_type'] === 'RECEIVE' ? self::postReceive($d, $ref) : self::postIssue($d, $ref);
            DB::update('inv_docs', (int) $d['id'], $upd + ['status' => 'posted', 'posted_by' => Auth::id(), 'posted_at' => now()]);
            Audit::log('post', "inv_doc:{$d['doc_type']}", (int) $d['id'], $d['doc_no']);
        });
    }

    private static function postReceive(array $d, array $ref): array
    {
        $vatRate = $d['vat_inclusive'] ? Settings::tax()['vatRate'] : 0.0;
        $gross = 0.0;
        $net = 0.0;
        foreach ($d['lines'] as $l) {
            $lineTotal = (float) $l['line_total'];
            $lineNet = $vatRate ? r2($lineTotal / (1 + $vatRate)) : $lineTotal;
            $gross += $lineTotal;
            $net += $lineNet;
            $baseQty = (float) $l['base_qty'];
            Inventory::move($ref + ['item_id' => (int) $l['item_id'], 'qty' => $baseQty, 'mtype' => 'RECEIVE',
                'unit_cost' => $baseQty ? $lineNet / $baseQty : 0, 'notes' => $d['supplier_name']]);
        }
        $gross = r2($gross);
        $net = r2($net);
        $memo = "Delivery {$d['doc_no']}" . ($d['supplier_name'] ? " - {$d['supplier_name']}" : '') . ($d['invoice_no'] ? " Inv#{$d['invoice_no']}" : '');
        $party = $d['supplier_id'] ? ['party_type' => 'supplier', 'party_id' => (int) $d['supplier_id']] : [];
        $jeId = Ledger::post($d['doc_date'], $memo, [
            ['key' => 'inventory', 'debit' => $net],
            ['key' => 'input_vat', 'debit' => r2($gross - $net)],
            ['account_id' => Ledger::sourceAccount($d['payment_mode'], $d['bank_account_id']), 'credit' => $gross,
                'bank_account_id' => $d['payment_mode'] === 'bank' ? (int) $d['bank_account_id'] : null] + $party,
        ], 'inv_receive', (int) $d['id'], $d['doc_no']);

        $billId = null;
        if ($d['payment_mode'] === 'credit') {
            $terms = (int) DB::value('SELECT terms_days FROM suppliers WHERE id = ?', [$d['supplier_id']]);
            $billId = DB::insert('ap_bills', [
                'bill_no' => Sequence::next('AP', 'AP'), 'supplier_id' => (int) $d['supplier_id'], 'bill_date' => $d['doc_date'],
                'due_date' => add_days($d['doc_date'], $terms), 'ref_no' => $d['invoice_no'] ?: $d['doc_no'],
                'description' => "Delivery {$d['doc_no']}", 'amount' => $gross, 'paid_amount' => 0, 'status' => 'open',
                'expense_account_id' => Ledger::account('inventory'), 'source_type' => 'inv_receive', 'source_id' => (int) $d['id'],
                'journal_entry_id' => $jeId, 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
        }
        return ['journal_entry_id' => $jeId, 'ap_bill_id' => $billId, 'total_cost' => $gross];
    }

    /** ISSUE and WASTE: deduct stock (composites explode into ingredients) at average cost. */
    private static function postIssue(array $d, array $ref): array
    {
        $issue = $d['doc_type'] === 'ISSUE';
        $mtype = $issue ? 'ISSUE' : 'WASTE';
        $total = 0.0;
        foreach ($d['lines'] as $l) {
            foreach (Inventory::explode((int) $l['item_id'], (float) $l['base_qty']) as $itemId => $q) {
                $total += abs(Inventory::move($ref + ['item_id' => $itemId, 'qty' => -$q, 'mtype' => $mtype, 'notes' => $d['reason'] ?: $d['issued_to']]));
            }
        }
        $total = r2($total);
        $memo = $issue
            ? "Stock issuance {$d['doc_no']}" . ($d['issued_to'] ? " to {$d['issued_to']}" : '')
            : "Spoilage/wastage {$d['doc_no']} ({$d['reason']})";
        $jeId = Ledger::post($d['doc_date'], $memo, [
            ['account_id' => $issue ? (int) $d['expense_account_id'] : Ledger::account('wastage'), 'debit' => $total],
            ['key' => 'inventory', 'credit' => $total],
        ], $issue ? 'inv_issue' : 'inv_waste', (int) $d['id'], $d['doc_no']);
        return ['journal_entry_id' => $jeId, 'total_cost' => $total];
    }

    /**
     * Void a posted document: reverse its stock movements and journal entry and void its payable.
     * Refused when the supplier bill already has payments.
     */
    public static function void(int $id, string $reason): void
    {
        Auth::require('inventory.post');
        $reason = trim($reason);
        if ($reason === '') throw HttpException::bad('Please give a reason for voiding');
        DB::transaction(function () use ($id, $reason) {
            $status = DB::value('SELECT status FROM inv_docs WHERE id = ? FOR UPDATE', [$id]);
            if ($status !== 'posted') throw HttpException::bad('Only posted documents can be voided');
            $d = self::find($id);
            if ($d['ap_bill_id']) {
                $bill = DB::one('SELECT * FROM ap_bills WHERE id = ? FOR UPDATE', [$d['ap_bill_id']]);
                if ($bill && (float) $bill['paid_amount'] > 0) throw HttpException::bad("Payable {$bill['bill_no']} already has payments. Void the payments first.");
                if ($bill) DB::run("UPDATE ap_bills SET status = 'void' WHERE id = ?", [$bill['id']]);
            }
            Inventory::reverseMovements('inv_doc', (int) $d['id'], today(), "Void {$d['doc_no']}");
            Ledger::reverse($d['journal_entry_id'] ? (int) $d['journal_entry_id'] : null, today(), "Void {$d['doc_no']}: $reason");
            DB::update('inv_docs', (int) $d['id'], ['status' => 'cancelled', 'notes' => implode(' | ', array_filter([$d['notes'], "VOID: $reason"]))]);
            Audit::log('void', "inv_doc:{$d['doc_type']}", (int) $d['id'], $reason);
        });
    }
}
