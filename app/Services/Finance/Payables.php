<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Accounts payable: supplier bills and their payments.
 * Bill:    Dr expense/asset account / Cr Accounts Payable (supplier)
 * Payment: Dr Accounts Payable (supplier) / Cr cash, petty cash or bank
 * Bills created by a delivery on credit (source_type inv_receive) are voided from the delivery, not here.
 */
class Payables
{
    public const METHODS = ['cash' => 'Cash on hand', 'petty_cash' => 'Petty cash fund', 'bank' => 'Bank account'];

    /** open / partial / paid from the amount and what has been paid so far. */
    public static function status(float $amount, float $paid): string
    {
        if ($paid <= 0.001) return 'open';
        if ($paid + 0.001 >= $amount) return 'paid';
        return 'partial';
    }

    /** $f: from, to, status ('' | unpaid | open | partial | paid | void), supplier_id */
    public static function list(array $f): array
    {
        $where = ['b.bill_date BETWEEN ? AND ?'];
        $params = [$f['from'] ?? '1000-01-01', $f['to'] ?? '9999-12-31'];
        $status = $f['status'] ?? '';
        if ($status === 'unpaid') $where[] = "b.status IN ('open','partial')";
        elseif ($status) { $where[] = 'b.status = ?'; $params[] = $status; }
        if (!empty($f['supplier_id'])) { $where[] = 'b.supplier_id = ?'; $params[] = (int) $f['supplier_id']; }
        return DB::all(
            'SELECT b.*, s.name AS supplier_name, a.name AS expense_account_name, ROUND(b.amount - b.paid_amount, 2) AS balance
             FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id LEFT JOIN accounts a ON a.id = b.expense_account_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY b.bill_date DESC, b.id DESC',
            $params
        );
    }

    public static function find(int $id): array
    {
        $b = DB::one(
            'SELECT b.*, s.name AS supplier_name, a.code AS account_code, a.name AS account_name
             FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id LEFT JOIN accounts a ON a.id = b.expense_account_id WHERE b.id = ?',
            [$id]
        );
        if (!$b) throw HttpException::notFound('Bill');
        $b['balance'] = r2($b['amount'] - $b['paid_amount']);
        $b['payments'] = DB::all(
            'SELECT p.*, bk.bank_name, u.full_name AS created_by_name FROM ap_payments p
             LEFT JOIN bank_accounts bk ON bk.id = p.bank_account_id LEFT JOIN users u ON u.id = p.created_by
             WHERE p.bill_id = ? ORDER BY p.pay_date, p.id',
            [$id]
        );
        return $b;
    }

