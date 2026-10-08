<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;

/**
 * Bank and e-wallet accounts. Each bank has its own GL account ("Cash in Bank - BDO 7890", codes 1031, 1032 ...).
 * Deposits, withdrawals and transfers post to the GL; voids reverse the entry.
 */
class Banks
{
    public const TXN_TYPES = ['deposit' => 'Money in', 'withdrawal' => 'Money out', 'transfer' => 'Transfer'];
    public const ACCOUNT_TYPES = ['savings' => 'Savings', 'checking' => 'Checking / current', 'e-wallet' => 'E-wallet', 'time_deposit' => 'Time deposit'];

    /** Banks with their GL code and current balance. */
    public static function all(bool $activeOnly = false): array
    {
        $rows = DB::all(
            'SELECT b.*, a.code AS gl_code, a.name AS gl_name FROM bank_accounts b JOIN accounts a ON a.id = b.gl_account_id'
            . ($activeOnly ? ' WHERE b.active = 1' : '') . ' ORDER BY b.bank_name'
        );
        foreach ($rows as &$b) $b['balance'] = Ledger::balance((int) $b['gl_account_id']);
        return $rows;
    }

    /** [id => "BDO ••7890 – Account name"] for selects. */
    public static function options(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM bank_accounts WHERE active = 1 ORDER BY bank_name') as $b) $out[$b['id']] = self::label($b);
        return $out;
    }

    public static function label(array $b): string
    {
        return $b['bank_name'] . ($b['account_no'] ? ' ••' . substr($b['account_no'], -4) : '') . ($b['account_name'] ? ' – ' . $b['account_name'] : '');
    }

    /** Create a bank, its own GL account and (optionally) the opening balance: Dr bank / Cr Opening Balance Equity. */
    public static function create(array $d): int
    {
        required($d, 'bank_name');
        $f = self::fields($d);
        $opening = r2($d['opening_balance'] ?? 0);
        $id = DB::transaction(function () use ($f, $opening, $d) {
            $n = 1;
            while (DB::value('SELECT id FROM accounts WHERE code = ?', ["103$n"])) $n++;
            $glId = DB::insert('accounts', ['code' => "103$n", 'name' => self::glName($f['bank_name'], $f['account_no']),
                'type' => 'asset', 'subtype' => 'bank', 'is_system' => 0, 'active' => 1]);
            $id = DB::insert('bank_accounts', $f + ['gl_account_id' => $glId, 'active' => 1]);
            if ($opening != 0) {
                Ledger::post(is_date($d['opening_date'] ?? null) ? $d['opening_date'] : today(), 'Opening balance - ' . $f['bank_name'], [
                    ['account_id' => $glId, 'debit' => $opening, 'bank_account_id' => $id],
                    ['key' => 'opening_equity', 'credit' => $opening],
                ], 'bank_opening', $id);
            }
            return $id;
        });
        Audit::log('create', 'bank', $id, $f + ['opening_balance' => $opening]);
        return $id;
    }

    public static function update(int $id, array $d): void
    {
        $b = DB::one('SELECT * FROM bank_accounts WHERE id = ?', [$id]);
        if (!$b) throw HttpException::notFound('Bank');
        required($d, 'bank_name');
        $f = self::fields($d) + ['active' => !empty($d['active']) ? 1 : 0];
        DB::transaction(function () use ($b, $f) {
            DB::update('bank_accounts', (int) $b['id'], $f);
            DB::update('accounts', (int) $b['gl_account_id'], ['name' => self::glName($f['bank_name'], $f['account_no'] ?: $b['account_no'])]);
        });
        Audit::log('update', 'bank', $id, $f);
    }

    /** Transactions in a date range, optionally for one bank (including transfers into it). */
    public static function txns(string $from, string $to, ?int $bankId = null): array
    {
        $params = [$from, $to];
        if ($bankId) array_push($params, $bankId, $bankId);
        return DB::all(
            'SELECT t.*, b.bank_name, b.account_no, a.code AS counter_account_code, a.name AS counter_account_name,
                    tb.bank_name AS transfer_bank_name, u.full_name AS created_by_name
             FROM bank_txns t JOIN bank_accounts b ON b.id = t.bank_account_id
             LEFT JOIN accounts a ON a.id = t.counter_account_id
             LEFT JOIN bank_accounts tb ON tb.id = t.transfer_bank_id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.txn_date BETWEEN ? AND ?' . ($bankId ? ' AND (t.bank_account_id = ? OR t.transfer_bank_id = ?)' : '') . '
             ORDER BY t.txn_date DESC, t.id DESC',
            $params
        );
    }

