<?php
namespace App\Controllers\Pos;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Discounts;
use App\Services\Finance\PettyCash;
use App\Services\Pos\CashSessions;
use App\Services\Pos\Tickets;
use App\Services\Settings;

/**
 * JSON API used by the POS screen (public/assets/js/pos.js). All routes are under /api/pos.
 * Every method is a thin wrapper around App\Services\Pos\Tickets / CashSessions.
 */
class PosApiController
{
    /** Everything the POS needs at start-up. */
    public function state(Request $req): Response
    {
        return json(self::bootstrap());
    }

    public static function bootstrap(): array
    {
        $s = Settings::all();
        $u = Auth::user();
        $perms = ['pos.open_day', 'pos.close_day', 'pos.xreading', 'pos.settle', 'pos.discount', 'pos.void_item', 'pos.void_receipt',
            'pos.reprint', 'pos.split_move', 'pos.petty_cash', 'dashboard.view', 'inventory.view', 'reports.sales', 'finance.view', 'admin.settings'];
        return [
            'session' => CashSessions::current(),
            'tax' => Settings::tax(),
            'payment_methods' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v['label']], array_keys(CashSessions::PAYMENT_METHODS), CashSessions::PAYMENT_METHODS),
            'denominations' => CashSessions::DENOMINATIONS,
            'discounts' => Discounts::all(true),
            'stations' => array_map(fn ($st) => ['id' => $st['id'], 'name' => $st['name']], \App\Services\Stations::all(true)),
            'options' => ['require_table' => Settings::get('require_table_dine_in', '1') === '1', 'prices_include_vat' => Settings::pricesIncludeVat(),
                'blind_count' => Settings::get('blind_count', '0') === '1'],
            'business' => [
                'name' => $s['business_name'], 'address' => $s['business_address'], 'tin' => $s['business_tin'], 'phone' => $s['business_phone'],
                'receipt_title' => $s['receipt_title'], 'receipt_footer' => $s['receipt_footer'],
            ],
            'user' => ['id' => $u['id'], 'name' => $u['full_name'], 'role' => $u['role_name']],
            'can' => array_fill_keys(array_values(array_filter($perms, fn ($p) => Auth::can($p))), true),
        ];
    }

    public function menu(Request $req): Response
    {
        $order = ['name' => 'i.name', 'name_desc' => 'i.name DESC', 'price' => 'i.price, i.name', 'price_desc' => 'i.price DESC, i.name'][Settings::get('pos_menu_sort', 'custom')]
            ?? 'i.sort_order, i.name';
        $items = array_map(fn ($i) => ['id' => (int) $i['id'], 'category_id' => (int) $i['category_id'], 'price' => (float) $i['price'], 'stock_qty' => (float) $i['stock_qty'],
            'station_id' => $i['station_id'] === null ? null : (int) $i['station_id']] + $i, DB::all(
            "SELECT i.id, i.name, i.price, i.category_id, i.color, i.item_type, i.stock_qty, i.sku, i.barcode, COALESCE(i.station_id, c.station_id) AS station_id
             FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.active = 1 AND i.sellable = 1 ORDER BY $order"
        ));
        $used = array_flip(array_column($items, 'category_id'));
        $categories = array_values(array_filter(
            DB::all('SELECT id, name, color FROM categories WHERE active = 1 ORDER BY sort_order, name'),
            fn ($c) => isset($used[(int) $c['id']])
        ));
        return json(['categories' => $categories, 'items' => $items]);
    }

    public function orders(Request $req): Response
    {
        return json(['orders' => Tickets::openList(), 'tables' => Tickets::tablesInUse(), 'session' => CashSessions::current()]);
    }

    public function create(Request $req): Response
    {
        return json(Tickets::create($req->all()));
    }

    public function show(Request $req, string $id): Response
    {
        return json(Tickets::get((int) $id));
    }

    public function update(Request $req, string $id): Response
    {
        return json(Tickets::update((int) $id, $req->all()));
    }

    public function table(Request $req, string $id): Response
    {
        return json(Tickets::setTable((int) $id, $req->input('table_label')));
    }

    public function addItem(Request $req, string $id): Response
    {
        return json(Tickets::addItem((int) $id, (int) $req->input('item_id'), (float) $req->input('qty', 1), $req->input('notes'), $req->input('price'), $req->input('pin')));
    }

    public function updateLine(Request $req, string $id, string $line): Response
    {
        return json(Tickets::updateLine((int) $id, (int) $line, $req->all(), $req->input('pin')));
    }

    public function voidLine(Request $req, string $id, string $line): Response
    {
        return json(Tickets::voidLine((int) $id, (int) $line, $req->input('reason'), $req->input('pin')));
    }

    /** Done taking the order: {ticket, sent} — the POS prints the order slips of the sent lines per station. */
    public function done(Request $req, string $id): Response
    {
        return json(Tickets::done((int) $id));
    }

    public function discount(Request $req, string $id): Response
    {
        return json(Tickets::discount((int) $id, $req->all(), $req->input('pin')));
    }

    public function discountLine(Request $req, string $id, string $line): Response
    {
        return json(Tickets::discountLine((int) $id, (int) $line, $req->all(), $req->input('pin')));
    }

    /** Reprint the order slip (all items, or the stations chosen) — logged in the audit trail. */
    public function reprintOrder(Request $req, string $id): Response
    {
        $t = Tickets::get((int) $id);
        Audit::log('reprint_order', 'ticket', (int) $id, ['ticket' => $t['ticket_no'], 'stations' => $req->input('stations')]);
        return json($t);
    }

    public function split(Request $req, string $id): Response
    {
        $target = $req->input('target_id');
        return json(Tickets::split((int) $id, (array) $req->input('lines', []), $target ? (int) $target : null, $req->input('table_label'), $req->input('customer_name')));
    }

    public function merge(Request $req, string $id): Response
    {
        return json(Tickets::merge((int) $id, (int) $req->input('source_id')));
    }

    public function pay(Request $req, string $id): Response
    {
        return json(Tickets::pay((int) $id, (array) $req->input('payments', [])));
    }

    public function void(Request $req, string $id): Response
    {
        return json(Tickets::void((int) $id, $req->input('reason'), $req->input('pin')));
    }

    public function reprint(Request $req, string $id): Response
    {
        $t = Tickets::get((int) $id);
        Audit::log('reprint', 'ticket', (int) $id, $t['receipt_no']);
        return json($t);
    }

    public function receipts(Request $req): Response
    {
        return json(Tickets::receipts($req->query('q')));
    }

    public function customers(Request $req): Response
    {
        return json(DB::all(
            "SELECT c.id, c.name, c.credit_limit,
                    (SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices a WHERE a.customer_id = c.id AND a.status IN ('open','partial')) AS balance
             FROM customers c WHERE c.active = 1 ORDER BY c.name"
        ));
    }

    // ------------------------------------------------------------------ business day
    public function openDay(Request $req): Response
    {
        return json(CashSessions::open((float) $req->input('opening_cash', 0), $req->input('business_date'), $req->input('denominations') ?: null, $req->input('notes')));
    }

    public function xreading(Request $req): Response
    {
        return json(CashSessions::report((int) CashSessions::requireOpen()['id']));
    }

    public function closeDay(Request $req): Response
    {
        $counted = $req->input('counted_cash');
        return json(CashSessions::close((array) $req->input('denominations', []), $counted === null || $counted === '' ? null : (float) $counted, $req->input('notes')));
    }

    // ------------------------------------------------------------------ drawer payouts (petty cash)
    public function payouts(Request $req): Response
    {
        $s = CashSessions::current();
        return json($s ? PettyCash::listForSession((int) $s['id']) : []);
    }

    public function addPayout(Request $req): Response
    {
        $id = PettyCash::record(['txn_type' => 'expense'] + $req->all());
        return json(['id' => $id]);
    }

    public function voidPayout(Request $req, string $id): Response
    {
        PettyCash::void((int) $id, $req->input('reason'), $req->input('pin'));
        return json(['ok' => true]);
    }
}
