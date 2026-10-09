<?php /** Upload page for the item import. */ use App\Services\ItemImport; ?>
<div class="page-header">
  <div>
    <h1>Import items from Excel</h1>
    <p class="muted">Add or update many items at once from an Excel (.xlsx) or CSV file. You will see a preview before anything is saved.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/inventory/items') ?>">← Items &amp; Recipes</a></div>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card">
    <div class="card-head"><h3>1 · Get the template</h3></div>
    <div class="card-body stack">
      <p class="mt-0">Download the template, fill one item per row and save it. Only <b>Name</b> is required — leave out any column you don't need.</p>
      <a class="btn btn-primary" href="<?= url('/inventory/items/import', ['template' => 1]) ?>">⬇ Download Excel template</a>
      <table class="table">
        <thead><tr><th>Column</th><th>What to put</th></tr></thead>
        <tbody>
          <tr><td class="bold">Name *</td><td>Item name. An item with the same name (or SKU) is updated instead of added twice.</td></tr>
          <tr><td class="bold">Type</td><td><b>Ingredient</b> (raw, stocked), <b>Menu</b> (dish with a recipe), <b>Retail</b> (bought &amp; sold as-is, stocked) or <b>Service</b> (not stocked).
            If blank: Menu when it has a price, else Ingredient.</td></tr>
          <tr><td class="bold">Category</td><td>Created when it does not exist yet.</td></tr>
          <tr><td class="bold">Unit</td><td>kg, g, L, ml, pc, pack, can, btl, srv … (new units are created).</td></tr>
          <tr><td class="bold">Selling price</td><td>Menu price (as set under Settings → Tax: VAT-inclusive or not).</td></tr>
          <tr><td class="bold">Cost</td><td>Cost per unit of ingredients and retail items.</td></tr>
          <tr><td class="bold">Stock on hand</td><td>Beginning stock, posted as opening inventory (only for new items or items with no stock history).</td></tr>
          <tr><td class="bold">Reorder point / qty</td><td>Low-stock warning level and the usual order quantity.</td></tr>
          <tr><td class="bold">SKU, Barcode</td><td>Optional. A blank SKU is numbered automatically.</td></tr>
          <tr><td class="bold">Sellable</td><td>Yes / No — shown on the POS.</td></tr>
          <tr><td class="bold">Station</td><td>Prep station for order slips (Kitchen, Grill …).</td></tr>
        </tbody>
      </table>
      <p class="muted small" style="margin:0">Recipes (ingredients of a menu item) are added on each item's page after the import.</p>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>2 · Upload the file</h3></div>
    <form class="card-body stack" method="post" action="<?= url('/inventory/items/import') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input class="input" type="file" name="file" accept=".xlsx,.csv" required style="height:auto;padding:10px">
      <label class="checkbox"><input type="checkbox" name="update_existing" value="1" checked> Update items that already exist (same SKU or name)</label>
      <div><button class="btn btn-primary btn-lg" type="submit">Check the file →</button></div>
      <p class="muted small" style="margin:0">Accepted: Excel workbook (.xlsx) — first sheet — or CSV. Nothing is saved until you confirm on the next page.</p>
    </form>
  </div>
</div>
