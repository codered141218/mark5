<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Services\Ledger;

/** Everything the dashboard shows for a date range: KPIs, chart series and the financial position as of $to. */
class Dashboard
{
    public static function data(string $from, string $to): array
    {
        $s = DB::one(
            "SELECT COUNT(*) receipts, COALESCE(SUM(total),0) net, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_amount),0) discounts,
                    COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(cogs),0) cogs, COALESCE(SUM(pax),0) pax
             FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?", [$from, $to]
        );
        $s = array_map('floatval', $s);
        $netOfVat = r2($s['net'] - $s['vat'] - $s['svc']);
        $voids = DB::one("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amt FROM tickets WHERE status = 'void' AND receipt_no IS NOT NULL AND business_date BETWEEN ? AND ?", [$from, $to]);
        $wastage = (float) DB::value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'WASTE' AND status = 'posted' AND doc_date BETWEEN ? AND ?", [$from, $to]);
        $purchases = (float) DB::value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'RECEIVE' AND status = 'posted' AND doc_date BETWEEN ? AND ?", [$from, $to]);
        $pettyCash = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM petty_cash_txns WHERE txn_type = 'expense' AND status = 'posted' AND txn_date BETWEEN ? AND ?", [$from, $to]);
        // Operating expenses straight from the ledger (all entries: voids are offset by their reversal)
        $expenses = (float) DB::value(
            "SELECT COALESCE(SUM(l.debit - l.credit),0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
             WHERE a.type = 'expense' AND COALESCE(a.subtype,'') <> 'cogs' AND e.entry_date BETWEEN ? AND ?", [$from, $to]
        );

        $kpi = [
            'net_sales' => $s['net'], 'net_of_vat' => $netOfVat, 'gross_sales' => $s['gross'], 'discounts' => $s['discounts'],
            'vat' => $s['vat'], 'service_charge' => $s['svc'], 'receipts' => (int) $s['receipts'], 'pax' => (int) $s['pax'],
            'avg_ticket' => $s['receipts'] ? r2($s['net'] / $s['receipts']) : 0.0,
            'cogs' => $s['cogs'], 'gross_profit' => r2($netOfVat - $s['cogs']),
            'food_cost_pct' => $netOfVat ? r2($s['cogs'] / $netOfVat * 100) : 0.0,
            'voids' => (int) $voids['cnt'], 'void_amount' => r2($voids['amt']), 'wastage' => r2($wastage), 'purchases' => r2($purchases),
            'petty_cash' => r2($pettyCash), 'operating_expenses' => r2($expenses),
            'net_income_est' => r2($netOfVat - $s['cogs'] - $wastage - $expenses),
        ];

        $daily = DB::all(
            "SELECT business_date date, COUNT(*) receipts, SUM(total) net, SUM(cogs) cogs FROM tickets
             WHERE status = 'paid' AND business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date", [$from, $to]
        );
        $hourly = DB::all(
            "SELECT HOUR(paid_at) hour, COUNT(*) receipts, SUM(total) net FROM tickets
             WHERE status = 'paid' AND business_date BETWEEN ? AND ? GROUP BY HOUR(paid_at) ORDER BY hour", [$from, $to]
        );
        $topItems = DB::all(
            "SELECT i.item_id, i.name, SUM(i.qty) qty, SUM(i.line_total) amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY i.item_id, i.name ORDER BY amount DESC LIMIT 10", [$from, $to]
        );
        $categories = DB::all(
            "SELECT COALESCE(c.name, 'Uncategorized') name, SUM(i.qty) qty, SUM(i.line_total) amount
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY c.name ORDER BY amount DESC", [$from, $to]
        );
        $orderTypes = DB::all(
            "SELECT order_type, COUNT(*) cnt, SUM(total) amount FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?
             GROUP BY order_type ORDER BY amount DESC", [$from, $to]
        );
        $lowStock = DB::all(
            "SELECT i.id, i.name, i.stock_qty, i.reorder_point, i.reorder_qty, u.abbr uom,
                    GREATEST(i.reorder_qty, i.reorder_point - i.stock_qty) order_qty
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.active = 1 AND i.item_type IN ('raw','retail') AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point
             ORDER BY i.stock_qty / i.reorder_point, i.name LIMIT 15"
        );

        return [
            'from' => $from, 'to' => $to, 'kpi' => $kpi,
            'daily' => $daily, 'hourly' => $hourly, 'top_items' => $topItems, 'categories' => $categories,
            'payments' => SalesReports::payments($from, $to), 'order_types' => $orderTypes, 'low_stock' => $lowStock,
            'position' => self::position($to),
            'session' => DB::one("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"),
            'open_tickets' => DB::one("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amt FROM tickets WHERE status = 'open'"),
        ];
    }

    /** Cash, banks, inventory, receivables, payables and employee advances (balances as of $asOf). */
    public static function position(string $asOf): array
    {
        $in7days = add_days(today(), 7);
        $apDue = DB::one("SELECT COUNT(*) cnt, COALESCE(SUM(amount - paid_amount),0) amt FROM ap_bills WHERE status IN ('open','partial') AND due_date <= ?", [$in7days]);
        $pendingCa = DB::one("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) amt FROM cash_advances WHERE status = 'pending'");
        $banks = DB::all('SELECT id, bank_name, account_no, gl_account_id FROM bank_accounts WHERE active = 1 ORDER BY bank_name');
        foreach ($banks as &$b) $b['balance'] = Ledger::balance((int) $b['gl_account_id'], $asOf);
        return [
            'cash_on_hand' => ($id = Ledger::accountOrNull('cash_on_hand')) ? Ledger::balance($id, $asOf) : 0.0,
            'petty_cash' => ($id = Ledger::accountOrNull('petty_cash')) ? Ledger::balance($id, $asOf) : 0.0,
            'banks' => $banks,
            'inventory_value' => r2(DB::value("SELECT COALESCE(SUM(CASE WHEN stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END),0) FROM items WHERE item_type IN ('raw','retail')")),
            'ap_total' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ap_bills WHERE status IN ('open','partial')")),
            'ap_due_7d' => r2($apDue['amt']), 'ap_due_count' => (int) $apDue['cnt'],
            'ar_total' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial')")),
            'ar_overdue' => r2(DB::value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial') AND due_date < ?", [today()])),
            'ca_pending' => (int) $pendingCa['cnt'], 'ca_pending_amount' => r2($pendingCa['amt']),
            'ca_outstanding' => r2(DB::value("SELECT COALESCE(SUM(balance),0) FROM cash_advances WHERE status = 'approved'")),
        ];
    }
}
