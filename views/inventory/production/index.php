<?php /** Production orders. Variables: $rows, $columns, $from, $to, $status */ use App\Core\Table; use App\Services\Production; ?>
<div class="page-header">
  <div>
    <h1>Production</h1>
    <p class="muted">Plan what to produce from raw materials, then release the finished product to inventory. The materials are taken out of stock and the
      finished product is added at their cost.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/bom') ?>">▤ BOM templates</a>
    <?php if (can('production.manage')): ?><a class="btn btn-primary" href="<?= url('/inventory/production/new') ?>">+ New production</a><?php endif; ?>
  </div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['status'],
    'extra' => '<label class="field"><span class="field-label">Status</span><select class="input" name="status">' . options(Production::STATUSES, $status, 'All statuses') . '</select></label>'], null) ?>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'page' => 25, 'empty' => 'No production in this period.', 'link' => fn ($r) => url('/inventory/production/' . $r['id'])]) ?>
</div>
