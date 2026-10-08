<?php /** Suppliers or customers. Variables: $k, $kind, $rows, $columns, $showAll */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1><?= e($k['title']) ?></h1>
    <p class="muted"><?= e($k['subtitle']) ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-partner" data-reset data-title="New <?= e(strtolower($k['one'])) ?>"
            data-fill='<?= e(json_encode(['id' => '', 'terms_days' => $k['terms'], 'credit_limit' => 0])) ?>'>+ New <?= e(strtolower($k['one'])) ?></button>
  </div>
</div>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => "No $kind yet.",
      'toolbar' => '<a class="btn btn-sm' . ($showAll ? ' btn-dark' : '') . '" href="' . e(current_url(['all' => $showAll ? null : 1])) . '">' . ($showAll ? '✓ ' : '') . 'Show inactive</a>']) ?>
</div>

<dialog class="modal" id="dlg-partner">
  <form method="post" action="<?= url("/finance/$kind") ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3><?= e($k['one']) ?></h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Name *</span><input class="input" name="name" required></label>
      <label class="field"><span class="field-label">Contact person</span><input class="input" name="contact_person"></label>
      <label class="field"><span class="field-label">Phone</span><input class="input" name="phone" placeholder="09xx-xxx-xxxx"></label>
      <label class="field"><span class="field-label">Email</span><input class="input" type="email" name="email"></label>
      <label class="field"><span class="field-label">TIN</span><input class="input" name="tin" placeholder="000-000-000-000"></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Address</span><input class="input" name="address"></label>
      <label class="field"><span class="field-label">Payment terms (days)</span><input class="input" type="number" min="0" step="1" name="terms_days">
        <span class="field-hint"><?= e($k['termsHint']) ?></span></label>
      <?php if ($kind === 'customers'): ?>
        <label class="field"><span class="field-label">Credit limit (₱)</span><input class="input" type="number" min="0" step="0.01" name="credit_limit"><span class="field-hint">0 = no limit</span></label>
      <?php endif; ?>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Notes</span><textarea class="input" name="notes" rows="2"></textarea></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
