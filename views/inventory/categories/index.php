<?php /** Categories list. Variables: $rows, $columns, $kinds */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Categories</h1>
    <p class="muted">Menu categories appear as tabs on the POS. Inventory categories group ingredients for counts and reports.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-category" data-reset data-title="New category"
            data-fill='{"id":"","active":1,"color":"#c2410c","kind":"menu","sort_order":0}'>+ New category</button>
  </div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true]) ?></div>

<dialog class="modal" id="dlg-category">
  <form method="post" action="<?= url('/inventory/categories') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Category</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Name *</span><input class="input" name="name" required></label>
      <label class="field"><span class="field-label">Used for</span><select class="input" name="kind"><?= options($kinds) ?></select></label>
      <label class="field"><span class="field-label">Tile color</span><input class="input" type="color" name="color"></label>
      <label class="field"><span class="field-label">Sort order</span><input class="input" type="number" name="sort_order"></label>
      <label class="checkbox"><input type="checkbox" name="active" value="1"> Active</label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
