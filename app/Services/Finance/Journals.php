<?php
namespace App\Services\Finance;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;

/** Journal entries: browsing the general ledger postings, manual entries and voiding them. */
class Journals
{
    /** Human labels for journal_entries.source_type. */
    public const SOURCES = [
        'manual' => 'Manual entry',
        'pos_sale' => 'POS sale',
        'pos_eod' => 'POS end of day',
        'inv_receive' => 'Delivery / stock in',
        'inv_issue' => 'Stock issuance',
        'inv_waste' => 'Spoilage & wastage',
        'inv_count' => 'Inventory count',
        'petty_cash' => 'Petty cash',
        'bank' => 'Bank transaction',
        'bank_opening' => 'Bank opening balance',
        'ap_bill' => 'Supplier bill',
        'ap_payment' => 'Supplier payment',
        'ar_invoice' => 'Customer invoice',
        'ar_receipt' => 'Customer collection',
        'cash_advance' => 'Cash advance release',
        'ca_repayment' => 'Cash advance repayment',
        'disbursement' => 'Payment / expense',
    ];

    public static function list(string $from, string $to, ?string $source = null, ?string $q = null): array
    {
        $where = ['e.entry_date BETWEEN ? AND ?'];
        $params = [$from, $to];
        if ($source) { $where[] = 'e.source_type = ?'; $params[] = $source; }
        if ($q) {
            $where[] = '(e.memo LIKE ? OR e.entry_no LIKE ? OR e.ref_no LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        return DB::all(
            'SELECT e.*, u.full_name AS created_by_name, (SELECT SUM(debit) FROM journal_lines l WHERE l.entry_id = e.id) AS amount
             FROM journal_entries e LEFT JOIN users u ON u.id = e.created_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY e.entry_date DESC, e.id DESC LIMIT 3000',
            $params
        );
    }

    /** One entry with its lines (debits first). */
    public static function find(int $id): array
    {
        $je = DB::one('SELECT e.*, u.full_name AS created_by_name FROM journal_entries e LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?', [$id]);
        if (!$je) throw HttpException::notFound('Journal entry');
        $je['lines'] = DB::all(
            'SELECT l.*, a.code, a.name AS account_name FROM journal_lines l JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_id = ? ORDER BY l.debit DESC, l.id',
            [$id]
        );
        return $je;
    }

    /**
     * Post a manual entry. $d: entry_date, memo, ref_no, lines[] of {account_id, debit, credit, memo}.
     * Ledger::post() rejects unbalanced entries; lines without amounts are ignored.
     */
    public static function postManual(array $d): int
    {
        required($d, 'entry_date');
        if (!is_date($d['entry_date'])) throw HttpException::bad('Enter a valid date');
        $lines = [];
        foreach ($d['lines'] ?? [] as $l) {
            $debit = num($l['debit'] ?? 0);
            $credit = num($l['credit'] ?? 0);
            if (!$debit && !$credit) continue;
            if (empty($l['account_id'])) throw HttpException::bad('Select an account on every line with an amount');
            if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$l['account_id']])) throw HttpException::notFound('Account');
            $lines[] = ['account_id' => (int) $l['account_id'], 'debit' => $debit, 'credit' => $credit, 'memo' => trim((string) ($l['memo'] ?? '')) ?: null];
        }
        $id = Ledger::post($d['entry_date'], trim((string) ($d['memo'] ?? '')) ?: null, $lines, 'manual', null, trim((string) ($d['ref_no'] ?? '')) ?: null);
        if (!$id) throw HttpException::bad('Journal entry has no amounts');
        Audit::log('create', 'journal', $id);
        return $id;
    }

    /** Void a manual entry by posting a reversing entry (dated today unless given). Returns the reversal id. */
    public static function void(int $id, ?string $reason, ?string $date = null): int
    {
        $je = self::find($id);
        if ($je['source_type'] !== 'manual') throw HttpException::bad('System-generated entries must be voided from their source document (receipt, delivery, etc.)');
        if ($je['reversal_of']) throw HttpException::bad('This entry is itself a reversal');
        $rev = Ledger::reverse($id, is_date($date) ? $date : today(), $reason ? "Void {$je['entry_no']}: $reason" : null);
        Audit::log('void', 'journal', $id, $reason);
        return $rev;
    }
}
