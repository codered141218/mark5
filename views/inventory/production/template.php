<?php
/**
 * BOM template: editable for users who may see BOM ingredients, a summary without ingredients for everyone else.
 * Variables: $t (null for new), $lines, $bom, $usage, $outputs, $outputUnits, $itemOptions, $itemData
 */
$isNew = !$t;
$v = fn ($k, $d = '') => $t[$k] ?? $d;
?>
<div class="page-header">
  <div>
    <h1><?= $isNew ? 'New BOM template' : e($t['name']) ?> <?= !$isNew && !$t['active'] ? badge('inactive') : '' ?></h1>
    <p class="muted"><?= $isNew ? 'The finished product, how much one batch makes, and the ingredients per batch.'
        : e($t['code'] . ' · makes ' . qty($t['yield_qty']) . ' ' . $t['yield_uom'] . ' of ' . $t['output_name'] . ' per batch') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/bom') ?>">← BOM templates</a>
    <?php if (!$isNew && can('production.manage') && $t['active']): ?><a class="btn btn-primary" href="<?= url('/inventory/production/new', ['template' => $t['id']]) ?>">Plan production</a><?php endif; ?>
    <?php if (!$isNew && $bom): ?>
      <form method="post" action="<?= url('/inventory/bom/' . $t['id'] . '/delete') ?>" class="inline" data-danger data-ok="Remove"
            data-confirm="Remove template <?= e($t['name']) ?>? A template already used by production is only deactivated.">
        <?= csrf_field() ?><button class="btn btn-ghost text-red" type="submit">Remove</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($bom): ?>
<form method="post" action="<?= url($isNew ? '/inventory/bom' : '/inventory/bom/' . $t['id']) ?>" class="stack" data-doc-form data-doc-type="ISSUE">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-head"><h3>Template</h3></div>
    <div class="card-body form-grid">
      <label class="field" style="grid-column:span 2"><span class="field-label">Template name *</span><input class="input" name="name" value="<?= e($v('name')) ?>" maxlength="120" required placeholder="e.g. Pork BBQ marinade (batch)"></label>
      <label class="field" style="grid-column:span 2"><span class="field-label">Finished product *</span>
        <select class="input" name="output_item_id" data-combo data-placeholder="Search item…" data-output required><?= options($outputs, $v('output_item_id'), '') ?></select>
        <span class="field-hint">A stocked item (raw material or retail). Add it under Items &amp; Recipes first if needed.</span></label>
      <label class="field"><span class="field-label">One batch makes *</span><input class="input num" type="number" step="any" min="0" name="yield_qty" value="<?= e($t ? (float) $t['yield_qty'] : '') ?>" required></label>
      <label class="field"><span class="field-label">Unit</span><select class="input" name="yield_uom_id" data-output-unit>
        <?php foreach ($outputUnits as $u): ?><option value="<?= $u['uom_id'] ?>" <?= (int) $u['uom_id'] === (int) $v('yield_uom_id') ? 'selected' : '' ?>><?= e($u['abbr']) ?></option><?php endforeach; ?></select></label>
      <label class="field" style="grid-column:span 2"><span class="field-label">Notes / procedure</span><textarea class="input" style="height:auto;padding:8px 10px" rows="2" name="notes"><?= e($v('notes')) ?></textarea></label>
      <?php if (!$isNew): ?><label class="checkbox"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>> Active</label><?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Ingredients per batch</h3><span class="muted small">🔒 Hidden from users without the BOM permission</span></div>
    <?= view('inventory/production/_lines', ['lines' => $lines, 'itemOptions' => $itemOptions, 'note' => 'Cost per batch at current average cost'], null) ?>
  </div>
  <div class="row gap-sm"><button class="btn btn-primary" type="submit">Save template</button></div>
</form>
<script type="application/json" id="inv-items"><?= json_encode($itemData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/inventory.js') ?>"></script>
<script src="<?= asset('js/production.js') ?>"></script>
<?php else: ?>
<div class="card">
  <div class="card-body">
    <dl class="kv" style="margin:0">
      <dt>Finished product</dt><dd><?= e($t['output_name']) ?></dd>
      <dt>One batch makes</dt><dd><?= qty($t['yield_qty']) ?> <?= e($t['yield_uom']) ?></dd>
      <dt>Ingredients</dt><dd class="muted">🔒 Hidden — your role cannot see BOM ingredients.</dd>
      <?php if ($t['notes']): ?><dt>Notes</dt><dd><?= nl2br(e($t['notes'])) ?></dd><?php endif; ?>
    </dl>
  </div>
</div>
<?php endif; ?>

<?php if ($usage): ?>
<div class="card mt">
  <div class="card-head"><h3>Recent production</h3></div>
  <table class="table dense clickable"><tbody>
    <?php foreach ($usage as $u): ?>
      <tr data-href="<?= url('/inventory/production/' . $u['id']) ?>" onclick="location.href=this.dataset.href"><td><b><?= e($u['doc_no']) ?></b></td><td><?= e(fmt_date($u['prod_date'])) ?></td>
        <td class="right"><?= qty($u['actual_qty'] ?? $u['planned_qty']) ?></td><td><?= badge(App\Services\Production::STATUSES[$u['status']] ?? $u['status'], ['planned' => 'amber', 'released' => 'green', 'cancelled' => 'gray'][$u['status']] ?? null) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
