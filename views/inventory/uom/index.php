<?php /** Units & conversions. Variables: $units, $unitColumns, $conversions, $convColumns, $uomOptions */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Units &amp; Conversions</h1>
    <p class="muted">Units used to count, buy and use stock.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-uom" data-reset data-title="New unit" data-fill='{"id":""}'>+ New unit</button>
  </div>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h3>Units of measure</h3></div>
    <?= Table::html($unitColumns, $units, ['export' => true]) ?>
  </div>

  <div class="card">
    <div class="card-head"><h3>Global conversions</h3></div>
    <div class="card-body">
      <p class="muted small" style="margin-top:0">
        Standard conversions that apply to every item (e.g. <b>1 kg = 1000 g</b>, <b>1 L = 1000 ml</b>), so recipes and deliveries can use either unit.
        Item-specific purchase units such as <b>1 sack = 50 kg</b> or <b>1 case = 24 btl</b> are set on each item.
      </p>
      <form class="row gap-sm wrap" method="post" action="<?= url('/inventory/uom/conversions') ?>">
        <?= csrf_field() ?>
        <span class="muted">1</span>
        <select class="input" style="width:150px" name="from_uom_id" required><?= options($uomOptions, null, 'from unit') ?></select>
        <span class="muted">=</span>
        <input class="input num" style="width:110px" type="number" step="any" min="0" name="factor" placeholder="factor" required>
        <select class="input" style="width:150px" name="to_uom_id" required><?= options($uomOptions, null, 'to unit') ?></select>
        <button class="btn btn-primary" type="submit">Add</button>
      </form>
      <p class="muted small">Adding a conversion that already exists updates its factor.</p>
    </div>
    <?php
    $excel = $conversions ? '<a class="btn btn-sm" href="' . e(current_url(['export' => 'xlsx', 'list' => 'conversions'])) . '">⬇ Excel</a>' : '';
    echo Table::html($convColumns, $conversions, ['search' => false, 'toolbar' => $excel, 'empty' => 'No conversions yet.']);
    ?>
  </div>
</div>

<dialog class="modal" id="dlg-uom">
  <form method="post" action="<?= url('/inventory/uom') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Unit</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Name *</span><input class="input" name="name" required maxlength="40" placeholder="e.g. Sack"></label>
      <label class="field"><span class="field-label">Abbreviation *</span><input class="input" name="abbr" required maxlength="12" placeholder="e.g. sack"></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
