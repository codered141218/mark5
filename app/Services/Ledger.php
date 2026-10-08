<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;

/**
 * General ledger (double-entry bookkeeping).
 * Every module that moves money or inventory value calls Ledger::post() with balanced lines.
 *
 *   Ledger::post('2026-10-08', 'LPG refill', [
 *       ['account_id' => $lpgExpenseId, 'debit' => 150],
 *       ['key' => 'petty_cash', 'credit' => 150],          // 'key' = accounts.system_key
 *   ], 'petty_cash', $txnId, 'PCV-000001');
 *
 * Voids never delete: Ledger::reverse($entryId) posts the opposite entry.
 */
class Ledger
{
    /** Default Philippine restaurant chart of accounts: [code, name, type, subtype, system_key] */
    public const DEFAULT_ACCOUNTS = [
        ['1000', 'Cash on Hand', 'asset', 'cash', 'cash_on_hand'],
        ['1020', 'Petty Cash Fund', 'asset', 'cash', 'petty_cash'],
        ['1030', 'Cash in Bank', 'asset', 'bank', 'cash_in_bank'],
        ['1040', 'Credit/Debit Card Receivable', 'asset', 'current', 'card_clearing'],
        ['1045', 'E-Wallet & Delivery App Receivable', 'asset', 'current', 'ewallet_clearing'],
        ['1100', 'Accounts Receivable', 'asset', 'current', 'ar'],
        ['1150', 'Advances to Employees', 'asset', 'current', 'emp_advances'],
        ['1200', 'Inventory', 'asset', 'inventory', 'inventory'],
        ['1300', 'Input VAT', 'asset', 'current', 'input_vat'],
        ['1400', 'Prepaid Expenses', 'asset', 'current', null],
        ['1500', 'Kitchen Equipment', 'asset', 'fixed', null],
        ['1510', 'Furniture & Fixtures', 'asset', 'fixed', null],
        ['1590', 'Accumulated Depreciation', 'asset', 'contra', null],
        ['2000', 'Accounts Payable', 'liability', 'current', 'ap'],
        ['2100', 'Output VAT Payable', 'liability', 'current', 'output_vat'],
        ['2150', 'Service Charge Payable', 'liability', 'current', 'service_charge'],
        ['2200', 'Salaries Payable', 'liability', 'current', 'salaries_payable'],
        ['2210', 'SSS/PhilHealth/Pag-IBIG Payable', 'liability', 'current', null],
        ['2220', 'Withholding Tax Payable', 'liability', 'current', null],
        ['2500', 'Loans Payable', 'liability', 'long_term', null],
        ['3000', "Owner's Capital", 'equity', 'capital', 'capital'],
        ['3100', "Owner's Drawings", 'equity', 'drawings', null],
        ['3200', 'Retained Earnings', 'equity', 'retained', 'retained_earnings'],
        ['3900', 'Opening Balance Equity', 'equity', 'capital', 'opening_equity'],
        ['4000', 'Food & Beverage Sales', 'income', 'sales', 'sales'],
        ['4100', 'Sales Discounts (SC/PWD/Promo)', 'income', 'contra', 'sales_discounts'],
        ['4800', 'Other Income', 'income', 'other', 'other_income'],
        ['5000', 'Cost of Goods Sold', 'expense', 'cogs', 'cogs'],
        ['5100', 'Spoilage & Wastage', 'expense', 'cogs', 'wastage'],
        ['5110', 'Inventory Variance (Over/Short)', 'expense', 'cogs', 'inv_variance'],
        ['5120', 'Staff Meals', 'expense', 'cogs', 'staff_meals'],
        ['6000', 'Salaries & Wages', 'expense', 'opex', 'salaries'],
        ['6010', 'SSS/PhilHealth/Pag-IBIG Contributions', 'expense', 'opex', null],
        ['6100', 'Rent Expense', 'expense', 'opex', null],
        ['6200', 'Electricity & Water', 'expense', 'opex', null],
        ['6210', 'LPG / Gas', 'expense', 'opex', null],
        ['6220', 'Internet & Telephone', 'expense', 'opex', null],
        ['6300', 'Repairs & Maintenance', 'expense', 'opex', null],
        ['6400', 'Transportation & Delivery', 'expense', 'opex', null],
        ['6500', 'Kitchen & Store Supplies', 'expense', 'opex', 'supplies'],
        ['6510', 'Packaging (Take-out)', 'expense', 'opex', null],
        ['6600', 'Marketing & Advertising', 'expense', 'opex', null],
        ['6700', 'Taxes & Licenses', 'expense', 'opex', null],
        ['6800', 'Bank & Card Charges', 'expense', 'opex', 'bank_charges'],
        ['6850', 'Cash Short / (Over)', 'expense', 'opex', 'cash_short_over'],
        ['6900', 'Miscellaneous Expense', 'expense', 'opex', 'misc_expense'],
    ];

    private static array $keyCache = [];

    public static function seedAccounts(): void
    {
        foreach (self::DEFAULT_ACCOUNTS as [$code, $name, $type, $subtype, $key]) {
            $exists = DB::value('SELECT id FROM accounts WHERE code = ? OR (system_key IS NOT NULL AND system_key = ?)', [$code, $key]);
            if (!$exists) {
                DB::insert('accounts', ['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype,
                    'system_key' => $key, 'is_system' => $key ? 1 : 0, 'active' => 1]);
            }
        }
    }

