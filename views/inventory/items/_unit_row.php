<?php /** One purchase / alternate unit row ("1 sack = 50 kg"). Variables: $i, $r, $uomOptions, $baseAbbr, $readonly */ ?>
<div class="row gap-sm mb" data-row>
  <span class="muted">1</span>
  <select class="input" style="width:180px" name="uoms[<?= e($i) ?>][uom_id]"><?= options($uomOptions, $r['uom_id'] ?? '', '— unit —') ?></select>
  <span class="muted">=</span>
  <input class="input num" style="width:110px" type="number" step="any" min="0" name="uoms[<?= e($i) ?>][factor]"
         value="<?= isset($r['factor']) && $r['factor'] !== '' ? e((float) $r['factor']) : '' ?>" placeholder="factor">
  <span class="bold" data-base-abbr><?= e($baseAbbr) ?></span>
  <?php if (!$readonly): ?><button type="button" class="icon-btn" data-remove-row title="Remove">✕</button><?php endif; ?>
</div>
