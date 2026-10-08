<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Petty cash: small expenses paid from the petty cash box ("fund") or the POS cash drawer ("drawer"),
 * and replenishments of the fund. Every transaction posts a journal entry; a void reverses it.
 * Used by the back office (/petty-cash) and by the POS for drawer payouts.
 */
class PettyCash
{
    public const SOURCES = ['fund' => 'Petty cash box', 'drawer' => 'POS cash drawer'];

    /** Transactions in a date range. Cashiers who only have pos.petty_cash see their own entries. */
    public static function list(string $from, string $to): array
    {
        $onlyMine = !Auth::can('pettycash.view', 'pettycash.manage');
        return DB::all(
            'SELECT p.*, a.code AS account_code, a.name AS account_name, b.bank_name, u.full_name AS created_by_name
             FROM petty_cash_txns p
             LEFT JOIN accounts a ON a.id = p.account_id
             LEFT JOIN bank_accounts b ON b.id = p.bank_account_id
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.txn_date BETWEEN ? AND ?' . ($onlyMine ? ' AND p.created_by = ?' : '') . '
             ORDER BY p.txn_date DESC, p.id DESC',
            $onlyMine ? [$from, $to, Auth::id()] : [$from, $to]
        );
    }

    /** Drawer payouts of one business day (shown on the POS). */
    public static function listForSession(int $cashSessionId): array
    {
        return DB::all(
            "SELECT p.id, p.doc_no, p.description, p.payee, p.amount, p.status, a.name AS account_name, p.created_at
             FROM petty_cash_txns p LEFT JOIN accounts a ON a.id = p.account_id
             WHERE p.cash_session_id = ? AND p.source = 'drawer' ORDER BY p.id",
            [$cashSessionId]
        );
    }

    /** Current balance of the Petty Cash Fund GL account (all dates). */
    public static function fundBalance(): float
    {
        return Ledger::balance(Ledger::account('petty_cash'));
    }

    /**
     * Record an expense or a fund replenishment and post it to the GL. Returns the new id.
     * Expense:   Dr expense account / Cr Petty Cash Fund (fund) or Cash on Hand (drawer).
     * Replenish: Dr Petty Cash Fund / Cr Cash on Hand or the bank.
     */
    public static function record(array $d): int
    {
        Auth::require('pettycash.manage', 'pos.petty_cash');
        $type = $d['txn_type'] ?? 'expense';
        $source = $d['source'] ?? 'fund';
        $amount = r2($d['amount'] ?? 0);
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        if (!in_array($type, ['expense', 'replenish'], true)) throw HttpException::bad('Invalid type');
        if (!array_key_exists($source, self::SOURCES)) throw HttpException::bad('Invalid source');
        if ($type === 'replenish') Auth::require('pettycash.manage');

        $date = is_date($d['txn_date'] ?? null) ? $d['txn_date'] : today();
        $sessionId = null;
        if ($source === 'drawer') {
            $s = DB::one("SELECT id, business_date FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
            if (!$s) throw HttpException::bad('Open the business day before paying out from the cash drawer');
            $sessionId = (int) $s['id'];
            $date = $s['business_date']; // drawer payouts belong to the open business day
        }

        $description = trim((string) ($d['description'] ?? '')) ?: null;
        $payee = trim((string) ($d['payee'] ?? '')) ?: null;
        $bankId = null;
        if ($type === 'expense') {
            required($d, 'account_id');
            if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$d['account_id']])) throw HttpException::notFound('Expense account');
            $memo = 'Petty cash: ' . ($description ?: 'expense') . ($payee ? ' - ' . $payee : '');
            $lines = [
                ['account_id' => (int) $d['account_id'], 'debit' => $amount, 'memo' => $description],
                ['key' => $source === 'drawer' ? 'cash_on_hand' : 'petty_cash', 'credit' => $amount],
            ];
        } else {
            $fromMethod = $d['from_method'] ?? ($source === 'drawer' ? 'cash' : 'bank');
            if (!in_array($fromMethod, ['cash', 'bank'], true)) throw HttpException::bad('Replenish from cash on hand or a bank account');
            $bankId = $fromMethod === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;
            $memo = 'Petty cash fund replenishment';
            $lines = [
                ['key' => 'petty_cash', 'debit' => $amount],
                ['account_id' => Ledger::sourceAccount($fromMethod, $bankId), 'credit' => $amount, 'bank_account_id' => $bankId],
            ];
        }

        $id = DB::transaction(function () use ($d, $type, $source, $amount, $date, $sessionId, $bankId, $payee, $description, $memo, $lines) {
            $orNo = trim((string) ($d['or_no'] ?? '')) ?: null;
            $id = DB::insert('petty_cash_txns', [
                'doc_no' => Sequence::next('PCV', 'PCV'), 'txn_date' => $date, 'txn_type' => $type, 'source' => $source, 'amount' => $amount,
                'account_id' => $type === 'expense' ? (int) $d['account_id'] : null, 'bank_account_id' => $bankId,
                'payee' => $payee, 'description' => $description, 'or_no' => $orNo, 'cash_session_id' => $sessionId,
                'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($date, $memo, $lines, 'petty_cash', $id, $orNo);
            DB::update('petty_cash_txns', $id, ['journal_entry_id' => $je]);
            return $id;
        });
        Audit::log('create', 'petty_cash', $id, ['type' => $type, 'source' => $source, 'amount' => $amount]);
        return $id;
    }

    /**
     * Void a transaction (reverses its journal entry).
     * Without pettycash.manage, a cashier may void only their own drawer payout of the open business day;
     * anything else needs a manager PIN. Payouts of a closed business day can no longer be voided.
     */
    public static function void(int $id, ?string $reason, ?string $pin = null): void
    {
        Auth::require('pettycash.manage', 'pos.petty_cash');
        $p = DB::one('SELECT * FROM petty_cash_txns WHERE id = ?', [$id]);
        if (!$p || $p['status'] !== 'posted') throw HttpException::bad('Transaction not found or already void');
        if (!Auth::can('pettycash.manage')) {
            $open = DB::value("SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
            $ownOpenPayout = (int) $p['created_by'] === Auth::id() && $open && (int) $p['cash_session_id'] === (int) $open;
            if (!$ownOpenPayout) Auth::authorize('pettycash.manage', $pin);
        }
        if ($p['cash_session_id'] && DB::value('SELECT status FROM cash_sessions WHERE id = ?', [$p['cash_session_id']]) === 'closed') {
            throw HttpException::bad('This drawer payout belongs to a closed business day and can no longer be voided');
        }
        DB::transaction(function () use ($p) {
            // A drawer payout is reversed on its own business day so the day's expected cash stays right.
            Ledger::reverse($p['journal_entry_id'] ? (int) $p['journal_entry_id'] : null, $p['cash_session_id'] ? $p['txn_date'] : today());
            DB::update('petty_cash_txns', (int) $p['id'], ['status' => 'void']);
        });
        Audit::log('void', 'petty_cash', $id, $reason);
    }
}
