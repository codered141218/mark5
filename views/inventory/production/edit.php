<?php
/**
 * Production order: plan (new / planned, editable), release to inventory, or the released / cancelled record.
 * Materials are shown only to users who may see BOM ingredients ($bom).
 * Variables: $o (null for new; lines removed for non-BOM users), $editable, $bom, $lines, $templates, $tplId, $outputs, $outputUnits,
 *            $templateData, $itemOptions, $itemData
 */
use App\Core\Table;
use App\Services\Production;

$isNew = !$o;
$status = $o['status'] ?? 'planned';
$manage = can('production.manage');
$v = fn ($k, $d = '') => $o[$k] ?? $d;
$tplOptions = array_column(array_map(fn ($t) => ['id' => $t['id'], 'l' => $t['name'] . ' → ' . $t['output_name'] . ($t['yield_uom'] ?? '' ? ' (' . qty($t['yield_qty']) . ' ' . $t['yield_uom'] . ' a batch)' : '')], $templates), 'l', 'id');
$tone = ['planned' => 'amber', 'released' => 'green', 'cancelled' => 'gray'][$status] ?? null;
?>
<div class="page-header">
  <div>
    <h1><?= $isNew ? 'New production' : e($o['doc_no']) . ' ' . badge(Production::STATUSES[$status], $tone) ?></h1>
    <p class="muted"><?= $isNew ? 'Choose a BOM template and how much to produce. The materials are scaled to the planned quantity.'
        : e(fmt_date($o['prod_date']) . ' · ' . $o['output_name'] . ($o['template_name'] ? ' · ' . $o['template_name'] : '') . ($o['created_by_name'] ? ' · prepared by ' . $o['created_by_name'] : '')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/production') ?>">← Production</a>
    <?php if (!$isNew && $manage && $status === 'planned'): ?>
      <form method="post" action="<?= url('/inventory/production/' . $o['id'] . '/delete') ?>" class="inline" data-danger data-ok="Delete" data-confirm="Delete plan <?= e($o['doc_no']) ?>?">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit">Delete plan</button></form>
    <?php endif; ?>
    <?php if (!$isNew && $manage && $status === 'released'): ?>
      <form method="post" action="<?= url('/inventory/production/' . $o['id'] . '/cancel') ?>" class="inline" data-danger data-ok="Void" data-prompt="Reason"
            data-confirm="Void <?= e($o['doc_no']) ?>? The finished product is taken back out of stock, the materials are put back and the journal entry is reversed.">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Void</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($status === 'released'): ?>
  <div class="alert alert-success row wrap gap">
    <span><b>Released to inventory</b><?= $o['released_by_name'] ? ' by ' . e($o['released_by_name']) : '' ?> on <?= e(fmt_datetime($o['released_at'])) ?></span>
    <span><b><?= qty($o['actual_qty']) ?> <?= e($o['output_uom']) ?></b> <?= e($o['output_name']) ?> added to stock</span>
    <span>Materials cost <?= peso($o['material_cost']) ?> · <?= peso($o['unit_cost']) ?> per <?= e($o['output_base_abbr']) ?></span>
    <?php if ($o['entry_no'] && can('finance.view')): ?><a href="<?= url('/finance/journals/' . $o['journal_entry_id']) ?>">Journal entry <?= e($o['entry_no']) ?> →</a><?php endif; ?>
  </div>
<?php elseif ($status === 'cancelled'): ?>
  <div class="alert alert-warn"><b>Cancelled.</b> <?= e($o['notes'] ?? '') ?></div>
<?php endif; ?>

<?php if ($editable && $manage): ?>
<form method="post" action="<?= url($isNew ? '/inventory/production' : '/inventory/production/' . $o['id']) ?>" class="stack" data-prod-form
      <?= $bom ? 'data-doc-form data-doc-type="ISSUE"' : '' ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="lines_edited" value="0">
  <script type="application/json" id="prod-templates"><?= json_encode((object) $templateData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <div class="card">
    <div class="card-head"><h3>Plan</h3></div>
    <div class="card-body form-grid">
      <label class="field" style="grid-column:span 2"><span class="field-label">BOM template<?= $bom ? '' : ' *' ?></span>
        <select class="input" name="template_id" data-template <?= $bom ? '' : 'required' ?>><?= options($tplOptions, $tplId, $bom ? '— None: enter the materials —' : 'Select…') ?></select>
        <?php if (!$templates): ?><span class="field-hint text-amber">No BOM templates yet<?= $bom ? ' — <a href="' . url('/inventory/bom/new') . '">create one</a>' : ' — ask someone with the BOM permission to create one' ?>.</span><?php endif; ?></label>
      <label class="field" style="grid-column:span 2" data-output-field><span class="field-label">Finished product *</span>
        <select class="input" name="output_item_id" data-output><?= options($outputs, $v('output_item_id'), 'Select…') ?></select></label>
      <label class="field"><span class="field-label">Quantity to produce *</span><input class="input num" type="number" step="any" min="0" name="planned_qty" value="<?= e($o ? (float) $o['planned_qty'] : '') ?>" required data-planned></label>
      <label class="field"><span class="field-label">Unit</span><select class="input" name="output_uom_id" data-output-unit>
        <?php foreach ($outputUnits as $u): ?><option value="<?= $u['uom_id'] ?>" data-factor="<?= e($u['factor']) ?>" <?= (int) $u['uom_id'] === (int) ($v('output_uom_id') ?: ($templateData[$tplId]['uom'] ?? 0)) ? 'selected' : '' ?>><?= e($u['abbr']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="field-label">Production date *</span><input class="input" type="date" name="prod_date" value="<?= e($v('prod_date', today())) ?>" required></label>
      <div class="field"><span class="field-label">Batches</span><div class="input-static" data-batches>—</div></div>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Notes</span><input class="input" name="notes" value="<?= e($status === 'planned' ? $v('notes') : '') ?>" maxlength="255"></label>
    </div>
  </div>

  <?php if ($bom): ?>
  <div class="card" data-lines-card>
    <div class="card-head"><h3>Materials</h3><span class="muted small" data-lines-hint>🔒 BOM — hidden from users without the BOM permission</span></div>
    <?php if ($isNew): ?><p class="card-body muted small" data-tpl-note style="margin:0">With a template, the materials are its ingredients scaled to the quantity — shown after you save the plan.</p><?php endif; ?>
    <div data-lines-box><?= view('inventory/production/_lines', ['lines' => $lines, 'itemOptions' => $itemOptions, 'note' => 'Estimated at current average cost'], null) ?></div>
    <?php if (!$isNew && $o['template_id']): ?><p class="card-body muted small" style="margin:0">Changing the quantity re-scales the template's ingredients, unless you change a material here.</p><?php endif; ?>
  </div>
  <?php elseif (!$isNew): ?>
    <div class="card"><div class="card-body muted">🔒 <?= (int) $o['line_count'] ?> material<?= $o['line_count'] === 1 ? '' : 's' ?> from the BOM template (hidden for your role) · estimated cost <?= peso($o['material_cost']) ?></div></div>
  <?php endif; ?>
  <div class="row gap-sm"><button class="btn btn-primary" type="submit"><?= $isNew ? 'Save plan' : 'Save changes' ?></button></div>
</form>
<?php if ($bom): ?>
<script type="application/json" id="inv-items"><?= json_encode($itemData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/inventory.js') ?>"></script>
<?php endif; ?>
<script src="<?= asset('js/production.js') ?>"></script>

<?php if (!$isNew): ?>
<div class="card mt release-card">
  <div class="card-head"><h3>Release to inventory</h3></div>
  <form method="post" action="<?= url('/inventory/production/' . $o['id'] . '/release') ?>" class="card-body row wrap gap" data-ok="Release"
        data-confirm="Release <?= e($o['doc_no']) ?>? The materials are taken out of stock and the quantity produced is added to <?= e($o['output_name']) ?>. Save any changes to the plan first.">
    <?= csrf_field() ?>
    <label class="field"><span class="field-label">Quantity actually produced (<?= e($o['output_uom']) ?>) *</span>
      <input class="input num" type="number" step="any" min="0" name="actual_qty" value="<?= e((float) $o['planned_qty']) ?>" required style="max-width:180px"></label>
    <div class="grow muted small">Planned <?= qty($o['planned_qty']) ?> <?= e($o['output_uom']) ?> · materials ≈ <?= peso($o['material_cost']) ?>.
      The finished product's cost = materials cost ÷ quantity produced.
      <?php if ($o['short']): ?><div class="text-amber mt-sm">⚠ <?= (int) $o['short'] ?> material<?= $o['short'] === 1 ? ' is' : 's are' ?> short of stock — stock will go below zero. Record the delivery first if it arrived.</div><?php endif; ?></div>
    <button class="btn btn-success btn-lg" type="submit">Release to inventory</button>
  </form>
  <form method="post" action="<?= url('/inventory/production/' . $o['id'] . '/cancel') ?>" class="card-body" style="padding-top:0" data-danger data-ok="Cancel plan" data-prompt="Reason" data-confirm="Cancel this plan? Nothing was taken from stock yet.">
    <?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Cancel plan</button></form>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <div class="card-body">
    <dl class="kv" style="margin:0">
      <dt>Finished product</dt><dd><?= e($o['output_name']) ?></dd>
      <dt>BOM template</dt><dd><?= e($o['template_name'] ?: '— (materials entered by hand)') ?></dd>
      <dt>Planned</dt><dd><?= qty($o['planned_qty']) ?> <?= e($o['output_uom']) ?></dd>
      <?php if ($o['actual_qty'] !== null): ?><dt>Produced</dt><dd><b><?= qty($o['actual_qty']) ?> <?= e($o['output_uom']) ?></b></dd><?php endif; ?>
      <dt>Materials cost</dt><dd><?= peso($o['material_cost']) ?><?= (float) $o['unit_cost'] ? ' · ' . peso($o['unit_cost']) . ' per ' . e($o['output_base_abbr']) : '' ?></dd>
      <?php if ($o['notes'] && $status !== 'cancelled'): ?><dt>Notes</dt><dd><?= e($o['notes']) ?></dd><?php endif; ?>
    </dl>
  </div>
</div>
<div class="card mt">
  <div class="card-head"><h3>Materials</h3></div>
  <?php if ($bom): ?>
    <?= Table::html([
        ['key' => 'item_name', 'label' => 'Material', 'html' => fn ($l) => '<b>' . e($l['item_name']) . '</b> <span class="muted small">' . e($l['sku']) . ($l['item_type'] === 'composite' ? ' · recipe' : '') . '</span>'],
        ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty'],
        ['key' => 'uom', 'label' => 'Unit'],
        ['key' => 'line_total', 'label' => $status === 'released' ? 'Cost' : 'Est. cost', 'type' => 'money', 'total' => true],
        ['key' => 'notes', 'label' => 'Notes'],
    ], $lines, ['search' => false]) ?>
  <?php else: ?>
    <div class="card-body muted">🔒 <?= (int) $o['line_count'] ?> material<?= $o['line_count'] === 1 ? '' : 's' ?> (hidden for your role) · <?= peso($o['material_cost']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>
