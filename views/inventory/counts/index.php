<?php /** Count sessions. Variables: $rows, $columns, $from, $to, $categories */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Inventory Count</h1>
    <p class="muted">Physical stock counts. Posting a count adjusts system stock to what was counted and books the over/short to the books.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-count" data-reset>+ Start new count</button>
  </div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to], null) ?>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No counts in this period.', 'link' => fn ($r) => url('/inventory/counts/' . $r['id'])]) ?>
</div>

<dialog class="modal" id="dlg-count">
  <form method="post" action="<?= url('/inventory/counts') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Start new count</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field"><span class="field-label">Count date</span><input class="input" type="date" name="count_date" value="<?= e(today()) ?>" required></label>
        <label class="field"><span class="field-label">Category</span>
          <select class="input" name="category_id"><?= options($categories, null, 'All stocked items') ?></select>
          <span class="field-hint">Blank = all stocked items</span></label>
        <label class="field" style="grid-column:1 / -1"><span class="field-label">Notes</span>
          <textarea class="input" style="height:auto;padding:8px 10px" rows="2" name="notes" placeholder="e.g. Month-end count, storeroom"></textarea></label>
      </div>
      <p class="muted small">A count sheet is created with the current system quantity of every active raw material and retail item in scope.</p>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Start count</button></div>
  </form>
</dialog>
