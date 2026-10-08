<?php
namespace App\Services\Reports;

use App\Core\DB;
use App\Core\HttpException;
use App\Services\Inventory;

/**
 * Sales report queries. Every method takes the business-date range and returns plain rows.
 * Only paid tickets count as sales; voided receipts are reported separately.
 */
class SalesReports
{
    public const METHOD_LABELS = [
        'cash' => 'Cash', 'card' => 'Card', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank Transfer',
        'grabfood' => 'GrabFood', 'foodpanda' => 'foodpanda', 'charge' => 'Charge (A/R)',
    ];

    public const DISCOUNT_LABELS = ['sc' => 'Senior Citizen', 'pwd' => 'PWD', 'percent' => 'Promo %', 'amount' => 'Fixed amount'];

    public const ORDER_TYPES = ['dine_in' => 'Dine-in', 'takeout' => 'Take-out', 'delivery' => 'Delivery'];

    public static function methodLabel(string $method): string
    {
        return self::METHOD_LABELS[$method] ?? $method;
    }

    public static function daily(string $from, string $to): array
    {
        return DB::all(
            "SELECT business_date AS date, COUNT(*) receipts, SUM(pax) pax, SUM(subtotal) gross, SUM(discount_amount) discounts,
                    SUM(vatable_sales) vatable_sales, SUM(vat_exempt_sales) vat_exempt, SUM(vat_amount) vat, SUM(service_charge) service_charge,
                    SUM(total) net_sales, SUM(cogs) cogs, SUM(total) - SUM(vat_amount) - SUM(service_charge) - SUM(cogs) gross_profit
             FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?
             GROUP BY business_date ORDER BY business_date", [$from, $to]
        );
    }

