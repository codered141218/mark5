<?php
/** Editable material lines (reuses the stock document line editor, inventory.js data-doc-form). Variables: $lines, $itemOptions, $note */
?>
<div class="table-wrap">
  <table class="table dense">
    <thead><tr><th>Ingredient / material</th><th class="right">Qty</th><th>Unit</th><th class="right">Est. cost</th><th>Notes</th><th></th></tr></thead>
    <tbody data-rows="lines">
      <?php foreach ($lines as $i => $l): ?>
        <?= view('inventory/docs/_line', ['i' => $i, 'l' => $l, 'type' => 'ISSUE', 'itemOptions' => $itemOptions], null) ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="3"><span data-line-count>0 items</span> <span class="muted small" style="font-weight:400;margin-left:12px"><?= e($note) ?></span><span data-vat-note hidden></span></td>
      <td class="right" data-doc-total>0.00</td><td colspan="2"></td>
    </tr></tfoot>
  </table>
</div>
<template id="lines-template"><?= view('inventory/docs/_line', ['i' => '__i__', 'l' => [], 'type' => 'ISSUE', 'itemOptions' => $itemOptions], null) ?></template>
