<?php
/**
 * One editable document line. Variables: $i, $l (item_id, qty, uom_id, unit_cost, line_total, notes, units), $type, $itemOptions
 * Unit options carry data-factor (base units per 1) for the live cost estimate in inventory.js.
 */
$uomId = (string) ($l['uom_id'] ?? '');
$val = fn ($k) => isset($l[$k]) && $l[$k] !== '' && $l[$k] !== null ? e((float) $l[$k]) : '';
$name = fn ($k) => 'lines[' . e($i) . '][' . $k . ']';
?>
<tr data-row>
  <td style="min-width:260px">
    <select class="input" data-combo data-field="item_id" data-placeholder="+ Add item…" name="<?= $name('item_id') ?>"><?= options($itemOptions, $l['item_id'] ?? '', '') ?></select>
  </td>
  <td style="width:110px"><input class="input num" type="number" step="any" min="0" data-field="qty" name="<?= $name('qty') ?>" value="<?= $val('qty') ?>"></td>
  <td style="width:100px">
    <select class="input" data-field="uom_id" name="<?= $name('uom_id') ?>">
      <?php foreach ($l['units'] ?? [] as $u): ?>
        <option value="<?= $u['uom_id'] ?>" data-factor="<?= e($u['factor']) ?>" <?= (string) $u['uom_id'] === $uomId ? 'selected' : '' ?>><?= e($u['abbr']) ?></option>
      <?php endforeach; ?>
    </select>
  </td>
  <?php if ($type === 'RECEIVE'): ?>
    <td style="width:130px"><input class="input num" type="number" step="any" min="0" data-field="unit_cost" name="<?= $name('unit_cost') ?>" value="<?= $val('unit_cost') ?>"></td>
    <td style="width:130px"><input class="input num" type="number" step="0.01" min="0" data-field="line_total" name="<?= $name('line_total') ?>" value="<?= $val('line_total') ?>"></td>
  <?php else: ?>
    <td class="right nowrap" style="width:130px" data-est></td>
  <?php endif; ?>
  <td style="min-width:140px"><input class="input" data-field="notes" name="<?= $name('notes') ?>" value="<?= e($l['notes'] ?? '') ?>" maxlength="255"></td>
  <td style="width:36px"><button type="button" class="icon-btn" data-remove-row title="Remove line">✕</button></td>
</tr>
