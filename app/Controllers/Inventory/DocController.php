<?php
namespace App\Controllers\Inventory;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Inventory;
use App\Services\InventoryDocs;
use App\Services\Items;
use App\Services\Ledger;
use App\Services\Settings;

/**
 * Delivery / stock in (/inventory/receiving), stock issuance (/inventory/issuance) and
 * spoilage & wastage (/inventory/wastage). One controller serves all three: the document
 * type comes from the first part of the URL. Business rules live in App\Services\InventoryDocs.
 */
class DocController
{
    /** URL slug => document type */
    public const SLUGS = ['receiving' => 'RECEIVE', 'issuance' => 'ISSUE', 'wastage' => 'WASTE'];

    private const CONFIG = [
        'RECEIVE' => ['title' => 'Delivery / Stock In', 'new' => 'New delivery',
            'subtitle' => 'Record supplier deliveries and purchases. Posting adds stock, updates average cost and books the payable or payment.'],
        'ISSUE' => ['title' => 'Stock Issuance', 'new' => 'New issuance',
            'subtitle' => 'Issue stock out of the storeroom to the kitchen, commissary, staff meals or another branch. Posting deducts stock and charges the expense account.'],
        'WASTE' => ['title' => 'Spoilage & Wastage', 'new' => 'New wastage report',
            'subtitle' => 'Write off spoiled, expired or damaged stock. Posting deducts stock and books it to Spoilage & Wastage expense.'],
    ];
    private const STATUSES = ['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled (void)'];
    private const ISSUE_TO = ['Kitchen', 'Bar', 'Commissary', 'Staff meal', 'Branch 2', 'Events / catering'];