    /** Quantity and sales per item, with the current recipe cost as an estimate of cost of sales. */
    public static function items(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT i.item_id, it.sku, i.name item, COALESCE(c.name, 'Uncategorized') category, SUM(i.qty) qty, SUM(i.line_total) gross
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id
             LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY i.item_id, i.name, it.sku, c.name ORDER BY gross DESC", [$from, $to]
        );
        $total = array_sum(array_column($rows, 'gross')) ?: 1;
        foreach ($rows as &$r) {
            $cost = Inventory::unitCost((int) $r['item_id']);
            $r['avg_price'] = (float) $r['qty'] ? r2($r['gross'] / $r['qty']) : 0;
            $r['unit_cost'] = $cost;
            $r['total_cost'] = r2($cost * $r['qty']);
            $r['share_pct'] = r2($r['gross'] / $total * 100);
        }
        return $rows;
    }

    public static function categories(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT COALESCE(c.name, 'Uncategorized') category, COUNT(DISTINCT t.id) receipts, SUM(i.qty) qty, SUM(i.line_total) gross
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id
             LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.status = 'paid' AND i.status = 'active' AND t.business_date BETWEEN ? AND ?
             GROUP BY c.name ORDER BY gross DESC", [$from, $to]
        );
        $total = array_sum(array_column($rows, 'gross')) ?: 1;
        foreach ($rows as &$r) $r['share_pct'] = r2($r['gross'] / $total * 100);
        return $rows;
    }

    /** Every issued receipt (paid and voided), optionally only one status. */
    public static function receipts(string $from, string $to, ?string $status = null): array
    {
        $params = [$from, $to];
        $where = '';
        if (in_array($status, ['paid', 'void'], true)) { $where = 'AND t.status = ?'; $params[] = $status; }
        $rows = DB::all(
            "SELECT t.id, t.business_date date, t.receipt_no, t.ticket_no, t.status, t.order_type, t.table_label, t.customer_name, t.pax,
                    t.subtotal gross, t.discount_type, t.discount_amount discount, t.vat_amount vat, t.service_charge, t.total, t.paid_at,
                    u.full_name cashier, t.void_reason,
                    (SELECT GROUP_CONCAT(p.method ORDER BY p.id SEPARATOR ', ') FROM payments p WHERE p.ticket_id = t.id) payment
             FROM tickets t LEFT JOIN users u ON u.id = t.paid_by
             WHERE t.receipt_no IS NOT NULL AND t.business_date BETWEEN ? AND ? $where ORDER BY t.receipt_no", $params
        );
        foreach ($rows as &$r) {
            $r['order_type'] = self::ORDER_TYPES[$r['order_type']] ?? $r['order_type'];
            $r['discount_type'] = $r['discount_type'] === 'none' ? '' : (self::DISCOUNT_LABELS[$r['discount_type']] ?? $r['discount_type']);
            $r['payment'] = implode(', ', array_map([self::class, 'methodLabel'], array_filter(explode(', ', (string) $r['payment']))));
        }
        return $rows;
    }

    public static function payments(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT p.method, COUNT(*) transactions, SUM(p.amount) amount FROM payments p JOIN tickets t ON t.id = p.ticket_id
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ? GROUP BY p.method ORDER BY amount DESC", [$from, $to]
        );
        foreach ($rows as &$r) $r['label'] = self::methodLabel($r['method']);
        return $rows;
    }

    public static function hourly(string $from, string $to): array
    {
        return DB::all(
            "SELECT HOUR(paid_at) h, CONCAT(LPAD(HOUR(paid_at), 2, '0'), ':00 - ', LPAD(HOUR(paid_at), 2, '0'), ':59') hour,
                    COUNT(*) receipts, SUM(pax) pax, SUM(total) net_sales, AVG(total) avg_ticket
             FROM tickets WHERE status = 'paid' AND business_date BETWEEN ? AND ?
             GROUP BY HOUR(paid_at) ORDER BY h", [$from, $to]
        );
    }

    public static function cashiers(string $from, string $to): array
    {
        return DB::all(
            "SELECT u.full_name cashier, COUNT(*) receipts, SUM(t.total) net_sales, SUM(t.discount_amount) discounts,
                    (SELECT COUNT(*) FROM tickets v WHERE v.voided_by = u.id AND v.status = 'void' AND v.receipt_no IS NOT NULL
                       AND v.business_date BETWEEN ? AND ?) voids_authorized
             FROM tickets t JOIN users u ON u.id = t.paid_by
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ? GROUP BY u.id, u.full_name ORDER BY net_sales DESC",
            [$from, $to, $from, $to]
        );
    }

    /** Voided receipts and voided items, in the order they were voided. */
    public static function voids(string $from, string $to): array
    {
        $receipts = DB::all(
            "SELECT t.business_date date, 'Receipt' kind, t.receipt_no ref, '' item, NULL qty, t.total amount, t.void_reason reason,
                    t.voided_at, u.full_name authorized_by
             FROM tickets t LEFT JOIN users u ON u.id = t.voided_by
             WHERE t.status = 'void' AND t.receipt_no IS NOT NULL AND t.business_date BETWEEN ? AND ?", [$from, $to]
        );
        $items = DB::all(
            "SELECT t.business_date date, 'Item' kind, COALESCE(t.receipt_no, t.ticket_no) ref, i.name item, i.qty, i.line_total amount,
                    i.void_reason reason, i.voided_at, u.full_name authorized_by
             FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id LEFT JOIN users u ON u.id = i.voided_by
             WHERE i.status = 'void' AND t.business_date BETWEEN ? AND ?", [$from, $to]
        );
        $rows = array_merge($receipts, $items);
        usort($rows, fn ($a, $b) => strcmp((string) $a['voided_at'], (string) $b['voided_at']));
        return $rows;
    }

    /**
     * SC/PWD sales book (BIR requirement) and other discounts, with the names and ID numbers captured at the POS.
     * One row per receipt that has any discount (whole receipt or single items); "Discount(s)" lists what was given.
     */
    public static function discounts(string $from, string $to): array
    {
        $rows = DB::all(
            "SELECT t.id, t.business_date date, t.receipt_no, t.discount_type, t.discount_name, t.discount_rate, t.sc_count, t.pax, t.sc_details,
                    t.subtotal gross, t.vat_exempt_sales, t.sc_discount, t.promo_discount, t.discount_amount discount, t.total net
             FROM tickets t
             WHERE t.status = 'paid' AND t.business_date BETWEEN ? AND ?
               AND (t.discount_type <> 'none' OR t.discount_amount > 0)
             ORDER BY t.receipt_no", [$from, $to]
        );
        $itemDiscounts = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            foreach (DB::all(
                "SELECT ticket_id, name, discount_name, discount_kind FROM ticket_items
                 WHERE status = 'active' AND discount_kind IS NOT NULL AND ticket_id IN (" . DB::placeholders($ids) . ')', $ids
            ) as $i) {
                $itemDiscounts[$i['ticket_id']][] = ($i['discount_name'] ?: (self::DISCOUNT_LABELS[$i['discount_kind']] ?? $i['discount_kind'])) . ' (' . $i['name'] . ')';
            }
        }
        foreach ($rows as &$r) {
            $people = json_decode((string) $r['sc_details'], true);
            $people = is_array($people) ? $people : [];
            $r['names'] = implode('; ', array_filter(array_column($people, 'name')));
            $r['id_numbers'] = implode('; ', array_filter(array_column($people, 'id_no')));
            $given = $itemDiscounts[$r['id']] ?? [];
            if ($r['discount_type'] !== 'none') {
                $label = $r['discount_name'] ?: ($r['discount_type'] === 'percent' ? (float) $r['discount_rate'] . '%' : (self::DISCOUNT_LABELS[$r['discount_type']] ?? $r['discount_type']));
                array_unshift($given, $label . ' (whole receipt)');
            }
            $r['discount_type'] = implode('; ', $given);
            unset($r['sc_details'], $r['id'], $r['discount_name'], $r['discount_rate']);
        }
        return $rows;
    }

    /** End-of-day history: one row per business day (cash session). */
    public static function eod(string $from, string $to): array
    {
        return DB::all(
            "SELECT s.id, s.business_date, s.status, s.opened_at, ou.full_name opened_by, s.closed_at, cu.full_name closed_by, s.opening_cash,
                    (SELECT COUNT(*) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') receipts,
                    (SELECT COALESCE(SUM(total), 0) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') net_sales,
                    s.expected_cash, s.counted_cash, s.variance
             FROM cash_sessions s LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by
             WHERE s.business_date BETWEEN ? AND ? ORDER BY s.business_date, s.id", [$from, $to]
        );
    }

    /**
     * X/Z reading of one business day. Uses the POS module's live computation when available,
     * otherwise the Z-reading snapshot stored when the day was closed.
     */
    public static function reading(int $sessionId): array
    {
        $s = DB::one('SELECT id, status, z_data FROM cash_sessions WHERE id = ?', [$sessionId]);
        if (!$s) throw HttpException::notFound('Business day');
        if (class_exists(\App\Services\Pos\CashSessions::class) && method_exists(\App\Services\Pos\CashSessions::class, 'report')) {
            return \App\Services\Pos\CashSessions::report($sessionId);
        }
        $z = json_decode((string) $s['z_data'], true);
        if (!is_array($z)) throw HttpException::bad('No reading is stored for this business day yet.');
        return $z;
    }
}
