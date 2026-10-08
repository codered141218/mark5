<?php
namespace App\Controllers\Finance;

use App\Core\Request;
use App\Core\Response;
use App\Core\Table;
use App\Services\Finance\Accounts;
use App\Services\Finance\Journals;

/** Journal entries: list, manual entry form, detail with void. */
class JournalController
{
    public function index(Request $req)
    {
        [$from, $to] = date_range($req);
        $source = isset(Journals::SOURCES[$req->query('source')]) ? $req->query('source') : '';
        $q = trim((string) $req->query('q', ''));
        $rows = Journals::list($from, $to, $source ?: null, $q ?: null);
        foreach ($rows as &$r) if ($r['status'] === 'void') $r['_class'] = 'muted-row';
        unset($r);

        $sourceLabel = fn ($r) => Journals::SOURCES[$r['source_type']] ?? $r['source_type'];
        $columns = [
            ['key' => 'entry_no', 'label' => 'Entry no', 'html' => fn ($r) => '<span class="bold nowrap">' . e($r['entry_no']) . '</span>'],
            ['key' => 'entry_date', 'label' => 'Date', 'type' => 'date'],
            ['key' => 'memo', 'label' => 'Memo'],
            ['key' => 'source_type', 'label' => 'Source', 'value' => $sourceLabel, 'html' => fn ($r) => e($sourceLabel($r))],
            ['key' => 'ref_no', 'label' => 'Ref no'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'value' => fn ($r) => $r['status'] . ($r['reversal_of'] ? ' (reversal)' : ''),
                'html' => fn ($r) => badge($r['status']) . ($r['reversal_of'] ? ' ' . badge('reversal', 'blue') : '')],
            ['key' => 'created_by_name', 'label' => 'By'],
        ];
        $subtitle = range_label($from, $to) . ($source ? ' · ' . Journals::SOURCES[$source] : '');
        if ($x = Table::export($req, 'journal-entries', 'Journal Entries', $subtitle, $columns, $rows)) return $x;

        return view('finance/journals/index', ['title' => 'Journal Entries', 'rows' => $rows, 'columns' => $columns,
            'from' => $from, 'to' => $to, 'source' => $source, 'q' => $q]);
    }

    public function create(Request $req)
    {
        return view('finance/journals/new', ['title' => 'New Journal Entry', 'accounts' => Accounts::grouped()]);
    }

    public function store(Request $req): Response
    {
        $id = Journals::postManual($req->all());
        flash('success', 'Journal entry posted.');
        return redirect('/finance/journals/' . $id);
    }

    public function show(Request $req, string $id)
    {
        $je = Journals::find((int) $id);
        $columns = [
            ['key' => 'code', 'label' => 'Code'],
            ['key' => 'account_name', 'label' => 'Account'],
            ['key' => 'memo', 'label' => 'Line memo'],
            ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'total' => true, 'html' => fn ($l) => (float) $l['debit'] ? money($l['debit']) : ''],
            ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'total' => true, 'html' => fn ($l) => (float) $l['credit'] ? money($l['credit']) : ''],
        ];
        if ($x = Table::export($req, 'journal-' . $je['entry_no'], 'Journal Entry ' . $je['entry_no'], fmt_date($je['entry_date']) . ' · ' . $je['memo'], $columns, $je['lines'])) return $x;
        return view('finance/journals/show', ['title' => 'Journal ' . $je['entry_no'], 'je' => $je, 'columns' => $columns]);
    }

    public function void(Request $req, string $id): Response
    {
        Journals::void((int) $id, $req->input('reason'));
        flash('success', 'Journal entry voided; a reversing entry dated today was posted.');
        return redirect('/finance/journals/' . $id);
    }
}
