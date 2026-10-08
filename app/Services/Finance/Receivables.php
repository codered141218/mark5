<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Accounts receivable: customer invoices (charge accounts) and their collections.
 * Invoice:    Dr Accounts Receivable (customer) / Cr income account (default Sales)
 * Collection: Dr cash or bank / Cr Accounts Receivable (customer)
 * Invoices created by a POS "charge" payment (source_type pos_sale) are voided from the POS receipt, not here.
 */
class Receivables
{
    public const METHODS = ['cash' => 'Cash on hand', 'bank' => 'Bank account'];

    /** $f: from, to, status ('' | unpaid | open | partial | paid | void), customer_id */
    public static function list(array $f): array
    {
        $where = ['i.inv_date BETWEEN ? AND ?'];
        $params = [$f['from'] ?? '1000-01-01', $f['to'] ?? '9999-12-31'];
        $status = $f['status'] ?? '';
        if ($status === 'unpaid') $where[] = "i.status IN ('open','partial')";
        elseif ($status) { $where[] = 'i.status = ?'; $params[] = $status; }
        if (!empty($f['customer_id'])) { $where[] = 'i.customer_id = ?'; $params[] = (int) $f['customer_id']; }
        return DB::all(
            'SELECT i.*, c.name AS customer_name, a.name AS income_account_name, ROUND(i.amount - i.paid_amount, 2) AS balance
             FROM ar_invoices i JOIN customers c ON c.id = i.customer_id LEFT JOIN accounts a ON a.id = i.income_account_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.inv_date DESC, i.id DESC',
            $params
        );
    }

    public static function find(int $id): array
    {
        $i = DB::one(
            'SELECT i.*, c.name AS customer_name, a.code AS account_code, a.name AS account_name
             FROM ar_invoices i JOIN customers c ON c.id = i.customer_id LEFT JOIN accounts a ON a.id = i.income_account_id WHERE i.id = ?',
            [$id]
        );
        if (!$i) throw HttpException::notFound('Invoice');
        $i['balance'] = r2($i['amount'] - $i['paid_amount']);
        $i['receipts'] = DB::all(
            'SELECT r.*, b.bank_name, u.full_name AS created_by_name FROM ar_receipts r
             LEFT JOIN bank_accounts b ON b.id = r.bank_account_id LEFT JOIN users u ON u.id = r.created_by
             WHERE r.invoice_id = ? ORDER BY r.rcpt_date, r.id',
            [$id]
        );
        return $i;
    }