    /**
     * Post a deposit, withdrawal or transfer. Bank charges (e.g. card MDR, transfer fee) go to Bank & Card Charges:
     *   deposit:    Dr bank (amount - charges), Dr bank charges / Cr counter account (amount)
     *   withdrawal: Dr counter account (amount), Dr bank charges / Cr bank (amount + charges)
     *   transfer:   Dr destination bank / Cr source bank
     */
    public static function postTxn(array $d): int
    {
        required($d, 'bank_account_id', 'txn_type', 'amount', 'txn_date');
        $type = $d['txn_type'];
        if (!isset(self::TXN_TYPES[$type])) throw HttpException::bad('Invalid transaction type');
        if (!is_date($d['txn_date'])) throw HttpException::bad('Enter a valid date');
        $amount = r2($d['amount']);
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        $bank = DB::one('SELECT * FROM bank_accounts WHERE id = ?', [$d['bank_account_id']]);
        if (!$bank) throw HttpException::notFound('Bank');
        $bankGl = (int) $bank['gl_account_id'];
        $bankId = (int) $bank['id'];
        $charges = 0.0;
        $counterId = null;
        $toBankId = null;

        if ($type === 'transfer') {
            $to = DB::one('SELECT * FROM bank_accounts WHERE id = ?', [$d['transfer_bank_id'] ?? 0]);
            if (!$to) throw HttpException::bad('Select the destination bank');
            if ((int) $to['id'] === $bankId) throw HttpException::bad('Source and destination bank must differ');
            $toBankId = (int) $to['id'];
            $memo = "Transfer {$bank['bank_name']} → {$to['bank_name']}";
            $lines = [
                ['account_id' => (int) $to['gl_account_id'], 'debit' => $amount, 'bank_account_id' => $toBankId],
                ['account_id' => $bankGl, 'credit' => $amount, 'bank_account_id' => $bankId],
            ];
        } else {
            if (empty($d['counter_account_id'])) throw HttpException::bad($type === 'deposit' ? 'Select where the money came from' : 'Select what the money was used for');
            $counterId = (int) $d['counter_account_id'];
            if (!DB::value('SELECT id FROM accounts WHERE id = ?', [$counterId])) throw HttpException::notFound('Account');
            if ($counterId === $bankGl) throw HttpException::bad('The counter account cannot be the same bank account');
            $charges = r2($d['bank_charges'] ?? 0);
            if ($charges < 0) throw HttpException::bad('Bank charges cannot be negative');
            if ($type === 'deposit') {
                if ($charges >= $amount) throw HttpException::bad('Bank charges must be less than the deposit amount');
                $memo = "Deposit to {$bank['bank_name']}";
                $lines = [
                    ['account_id' => $bankGl, 'debit' => r2($amount - $charges), 'bank_account_id' => $bankId],
                    ['key' => 'bank_charges', 'debit' => $charges],
                    ['account_id' => $counterId, 'credit' => $amount],
                ];
            } else {
                $memo = "Withdrawal from {$bank['bank_name']}";
                $lines = [
                    ['account_id' => $counterId, 'debit' => $amount],
                    ['key' => 'bank_charges', 'debit' => $charges],
                    ['account_id' => $bankGl, 'credit' => r2($amount + $charges), 'bank_account_id' => $bankId],
                ];
            }
        }

        $reference = trim((string) ($d['reference'] ?? '')) ?: null;
        $description = trim((string) ($d['description'] ?? '')) ?: null;
        $id = DB::transaction(function () use ($d, $bankId, $type, $amount, $charges, $counterId, $toBankId, $reference, $description, $memo, $lines) {
            $id = DB::insert('bank_txns', [
                'doc_no' => Sequence::next('BT', 'BT'), 'bank_account_id' => $bankId, 'txn_date' => $d['txn_date'], 'txn_type' => $type,
                'amount' => $amount, 'bank_charges' => $charges, 'counter_account_id' => $counterId, 'transfer_bank_id' => $toBankId,
                'reference' => $reference, 'description' => $description, 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $je = Ledger::post($d['txn_date'], $description ? "$memo - $description" : $memo, $lines, 'bank', $id, $reference);
            DB::update('bank_txns', $id, ['journal_entry_id' => $je]);
            return $id;
        });
        Audit::log('create', 'bank_txn', $id, ['type' => $type, 'amount' => $amount, 'charges' => $charges]);
        return $id;
    }

    public static function voidTxn(int $id, ?string $reason): void
    {
        $t = DB::one('SELECT * FROM bank_txns WHERE id = ?', [$id]);
        if (!$t) throw HttpException::notFound('Bank transaction');
        if ($t['status'] !== 'posted') throw HttpException::bad('Already void');
        DB::transaction(function () use ($t) {
            Ledger::reverse($t['journal_entry_id'] ? (int) $t['journal_entry_id'] : null, today());
            DB::update('bank_txns', (int) $t['id'], ['status' => 'void']);
        });
        Audit::log('void', 'bank_txn', $id, $reason);
    }

    /** Bank-side effect of a transaction as seen from $bankId (or from the source bank when no bank is selected). */
    public static function moneyInOut(array $t, ?int $bankId = null): array
    {
        $incoming = $t['txn_type'] === 'deposit' || ($t['txn_type'] === 'transfer' && $bankId && (int) $t['transfer_bank_id'] === $bankId);
        if ($incoming) return [r2($t['amount'] - ($t['txn_type'] === 'deposit' ? $t['bank_charges'] : 0)), null];
        return [null, r2($t['amount'] + ($t['txn_type'] === 'withdrawal' ? $t['bank_charges'] : 0))];
    }

    private static function fields(array $d): array
    {
        $f = pick($d, ['bank_name', 'account_name', 'account_no', 'account_type', 'notes']) + array_fill_keys(['account_name', 'account_no', 'account_type', 'notes'], null);
        $f['bank_name'] = trim($f['bank_name']);
        return $f;
    }

    private static function glName(string $bankName, ?string $accountNo): string
    {
        return 'Cash in Bank - ' . $bankName . ($accountNo ? ' ' . substr($accountNo, -4) : '');
    }
}