    /** Account id for a system key, e.g. Ledger::account('cash_on_hand'). */
    public static function account(string $key): int
    {
        if (!isset(self::$keyCache[$key])) {
            $id = DB::value('SELECT id FROM accounts WHERE system_key = ?', [$key]);
            if (!$id) throw new \RuntimeException("System account '$key' is missing from the chart of accounts");
            self::$keyCache[$key] = (int) $id;
        }
        return self::$keyCache[$key];
    }

    public static function clearCache(): void
    {
        self::$keyCache = [];
    }

    /**
     * GL account for where money came from / went to:
     * cash (cash on hand / drawer), petty_cash, bank (+ bank account id), credit (A/P), opening (opening balance), payroll (salaries payable)
     */
    public static function sourceAccount(string $method, $bankAccountId = null): int
    {
        switch ($method) {
            case 'cash':
            case 'drawer':
                return self::account('cash_on_hand');
            case 'petty_cash':
            case 'fund':
                return self::account('petty_cash');
            case 'bank':
                $gl = DB::value('SELECT gl_account_id FROM bank_accounts WHERE id = ?', [(int) $bankAccountId]);
                if (!$gl) throw HttpException::bad('Please select the bank account');
                return (int) $gl;
            case 'credit':
                return self::account('ap');
            case 'opening':
                return self::account('opening_equity');
            case 'payroll':
                return self::account('salaries_payable');
        }
        throw HttpException::bad("Unknown payment method \"$method\"");
    }

    /**
     * Post a balanced journal entry. Returns the entry id (or null if every amount is zero).
     * Each line: ['account_id' => int] or ['key' => system_key], plus 'debit' / 'credit', optional 'memo',
     * 'bank_account_id', 'party_type', 'party_id'. Negative amounts flip sides automatically.
     */
    public static function post(string $date, ?string $memo, array $lines, string $sourceType = 'manual', ?int $sourceId = null, ?string $refNo = null): ?int
    {
        $clean = [];
        foreach ($lines as $l) {
            $accountId = $l['account_id'] ?? (isset($l['key']) ? self::account($l['key']) : null);
            if (!$accountId) throw HttpException::bad('Journal line is missing an account');
            $debit = r2($l['debit'] ?? 0);
            $credit = r2($l['credit'] ?? 0);
            if ($debit < 0) { $credit = r2($credit - $debit); $debit = 0.0; }
            if ($credit < 0) { $debit = r2($debit - $credit); $credit = 0.0; }
            if ($debit == 0 && $credit == 0) continue;
            $clean[] = ['account_id' => (int) $accountId, 'debit' => $debit, 'credit' => $credit] + $l;
        }
        $dr = r2(array_sum(array_column($clean, 'debit')));
        $cr = r2(array_sum(array_column($clean, 'credit')));
        if (abs($dr - $cr) > 0.009) throw HttpException::bad("Journal entry is not balanced (debit $dr vs credit $cr)");
        if (!$clean) return null;

        return DB::transaction(function () use ($date, $memo, $clean, $sourceType, $sourceId, $refNo) {
            $id = DB::insert('journal_entries', [
                'entry_no' => Sequence::next('JE', 'JE'), 'entry_date' => $date, 'memo' => $memo ? mb_substr($memo, 0, 255) : null,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'ref_no' => $refNo, 'status' => 'posted',
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            foreach ($clean as $l) {
                DB::insert('journal_lines', [
                    'entry_id' => $id, 'account_id' => $l['account_id'], 'debit' => $l['debit'], 'credit' => $l['credit'],
                    'memo' => $l['memo'] ?? null, 'bank_account_id' => $l['bank_account_id'] ?? null,
                    'party_type' => $l['party_type'] ?? null, 'party_id' => $l['party_id'] ?? null,
                ]);
            }
            return $id;
        });
    }

    /** Reverse an entry (used by every "void"). Returns the reversing entry id. */
    public static function reverse(?int $entryId, ?string $date = null, ?string $memo = null): ?int
    {
        if (!$entryId) return null;
        $je = DB::one('SELECT * FROM journal_entries WHERE id = ?', [$entryId]);
        if (!$je) return null;
        if ($je['reversed_by']) throw HttpException::bad("Journal {$je['entry_no']} is already reversed");
        $lines = DB::all('SELECT * FROM journal_lines WHERE entry_id = ?', [$entryId]);
        return DB::transaction(function () use ($je, $lines, $date, $memo, $entryId) {
            $revId = self::post(
                $date ?: $je['entry_date'],
                $memo ?: 'Reversal of ' . $je['entry_no'] . ($je['memo'] ? ' - ' . $je['memo'] : ''),
                array_map(fn ($l) => [
                    'account_id' => (int) $l['account_id'], 'debit' => (float) $l['credit'], 'credit' => (float) $l['debit'],
                    'memo' => $l['memo'], 'bank_account_id' => $l['bank_account_id'], 'party_type' => $l['party_type'], 'party_id' => $l['party_id'],
                ], $lines),
                $je['source_type'], $je['source_id'] ? (int) $je['source_id'] : null, $je['ref_no']
            );
            DB::run('UPDATE journal_entries SET reversal_of = ? WHERE id = ?', [$entryId, $revId]);
            DB::run("UPDATE journal_entries SET reversed_by = ?, status = 'void' WHERE id = ?", [$revId, $entryId]);
            return $revId;
        });
    }

    /** Debit-minus-credit balance of an account (optionally as of a date). */
    public static function balance(int $accountId, ?string $asOf = null): float
    {
        $sql = 'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ?';
        $params = [$accountId];
        if ($asOf) { $sql .= ' AND e.entry_date <= ?'; $params[] = $asOf; }
        return r2(DB::value($sql, $params));
    }
}