    public function index(Request $req)
    {
        [$slug, $type] = $this->type($req);
        [$from, $to] = date_range($req);
        $status = isset(self::STATUSES[$req->query('status')]) ? $req->query('status') : '';
        $rows = DB::all(
            'SELECT d.*, s.name AS supplier_name, u.full_name AS created_by_name,
                    (SELECT COUNT(*) FROM inv_doc_lines l WHERE l.doc_id = d.id) AS line_count
             FROM inv_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN users u ON u.id = d.created_by
             WHERE d.doc_type = ? AND d.doc_date BETWEEN ? AND ?' . ($status ? ' AND d.status = ?' : '') . '
             ORDER BY d.doc_date DESC, d.id DESC',
            array_merge([$type, $from, $to], $status ? [$status] : [])
        );
        foreach ($rows as &$r) {
            $r['_class'] = $r['status'] === 'cancelled' ? 'muted-row' : '';
            $r['party'] = match ($type) {
                'RECEIVE' => implode(' · ', array_filter([$r['supplier_name'], $r['invoice_no'] ? "Inv/DR {$r['invoice_no']}" : null,
                    InventoryDocs::PAYMENT_MODES[$r['payment_mode']] ?? null])),
                'ISSUE' => implode(' · ', array_filter([$r['issued_to'], $r['reason']])),
                default => ucfirst((string) $r['reason']),
            };
        }
        unset($r);

        $columns = [
            ['key' => 'doc_no', 'label' => 'Doc no.', 'html' => fn ($r) => '<b>' . e($r['doc_no']) . '</b>'],
            ['key' => 'doc_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'party', 'label' => ['RECEIVE' => 'Supplier / Invoice / Payment', 'ISSUE' => 'Issued to / Reason', 'WASTE' => 'Reason'][$type]],
            ['key' => 'line_count', 'label' => 'Lines', 'type' => 'int'],
            ['key' => 'total_cost', 'label' => 'Total cost', 'type' => 'money',
                'total' => fn ($rs) => array_sum(array_map(fn ($r) => $r['status'] === 'cancelled' ? 0 : (float) $r['total_cost'], $rs))],
            ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['key' => 'created_by_name', 'label' => 'Prepared by'],
        ];
        $cfg = self::CONFIG[$type];
        if ($x = Table::export($req, $slug, $cfg['title'], range_label($from, $to), $columns, $rows)) return $x;

        $posted = array_filter($rows, fn ($r) => $r['status'] === 'posted');
        $drafts = array_filter($rows, fn ($r) => $r['status'] === 'draft');
        return view('inventory/docs/index', [
            'title' => $cfg['title'], 'cfg' => $cfg, 'slug' => $slug, 'rows' => $rows, 'columns' => $columns,
            'from' => $from, 'to' => $to, 'status' => $status, 'statuses' => self::STATUSES,
            'stats' => [
                'posted_total' => array_sum(array_column($posted, 'total_cost')), 'posted_count' => count($posted),
                'draft_total' => array_sum(array_column($drafts, 'total_cost')), 'draft_count' => count($drafts),
            ],
        ]);
    }

    public function create(Request $req)
    {
        [$slug, $type] = $this->type($req);
        return $this->form($slug, $type, null);
    }

    public function show(Request $req, string $id)
    {
        [$slug, $type] = $this->type($req);
        return $this->form($slug, $type, $this->find($type, $id));
    }

    public function store(Request $req): Response
    {
        [$slug, $type] = $this->type($req);
        $id = InventoryDocs::create($type, $this->input($req));
        $d = InventoryDocs::find($id);
        flash('success', $d['status'] === 'posted' ? "{$d['doc_no']} posted." : "{$d['doc_no']} saved as draft.");
        return redirect("/inventory/$slug/$id");
    }

    public function update(Request $req, string $id): Response
    {
        [$slug, $type] = $this->type($req);
        $d = $this->find($type, $id);
        InventoryDocs::update((int) $d['id'], $this->input($req));
        flash('success', $req->input('post') ? "{$d['doc_no']} posted." : "{$d['doc_no']} saved.");
        return redirect("/inventory/$slug/{$d['id']}");
    }

    public function post(Request $req, string $id): Response
    {
        [$slug, $type] = $this->type($req);
        $d = $this->find($type, $id);
        InventoryDocs::post((int) $d['id']);
        flash('success', "{$d['doc_no']} posted.");
        return redirect("/inventory/$slug/{$d['id']}");
    }

    public function void(Request $req, string $id): Response
    {
        [$slug, $type] = $this->type($req);
        $d = $this->find($type, $id);
        InventoryDocs::void((int) $d['id'], (string) $req->input('reason', ''));
        flash('success', "{$d['doc_no']} voided. Stock and journal entries were reversed.");
        return redirect("/inventory/$slug/{$d['id']}");
    }

    public function delete(Request $req, string $id): Response
    {
        [$slug, $type] = $this->type($req);
        $d = $this->find($type, $id);
        InventoryDocs::delete((int) $d['id']);
        flash('success', "Draft {$d['doc_no']} deleted.");
        return redirect("/inventory/$slug");
    }

    /** [slug, type] from the URL, e.g. /inventory/receiving/5 -> ['receiving', 'RECEIVE']. */
    private function type(Request $req): array
    {
        $slug = explode('/', $req->path)[2] ?? '';
        if (!isset(self::SLUGS[$slug])) throw HttpException::notFound('Page');
        return [$slug, self::SLUGS[$slug]];
    }

    /** The document, which must be of the type in the URL. */
    private function find(string $type, string $id): array
    {
        $d = InventoryDocs::find((int) $id);
        if ($d['doc_type'] !== $type) throw HttpException::notFound('Document');
        return $d;
    }

    private function input(Request $req): array
    {
        $data = $req->all();
        $data['lines'] = array_values($data['lines'] ?? []);
        return $data;
    }

    /** Document page: an editable form for new / draft documents, read-only for posted and voided ones. */
    private function form(string $slug, string $type, ?array $doc): string
    {
        $editable = !$doc || $doc['status'] === 'draft';
        $old = old('_form') === 'doc' ? $GLOBALS['__old'] : null;
        $head = $old ?? $doc ?? ['doc_date' => today(), 'payment_mode' => 'credit', 'reason' => $type === 'WASTE' ? 'spoilage' : '',
            'expense_account_id' => $type === 'ISSUE' ? Ledger::account('supplies') : null];
        if ($old) $head += ['vat_inclusive' => 0];

        $lines = $old ? array_values(array_filter($old['lines'] ?? [], fn ($l) => !empty($l['item_id']))) : ($doc['lines'] ?? []);
        foreach ($lines as &$l) {
            $l['units'] = DB::value('SELECT id FROM items WHERE id = ?', [(int) $l['item_id']]) ? Inventory::units((int) $l['item_id']) : [];
        }
        unset($l);

        $types = $type === 'RECEIVE' ? "'raw','retail'" : "'raw','retail','composite'";
        $lineItems = array_map('intval', array_column($lines, 'item_id'));
        $items = DB::all(
            "SELECT i.id, i.name, i.sku, i.item_type, i.stock_qty, i.avg_cost, i.last_cost, i.base_uom_id, i.active, u.abbr AS uom
             FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
             WHERE i.item_type IN ($types) AND (i.active = 1 OR i.id IN (" . DB::placeholders($lineItems) . ')) ORDER BY i.name',
            $lineItems ?: [0]
        );
        $costs = Items::unitCosts();
        $itemOptions = [];
        $itemData = [];
        foreach ($items as $i) {
            $stocked = $i['item_type'] !== 'composite';
            $itemOptions[$i['id']] = $i['name'] . ($i['sku'] ? " · {$i['sku']}" : '')
                . ($stocked ? ' · ' . qty($i['stock_qty']) . " {$i['uom']} on hand" : ' · recipe') . ($i['active'] ? '' : ' (inactive)');
            $itemData[$i['id']] = [
                'type' => $i['item_type'], 'base' => (int) $i['base_uom_id'],
                'cost' => (float) $i['last_cost'] ?: (float) $i['avg_cost'],   // pre-fills the delivery unit cost
                'unit_cost' => $costs[(int) $i['id']] ?? 0,                     // values issuance / wastage lines
            ];
        }

        $bill = $doc && $doc['ap_bill_id'] ? DB::one('SELECT * FROM ap_bills WHERE id = ?', [$doc['ap_bill_id']]) : null;
        $expense = $type === 'ISSUE' ? DB::all("SELECT id, code, name, system_key FROM accounts WHERE type = 'expense' AND (active = 1 OR id = ?) ORDER BY code",
            [(int) ($head['expense_account_id'] ?? 0)]) : [];
        $cfg = self::CONFIG[$type];

        return view('inventory/docs/edit', [
            'title' => $doc ? $doc['doc_no'] : $cfg['new'],
            'cfg' => $cfg, 'slug' => $slug, 'type' => $type, 'doc' => $doc, 'head' => $head, 'lines' => $lines, 'editable' => $editable,
            'itemOptions' => $itemOptions, 'itemData' => $itemData, 'bill' => $bill,
            'suppliers' => $type === 'RECEIVE' ? DB::all('SELECT id, name, terms_days FROM suppliers WHERE active = 1 OR id = ? ORDER BY name', [(int) ($head['supplier_id'] ?? 0)]) : [],
            'banks' => $type === 'RECEIVE' ? DB::all('SELECT id, bank_name, account_no FROM bank_accounts WHERE active = 1 ORDER BY bank_name') : [],
            'expenseAccounts' => array_column(array_map(fn ($a) => ['id' => $a['id'], 'label' => "{$a['code']} · {$a['name']}"], $expense), 'label', 'id'),
            'accountKeys' => array_column(array_filter($expense, fn ($a) => $a['system_key']), 'id', 'system_key'),
            'issueTo' => self::ISSUE_TO,
            'vatRate' => Settings::tax()['vatRate'],
            'inputVat' => $doc && $doc['journal_entry_id'] ? (float) DB::value('SELECT COALESCE(SUM(debit), 0) FROM journal_lines WHERE entry_id = ? AND account_id = ?',
                [$doc['journal_entry_id'], Ledger::account('input_vat')]) : 0.0,
            'canPost' => can('inventory.post'),
        ]);
    }
}
