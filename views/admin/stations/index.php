<?php /** Prep stations. Variables: $rows, $columns, $untagged, $bulk */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Prep Stations</h1>
    <p class="muted">Where food is prepared — Kitchen, Grill, Bar … When the cashier presses <b>Done</b>, the order slip is split per station
      so each station gets only its own items. Tag a whole category (Inventory → Categories) or single items (on the item page).</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-station" data-reset data-title="New station" data-fill='{"id":"","active":1}'>+ New station</button>
  </div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'bulk' => $bulk]) ?></div>

<div class="alert alert-info mt">
  <b>Which printer prints which station</b> is chosen on each tablet under <a href="<?= url('/printer') ?>">Printer Setup</a>:
  e.g. the cashier's printer prints receipts and the Grill slips, the kitchen printer prints the Kitchen slips.
  With a single printer, every station's slip comes out of that printer, one slip per station, ready to hand over.
</div>

<?php if ($untagged): ?>
  <div class="card mt">
    <div class="card-head"><h3>Menu items without a station (<?= count($untagged) ?>)</h3></div>
    <div class="card-body">
      <p class="muted small" style="margin-top:0">These print on the printers set to take “items without a station” (by default every printer that prints order slips).</p>
      <p class="small"><?= implode(', ', array_map(fn ($i) => e($i['name']) . ($i['category'] ? ' <span class="muted">(' . e($i['category']) . ')</span>' : ''), $untagged)) ?></p>
    </div>
  </div>
<?php endif; ?>

<dialog class="modal" id="dlg-station">
  <form method="post" action="<?= url('/admin/stations') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Station</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Name *</span><input class="input" name="name" required maxlength="40" placeholder="e.g. Grill"></label>
      <label class="checkbox"><input type="checkbox" name="active" value="1"> Active</label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
