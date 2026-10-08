<?php /** Journal entries list. Variables: $rows, $columns, $from, $to, $source, $q */ use App\Core\Table; use App\Services\Finance\Journals; ?>
<div class="page-header">
  <div>
    <h1>Journal Entries</h1>
    <p class="muted">Every posting to the general ledger. Most entries are created automatically by POS, inventory, petty cash, banks, payables and receivables.
      Use a manual entry for opening balances, adjustments, depreciation, payroll accruals, owner's contributions and drawings, and corrections.</p>
  </div>
  <?php if (can('finance.journal')): ?>
    <div class="page-actions"><a class="btn btn-primary" href="<?= url('/finance/journals/new') ?>">+ New journal entry</a></div>
  <?php endif; ?>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['source', 'q'], 'extra' =>
    '<label class="field"><span class="field-label">Source</span><select class="input" name="source">' . options(Journals::SOURCES, $source, 'All sources') . '</select></label>'
    . '<label class="field"><span class="field-label">Search</span><input class="input" name="q" value="' . e($q) . '" placeholder="Entry no, memo, ref no"></label>'], null) ?>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'link' => fn ($r) => url('/finance/journals/' . $r['id']), 'empty' => 'No journal entries in this period.']) ?></div>
