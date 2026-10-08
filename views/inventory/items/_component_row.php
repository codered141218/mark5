<?php
/**
 * One recipe line. Variables: $i, $c (component_id, qty, uom_id), $compOptions, $units (units of the component), $uomAbbr, $readonly
 * The unit options carry data-factor (base units per 1) so inventory.js can cost the line.
 */
$uomId = (string) ($c['uom_id'] ?? '');
$known = array_map('strval', array_column($units, 'uom_id'));
?>
<tr data-row>
  <td style="min-width:260px">
    <select class="input" data-combo data-field="component_id" data-placeholder="Search ingredient…" name="components[<?= e($i) ?>][component_id]">
      <?= options($compOptions, $c['component_id'] ?? '', '') ?>
    </select>
  </td>
  <td style="width:110px"><input class="input num" type="number" step="any" min="0" data-field="qty" name="components[<?= e($i) ?>][qty]"
      value="<?= isset($c['qty']) && $c['qty'] !== '' ? e((float) $c['qty']) : '1' ?>"></td>
  <td style="width:110px">
    <select class="input" data-field="uom_id" name="components[<?= e($i) ?>][uom_id]">
      <?php foreach ($units as $u): ?>
        <option value="<?= $u['uom_id'] ?>" data-factor="<?= e($u['factor']) ?>" <?= (string) $u['uom_id'] === $uomId ? 'selected' : '' ?>><?= e($u['abbr']) ?></option>
      <?php endforeach; ?>
      <?php if ($uomId !== '' && !in_array($uomId, $known, true)): ?>
        <option value="<?= e($uomId) ?>" data-factor="" selected><?= e($uomAbbr[$uomId] ?? '?') ?></option>
      <?php endif; ?>
    </select>
  </td>
  <td class="right muted nowrap" data-unit-cost></td>
  <td class="right nowrap" data-cost></td>
  <td class="right muted" data-share></td>
  <td style="width:36px"><?php if (!$readonly): ?><button type="button" class="icon-btn" data-remove-row title="Remove">✕</button><?php endif; ?></td>
</tr>
