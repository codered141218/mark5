<?php
namespace App\Services\Finance;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;

/** Chart of accounts: list with balances, create / edit / delete with the system-account rules. */
class Accounts
{
    public const TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];
    public const SINGULAR = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];
    public const SUBTYPES = [
        'asset' => ['cash', 'bank', 'current', 'inventory', 'fixed', 'contra'],
        'liability' => ['current', 'long_term'],
        'equity' => ['capital', 'drawings', 'retained'],
        'income' => ['sales', 'other', 'contra'],
        'expense' => ['cogs', 'opex', 'other'],
    ];

    /** Every account with total debit/credit, debit-minus-credit balance and the natural-sign balance. */
    public static function all(): array
    {
        $rows = DB::all(
            'SELECT a.*, COALESCE(SUM(l.debit), 0) AS total_debit, COALESCE(SUM(l.credit), 0) AS total_credit
             FROM accounts a LEFT JOIN journal_lines l ON l.account_id = a.id GROUP BY a.id ORDER BY a.code'
        );
        foreach ($rows as &$a) {
            $a['balance'] = r2($a['total_debit'] - $a['total_credit']);
            $a['natural_balance'] = self::natural($a['type'], $a['balance']);
            $a['has_tx'] = (float) $a['total_debit'] + (float) $a['total_credit'] > 0;
        }
        return $rows;
    }

    /** Balance in the account's normal sign: liabilities, equity and income are shown positive when in credit. */
    public static function natural(string $type, float $debitMinusCredit): float
    {
        return in_array($type, ['liability', 'equity', 'income'], true) ? r2(-$debitMinusCredit) : $debitMinusCredit;
    }

    /** Active accounts for pickers, grouped by type: ['Assets' => [id => '1000 · Cash on Hand', ...], ...]. */
    public static function grouped(?array $types = null): array
    {
        $out = [];
        foreach (DB::all('SELECT id, code, name, type FROM accounts WHERE active = 1 ORDER BY code') as $a) {
            if ($types && !in_array($a['type'], $types, true)) continue;
            $out[self::TYPES[$a['type']]][$a['id']] = $a['code'] . ' · ' . $a['name'];
        }
        return $out;
    }

    /** Code ranges per type (expenses: 5xxx cost of sales, 6xxx operating). */
    public const RANGES = ['asset' => [1000, 1999], 'liability' => [2000, 2999], 'equity' => [3000, 3999], 'income' => [4000, 4999], 'expense' => [6000, 6999]];

    /**
     * Next free account code for a type: 10 after the highest code in its range (1000s assets, 2000s liabilities,
     * 3000s equity, 4000s income, 5000s cost of sales / 6000s expenses), or the first free one if the range is full.
     */
    public static function nextCode(string $type, ?string $subtype = null): string
    {
        [$lo, $hi] = $type === 'expense' && $subtype === 'cogs' ? [5000, 5999] : (self::RANGES[$type] ?? [9000, 9999]);
        $codes = array_map('intval', array_filter(array_column(
            DB::all('SELECT code FROM accounts WHERE code REGEXP ? ', ['^[0-9]+$']), 'code'), fn ($c) => (int) $c >= $lo && (int) $c <= $hi));
        $used = array_flip($codes);
        $next = $codes ? (int) (floor(max($codes) / 10) * 10 + 10) : $lo;
        if ($next <= $hi && !isset($used[$next])) return (string) $next;
        for ($c = $lo; $c <= $hi; $c++) if (!isset($used[$c])) return (string) $c;
        throw HttpException::bad('No free account code left in the ' . strtolower(self::TYPES[$type] ?? $type) . ' range — enter a code yourself');
    }

    /** Next code for every type, for the "new account" form. */
    public static function nextCodes(): array
    {
        $out = [];
        foreach (array_keys(self::TYPES) as $t) { try { $out[$t] = self::nextCode($t); } catch (HttpException $e) { $out[$t] = ''; } }
        try { $out['expense:cogs'] = self::nextCode('expense', 'cogs'); } catch (HttpException $e) { $out['expense:cogs'] = ''; }
        return $out;
    }

    public static function create(array $d): int
    {
        if (trim((string) ($d['code'] ?? '')) === '' && isset(self::TYPES[$d['type'] ?? ''])) {
            $d['code'] = self::nextCode($d['type'], trim((string) ($d['subtype'] ?? '')) ?: null);   // automatic code
        }
        $row = self::validate($d);
        if (DB::value('SELECT id FROM accounts WHERE code = ?', [$row['code']])) throw HttpException::bad('Account code already exists');
        $id = DB::insert('accounts', $row + ['active' => 1, 'is_system' => 0]);
        Audit::log('create', 'account', $id, $row);
        return $id;
    }

    public static function update(int $id, array $d): void
    {
        $a = DB::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if (!$a) throw HttpException::notFound('Account');
        $row = self::validate($d + ['type' => $a['type']]); // a locked (disabled) type field is not submitted
        $hasTx = DB::value('SELECT id FROM journal_lines WHERE account_id = ? LIMIT 1', [$id]);
        if ($row['type'] !== $a['type'] && ($a['is_system'] || $hasTx)) {
            throw HttpException::bad('Cannot change the type of a system account or an account with transactions');
        }
        if ($row['code'] !== $a['code'] && DB::value('SELECT id FROM accounts WHERE code = ? AND id <> ?', [$row['code'], $id])) {
            throw HttpException::bad('Account code already exists');
        }
        $row['active'] = !empty($d['active']) ? 1 : 0;
        DB::update('accounts', $id, $row);
        Audit::log('update', 'account', $id, $row);
    }

    public static function delete(int $id): void
    {
        $a = DB::one('SELECT * FROM accounts WHERE id = ?', [$id]);
        if (!$a) throw HttpException::notFound('Account');
        if ($a['is_system']) throw HttpException::bad('System accounts are used by automatic postings and cannot be deleted');
        if (DB::value('SELECT id FROM journal_lines WHERE account_id = ? LIMIT 1', [$id])) throw HttpException::bad('Account has transactions; deactivate it instead');
        if (DB::value('SELECT id FROM bank_accounts WHERE gl_account_id = ?', [$id])) throw HttpException::bad('Account is linked to a bank');
        DB::run('DELETE FROM accounts WHERE id = ?', [$id]);
        Audit::log('delete', 'account', $id, $a['code'] . ' ' . $a['name']);
    }

    private static function validate(array $d): array
    {
        required($d, 'code', 'name', 'type');
        if (!isset(self::TYPES[$d['type']])) throw HttpException::bad('Invalid account type');
        return [
            'code' => trim($d['code']), 'name' => trim($d['name']), 'type' => $d['type'],
            'subtype' => trim((string) ($d['subtype'] ?? '')) ?: null, 'description' => trim((string) ($d['description'] ?? '')) ?: null,
        ];
    }
}