    public static function create(array $d): int
    {
        required($d, 'supplier_id', 'bill_date', 'amount', 'expense_account_id');
        if (!is_date($d['bill_date'])) throw HttpException::bad('Enter a valid bill date');
        $amount = r2($d['amount']);
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        $sup = DB::one('SELECT * FROM suppliers WHERE id = ?', [$d['supplier_id']]);
        if (!$sup) throw HttpException::notFound('Supplier');
        $accountId = (int) $d['expense_account_id'];
        if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$accountId])) throw HttpException::notFound('Expense account');
        $dueDate = is_date($d['due_date'] ?? null) ? $d['due_date'] : add_days($d['bill_date'], (int) $sup['terms_days']);
        $refNo = trim((string) ($d['ref_no'] ?? '')) ?: null;
        $description = trim((string) ($d['description'] ?? '')) ?: null;

        $id = DB::transaction(function () use ($d, $sup, $amount, $accountId, $dueDate, $refNo, $description) {
            $id = DB::insert('ap_bills', [
                'bill_no' => Sequence::next('AP', 'AP'), 'supplier_id' => $sup['id'], 'bill_date' => $d['bill_date'], 'due_date' => $dueDate,
                'ref_no' => $refNo, 'description' => $description, 'amount' => $amount, 'paid_amount' => 0, 'status' => 'open',
                'expense_account_id' => $accountId, 'source_type' => 'manual', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($d['bill_date'], 'Bill ' . $sup['name'] . ($description ? " - $description" : ''), [
                ['account_id' => $accountId, 'debit' => $amount],
                ['key' => 'ap', 'credit' => $amount, 'party_type' => 'supplier', 'party_id' => (int) $sup['id']],
            ], 'ap_bill', $id, $refNo);
            DB::update('ap_bills', $id, ['journal_entry_id' => $je]);
            return $id;
        });
        Audit::log('create', 'ap_bill', $id, ['amount' => $amount, 'supplier_id' => $sup['id']]);
        return $id;
    }

    public static function void(int $id, ?string $reason): void
    {
        $b = DB::one('SELECT * FROM ap_bills WHERE id = ?', [$id]);
        if (!$b) throw HttpException::notFound('Bill');
        if ($b['source_type'] === 'inv_receive') throw HttpException::bad('This payable came from a delivery. Void the delivery receipt instead.');
        if ((float) $b['paid_amount'] > 0) throw HttpException::bad('Void the payments first');
        if ($b['status'] === 'void') throw HttpException::bad('Already void');
        DB::transaction(function () use ($b) {
            Ledger::reverse($b['journal_entry_id'] ? (int) $b['journal_entry_id'] : null, today());
            DB::update('ap_bills', (int) $b['id'], ['status' => 'void']);
        });
        Audit::log('void', 'ap_bill', $id, $reason);
    }

    /** Record a (partial) payment. $d: amount, method (cash|petty_cash|bank), bank_account_id, reference, pay_date. Returns the payment id. */
    public static function pay(int $billId, array $d): int
    {
        $b = DB::one('SELECT b.*, s.name AS supplier_name FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id WHERE b.id = ?', [$billId]);
        if (!$b) throw HttpException::notFound('Bill');
        if (!in_array($b['status'], ['open', 'partial'], true)) throw HttpException::bad('Bill is not open');
        $amount = r2($d['amount'] ?? 0);
        $balance = r2($b['amount'] - $b['paid_amount']);
        if (!($amount > 0) || $amount > $balance + 0.001) throw HttpException::bad('Amount must be between 0 and the balance of ' . number_format($balance, 2));
        $method = $d['method'] ?? 'cash';
        if (!isset(self::METHODS[$method])) throw HttpException::bad('Choose how the bill is paid');
        $bankId = $method === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;
        $date = is_date($d['pay_date'] ?? null) ? $d['pay_date'] : today();
        $reference = trim((string) ($d['reference'] ?? '')) ?: null;

        $id = DB::transaction(function () use ($b, $amount, $method, $bankId, $date, $reference) {
            $source = Ledger::sourceAccount($method, $bankId);
            $id = DB::insert('ap_payments', [
                'doc_no' => Sequence::next('APV', 'PV'), 'bill_id' => $b['id'], 'pay_date' => $date, 'amount' => $amount, 'method' => $method,
                'bank_account_id' => $bankId, 'reference' => $reference, 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($date, "Payment to {$b['supplier_name']} ({$b['bill_no']})", [
                ['key' => 'ap', 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => (int) $b['supplier_id']],
                ['account_id' => $source, 'credit' => $amount, 'bank_account_id' => $bankId],
            ], 'ap_payment', $id, $reference);
            DB::update('ap_payments', $id, ['journal_entry_id' => $je]);
            $paid = r2($b['paid_amount'] + $amount);
            DB::update('ap_bills', (int) $b['id'], ['paid_amount' => $paid, 'status' => self::status((float) $b['amount'], $paid)]);
            return $id;
        });
        Audit::log('pay', 'ap_bill', $billId, ['amount' => $amount, 'method' => $method]);
        return $id;
    }

    public static function voidPayment(int $paymentId, ?string $reason): void
    {
        $p = DB::one('SELECT * FROM ap_payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'posted') throw HttpException::bad('Payment not found or already void');
        DB::transaction(function () use ($p) {
            Ledger::reverse($p['journal_entry_id'] ? (int) $p['journal_entry_id'] : null, today());
            DB::update('ap_payments', (int) $p['id'], ['status' => 'void']);
            $b = DB::one('SELECT * FROM ap_bills WHERE id = ? FOR UPDATE', [$p['bill_id']]);
            $paid = r2($b['paid_amount'] - $p['amount']);
            DB::update('ap_bills', (int) $b['id'], ['paid_amount' => $paid, 'status' => self::status((float) $b['amount'], $paid)]);
        });
        Audit::log('void', 'ap_payment', $paymentId, $reason);
    }

    /** Unpaid totals for the stat cards: all, overdue, due within 7 days (all dates). */
    public static function stats(): array
    {
        return Aging::stats('ap_bills', 'bill_date');
    }

    /** Unpaid balances per supplier by days past due, as of a date. */
    public static function aging(string $asOf): array
    {
        return Aging::compute('ap_bills', 'suppliers', 'supplier_id', 'bill_date', $asOf);
    }
}
