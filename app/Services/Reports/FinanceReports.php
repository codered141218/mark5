<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Services\Ledger;

/**
 * Financial statements and ledgers, all computed from journal lines.
 * Voided entries are never filtered out: a void posts a separate reversing entry,
 * so including every entry gives the correct (net zero) effect.
 */
class FinanceReports
{
    public const SOURCE_LABELS = [
        'manual' => 'Manual', 'pos_sale' => 'POS sale', 'pos_eod' => 'POS EOD', 'inv_receive' => 'Stock in', 'inv_issue' => 'Issuance',
        'inv_waste' => 'Wastage', 'inv_count' => 'Count', 'petty_cash' => 'Petty cash', 'bank' => 'Bank', 'bank_opening' => 'Bank opening',
        'ap_bill' => 'AP bill', 'ap_payment' => 'AP payment', 'ar_invoice' => 'AR invoice', 'ar_receipt' => 'AR collection',
        'cash_advance' => 'Cash advance', 'ca_repayment' => 'CA repayment',
    ];

    public static function sourceLabel(?string $source): string
    {
        return self::SOURCE_LABELS[$source] ?? (string) $source;
    }

    /**
     * Every account with its opening balance (before $from), period debits/credits and closing balance (debit - credit).
     * $from = null means "since the beginning" (opening is then zero).
     */
    public static function balances(?string $from, string $to): array
    {
        $from = $from ?: '1000-01-01';
        $rows = DB::all(
            'SELECT a.id, a.code, a.name, a.type, a.subtype,
                    COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit END), 0) opening,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.debit END), 0) debit,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.credit END), 0) credit
             FROM accounts a
             LEFT JOIN journal_lines l ON l.account_id = a.id
             LEFT JOIN journal_entries e ON e.id = l.entry_id AND e.entry_date <= ?
             GROUP BY a.id, a.code, a.name, a.type, a.subtype ORDER BY a.code', [$from, $from, $from, $to]
        );
        foreach ($rows as &$a) {
            foreach (['opening', 'debit', 'credit'] as $k) $a[$k] = r2($a[$k]);
            $a['closing'] = r2($a['opening'] + $a['debit'] - $a['credit']);
        }
        return $rows;
    }

    public static function trialBalance(string $from, string $to): array
    {
        $out = [];
        foreach (self::balances($from, $to) as $a) {
            if (!$a['opening'] && !$a['debit'] && !$a['credit']) continue;
            $out[] = [
                'id' => $a['id'], 'code' => $a['code'], 'account' => $a['name'], 'type' => $a['type'], 'opening' => $a['opening'],
                'period_debit' => $a['debit'], 'period_credit' => $a['credit'],
                'ending_debit' => $a['closing'] > 0 ? $a['closing'] : 0.0, 'ending_credit' => $a['closing'] < 0 ? -$a['closing'] : 0.0,
            ];
        }
        return $out;
    }

    /** Revenue, cost of sales (subtype cogs) and operating expenses for the period. */
    public static function incomeStatement(string $from, string $to): array
    {
        $income = $cogs = $opex = [];
        foreach (self::balances($from, $to) as $a) {
            $period = r2($a['debit'] - $a['credit']);
            if (!$period) continue;
            $line = ['code' => $a['code'], 'name' => $a['name'], 'account_id' => $a['id']];
            if ($a['type'] === 'income') $income[] = $line + ['amount' => -$period];
            elseif ($a['type'] === 'expense' && $a['subtype'] === 'cogs') $cogs[] = $line + ['amount' => $period];
            elseif ($a['type'] === 'expense') $opex[] = $line + ['amount' => $period];
        }
        $sum = fn ($l) => r2(array_sum(array_column($l, 'amount')));
        $revenue = $sum($income);
        $totalCogs = $sum($cogs);
        $totalOpex = $sum($opex);
        return [
            'from' => $from, 'to' => $to, 'income' => $income, 'cogs' => $cogs, 'opex' => $opex,
            'revenue' => $revenue, 'total_cogs' => $totalCogs, 'gross_profit' => r2($revenue - $totalCogs),
            'total_opex' => $totalOpex, 'net_income' => r2($revenue - $totalCogs - $totalOpex),
        ];
    }

    /** Assets = liabilities + equity (+ unclosed cumulative net income). 'check' must be 0. */
    public static function balanceSheet(string $asOf): array
    {
        $rows = self::balances(null, $asOf);
        $pick = function (string $type, int $sign) use ($rows) {
            $out = [];
            foreach ($rows as $a) {
                if ($a['type'] === $type && $a['closing']) $out[] = ['code' => $a['code'], 'name' => $a['name'], 'account_id' => $a['id'], 'amount' => r2($sign * $a['closing'])];
            }
            return $out;
        };
        $assets = $pick('asset', 1);
        $liabilities = $pick('liability', -1);
        $equity = $pick('equity', -1);
        $netIncome = 0.0;
        foreach ($rows as $a) if (in_array($a['type'], ['income', 'expense'], true)) $netIncome -= $a['closing'];
        $equity[] = ['code' => '', 'name' => 'Net Income (cumulative, unclosed)', 'account_id' => null, 'amount' => r2($netIncome)];
        $sum = fn ($l) => r2(array_sum(array_column($l, 'amount')));
        return [
            'as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'total_assets' => $sum($assets), 'total_liabilities' => $sum($liabilities), 'total_equity' => $sum($equity),
            'check' => r2($sum($assets) - $sum($liabilities) - $sum($equity)),
        ];
    }

    /** Lines of one account with a running balance, starting from the balance before $from. */
    public static function generalLedger(int $accountId, string $from, string $to): array
    {
        $balance = Ledger::balance($accountId, add_days($from, -1));
        $lines = DB::all(
            'SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, COALESCE(l.memo, e.memo) description, l.debit, l.credit
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.id', [$accountId, $from, $to]
        );
        $rows = [['date' => $from, 'entry_no' => '', 'source' => '', 'description' => 'Beginning balance', 'debit' => null, 'credit' => null, 'balance' => $balance, '_bold' => true]];
        foreach ($lines as $l) {
            $balance = r2($balance + $l['debit'] - $l['credit']);
            $rows[] = $l + ['balance' => $balance];
        }
        return $rows;
    }

    public static function journal(string $from, string $to): array
    {
        return DB::all(
            'SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, e.memo, a.code, a.name account, l.debit, l.credit, l.memo line_memo
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
             WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.debit DESC, l.id', [$from, $to]
        );
    }

    /** Money in / out of a bank account's GL account with a running balance. */
    public static function bankRegister(int $bankAccountId, string $from, string $to): array
    {
        $gl = (int) DB::value('SELECT gl_account_id FROM bank_accounts WHERE id = ?', [$bankAccountId]);
        if (!$gl) return [];
        $balance = Ledger::balance($gl, add_days($from, -1));
        $lines = DB::all(
            'SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, COALESCE(l.memo, e.memo) description, l.debit money_in, l.credit money_out
             FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id
             WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.id', [$gl, $from, $to]
        );
        $rows = [['date' => $from, 'entry_no' => '', 'source' => '', 'description' => 'Beginning balance', 'money_in' => null, 'money_out' => null, 'balance' => $balance, '_bold' => true]];
        foreach ($lines as $l) {
            $balance = r2($balance + $l['money_in'] - $l['money_out']);
            $rows[] = $l + ['balance' => $balance];
        }
        return $rows;
    }

    /**
     * Petty cash fund: beginning/ending GL balance of the fund, every voucher in the period
     * (fund and drawer payouts) and posted expenses grouped by account.
     */
    public static function pettyCash(string $from, string $to): array
    {
        $pc = Ledger::account('petty_cash');
        $rows = DB::all(
            'SELECT p.id, p.txn_date date, p.doc_no, p.txn_type type, p.source, p.payee, p.description, a.name account, p.or_no, p.amount, p.status,
                    u.full_name recorded_by
             FROM petty_cash_txns p LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN users u ON u.id = p.created_by
             WHERE p.txn_date BETWEEN ? AND ? ORDER BY p.txn_date, p.id', [$from, $to]
        );
        $byAccount = DB::all(
            "SELECT a.name account, COUNT(*) cnt, SUM(p.amount) amount FROM petty_cash_txns p JOIN accounts a ON a.id = p.account_id
             WHERE p.status = 'posted' AND p.txn_type = 'expense' AND p.txn_date BETWEEN ? AND ? GROUP BY a.name ORDER BY amount DESC", [$from, $to]
        );
        $spent = $added = 0.0;
        foreach ($rows as $r) {
            if ($r['status'] !== 'posted') continue;
            if ($r['type'] === 'expense') $spent += (float) $r['amount'];
            else $added += (float) $r['amount'];
        }
        return [
            'beginning' => Ledger::balance($pc, add_days($from, -1)), 'ending' => Ledger::balance($pc, $to),
            'spent' => r2($spent), 'added' => r2($added), 'rows' => $rows, 'by_account' => $byAccount,
        ];
    }

    /**
     * Open bills (ap) or invoices (ar) per party in aging buckets, by days past due as of $asOf
     * (the document date is used when there is no due date).
     */
    public static function aging(string $kind, string $asOf): array
    {
        [$table, $partyTable, $partyField, $dateField] = $kind === 'ap'
            ? ['ap_bills', 'suppliers', 'supplier_id', 'bill_date']
            : ['ar_invoices', 'customers', 'customer_id', 'inv_date'];
        $docs = DB::all(
            "SELECT x.amount, x.paid_amount, x.due_date, x.$dateField doc_date, p.name party
             FROM $table x JOIN $partyTable p ON p.id = x.$partyField
             WHERE x.status IN ('open','partial') AND x.$dateField <= ?", [$asOf]
        );
        $byParty = [];
        foreach ($docs as $d) {
            $bal = r2($d['amount'] - $d['paid_amount']);
            $days = (int) floor((strtotime($asOf) - strtotime($d['due_date'] ?: $d['doc_date'])) / 86400);
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'over_90')));
            $byParty[$d['party']] ??= ['party' => $d['party'], 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];
            $byParty[$d['party']][$bucket] = r2($byParty[$d['party']][$bucket] + $bal);
            $byParty[$d['party']]['total'] = r2($byParty[$d['party']]['total'] + $bal);
        }
        $rows = array_values($byParty);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        return $rows;
    }

    public static function cashAdvances(string $from, string $to): array
    {
        return DB::all(
            "SELECT c.id, c.doc_no, c.request_date, e.emp_no, e.full_name employee, c.amount, c.status, c.release_method, c.release_date,
                    au.full_name approved_by,
                    COALESCE((SELECT SUM(r.amount) FROM ca_repayments r WHERE r.advance_id = c.id AND r.status = 'posted'), 0) repaid,
                    c.balance, c.reason
             FROM cash_advances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users au ON au.id = c.approved_by
             WHERE c.request_date BETWEEN ? AND ? ORDER BY c.request_date, c.id", [$from, $to]
        );
    }
}