    public static function create(array $d): int
    {
        required($d, 'customer_id', 'inv_date', 'amount');
        if (!is_date($d['inv_date'])) throw HttpException::bad('Enter a valid invoice date');
        $amount = r2($d['amount']);
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        $cust = DB::one('SELECT * FROM customers WHERE id = ?', [$d['customer_id']]);
        if (!$cust) throw HttpException::notFound('Customer');
        $incomeId = !empty($d['income_account_id']) ? (int) $d['income_account_id'] : Ledger::account('sales');
        if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$incomeId])) throw HttpException::notFound('Income account');
        $dueDate = is_date($d['due_date'] ?? null) ? $d['due_date'] : add_days($d['inv_date'], (int) $cust['terms_days']);
        $refNo = trim((string) ($d['ref_no'] ?? '')) ?: null;
        $description = trim((string) ($d['description'] ?? '')) ?: null;

        $id = DB::transaction(function () use ($d, $cust, $amount, $incomeId, $dueDate, $refNo, $description) {
            $id = DB::insert('ar_invoices', [
                'invoice_no' => Sequence::next('AR', 'AR'), 'customer_id' => $cust['id'], 'inv_date' => $d['inv_date'], 'due_date' => $dueDate,
                'ref_no' => $refNo, 'description' => $description, 'amount' => $amount, 'paid_amount' => 0, 'status' => 'open',
                'income_account_id' => $incomeId, 'source_type' => 'manual', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($d['inv_date'], 'Invoice ' . $cust['name'] . ($description ? " - $description" : ''), [
                ['key' => 'ar', 'debit' => $amount, 'party_type' => 'customer', 'party_id' => (int) $cust['id']],
                ['account_id' => $incomeId, 'credit' => $amount],
            ], 'ar_invoice', $id, $refNo);
            DB::update('ar_invoices', $id, ['journal_entry_id' => $je]);
            return $id;
        });
        Audit::log('create', 'ar_invoice', $id, ['amount' => $amount, 'customer_id' => $cust['id']]);
        return $id;
    }

    public static function void(int $id, ?string $reason): void
    {
        $i = DB::one('SELECT * FROM ar_invoices WHERE id = ?', [$id]);
        if (!$i) throw HttpException::notFound('Invoice');
        if ($i['source_type'] === 'pos_sale') throw HttpException::bad('This receivable came from a POS charge. Void the POS receipt instead.');
        if ((float) $i['paid_amount'] > 0) throw HttpException::bad('Void the collections first');
        if ($i['status'] === 'void') throw HttpException::bad('Already void');
        DB::transaction(function () use ($i) {
            Ledger::reverse($i['journal_entry_id'] ? (int) $i['journal_entry_id'] : null, today());
            DB::update('ar_invoices', (int) $i['id'], ['status' => 'void']);
        });
        Audit::log('void', 'ar_invoice', $id, $reason);
    }

    /** Record a (partial) collection. $d: amount, method (cash|bank), bank_account_id, reference, rcpt_date. Returns the receipt id. */
    public static function collect(int $invoiceId, array $d): int
    {
        $i = DB::one('SELECT i.*, c.name AS customer_name FROM ar_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?', [$invoiceId]);
        if (!$i) throw HttpException::notFound('Invoice');
        if (!in_array($i['status'], ['open', 'partial'], true)) throw HttpException::bad('Invoice is not open');
        $amount = r2($d['amount'] ?? 0);
        $balance = r2($i['amount'] - $i['paid_amount']);
        if (!($amount > 0) || $amount > $balance + 0.001) throw HttpException::bad('Amount must be between 0 and the balance of ' . number_format($balance, 2));
        $method = $d['method'] ?? 'cash';
        if (!isset(self::METHODS[$method])) throw HttpException::bad('Choose where the collection was received');
        $bankId = $method === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;
        $date = is_date($d['rcpt_date'] ?? null) ? $d['rcpt_date'] : today();
        $reference = trim((string) ($d['reference'] ?? '')) ?: null;

        $id = DB::transaction(function () use ($i, $amount, $method, $bankId, $date, $reference) {
            $target = Ledger::sourceAccount($method, $bankId);
            $id = DB::insert('ar_receipts', [
                'doc_no' => Sequence::next('CR', 'CR'), 'invoice_id' => $i['id'], 'rcpt_date' => $date, 'amount' => $amount, 'method' => $method,
                'bank_account_id' => $bankId, 'reference' => $reference, 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($date, "Collection from {$i['customer_name']} ({$i['invoice_no']})", [
                ['account_id' => $target, 'debit' => $amount, 'bank_account_id' => $bankId],
                ['key' => 'ar', 'credit' => $amount, 'party_type' => 'customer', 'party_id' => (int) $i['customer_id']],
            ], 'ar_receipt', $id, $reference);
            DB::update('ar_receipts', $id, ['journal_entry_id' => $je]);
            $paid = r2($i['paid_amount'] + $amount);
            DB::update('ar_invoices', (int) $i['id'], ['paid_amount' => $paid, 'status' => Payables::status((float) $i['amount'], $paid)]);
            return $id;
        });
        Audit::log('collect', 'ar_invoice', $invoiceId, ['amount' => $amount, 'method' => $method]);
        return $id;
    }

    public static function voidReceipt(int $receiptId, ?string $reason): void
    {
        $r = DB::one('SELECT * FROM ar_receipts WHERE id = ?', [$receiptId]);
        if (!$r || $r['status'] !== 'posted') throw HttpException::bad('Collection not found or already void');
        DB::transaction(function () use ($r) {
            Ledger::reverse($r['journal_entry_id'] ? (int) $r['journal_entry_id'] : null, today());
            DB::update('ar_receipts', (int) $r['id'], ['status' => 'void']);
            $i = DB::one('SELECT * FROM ar_invoices WHERE id = ? FOR UPDATE', [$r['invoice_id']]);
            $paid = r2($i['paid_amount'] - $r['amount']);
            DB::update('ar_invoices', (int) $i['id'], ['paid_amount' => $paid, 'status' => Payables::status((float) $i['amount'], $paid)]);
        });
        Audit::log('void', 'ar_receipt', $receiptId, $reason);
    }

    public static function stats(): array
    {
        return Aging::stats('ar_invoices', 'inv_date');
    }

    public static function aging(string $asOf): array
    {
        return Aging::compute('ar_invoices', 'customers', 'customer_id', 'inv_date', $asOf);
    }
}
