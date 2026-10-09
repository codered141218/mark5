<?php
/** Item master list. Variables: $rows, $columns, $stats, $f (filters), $categories */
use App\Core\Table;
use App\Services\Items;
?>
<div class="page-header">
  <div>
    <h1>Items &amp; Recipes</h1>
    <p class="muted">Ingredients, menu items with recipes, retail goods and services.</p>
  </div>
  <div class="page-actions">
    <?php if (can('inventory.manage')): ?><a class="btn btn-primary" href="<?= url('/inventory/items/new') ?>">+ New item</a><?php endif; ?>
  </div>
</div>

<div class="stats mb">
  <div class="stat"><div class="stat-label">Items</div><div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-sub"><?= $f['inactive'] ? 'including inactive' : 'active' ?></div></div>
  <div class="stat"><div class="stat-label">Stocked items</div><div class="stat-value"><?= number_format($stats['stocked']) ?></div><div class="stat-sub">raw materials &amp; retail</div></div>
  <div class="stat<?= $stats['low'] ? ' stat-amber' : '' ?>"><div class="stat-label">Low / negative stock</div><div class="stat-value"><?= number_format($stats['low']) ?></div><div class="stat-sub">at or below reorder point</div></div>
  <div class="stat stat-green"><div class="stat-label">Total stock value</div><div class="stat-value"><?= peso($stats['value']) ?></div><div class="stat-sub">at moving average cost</div></div>
</div>

<form class="filters" method="get">
  <label class="field"><span class="field-label">Type</span><select class="input" name="type"><?= options(Items::TYPES, $f['type'], 'All types') ?></select></label>
  <label class="field"><span class="field-label">Category</span><select class="input" name="category_id"><?= options(array_column($categories, 'name', 'id'), $f['category_id'], 'All categories') ?></select></label>
  <label class="field"><span class="field-label">Search</span><input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, SKU or barcode"></label>
  <label class="checkbox" style="height:36px"><input type="checkbox" name="low" value="1" <?= $f['low'] ? 'checked' : '' ?>> Low stock only</label>
  <label class="checkbox" style="height:36px"><input type="checkbox" name="inactive" value="1" <?= $f['inactive'] ? 'checked' : '' ?>> Show inactive</label>
  <button class="btn" type="submit">Apply</button>
</form>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No items match the filters.', 'link' => fn ($r) => url('/inventory/items/' . $r['id']), 'bulk' => $bulk]) ?>
</div>
