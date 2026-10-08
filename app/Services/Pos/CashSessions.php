<?php
namespace App\Services\Pos;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Ledger;

/**
 * Business day / cash drawer.
 * Open with the beginning cash, sell, then close with a cash count. The X-reading (mid-day) and
 * Z-reading (end of day) both come from report(). Any shortage/overage is posted to the GL on close.
 */
class CashSessions
{
    public const PAYMENT_METHODS = [
        'cash' => ['label' => 'Cash', 'account' => 'cash_on_hand'],
        'card' => ['label' => 'Credit/Debit Card', 'account' => 'card_clearing'],
        'gcash' => ['label' => 'GCash', 'account' => 'ewallet_clearing'],
        'maya' => ['label' => 'Maya', 'account' => 'ewallet_clearing'],
        'bank_transfer' => ['label' => 'Bank Transfer / InstaPay', 'account' => 'ewallet_clearing'],
        'grabfood' => ['label' => 'GrabFood', 'account' => 'ewallet_clearing'],
        'foodpanda' => ['label' => 'foodpanda', 'account' => 'ewallet_clearing'],
        'charge' => ['label' => 'Charge to Account', 'account' => 'ar'],
    ];

    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.25, 0.1, 0.05];

    public static function current(): ?array
    {
        return DB::one("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    }

    public static function requireOpen(): array
    {
        $s = self::current();
        if (!$s) throw HttpException::bad('The business day is not open yet. Open the day with the beginning cash first.');
        return $s;
    }

    public static function open(float $openingCash, ?string $businessDate = null, ?array $denominations = null, ?string $notes = null): array
    {
        return DB::transaction(function () use ($openingCash, $businessDate, $denominations, $notes) {
            if (DB::one("SELECT id FROM cash_sessions WHERE status = 'open' FOR UPDATE")) throw HttpException::bad('A business day is already open');
            if ($openingCash < 0) throw HttpException::bad('Beginning cash cannot be negative');
            $id = DB::insert('cash_sessions', [
                'business_date' => is_date($businessDate) ? $businessDate : today(), 'status' => 'open',
                'opened_by' => Auth::id(), 'opened_at' => now(), 'opening_cash' => r2($openingCash),
                'denominations' => $denominations ? json_encode(['opening' => $denominations]) : null, 'notes' => $notes,
            ]);
            Audit::log('open_day', 'cash_session', $id, ['opening' => $openingCash]);
            return DB::one('SELECT * FROM cash_sessions WHERE id = ?', [$id]);
        });
    }

    /** X / Z reading data for a business day. */
    public static function report(int $sessionId): array
    {
        $s = DB::one(
            'SELECT s.*, ou.full_name AS opened_by_name, cu.full_name AS closed_by_name FROM cash_sessions s
             LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by WHERE s.id = ?', [$sessionId]
        );
        if (!$s) throw HttpException::notFound('Business day');
        $sales = DB::one(
            "SELECT COUNT(*) cnt, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_amount),0) discounts,
                    COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(vatable_sales),0) vatable, COALESCE(SUM(vat_amount),0) vat,
                    COALESCE(SUM(vat_exempt_sales),0) exempt, COALESCE(SUM(total),0) net, COALESCE(SUM(pax),0) pax,
                    MIN(receipt_no) first_or, MAX(receipt_no) last_or
             FROM tickets WHERE cash_session_id = ? AND status = 'paid'", [$sessionId]
        );
        foreach (['gross', 'discounts', 'svc', 'vatable', 'vat', 'exempt', 'net'] as $k) $sales[$k] = r2($sales[$k]);
        $sales['cnt'] = (int) $sales['cnt'];
        $sales['pax'] = (int) $sales['pax'];
        $voided = DB::one("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amount FROM tickets WHERE cash_session_id = ? AND status = 'void' AND receipt_no IS NOT NULL", [$sessionId]);
        $cancelled = (int) DB::value("SELECT COUNT(*) FROM tickets WHERE cash_session_id = ? AND status = 'void' AND receipt_no IS NULL", [$sessionId]);
        $itemVoids = DB::one(
            "SELECT COUNT(*) cnt, COALESCE(SUM(i.line_total),0) amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
             WHERE t.cash_session_id = ? AND i.status = 'void'", [$sessionId]
        );
        $discounts = DB::all(
            "SELECT discount_type, COUNT(*) cnt, SUM(discount_amount) amount FROM tickets
             WHERE cash_session_id = ? AND status = 'paid' AND discount_type <> 'none' GROUP BY discount_type", [$sessionId]
        );
        $payments = array_map(fn ($p) => $p + ['label' => self::PAYMENT_METHODS[$p['method']]['label'] ?? $p['method']], DB::all(
            "SELECT p.method, COUNT(*) cnt, SUM(p.amount) amount FROM payments p JOIN tickets t ON t.id = p.ticket_id
             WHERE t.cash_session_id = ? AND t.status = 'paid' GROUP BY p.method ORDER BY amount DESC", [$sessionId]
        ));
        $cashSales = 0.0;
        foreach ($payments as $p) if ($p['method'] === 'cash') $cashSales = r2($p['amount']);
        $payouts = DB::all(
            "SELECT p.*, a.name AS account_name FROM petty_cash_txns p LEFT JOIN accounts a ON a.id = p.account_id
             WHERE p.cash_session_id = ? AND p.source = 'drawer' AND p.status = 'posted' ORDER BY p.id", [$sessionId]
        );
        $payoutTotal = r2(array_sum(array_map(fn ($p) => $p['txn_type'] === 'expense' ? (float) $p['amount'] : -(float) $p['amount'], $payouts)));
        // Cash refunded now for receipts that belonged to an earlier day
        $refunds = r2(DB::value(
            "SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN tickets t ON t.id = p.ticket_id
             WHERE t.void_session_id = ? AND t.cash_session_id <> ? AND p.method = 'cash'", [$sessionId, $sessionId]
        ));
        $categories = DB::all(
            "SELECT COALESCE(c.name, 'Uncategorized') category, SUM(i.qty) qty, SUM(i.line_total) amount FROM ticket_items i
             JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
             WHERE t.cash_session_id = ? AND t.status = 'paid' AND i.status = 'active' GROUP BY c.name ORDER BY amount DESC", [$sessionId]
        );
        $grandBefore = r2(DB::value("SELECT COALESCE(SUM(total),0) FROM tickets WHERE status = 'paid' AND cash_session_id < ?", [$sessionId]));
        $expected = r2((float) $s['opening_cash'] + $cashSales - $payoutTotal - $refunds);

        return [
            'session' => $s,
            'sales' => $sales,
            'voided' => ['cnt' => (int) $voided['cnt'], 'amount' => r2($voided['amount'])],
            'cancelled' => $cancelled,
            'item_voids' => ['cnt' => (int) $itemVoids['cnt'], 'amount' => r2($itemVoids['amount'])],
            'discounts' => $discounts,
            'payments' => $payments,
            'categories' => $categories,
            'payouts' => $payouts,
            'cash' => [
                'opening' => r2($s['opening_cash']), 'cash_sales' => $cashSales, 'payouts' => $payoutTotal, 'refunds' => $refunds,
                'expected' => $s['status'] === 'closed' ? r2($s['expected_cash']) : $expected,
                'counted' => $s['counted_cash'] === null ? null : r2($s['counted_cash']),
                'variance' => $s['variance'] === null ? null : r2($s['variance']),
            ],
            'grand_total' => ['beginning' => $grandBefore, 'ending' => r2($grandBefore + $sales['net'])],
        ];
    }

    /**
     * End of day. $denominations = [denomination => count]; if empty, $countedCash is used.
     * Posts cash short/over to the GL and stores the Z-reading snapshot.
     */
    public static function close(array $denominations = [], ?float $countedCash = null, ?string $notes = null): array
    {
        $s = self::requireOpen();
        $open = (int) DB::value("SELECT COUNT(*) FROM tickets WHERE cash_session_id = ? AND status = 'open'", [$s['id']]);
        if ($open > 0) throw HttpException::bad("There are still $open open order(s). Settle or cancel them before closing the day.");
        $counted = 0.0;
        foreach ($denominations as $d => $q) $counted += (float) $d * (float) $q;
        if (!$denominations && $countedCash !== null) $counted = $countedCash;
        $counted = r2($counted);

        DB::transaction(function () use ($s, $counted, $denominations, $notes) {
            $expected = self::report((int) $s['id'])['cash']['expected'];
            $variance = r2($counted - $expected);
            $jeId = null;
            if ($variance != 0) {
                $jeId = Ledger::post($s['business_date'], 'Cash ' . ($variance < 0 ? 'short' : 'over') . ' - EOD ' . $s['business_date'], [
                    ['key' => 'cash_on_hand', 'debit' => max($variance, 0), 'credit' => max(-$variance, 0)],
                    ['key' => 'cash_short_over', 'debit' => max(-$variance, 0), 'credit' => max($variance, 0)],
                ], 'pos_eod', (int) $s['id']);
            }
            $den = json_decode($s['denominations'] ?? '', true) ?: [];
            $den['closing'] = $denominations;
            DB::update('cash_sessions', (int) $s['id'], [
                'status' => 'closed', 'closed_by' => Auth::id(), 'closed_at' => now(), 'expected_cash' => $expected,
                'counted_cash' => $counted, 'variance' => $variance, 'denominations' => json_encode($den),
                'notes' => trim(($s['notes'] ?? '') . ($notes ? ' | ' . $notes : ''), ' |') ?: null, 'journal_entry_id' => $jeId,
            ]);
            DB::update('cash_sessions', (int) $s['id'], ['z_data' => json_encode(self::report((int) $s['id']))]);
            Audit::log('close_day', 'cash_session', (int) $s['id'], ['expected' => $expected, 'counted' => $counted, 'variance' => $variance]);
        });
        return self::report((int) $s['id']);
    }
}
