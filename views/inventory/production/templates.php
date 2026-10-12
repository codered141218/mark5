<?php /** BOM templates. Variables: $rows, $columns, $bom */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>BOM templates</h1>
    <p class="muted">Bill of materials: the ingredients for one batch of a finished product and how much a batch makes.
      <?= $bom ? 'Only users with the <b>See &amp; edit BOM templates</b> permission see the ingredients.' : 'The ingredients are hidden for your role.' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/production') ?>">⚒ Production</a>
    <?php if ($bom): ?><a class="btn btn-primary" href="<?= url('/inventory/bom/new') ?>">+ New template</a><?php endif; ?>
  </div>
</div>
<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'page' => 25, 'empty' => $bom ? 'No templates yet. Click “New template”.' : 'No templates yet.',
      'link' => fn ($r) => url('/inventory/bom/' . $r['id'])]) ?>
</div>
