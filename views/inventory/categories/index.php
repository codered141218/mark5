<?php /** Categories list. Variables: $rows, $columns, $kinds, $stations, $accounts, $bulk */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Categories</h1>
    <p class="muted">Menu categories appear as tabs on the POS. Inventory categories group ingredients for counts and reports.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/arrange') ?>">⇅ Arrange menu</a>
    <button class="btn btn-primary" data-open="dlg-category" data-reset data-title="New category"
            data-fill='{"id":"","active":1,"color":"#c2410c","kind":"menu"}'>+ New category</button>
  </div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'bulk' => $bulk]) ?></div>
<p class="muted small mt">The order of categories and items on the POS is set under <a href="<?= url('/inventory/arrange') ?>">Arrange menu</a> — new ones are added at the end.</p>

<dialog class="modal" id="dlg-category">
  <form method="post" action="<?= url('/inventory/categories') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Category</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Name *</span><input class="input" name="name" required></label>
      <label class="field"><span class="field-label">Used for</span><select class="input" name="kind"><?= options($kinds) ?></select></label>
      <label class="field"><span class="field-label">Tile color</span><input class="input" type="color" name="color"></label>
      <label class="field"><span class="field-label">Prep station (order slip)</span><select class="input" name="station_id"><?= options($stations, null, '— None —') ?></select>
        <span class="field-hint">Items in this category print on this station's order slip (an item can override it).</span></label>
      <label class="checkbox"><input type="checkbox" name="active" value="1"> Active</label>
      <details class="field" style="grid-column:1/-1">
        <summary class="field-label" style="cursor:pointer">GL accounts for this category (optional)</summary>
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Sales account</span><select class="input" name="sales_account_id"><?= options($accounts['sales'], null, 'Default (GL Account Setup)') ?></select></label>
          <label class="field"><span class="field-label">Cost of goods sold account</span><select class="input" name="cogs_account_id"><?= options($accounts['cogs'], null, 'Default (GL Account Setup)') ?></select></label>
          <label class="field"><span class="field-label">Inventory account</span><select class="input" name="inventory_account_id"><?= options($accounts['inventory'], null, 'Default (GL Account Setup)') ?></select></label>
        </div>
        <span class="field-hint">Sales and COGS follow the menu item sold; Inventory follows the category of each ingredient.</span>
      </details>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
