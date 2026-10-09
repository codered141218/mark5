<?php /** Discount presets. Variables: $rows, $columns, $bulk */ use App\Core\Table; use App\Services\Discounts; ?>
<div class="page-header">
  <div>
    <h1>Discounts</h1>
    <p class="muted">The discounts cashiers can pick on the POS — for a single item or the whole receipt.
      Leave the value blank for an “open” discount where the cashier types the amount.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-discount" data-reset data-title="New discount"
            data-fill='{"id":"","kind":"percent","scope":"both","requires_approval":1,"active":1}'>+ New discount</button>
  </div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'bulk' => $bulk]) ?></div>

<div class="alert alert-info mt">
  <b>Senior Citizen / PWD</b> discounts are VAT-exempt and use the rate in Settings → Tax &amp; charges.
  On the POS they can be tagged on the senior's own items, or applied to the whole receipt (share = seniors ÷ guests).
  They cannot be combined with other discounts on the same receipt.
</div>

<dialog class="modal" id="dlg-discount">
  <form method="post" action="<?= url('/admin/discounts') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Discount</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Name *</span><input class="input" name="name" required maxlength="60" placeholder="e.g. Employee 10%"></label>
      <label class="field"><span class="field-label">Type</span><select class="input" name="kind"><?= options(Discounts::KINDS) ?></select></label>
      <label class="field"><span class="field-label">Value (% or ₱)</span><input class="input" type="number" step="any" min="0" name="value" placeholder="blank = cashier enters">
        <span class="field-hint">Ignored for Senior Citizen / PWD.</span></label>
      <label class="field"><span class="field-label">Can be applied to</span><select class="input" name="scope"><?= options(Discounts::SCOPES) ?></select></label>
      <label class="checkbox"><input type="checkbox" name="requires_approval" value="1"> Needs manager approval (PIN) for cashiers</label>
      <label class="checkbox"><input type="checkbox" name="active" value="1"> Active</label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
