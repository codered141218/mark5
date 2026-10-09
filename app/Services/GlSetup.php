<?php
namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Pos\CashSessions;

/**
 * GL account determination: which account each automatic posting uses (Finance → GL Account Setup).
 *
 * Every module asks Ledger::account('<role>') for its account. By default a role uses the chart-of-accounts row with
 * the same system_key (e.g. 'inventory' -> 1200 Inventory); choosing another account here stores settings
 * "gl.<role>" = account id. Sales / COGS / Inventory can also be overridden per category (Inventory → Categories).
 */
class GlSetup
{
    /** role => [group, label, where it is used, allowed account types] */
    public const ROLES = [
        'cash_on_hand' => ['Cash & payments', 'Cash on hand (cash drawer)', 'POS cash sales, end-of-day over/short, cash payments', ['asset']],
        'petty_cash' => ['Cash & payments', 'Petty cash fund', 'Petty cash vouchers and replenishments', ['asset']],
        'pay.card' => ['Cash & payments', 'Card payments', 'POS sales paid by credit / debit card', ['asset']],
        'pay.gcash' => ['Cash & payments', 'GCash payments', 'POS sales paid by GCash', ['asset']],
        'pay.maya' => ['Cash & payments', 'Maya payments', 'POS sales paid by Maya', ['asset']],
        'pay.bank_transfer' => ['Cash & payments', 'Bank transfer / InstaPay payments', 'POS sales paid by bank transfer', ['asset']],
        'pay.grabfood' => ['Cash & payments', 'GrabFood receivable', 'POS sales paid through GrabFood', ['asset']],
        'pay.foodpanda' => ['Cash & payments', 'foodpanda receivable', 'POS sales paid through foodpanda', ['asset']],
        'bank_charges' => ['Cash & payments', 'Bank & card charges', 'Bank charges and card settlement fees', ['expense']],

        'ar' => ['Receivables & payables', 'Accounts receivable', 'Customer invoices and POS "charge to account"', ['asset']],
        'emp_advances' => ['Receivables & payables', 'Employee cash advances', 'Approved cash advances and repayments', ['asset']],
        'ap' => ['Receivables & payables', 'Accounts payable', 'Supplier bills and deliveries on credit', ['liability']],
        'salaries_payable' => ['Receivables & payables', 'Salaries payable', 'Cash advances repaid by salary deduction', ['liability']],

        'sales' => ['Sales & taxes', 'Sales', 'POS sales (unless the category has its own), customer invoices', ['income']],
        'sales_discounts' => ['Sales & taxes', 'Sales discounts', 'SC/PWD, employee and promo discounts', ['income', 'expense']],
        'service_charge' => ['Sales & taxes', 'Service charge', 'Service charge collected at the POS', ['liability', 'income']],
        'output_vat' => ['Sales & taxes', 'Output VAT', 'VAT on POS sales', ['liability']],
        'input_vat' => ['Sales & taxes', 'Input VAT', 'VAT on deliveries / purchases', ['asset']],

        'inventory' => ['Inventory & cost of sales', 'Inventory', 'Stock received, sold, issued, wasted and counted (unless the category has its own)', ['asset']],
        'cogs' => ['Inventory & cost of sales', 'Cost of goods sold', 'Ingredient cost of POS sales (unless the category has its own)', ['expense']],
        'wastage' => ['Inventory & cost of sales', 'Spoilage & wastage', 'Spoilage & wastage documents', ['expense']],
        'inv_variance' => ['Inventory & cost of sales', 'Inventory count variance', 'Over / short found in inventory counts', ['expense', 'income']],
        'supplies' => ['Inventory & cost of sales', 'Default stock issuance expense', 'Suggested expense account on stock issuance', ['expense']],

        'cash_short_over' => ['Other', 'Cash short / (over)', 'End-of-day cash count differences', ['expense', 'income']],
        'opening_equity' => ['Other', 'Opening balance equity', 'Opening balances of banks and stock', ['equity']],
    ];

    /** Role a payment-method role falls back to when not set (pay.gcash -> ewallet_clearing). */
    public static function fallback(string $role): string
    {
        if (str_starts_with($role, 'pay.')) {
            return CashSessions::PAYMENT_METHODS[substr($role, 4)]['account'] ?? 'ewallet_clearing';
        }
        return $role;
    }

    /** Rows for the setup page: role, labels, current account id and whether it was changed from the default. */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::ROLES as $role => [$group, $label, $usedFor, $types]) {
            $mapped = (int) Settings::get('gl.' . $role, 0);
            $default = self::defaultAccount($role);
            $rows[] = ['role' => $role, 'group' => $group, 'label' => $label, 'used_for' => $usedFor, 'types' => $types,
                'account_id' => $mapped ?: $default, 'default_id' => $default, 'changed' => $mapped && $mapped !== $default];
        }
        return $rows;
    }

    private static function defaultAccount(string $role): ?int
    {
        $id = DB::value('SELECT id FROM accounts WHERE system_key = ?', [self::fallback($role)]);
        return $id ? (int) $id : null;
    }

    /** Save [role => account id]; choosing the default account clears the override. */
    public static function save(array $map): void
    {
        DB::transaction(function () use ($map) {
            foreach (self::ROLES as $role => [$group, $label, $usedFor, $types]) {
                if (!array_key_exists($role, $map)) continue;
                $id = (int) $map[$role];
                if (!$id) { Settings::set('gl.' . $role, null); continue; }   // not chosen yet (e.g. a fresh chart of accounts)
                $acct = DB::one('SELECT id, code, name, type FROM accounts WHERE id = ?', [$id]);
                if (!$acct) throw HttpException::bad("Unknown account for “{$label}”");
                if (!in_array($acct['type'], $types, true)) {
                    throw HttpException::bad("“{$label}” must be " . implode(' or ', array_map(fn ($t) => "a $t", $types)) . " account; {$acct['code']} {$acct['name']} is $acct[type].");
                }
                Settings::set('gl.' . $role, $id === self::defaultAccount($role) ? null : $id);
            }
        });
        DB::run("DELETE FROM settings WHERE `key` LIKE 'gl.%' AND `value` IS NULL");
        Settings::flush();
        Ledger::clearCache();
        Audit::log('update', 'gl_setup', null, $map);
    }

    /** Category override columns => the role they replace. */
    public const CATEGORY_ROLES = ['sales_account_id' => 'sales', 'cogs_account_id' => 'cogs', 'inventory_account_id' => 'inventory'];

    /** Validate the GL override fields of a category form; returns the columns to save. */
    public static function categoryFields(array $d): array
    {
        $out = [];
        foreach (self::CATEGORY_ROLES as $col => $role) {
            $id = (int) ($d[$col] ?? 0);
            if ($id) {
                $type = DB::value('SELECT type FROM accounts WHERE id = ?', [$id]);
                $types = self::ROLES[$role][3];
                if (!in_array($type, $types, true)) throw HttpException::bad(self::ROLES[$role][1] . ' account must be ' . implode(' / ', $types));
            }
            $out[$col] = $id ?: null;
        }
        Ledger::clearCache();
        return $out;
    }

    /** [id => "code · name"] of active accounts of the given types (for dropdowns). */
    public static function accountOptions(array $types): array
    {
        $rows = DB::all('SELECT id, code, name FROM accounts WHERE active = 1 AND type IN (' . DB::placeholders($types) . ') ORDER BY code', $types);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = "{$r['code']} · {$r['name']}";
        return $out;
    }
}
