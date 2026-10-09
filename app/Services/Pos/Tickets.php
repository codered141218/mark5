<?php
namespace App\Services\Pos;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Services\Audit;
use App\Services\Discounts;
use App\Services\Inventory;
use App\Services\Ledger;
use App\Services\Sequence;
use App\Services\Settings;

/**
 * Orders ("tickets") at the POS.
 * Flow: create order -> add items -> (send to kitchen) -> assign a table any time -> discount -> pay.
 * Paying assigns the receipt number, deducts recipe ingredients from stock and posts the sale to the GL.
 */
class Tickets
{
    /** A ticket with its lines and payments (sc_details decoded). */
    public static function get(int $id): array
    {
        $t = DB::one(
            'SELECT t.*, u.full_name AS created_by_name, pu.full_name AS paid_by_name, vu.full_name AS voided_by_name, c.name AS customer_account_name
             FROM tickets t LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users pu ON pu.id = t.paid_by
             LEFT JOIN users vu ON vu.id = t.voided_by LEFT JOIN customers c ON c.id = t.customer_id WHERE t.id = ?', [$id]
        );
        if (!$t) throw HttpException::notFound('Order');
        $t['items'] = DB::all('SELECT * FROM ticket_items WHERE ticket_id = ? ORDER BY id', [$id]);
        $t['payments'] = DB::all('SELECT * FROM payments WHERE ticket_id = ? ORDER BY id', [$id]);
        $t['sc_details'] = json_decode($t['sc_details'] ?? '[]', true) ?: [];
        return self::numeric($t);
    }

    /** Cast DECIMAL strings to numbers so the POS JavaScript gets real numbers. */
    private static function numeric(array $t): array
    {
        $money = ['subtotal', 'discount_rate', 'discount_amount', 'sc_discount', 'promo_discount', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'service_charge', 'total', 'paid_total', 'change_amount', 'cogs'];
        foreach ($money as $k) $t[$k] = (float) $t[$k];
        foreach (['id', 'pax', 'sc_count', 'cash_session_id'] as $k) $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        foreach ($t['items'] as &$i) {
            foreach (['qty', 'price', 'line_total', 'discount_value', 'discount_amount'] as $k) $i[$k] = (float) $i[$k];
            $i['id'] = (int) $i['id']; $i['item_id'] = (int) $i['item_id']; $i['kitchen_sent'] = (int) $i['kitchen_sent'];
        }
        foreach ($t['payments'] as &$p) {
            foreach (['amount', 'tendered', 'change_amount'] as $k) $p[$k] = $p[$k] === null ? null : (float) $p[$k];
        }
        return $t;
    }

    public static function getOpen(int $id): array
    {
        $t = self::get($id);
        if ($t['status'] !== 'open') throw HttpException::bad("Order {$t['ticket_no']} is already {$t['status']}");
        return $t;
    }

    /** Open orders for the POS board. */
    public static function openList(): array
    {
        return array_map(fn ($r) => ['total' => (float) $r['total'], 'pax' => (int) $r['pax'], 'item_count' => (float) $r['item_count'], 'id' => (int) $r['id']] + $r, DB::all(
            "SELECT t.id, t.ticket_no, t.table_label, t.order_type, t.customer_name, t.pax, t.total, t.created_at, u.full_name AS created_by_name,
                    (SELECT COALESCE(SUM(i.qty),0) FROM ticket_items i WHERE i.ticket_id = t.id AND i.status = 'active') AS item_count,
                    (SELECT COUNT(*) FROM ticket_items i WHERE i.ticket_id = t.id AND i.status = 'active' AND i.kitchen_sent = 0) AS unsent
             FROM tickets t LEFT JOIN users u ON u.id = t.created_by WHERE t.status = 'open' ORDER BY t.id"
        ));
    }

    public static function recalc(int $id): array
    {
        $t = DB::one('SELECT * FROM tickets WHERE id = ?', [$id]);
        $lines = DB::all('SELECT * FROM ticket_items WHERE ticket_id = ?', [$id]);
        $totals = Totals::compute($t, $lines);
        $lineDiscounts = $totals['line_discounts'];
        unset($totals['line_discounts']);
        DB::update('tickets', $id, $totals);
        foreach ($lines as $l) {
            $amount = $l['status'] === 'active' ? ($lineDiscounts[(int) $l['id']] ?? 0.0) : 0.0;
            if (r2($l['discount_amount']) != $amount) DB::update('ticket_items', (int) $l['id'], ['discount_amount' => $amount]);
        }
        return $totals + ['line_discounts' => $lineDiscounts];
    }

