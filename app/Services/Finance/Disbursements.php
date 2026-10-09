<?php
namespace App\Services\Finance;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\Sequence;
use App\Services\Settings;

/**
 * Payments & expenses recorded in the back office (Cash & Finance → Payments & Expenses): rent, electricity,
 * salaries, a loan payment, owner's drawings, equipment … paid from cash on hand, the petty cash fund or a bank.
 *
 *   Dr  <account it was for> (amount net of VAT)
 *   Dr  Input VAT            (when the receipt shows VAT)
 *   Cr  Cash on hand / Petty cash fund / the bank's GL account
 *
 * Payments from a bank also appear in that bank's register. Void = reversing entry.
 */
class Disbursements
{
    public const SOURCES = ['cash' => 'Cash on hand', 'petty_cash' => 'Petty cash fund', 'bank' => 'Bank / e-wallet'];

    public static function list(string $from, string $to, string $source = ''): array
    {
        $where = ['d.txn_date BETWEEN ? AND ?'];
        $params = [$from, $to];
        if (isset(self::SOURCES[$source])) { $where[] = 'd.pay_from = ?'; $params[] = $source; }
        return DB::all(
            'SELECT d.*, a.code AS account_code, a.name AS account_name, b.bank_name, s.name AS supplier_name, u.full_name AS created_by_name
             FROM disbursements d JOIN accounts a ON a.id = d.account_id LEFT JOIN bank_accounts b ON b.id = d.bank_account_id
             LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN users u ON u.id = d.created_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY d.txn_date DESC, d.id DESC', $params
        );
    }

