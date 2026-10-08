<?php /** Journal entry detail. Variables: $je, $columns */ use App\Core\Table; use App\Services\Finance\Journals; ?>
<?php $debit = array_sum(array_column($je['lines'], 'debit')); $credit = array_sum(array_column($je['lines'], 'credit')); ?>
<div class="page-header">
  <div>
    <h1>Journal entry <?= e($je['entry_no']) ?></h1>
    <p class="muted"><a href="<?= url('/finance/journals') ?>">← Journal entries</a></p>
  </div>
  <div class="page-actions">
    <?php if ($je['source_type'] === 'manual' && $je['status'] === 'posted' && !$je['reversal_of'] && can('finance.journal')): ?>
      <?= view('finance/partials/void', ['action' => "/finance/journals/{$je['id']}/void", 'label' => 'Void entry', 'class' => 'btn-danger',
          'title' => "Void {$je['entry_no']}? A reversing entry dated today will be posted and this entry will be marked void."], null) ?>
    <?php endif; ?>
  </div>
</div>

<div class="card mb"><div class="card-body">
  <dl class="kv">
    <dt>Date</dt><dd><?= e(fmt_date($je['entry_date'])) ?></dd>
    <dt>Source</dt><dd><?= e(Journals::SOURCES[$je['source_type']] ?? $je['source_type']) ?></dd>
    <dt>Reference no</dt><dd><?= e($je['ref_no'] ?: '—') ?></dd>
    <dt>Status</dt><dd><?= badge($je['status']) ?> <?= $je['reversal_of'] ? badge('reversal', 'blue') : '' ?></dd>
    <dt>Memo</dt><dd><?= e($je['memo'] ?: '—') ?></dd>
    <dt>Recorded by</dt><dd><?= e(trim(($je['created_by_name'] ?? '') . ' · ' . fmt_datetime($je['created_at']), ' ·')) ?></dd>
    <?php if ($je['reversal_of']): ?><dt>Reverses</dt><dd><a href="<?= url('/finance/journals/' . $je['reversal_of']) ?>">Open original entry</a></dd><?php endif; ?>
    <?php if ($je['reversed_by']): ?><dt>Reversed by</dt><dd><a href="<?= url('/finance/journals/' . $je['reversed_by']) ?>">Open reversing entry</a></dd><?php endif; ?>
  </dl>
</div></div>

<?php if ($je['source_type'] !== 'manual' && $je['status'] === 'posted' && !$je['reversal_of']): ?>
  <div class="alert alert-info">System-generated entry. To undo it, void the source document (receipt, delivery, payment…).</div>
<?php endif; ?>
<?php if (abs($debit - $credit) > 0.009): ?><div class="alert alert-error">Entry is out of balance.</div><?php endif; ?>

<div class="card"><?= Table::html($columns, $je['lines'], ['export' => true, 'search' => false]) ?></div>