    public static function create(array $data): array
    {
        $s = CashSessions::requireOpen();
        $type = in_array($data['order_type'] ?? '', ['dine_in', 'takeout', 'delivery'], true) ? $data['order_type'] : 'dine_in';
        $id = DB::insert('tickets', [
            'ticket_no' => Sequence::next('TKT', 'T', 6), 'cash_session_id' => $s['id'], 'business_date' => $s['business_date'],
            'table_label' => self::cleanLabel($data['table_label'] ?? null), 'order_type' => $type,
            'customer_name' => trim((string) ($data['customer_name'] ?? '')) ?: null, 'pax' => max((int) ($data['pax'] ?? 1), 1),
            'status' => 'open', 'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        return self::get($id);
    }

    private static function cleanLabel($label): ?string
    {
        $label = trim((string) $label);
        return $label === '' ? null : mb_substr($label, 0, 30);
    }

    public static function update(int $id, array $data): array
    {
        $t = self::getOpen($id);
        $upd = [];
        if (array_key_exists('customer_name', $data)) $upd['customer_name'] = trim((string) $data['customer_name']) ?: null;
        if (array_key_exists('pax', $data)) $upd['pax'] = max((int) $data['pax'], 1, (int) $t['sc_count']);
        if (isset($data['order_type']) && in_array($data['order_type'], ['dine_in', 'takeout', 'delivery'], true)) $upd['order_type'] = $data['order_type'];
        if (array_key_exists('notes', $data)) $upd['notes'] = $data['notes'] ?: null;
        DB::update('tickets', $id, $upd);
        self::recalc($id);
        return self::get($id);
    }

    /** Assign or change the table. Empty label = no table (take-out / counter). */
    public static function setTable(int $id, ?string $label): array
    {
        $t = self::getOpen($id);
        $label = self::cleanLabel($label);
        DB::update('tickets', $id, ['table_label' => $label, 'order_type' => $label ? 'dine_in' : $t['order_type']]);
        if ($t['table_label'] !== $label) Audit::log('assign_table', 'ticket', $id, ['from' => $t['table_label'], 'to' => $label]);
        self::recalc($id);
        return self::get($id);
    }

    /** Tables currently in use (for the "assign table" picker). */
    public static function tablesInUse(): array
    {
        return array_column(DB::all("SELECT DISTINCT table_label FROM tickets WHERE status = 'open' AND table_label IS NOT NULL ORDER BY table_label"), 'table_label');
    }

    public static function addItem(int $id, int $itemId, float $qty = 1, ?string $notes = null, $price = null, ?string $pin = null): array
    {
        $t = self::getOpen($id);
        $item = Inventory::item($itemId);
        if (!$item['active'] || !$item['sellable']) throw HttpException::bad("{$item['name']} is not available for sale");
        if (!($qty > 0)) throw HttpException::bad('Quantity must be greater than zero');
        $unitPrice = (float) $item['price'];
        if ($price !== null && $price !== '' && r2($price) != $unitPrice) {
            Auth::authorize('pos.discount', $pin);   // price override needs discount rights
            $unitPrice = r2($price);
        }
        $notes = trim((string) $notes) ?: null;
        // Same item, same price and notes, not yet sent to the kitchen -> increase quantity instead of a new line
        $existing = DB::one(
            "SELECT * FROM ticket_items WHERE ticket_id = ? AND item_id = ? AND status = 'active' AND kitchen_sent = 0 AND price = ? AND COALESCE(notes,'') = ? AND discount_kind IS NULL",
            [$id, $itemId, $unitPrice, $notes ?? '']
        );
        if ($existing) {
            $q = (float) $existing['qty'] + $qty;
            DB::update('ticket_items', (int) $existing['id'], ['qty' => $q, 'line_total' => r2($q * $unitPrice)]);
        } else {
            DB::insert('ticket_items', [
                'ticket_id' => $id, 'item_id' => $itemId, 'name' => $item['name'], 'qty' => $qty, 'price' => $unitPrice,
                'line_total' => r2($qty * $unitPrice), 'notes' => $notes, 'status' => 'active', 'kitchen_sent' => 0,
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
        }
        self::recalc($id);
        return self::get($id);
    }

    private static function line(int $ticketId, int $lineId): array
    {
        $l = DB::one("SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ? AND status = 'active'", [$lineId, $ticketId]);
        if (!$l) throw HttpException::notFound('Order line');
        return $l;
    }

    /** Change quantity / notes. Reducing a line already sent to the kitchen voids the difference (needs pos.void_item). */
    public static function updateLine(int $id, int $lineId, array $data, ?string $pin = null): array
    {
        self::getOpen($id);
        $l = self::line($id, $lineId);
        $qty = isset($data['qty']) ? (float) $data['qty'] : (float) $l['qty'];
        if (!($qty > 0)) throw HttpException::bad('Quantity must be greater than zero');
        DB::transaction(function () use ($id, $l, $qty, $data, $pin) {
            if ($l['kitchen_sent'] && $qty < (float) $l['qty']) {
                $by = Auth::authorize('pos.void_item', $pin);
                $diff = (float) $l['qty'] - $qty;
                DB::insert('ticket_items', [
                    'ticket_id' => $id, 'item_id' => $l['item_id'], 'name' => $l['name'], 'qty' => $diff, 'price' => $l['price'],
                    'line_total' => r2($diff * (float) $l['price']), 'notes' => $l['notes'], 'status' => 'void',
                    'void_reason' => ($data['reason'] ?? '') ?: 'Reduced quantity', 'voided_by' => $by, 'voided_at' => now(),
                    'kitchen_sent' => 1, 'created_by' => $l['created_by'], 'created_at' => $l['created_at'],
                ]);
            }
            $upd = ['qty' => $qty, 'line_total' => r2($qty * (float) $l['price'])];
            if (array_key_exists('notes', $data)) $upd['notes'] = trim((string) $data['notes']) ?: null;
            DB::update('ticket_items', (int) $l['id'], $upd);
            self::recalc($id);
        });
        return self::get($id);
    }

    /** Remove a line. Unsent lines are simply deleted; sent lines become voids (reason + pos.void_item / PIN). */
    public static function voidLine(int $id, int $lineId, ?string $reason, ?string $pin = null): array
    {
        self::getOpen($id);
        $l = self::line($id, $lineId);
        if (!$l['kitchen_sent']) {
            DB::run('DELETE FROM ticket_items WHERE id = ?', [$lineId]);
        } else {
            $by = Auth::authorize('pos.void_item', $pin);
            if (!trim((string) $reason)) throw HttpException::bad('Void reason is required');
            DB::update('ticket_items', $lineId, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $by, 'voided_at' => now()]);
            Audit::log('void_item', 'ticket', $id, ['line' => $l['name'], 'qty' => $l['qty'], 'reason' => $reason, 'authorized_by' => $by]);
        }
        self::recalc($id);
        return self::get($id);
    }

    /** Mark unsent lines as sent to the kitchen. Returns [ticket, sent lines] for the kitchen slip. */
    public static function send(int $id): array
    {
        $t = self::getOpen($id);
        $pending = array_values(array_filter($t['items'], fn ($i) => $i['status'] === 'active' && !$i['kitchen_sent']));
        DB::run("UPDATE ticket_items SET kitchen_sent = 1 WHERE ticket_id = ? AND status = 'active' AND kitchen_sent = 0", [$id]);
        return ['ticket' => self::get($id), 'sent' => $pending];
    }

    /**
     * Discount on the whole receipt. $d = ['discount_id' => preset id (+ 'value' for open presets)]
     * or the plain form ['discount_type' => none|sc|pwd|percent|amount, 'discount_rate' => n].
     * SC/PWD also needs 'sc_count', 'pax' and 'sc_details' => [['name' => .., 'id_no' => ..], ...].
     * Presets marked "requires approval" need pos.discount or a manager PIN.
     */
    public static function discount(int $id, array $d, ?string $pin = null): array
    {
        $t = self::getOpen($id);
        $disc = Discounts::resolve($d, 'order');
        $hasItemSc = (bool) array_filter($t['items'], fn ($i) => $i['status'] === 'active' && in_array($i['discount_kind'], ['sc', 'pwd'], true));
        if (!$disc) {
            $upd = ['discount_type' => 'none', 'discount_id' => null, 'discount_name' => null, 'discount_rate' => 0];
            if (!$hasItemSc) $upd += ['sc_count' => 0, 'sc_details' => null];
            DB::update('tickets', $id, $upd);
            self::recalc($id);
            Audit::log('discount', 'ticket', $id, ['type' => 'none']);
            return self::get($id);
        }
        if ($disc['requires_approval']) Auth::authorize('pos.discount', $pin);
        $upd = ['discount_type' => $disc['kind'], 'discount_id' => $disc['id'], 'discount_name' => $disc['name'], 'discount_rate' => 0];
        if ($disc['kind'] === 'sc' || $disc['kind'] === 'pwd') {
            if (array_filter($t['items'], fn ($i) => $i['status'] === 'active' && $i['discount_kind'])) {
                throw HttpException::bad('Remove the item discounts first: a Senior Citizen / PWD discount on the whole receipt cannot be combined with other discounts.');
            }
            $people = self::people($d['sc_details'] ?? []);
            $cnt = max((int) ($d['sc_count'] ?? count($people)), 1);
            if (count($people) < $cnt) throw HttpException::bad('Enter the name and ID number of each Senior Citizen / PWD');
            $upd['sc_count'] = $cnt;
            $upd['sc_details'] = json_encode(array_slice($people, 0, $cnt), JSON_UNESCAPED_UNICODE);
            $pax = (int) ($d['pax'] ?? 0) > 0 ? (int) $d['pax'] : (int) $t['pax'];
            $upd['pax'] = max($pax, $cnt);
        } else {
            if (!$hasItemSc) $upd += ['sc_count' => 0, 'sc_details' => null];   // keep names captured for SC/PWD items
            $rate = (float) $disc['value'];
            if (!($rate > 0)) throw HttpException::bad('Enter the discount value');
            if ($disc['kind'] === 'percent' && $rate > 100) throw HttpException::bad('Discount cannot exceed 100%');
            $upd['discount_rate'] = $rate;
        }
        DB::update('tickets', $id, $upd);
        self::recalc($id);
        Audit::log('discount', 'ticket', $id, ['name' => $disc['name'], 'type' => $disc['kind'], 'rate' => $upd['discount_rate'], 'sc_count' => $upd['sc_count'] ?? null]);
        return self::get($id);
    }

    /**
     * Discount on a single order line. $d like discount() (preset id or kind/value); kind 'none' removes it.
     * For SC/PWD on an item, pass 'sc_person' => ['name' => .., 'id_no' => ..] unless the order already has one.
     */
    public static function discountLine(int $id, int $lineId, array $d, ?string $pin = null): array
    {
        $t = self::getOpen($id);
        $l = self::line($id, $lineId);
        $disc = Discounts::resolve($d, 'item');
        if (!$disc) {
            DB::update('ticket_items', $lineId, ['discount_id' => null, 'discount_name' => null, 'discount_kind' => null, 'discount_value' => 0, 'discount_amount' => 0]);
            self::syncScPeople($id);
            self::recalc($id);
            Audit::log('discount_item', 'ticket', $id, ['line' => $l['name'], 'type' => 'none']);
            return self::get($id);
        }
        if (in_array($t['discount_type'], ['sc', 'pwd'], true)) {
            throw HttpException::bad('This receipt already has a Senior Citizen / PWD discount on the whole receipt. Remove it first to discount single items.');
        }
        if ($disc['requires_approval']) Auth::authorize('pos.discount', $pin);
        $value = (float) $disc['value'];
        if ($disc['kind'] === 'sc' || $disc['kind'] === 'pwd') {
            $people = self::people($t['sc_details']);
            $new = self::people(isset($d['sc_person']) ? [$d['sc_person']] : []);
            foreach ($new as $p) {
                if (!in_array($p['id_no'], array_column($people, 'id_no'), true)) $people[] = $p;
            }
            if (!$people) throw HttpException::bad('Enter the name and ID number of the Senior Citizen / PWD');
            DB::update('tickets', $id, ['sc_details' => json_encode($people, JSON_UNESCAPED_UNICODE), 'sc_count' => count($people), 'pax' => max((int) $t['pax'], count($people))]);
            $value = 0;
        } else {
            if (!($value > 0)) throw HttpException::bad('Enter the discount value');
            if ($disc['kind'] === 'percent' && $value > 100) throw HttpException::bad('Discount cannot exceed 100%');
        }
        DB::update('ticket_items', $lineId, ['discount_id' => $disc['id'], 'discount_name' => $disc['name'], 'discount_kind' => $disc['kind'], 'discount_value' => $value]);
        self::recalc($id);
        Audit::log('discount_item', 'ticket', $id, ['line' => $l['name'], 'name' => $disc['name'], 'type' => $disc['kind'], 'value' => $value]);
        return self::get($id);
    }

    /** Clean list of [name, id_no] pairs; both are required. */
    private static function people($list): array
    {
        if (is_string($list)) $list = json_decode($list, true) ?: [];
        $out = [];
        foreach ((array) $list as $p) {
            if (!is_array($p)) continue;
            $name = trim((string) ($p['name'] ?? ''));
            $idNo = trim((string) ($p['id_no'] ?? ''));
            if ($name === '' && $idNo === '') continue;
            if ($name === '' || $idNo === '') throw HttpException::bad('Name and ID number are required for each Senior Citizen / PWD');
            $out[] = ['name' => $name, 'id_no' => $idNo];
        }
        return $out;
    }

    /** When the last SC/PWD item discount is removed, forget the names (unless the whole receipt is SC/PWD). */
    private static function syncScPeople(int $id): void
    {
        $t = DB::one('SELECT discount_type FROM tickets WHERE id = ?', [$id]);
        if (in_array($t['discount_type'], ['sc', 'pwd'], true)) return;
        $tagged = DB::value("SELECT COUNT(*) FROM ticket_items WHERE ticket_id = ? AND status = 'active' AND discount_kind IN ('sc','pwd')", [$id]);
        if (!$tagged) DB::update('tickets', $id, ['sc_details' => null, 'sc_count' => 0]);
    }

    /**
     * Split: move selected lines (whole or partial quantities) to a new order or an existing open order.
     * $lines = [['id' => lineId, 'qty' => n], ...]. Returns ['source' => ticket, 'target' => ticket].
     */
    public static function split(int $id, array $lines, ?int $targetId = null, ?string $tableLabel = null, ?string $customerName = null): array
    {
        $t = self::getOpen($id);
        $sel = array_values(array_filter($lines, fn ($l) => (float) ($l['qty'] ?? 0) > 0));
        if (!$sel) throw HttpException::bad('Select the items to move');
        $target = DB::transaction(function () use ($t, $sel, $targetId, $tableLabel, $customerName) {
            if ($targetId) {
                if ($targetId === $t['id']) throw HttpException::bad('Choose a different order');
                self::getOpen($targetId);
            } else {
                $targetId = DB::insert('tickets', [
                    'ticket_no' => Sequence::next('TKT', 'T', 6), 'cash_session_id' => $t['cash_session_id'], 'business_date' => $t['business_date'],
                    'table_label' => $tableLabel !== null ? self::cleanLabel($tableLabel) : $t['table_label'], 'order_type' => $t['order_type'],
                    'customer_name' => $customerName ?: $t['customer_name'], 'pax' => 1, 'status' => 'open', 'split_from_id' => $t['id'],
                    'created_by' => Auth::id(), 'created_at' => now(),
                ]);
                if ($t['pax'] > 1) DB::update('tickets', $t['id'], ['pax' => $t['pax'] - 1]);
            }
            foreach ($sel as $s) {
                $l = DB::one("SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ? AND status = 'active'", [(int) $s['id'], $t['id']]);
                if (!$l) throw HttpException::bad('A selected item is no longer on the order');
                $q = min((float) $s['qty'], (float) $l['qty']);
                if ($q >= (float) $l['qty']) {
                    DB::run('UPDATE ticket_items SET ticket_id = ? WHERE id = ?', [$targetId, $l['id']]);
                } else {
                    $rest = (float) $l['qty'] - $q;
                    $isAmount = $l['discount_kind'] === 'amount';
                    $movedValue = $isAmount ? r2((float) $l['discount_value'] * $q / (float) $l['qty']) : (float) $l['discount_value'];
                    DB::update('ticket_items', (int) $l['id'], ['qty' => $rest, 'line_total' => r2($rest * (float) $l['price']),
                        'discount_value' => $isAmount ? r2((float) $l['discount_value'] - $movedValue) : (float) $l['discount_value']]);
                    $copy = $l;
                    unset($copy['id']);
                    DB::insert('ticket_items', array_merge($copy, ['ticket_id' => $targetId, 'qty' => $q, 'line_total' => r2($q * (float) $l['price']), 'discount_value' => $movedValue]));
                }
            }
            if (DB::value("SELECT COUNT(*) FROM ticket_items WHERE ticket_id = ? AND discount_kind IN ('sc','pwd')", [$targetId])) {
                $names = $t['sc_details'] ? json_encode($t['sc_details'], JSON_UNESCAPED_UNICODE) : null;
                DB::update('tickets', $targetId, ['sc_details' => $names, 'sc_count' => count($t['sc_details'])]);
            }
            self::syncScPeople($t['id']);
            self::recalc($t['id']);
            self::recalc($targetId);
            return $targetId;
        });
        Audit::log('split', 'ticket', $t['id'], ['to' => $target]);
        return ['source' => self::get($t['id']), 'target' => self::get($target)];
    }

    /** Move every line of $sourceId into $id; the source order is closed as merged. */
    public static function merge(int $id, int $sourceId): array
    {
        $t = self::getOpen($id);
        $src = self::getOpen($sourceId);
        if ($src['id'] === $t['id']) throw HttpException::bad('Choose a different order to merge');
        DB::transaction(function () use ($t, $src) {
            DB::run('UPDATE ticket_items SET ticket_id = ? WHERE ticket_id = ?', [$t['id'], $src['id']]);
            DB::update('tickets', $t['id'], ['pax' => $t['pax'] + $src['pax'], 'table_label' => $t['table_label'] ?? $src['table_label']]);
            DB::update('tickets', $src['id'], ['status' => 'void', 'void_reason' => "Merged into {$t['ticket_no']}", 'voided_by' => Auth::id(),
                'voided_at' => now(), 'void_session_id' => $src['cash_session_id']]);
            self::recalc($t['id']);
        });
        Audit::log('merge', 'ticket', $t['id'], ['from' => $src['id']]);
        return self::get($t['id']);
    }

    /**
     * Settle an order with one or more payments:
     *   [['method' => 'cash', 'amount' => 500], ['method' => 'card', 'amount' => 200, 'reference' => 'APP123'],
     *    ['method' => 'charge', 'amount' => 300, 'customer_id' => 1]]
     * Cash may exceed the balance (change is given); non-cash payments may not.
     */
    public static function pay(int $id, array $payments): array
    {
        $t = self::getOpen($id);
        $session = CashSessions::requireOpen();
        $active = array_values(array_filter($t['items'], fn ($i) => $i['status'] === 'active'));
        if (!$active) throw HttpException::bad('The order has no items');
        $totals = self::recalc($id);
        $total = $totals['total'];

        $pays = [];
        foreach ($payments as $p) {
            $amt = r2($p['amount'] ?? 0);
            if ($amt <= 0) continue;
            $method = $p['method'] ?? '';
            if (!isset(CashSessions::PAYMENT_METHODS[$method])) throw HttpException::bad("Unknown payment method $method");
            if ($method !== 'cash' && $method !== 'charge' && Settings::get('require_payment_ref') === '1' && trim($p['reference'] ?? '') === '') {
                throw HttpException::bad('Reference / approval number is required for ' . CashSessions::PAYMENT_METHODS[$method]['label']);
            }
            $pays[] = ['method' => $method, 'amount' => $amt, 'reference' => trim($p['reference'] ?? '') ?: null, 'customer_id' => isset($p['customer_id']) ? (int) $p['customer_id'] : null];
        }
        if (!$pays) throw HttpException::bad('Enter the payment');
        $nonCash = r2(array_sum(array_map(fn ($p) => $p['method'] !== 'cash' ? $p['amount'] : 0, $pays)));
        $cash = r2(array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? $p['amount'] : 0, $pays)));
        if ($nonCash > $total + 0.001) throw HttpException::bad('Non-cash payments cannot exceed the amount due');
        if (r2($nonCash + $cash) < $total) throw HttpException::bad('Payment is short by ₱' . number_format($total - $nonCash - $cash, 2));
        $change = r2($nonCash + $cash - $total);
        $charge = null;
        foreach ($pays as $p) if ($p['method'] === 'charge') $charge = $p;
        if ($charge && !$charge['customer_id']) throw HttpException::bad('Select the customer account to charge');

        DB::transaction(function () use ($t, $session, $active, $totals, $total, $pays, $nonCash, $cash, $change, $charge) {
            $receiptNo = Sequence::next('OR', Settings::get('receipt_prefix', 'OR') ?: 'OR', 8);
            $cashApplied = r2($cash - $change);
            $applied = [];   // GL account key => amount
            foreach ($pays as $p) {
                $amt = $p['amount'];
                if ($p['method'] === 'cash') { $amt = max($cashApplied, 0); $cashApplied = 0; }
                DB::insert('payments', [
                    'ticket_id' => $t['id'], 'cash_session_id' => $session['id'], 'method' => $p['method'], 'amount' => $amt,
                    'tendered' => $p['method'] === 'cash' ? $p['amount'] : $amt, 'change_amount' => $p['method'] === 'cash' ? $change : 0,
                    'reference' => $p['reference'], 'customer_id' => $p['customer_id'], 'user_id' => Auth::id(), 'created_at' => now(),
                ]);
                $key = CashSessions::PAYMENT_METHODS[$p['method']]['account'];
                $applied[$key] = r2(($applied[$key] ?? 0) + $amt);
            }

            // Inventory: deduct recipe ingredients / retail stock
            $ref = ['ref_type' => 'ticket', 'ref_id' => $t['id'], 'ref_no' => $receiptNo, 'bdate' => $t['business_date']];
            $cogs = Inventory::consume($active, 'SALE', $ref);

            // General ledger
            $discountNet = Totals::discountNetOfVat($totals);
            $sales = r2($total - $totals['vat_amount'] - $totals['service_charge'] + $discountNet);
            $lines = [];
            foreach ($applied as $key => $amt) {
                $lines[] = ['key' => $key, 'debit' => $amt, 'party_type' => $key === 'ar' ? 'customer' : null, 'party_id' => $key === 'ar' ? $charge['customer_id'] : null];
            }
            $lines[] = ['key' => 'sales_discounts', 'debit' => $discountNet, 'memo' => $totals['discount_amount'] > 0 ? ($t['discount_name'] ?: 'Item discounts') : null];
            $lines[] = ['key' => 'sales', 'credit' => $sales];
            $lines[] = ['key' => 'output_vat', 'credit' => $totals['vat_amount']];
            $lines[] = ['key' => 'service_charge', 'credit' => $totals['service_charge']];
            $lines[] = ['key' => 'cogs', 'debit' => $cogs];
            $lines[] = ['key' => 'inventory', 'credit' => $cogs];
            $jeId = Ledger::post($t['business_date'], "POS sale $receiptNo" . ($t['table_label'] ? " (Table {$t['table_label']})" : ''), $lines, 'pos_sale', $t['id'], $receiptNo);

            if ($charge) {
                $cust = DB::one('SELECT * FROM customers WHERE id = ?', [$charge['customer_id']]);
                if (!$cust) throw HttpException::notFound('Customer');
                DB::insert('ar_invoices', [
                    'invoice_no' => Sequence::next('AR', 'AR'), 'customer_id' => $cust['id'], 'inv_date' => $t['business_date'],
                    'due_date' => add_days($t['business_date'], (int) $cust['terms_days']), 'ref_no' => $receiptNo, 'description' => "POS charge $receiptNo",
                    'amount' => $applied['ar'], 'paid_amount' => 0, 'status' => 'open', 'income_account_id' => Ledger::account('sales'),
                    'source_type' => 'pos_sale', 'source_id' => $t['id'], 'journal_entry_id' => $jeId, 'created_by' => Auth::id(), 'created_at' => now(),
                ]);
            }
            DB::update('tickets', $t['id'], [
                'status' => 'paid', 'receipt_no' => $receiptNo, 'paid_total' => r2($nonCash + $cash), 'change_amount' => $change, 'cogs' => $cogs,
                'paid_by' => Auth::id(), 'paid_at' => now(), 'journal_entry_id' => $jeId, 'customer_id' => $charge ? $charge['customer_id'] : $t['customer_id'],
            ]);
            DB::run("UPDATE ticket_items SET kitchen_sent = 1 WHERE ticket_id = ? AND status = 'active'", [$t['id']]);
        });
        Audit::log('settle', 'ticket', $t['id'], ['total' => $total, 'change' => $change]);
        return self::get($t['id']);
    }

    /**
     * Cancel an open order, or void a paid receipt (returns stock, reverses the sale in the GL).
     * Cancelling an order with items already sent to the kitchen needs pos.void_item; voiding a receipt needs pos.void_receipt.
     */
    public static function void(int $id, ?string $reason, ?string $pin = null): array
    {
        $t = self::get($id);
        if ($t['status'] === 'void') throw HttpException::bad('This order is already void');
        if (!trim((string) $reason)) throw HttpException::bad('Void reason is required');

        if ($t['status'] === 'open') {
            $sent = (bool) array_filter($t['items'], fn ($i) => $i['kitchen_sent'] && $i['status'] === 'active');
            $by = $sent ? Auth::authorize('pos.void_item', $pin) : Auth::id();
            DB::update('tickets', $id, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $by, 'voided_at' => now(), 'void_session_id' => $t['cash_session_id']]);
            Audit::log('cancel_order', 'ticket', $id, ['reason' => $reason, 'authorized_by' => $by]);
            return self::get($id);
        }

        $by = Auth::authorize('pos.void_receipt', $pin);
        $session = CashSessions::current();
        $cashPart = array_sum(array_map(fn ($p) => $p['method'] === 'cash' ? $p['amount'] : 0, $t['payments']));
        if ($cashPart > 0 && !$session) throw HttpException::bad('Open the business day first: the cash refund for this receipt comes out of the drawer.');
        DB::transaction(function () use ($t, $id, $reason, $by, $session) {
            $ar = DB::one("SELECT * FROM ar_invoices WHERE source_type = 'pos_sale' AND source_id = ? AND status <> 'void'", [$id]);
            if ($ar) {
                if ((float) $ar['paid_amount'] > 0) throw HttpException::bad("Receivable {$ar['invoice_no']} already has collections. Void the collections first.");
                DB::run("UPDATE ar_invoices SET status = 'void' WHERE id = ?", [$ar['id']]);
            }
            $sameDay = $session && (int) $session['id'] === $t['cash_session_id'];
            $vdate = $sameDay ? $t['business_date'] : ($session['business_date'] ?? today());
            Inventory::reverseMovements('ticket', $id, $vdate, "Void {$t['receipt_no']}");
            Ledger::reverse($t['journal_entry_id'] ? (int) $t['journal_entry_id'] : null, $vdate, "Void receipt {$t['receipt_no']}: $reason");
            DB::update('tickets', $id, ['status' => 'void', 'void_reason' => $reason, 'voided_by' => $by, 'voided_at' => now(), 'void_session_id' => $session['id'] ?? null]);
        });
        Audit::log('void_receipt', 'ticket', $id, ['receipt' => $t['receipt_no'], 'total' => $t['total'], 'reason' => $reason, 'authorized_by' => $by]);
        return self::get($id);
    }

    /** Receipts of the current day (or search by receipt / order no / customer). */
    public static function receipts(?string $q = null): array
    {
        $where = ["t.status <> 'open'"];
        $params = [];
        if ($q) {
            $where[] = '(t.receipt_no LIKE ? OR t.ticket_no LIKE ? OR t.customer_name LIKE ? OR t.table_label = ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", $q);
        } else {
            $s = CashSessions::current() ?? DB::one('SELECT * FROM cash_sessions ORDER BY id DESC LIMIT 1');
            if (!$s) return [];
            $where[] = 't.cash_session_id = ?';
            $params[] = $s['id'];
        }
        return array_map(fn ($r) => ['total' => (float) $r['total'], 'id' => (int) $r['id']] + $r, DB::all(
            'SELECT t.id, t.ticket_no, t.receipt_no, t.status, t.total, t.paid_at, t.created_at, t.order_type, t.customer_name, t.table_label,
                    t.business_date, t.void_reason, u.full_name AS cashier
             FROM tickets t LEFT JOIN users u ON u.id = COALESCE(t.paid_by, t.created_by)
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 300', $params
        ));
    }
}