    public static function create(array $d): int
    {
        Auth::require('finance.journal', 'pettycash.manage', 'finance.banks');
        $from = $d['pay_from'] ?? 'cash';
        if (!isset(self::SOURCES[$from])) throw HttpException::bad('Choose where the money came from');
        $amount = r2(num($d['amount'] ?? 0));
        if (!($amount > 0)) throw HttpException::bad('Amount must be greater than zero');
        $accountId = (int) ($d['account_id'] ?? 0);
        $acct = DB::one('SELECT id, type, name FROM accounts WHERE id = ? AND active = 1', [$accountId]);
        if (!$acct) throw HttpException::bad('Choose what the payment was for (account)');
        $date = is_date($d['txn_date'] ?? null) ? $d['txn_date'] : today();
        $bankId = $from === 'bank' ? (int) ($d['bank_account_id'] ?? 0) : null;
        $bank = $bankId ? DB::one('SELECT * FROM bank_accounts WHERE id = ?', [$bankId]) : null;
        if ($from === 'bank' && !$bank) throw HttpException::bad('Choose the bank account');
        $vat = 0.0;
        if (!empty($d['with_vat'])) {
            $rate = Settings::tax()['vatRate'] ?: 0.12;
            $vat = r2($amount - $amount / (1 + $rate));
        }
        $supplierId = !empty($d['supplier_id']) ? (int) $d['supplier_id'] : null;
        $payee = trim((string) ($d['payee'] ?? '')) ?: ($supplierId ? DB::value('SELECT name FROM suppliers WHERE id = ?', [$supplierId]) : null);
        $description = trim((string) ($d['description'] ?? '')) ?: null;
        $reference = trim((string) ($d['reference'] ?? '')) ?: null;
        $sourceAcct = $from === 'bank' ? (int) $bank['gl_account_id'] : Ledger::account($from === 'cash' ? 'cash_on_hand' : 'petty_cash');
        if ($sourceAcct === $accountId) throw HttpException::bad('The payment cannot be for the same account it is paid from');

        $id = DB::transaction(function () use ($date, $from, $bankId, $payee, $supplierId, $accountId, $amount, $vat, $reference, $description, $sourceAcct, $acct) {
            $id = DB::insert('disbursements', [
                'doc_no' => Sequence::next('DV', 'DV'), 'txn_date' => $date, 'pay_from' => $from, 'bank_account_id' => $bankId,
                'payee' => $payee, 'supplier_id' => $supplierId, 'account_id' => $accountId, 'amount' => $amount, 'vat_amount' => $vat,
                'reference' => $reference, 'description' => $description, 'status' => 'posted', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $memo = ($description ?: $acct['name']) . ($payee ? ' - ' . $payee : '');
            $party = $supplierId ? ['party_type' => 'supplier', 'party_id' => $supplierId] : [];
            $je = Ledger::post($date, $memo, [
                ['account_id' => $accountId, 'debit' => r2($amount - $vat), 'memo' => $description] + $party,
                ['key' => 'input_vat', 'debit' => $vat],
                ['account_id' => $sourceAcct, 'credit' => $amount, 'bank_account_id' => $bankId],
            ], 'disbursement', $id, $reference);
            $upd = ['journal_entry_id' => $je];
            if ($bankId) {
                // show it in the bank register too
                $upd['bank_txn_id'] = DB::insert('bank_txns', [
                    'doc_no' => Sequence::next('BT', 'BT'), 'bank_account_id' => $bankId, 'txn_date' => $date, 'txn_type' => 'withdrawal',
                    'amount' => $amount, 'bank_charges' => 0, 'counter_account_id' => $accountId, 'reference' => $reference,
                    'description' => 'Payment: ' . $memo, 'status' => 'posted', 'journal_entry_id' => $je, 'created_by' => Auth::id(), 'created_at' => now(),
                ]);
            }
            DB::update('disbursements', $id, $upd);
            return $id;
        });
        Audit::log('create', 'disbursement', $id, ['amount' => $amount, 'from' => $from, 'account' => $acct['name']]);
        return $id;
    }

    public static function void(int $id, string $reason): void
    {
        Auth::require('finance.journal', 'pettycash.manage', 'finance.banks');
        if (trim($reason) === '') throw HttpException::bad('Give a reason for voiding');
        $d = DB::one('SELECT * FROM disbursements WHERE id = ?', [$id]);
        if (!$d || $d['status'] !== 'posted') throw HttpException::bad('Payment not found or already void');
        DB::transaction(function () use ($d, $reason) {
            Ledger::reverse($d['journal_entry_id'] ? (int) $d['journal_entry_id'] : null, today(), "Void {$d['doc_no']}: $reason");
            if ($d['bank_txn_id']) DB::run("UPDATE bank_txns SET status = 'void' WHERE id = ?", [$d['bank_txn_id']]);
            DB::update('disbursements', (int) $d['id'], ['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255)]);
        });
        Audit::log('void', 'disbursement', $id, $reason);
    }

    /**
     * Cash and bank position as of a date: cash on hand, petty cash fund, every bank / e-wallet, other cash accounts,
     * and card / e-wallet payments not yet settled. Returns ['groups' => [...], 'total' => cash + banks].
     */
    public static function position(string $asOf): array
    {
        $bal = fn (?int $id) => $id ? Ledger::balance($id, $asOf) : 0.0;
        $acct = fn (?int $id) => $id ? DB::one('SELECT id, code, name FROM accounts WHERE id = ?', [$id]) : null;
        $cash = [];
        $seen = [];
        foreach (['cash_on_hand' => 'Cash on hand (incl. the cash drawer)', 'petty_cash' => 'Petty cash fund'] as $role => $label) {
            $id = Ledger::accountOrNull($role);
            if (!$id) continue;
            $seen[$id] = true;
            $a = $acct($id);
            $cash[] = ['label' => $label, 'code' => $a['code'], 'account' => $a['name'], 'account_id' => $id, 'balance' => $bal($id)];
        }
        // other accounts marked as cash (e.g. a second register, a safe)
        foreach (DB::all("SELECT id, code, name FROM accounts WHERE type = 'asset' AND subtype = 'cash' AND active = 1 ORDER BY code") as $a) {
            if (isset($seen[(int) $a['id']])) continue;
            $seen[(int) $a['id']] = true;
            $cash[] = ['label' => $a['name'], 'code' => $a['code'], 'account' => $a['name'], 'account_id' => (int) $a['id'], 'balance' => $bal((int) $a['id'])];
        }
        $banks = [];
        foreach (Banks::all() as $b) {
            $seen[(int) $b['gl_account_id']] = true;
            $banks[] = ['label' => Banks::label($b), 'code' => $b['gl_code'], 'account' => $b['gl_name'], 'account_id' => (int) $b['gl_account_id'],
                'balance' => $bal((int) $b['gl_account_id']), 'active' => (int) $b['active']];
        }
        foreach (DB::all("SELECT id, code, name FROM accounts WHERE type = 'asset' AND subtype = 'bank' AND active = 1 ORDER BY code") as $a) {
            if (isset($seen[(int) $a['id']])) continue;
            $seen[(int) $a['id']] = true;
            $banks[] = ['label' => $a['name'], 'code' => $a['code'], 'account' => $a['name'], 'account_id' => (int) $a['id'], 'balance' => $bal((int) $a['id']), 'active' => 1];
        }
        $pending = [];
        $roles = ['card_clearing' => 'Card payments', 'ewallet_clearing' => 'E-wallet & delivery apps'];
        foreach (array_keys(\App\Services\Pos\CashSessions::PAYMENT_METHODS) as $m) if (!in_array($m, ['cash', 'charge'], true)) $roles['pay.' . $m] = \App\Services\Pos\CashSessions::PAYMENT_METHODS[$m]['label'];
        foreach ($roles as $role => $label) {
            $id = Ledger::accountOrNull($role);
            if (!$id || isset($seen[$id])) continue;
            $seen[$id] = true;
            $a = $acct($id);
            $pending[] = ['label' => $a['name'], 'code' => $a['code'], 'account' => $a['name'], 'account_id' => $id, 'balance' => $bal($id)];
        }
        $sum = fn ($rows) => r2(array_sum(array_column($rows, 'balance')));
        $drawer = \App\Services\Pos\CashSessions::current();
        return [
            'cash' => $cash, 'banks' => $banks, 'pending' => $pending,
            'totals' => ['cash' => $sum($cash), 'banks' => $sum($banks), 'pending' => $sum($pending), 'all' => r2($sum($cash) + $sum($banks))],
            'drawer' => $drawer ? \App\Services\Pos\CashSessions::report((int) $drawer['id'])['cash'] + ['business_date' => $drawer['business_date']] : null,
        ];
    }
}
