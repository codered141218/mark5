<?php
/**
 * Item form (new / edit).
 * Variables: $item (row or null), $v (form values), $components, $altUnits, $compUnits, $compOptions, $itemData,
 *            $uomOptions, $uomAbbr, $categories, $usedIn, $hasMovements, $unitCost, $readonly, $vatRate
 * inventory.js shows the sections that fit the item type and computes recipe cost / food cost % live.
 */
use App\Services\Inventory;
use App\Services\Items;

$isNew = !$item;
$stockedItem = $item && Inventory::isStocked($item);
$baseAbbr = $uomAbbr[$v['base_uom_id'] ?? ''] ?? 'base unit';
$num = fn ($x) => $x === null || $x === '' ? '' : (float) $x;
$action = $isNew ? url('/inventory/items') : url('/inventory/items/' . $item['id']);
$rowData = ['uomOptions' => $uomOptions, 'baseAbbr' => $baseAbbr, 'readonly' => $readonly];
$compData = ['compOptions' => $compOptions, 'uomAbbr' => $uomAbbr, 'readonly' => $readonly];
?>
<div class="page-header">
  <div>
    <h1><?= e($isNew ? 'New item' : $item['name']) ?></h1>
    <p class="muted">
      <?php if ($isNew): ?>Add an ingredient, menu item, retail product or service.
      <?php else: ?><?= e($item['sku']) ?> · <?= e(Items::TYPES[$item['item_type']]) ?> <?= $item['active'] ? '' : badge('inactive') ?><?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/items') ?>">← Back</a>
    <?php if ($stockedItem && can('reports.inventory')): ?>
      <a class="btn" href="<?= url('/reports/inventory', ['report' => 'stockcard', 'item_id' => $item['id']]) ?>">Stock card</a>
    <?php endif; ?>
    <?php if (!$readonly && !$isNew): ?>
      <form method="post" action="<?= url('/inventory/items/' . $item['id'] . '/delete') ?>" class="inline" data-danger data-ok="Delete"
            data-confirm="Delete “<?= e($item['name']) ?>”? Items with history are deactivated instead.">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Delete</button>
      </form>
    <?php endif; ?>
    <?php if (!$readonly): ?><button class="btn btn-primary" type="submit" form="item-form"><?= $isNew ? 'Create item' : 'Save' ?></button><?php endif; ?>
  </div>
</div>

<?php if ($readonly): ?><div class="alert alert-info">You can view this item but not change it.</div><?php endif; ?>

<form id="item-form" method="post" action="<?= $action ?>" data-item-form data-item-id="<?= $item['id'] ?? '' ?>"
      data-vat-rate="<?= e($vatRate) ?>" data-new="<?= $isNew ? '1' : '0' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="_form" value="item">
  <fieldset class="stack" style="border:0;padding:0;margin:0;min-width:0" <?= $readonly ? 'disabled' : '' ?>>

    <div class="card">
      <div class="card-head"><h3>General</h3></div>
      <div class="card-body form-grid">
        <label class="field" style="grid-column:span 2"><span class="field-label">Item name *</span>
          <input class="input" name="name" value="<?= e($v['name'] ?? '') ?>" required maxlength="120" placeholder="e.g. Pork Liempo" <?= $isNew ? 'autofocus' : '' ?>></label>
        <label class="field"><span class="field-label">SKU</span>
          <input class="input" name="sku" value="<?= e($v['sku'] ?? '') ?>" maxlength="40"><span class="field-hint">Leave blank to auto-number</span></label>
        <label class="field"><span class="field-label">Barcode</span><input class="input" name="barcode" value="<?= e($v['barcode'] ?? '') ?>" maxlength="60"></label>
        <label class="field" style="grid-column:span 2"><span class="field-label">Item type *</span>
          <select class="input" name="item_type" data-item-type><?= options(Items::TYPES, $v['item_type']) ?></select>
          <span class="field-hint" data-type-hint><?= e(Items::TYPE_HINTS[$v['item_type']] ?? '') ?></span></label>
        <label class="field"><span class="field-label">Category</span>
          <select class="input" name="category_id"><?= options($categories, $v['category_id'] ?? '', '— None —') ?></select></label>
        <label class="field"><span class="field-label">Base unit *</span>
          <select class="input" name="base_uom_id" data-base-unit required <?= $hasMovements ? 'disabled' : '' ?>>
            <?= options($uomOptions, $v['base_uom_id'] ?? '', '— Choose —') ?></select>
          <?php if ($hasMovements): ?><input type="hidden" name="base_uom_id" value="<?= e($item['base_uom_id']) ?>"><?php endif; ?>
          <span class="field-hint"><?= $hasMovements ? 'Cannot change: the item has stock movements' : 'Unit stock is counted in (menu items: usually Serving)' ?></span></label>
        <label class="field"><span class="field-label">Selling price (<?= App\Services\Settings::pricesIncludeVat() ? 'VAT-inclusive' : 'VAT-exclusive' ?>)</span>
          <input class="input num" type="number" step="0.01" min="0" name="price" value="<?= e($num($v['price'] ?? '')) ?>" placeholder="0.00" data-price>
          <?php if (App\Services\Settings::tax()['vatRegistered']): ?><span class="field-hint"><?= App\Services\Settings::pricesIncludeVat()
            ? 'Price the customer pays, VAT included' : 'VAT is added on top at the POS' ?> · <a href="<?= url('/admin/settings?tab=tax') ?>">change</a></span><?php endif; ?></label>
        <label class="field"><span class="field-label">Prep station (order slip)</span>
          <select class="input" name="station_id"><?= options(App\Services\Stations::options(), $v['station_id'] ?? '', 'Same as its category') ?></select>
          <span class="field-hint">Where the order slip for this item prints: Kitchen, Grill …</span></label>
        <div class="field"><span class="field-label">POS tile color</span>
          <div class="row gap-sm" style="height:36px">
            <input type="color" name="color" value="<?= e($v['color'] ?? '' ?: '#868e96') ?>">
            <label class="checkbox"><input type="checkbox" name="use_color" value="1" <?= !empty($v['color']) ? 'checked' : '' ?>> Custom color</label>
          </div></div>
        <div class="field"><span class="field-label">Options</span>
          <label class="checkbox"><input type="checkbox" name="sellable" value="1" data-sellable <?= !empty($v['sellable']) ? 'checked' : '' ?>> Sellable on POS</label>
          <label class="checkbox"><input type="checkbox" name="active" value="1" <?= !empty($v['active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <label class="field" style="grid-column:span 2"><span class="field-label">Description</span>
          <textarea class="input" style="height:auto;padding:8px 10px" rows="2" name="description" maxlength="255"><?= e($v['description'] ?? '') ?></textarea></label>
      </div>
    </div>

    <div class="grid-2" data-show-for="raw retail" style="align-items:start">
      <div class="card">
        <div class="card-head"><h3>Stock settings</h3></div>
        <div class="card-body">
          <div class="form-grid">
            <label class="field"><span class="field-label">Reorder point (<span data-base-abbr><?= e($baseAbbr) ?></span>)</span>
              <input class="input num" type="number" step="any" min="0" name="reorder_point" value="<?= e($num($v['reorder_point'] ?? '')) ?>">
              <span class="field-hint">Flag for reorder at this level</span></label>
            <label class="field"><span class="field-label">Reorder qty (<span data-base-abbr><?= e($baseAbbr) ?></span>)</span>
              <input class="input num" type="number" step="any" min="0" name="reorder_qty" value="<?= e($num($v['reorder_qty'] ?? '')) ?>">
              <span class="field-hint">Suggested quantity to order</span></label>
            <label class="field"><span class="field-label"><?= $isNew ? 'Opening' : 'Standard' ?> cost per <span data-base-abbr><?= e($baseAbbr) ?></span></span>
              <?php if ($hasMovements): ?>
                <input class="input num" type="number" value="<?= e((float) $item['avg_cost']) ?>" disabled data-avg-cost>
                <span class="field-hint">Set by deliveries (moving average)</span>
              <?php else: ?>
                <input class="input num" type="number" step="any" min="0" name="avg_cost" value="<?= e($num($v['avg_cost'] ?? '')) ?>" placeholder="0.00" data-avg-cost>
                <span class="field-hint"><?= $isNew ? 'Optional starting cost; deliveries update it automatically' : 'Only editable while the item has no stock movements' ?></span>
              <?php endif; ?>
            </label>
          </div>
          <?php if ($stockedItem): ?>
            <dl class="kv mt">
              <dt>On hand</dt><dd class="<?= (float) $item['stock_qty'] < 0 ? 'text-red' : '' ?>"><?= qty($item['stock_qty']) ?> <?= e($item['uom']) ?></dd>
              <dt>Average cost</dt><dd>₱<?= Items::cost4($item['avg_cost']) ?> / <?= e($item['uom']) ?></dd>
              <dt>Last cost</dt><dd>₱<?= Items::cost4($item['last_cost']) ?> / <?= e($item['uom']) ?></dd>
              <dt>Stock value</dt><dd><?= peso((float) $item['stock_qty'] * (float) $item['avg_cost']) ?></dd>
            </dl>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Purchase / alternate units</h3>
          <?php if (!$readonly): ?><button class="btn btn-sm" type="button" data-add-row="uoms">+ Add unit</button><?php endif; ?></div>
        <div class="card-body">
          <p class="muted small" style="margin-top:0">How suppliers deliver this item, e.g. <b>1 sack = 50 kg</b>, <b>1 case = 24 btl</b>.
            Standard conversions like kg ↔ g are set under Units &amp; Conversions.</p>
          <div data-rows="uoms">
            <?php foreach ($altUnits as $i => $r): ?><?= view('inventory/items/_unit_row', $rowData + ['i' => $i, 'r' => $r], null) ?><?php endforeach; ?>
          </div>
          <template id="uoms-template"><?= view('inventory/items/_unit_row', $rowData + ['i' => '__i__', 'r' => []], null) ?></template>
        </div>
      </div>
    </div>

    <div class="card" data-show-for="composite">
      <div class="card-head"><h3>Recipe / components</h3>
        <?php if (!$readonly): ?><button class="btn btn-sm" type="button" data-add-row="components">+ Add ingredient</button><?php endif; ?></div>
      <div class="card-body" style="padding-bottom:0">
        <p class="muted small" style="margin:0">Ingredients used to make <b>1 <span data-base-abbr><?= e($baseAbbr) ?></span></b>.
          Sub-recipes (other composite items) are allowed. Selling this item deducts these from stock.</p>
      </div>
      <div class="table-wrap">
        <table class="table dense">
          <thead><tr><th>Component</th><th class="right">Qty</th><th>Unit</th><th class="right">Unit cost</th><th class="right">Cost</th><th class="right">% of cost</th><th></th></tr></thead>
          <tbody data-rows="components">
            <?php foreach ($components as $i => $c): ?>
              <?= view('inventory/items/_component_row', $compData + ['i' => $i, 'c' => $c, 'units' => $compUnits[(int) ($c['component_id'] ?? 0)] ?? []], null) ?>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4">Total recipe cost per <span data-base-abbr><?= e($baseAbbr) ?></span></td><td class="right" data-recipe-total><?= peso($unitCost) ?></td><td colspan="2"></td></tr></tfoot>
        </table>
      </div>
      <template id="components-template"><?= view('inventory/items/_component_row', $compData + ['i' => '__i__', 'c' => [], 'units' => []], null) ?></template>
    </div>

    <div class="card" data-show-for="composite retail" data-costing>
      <div class="card-head"><h3>Menu costing</h3><span class="muted small">Recalculated as you type; saved figures are computed by the server.</span></div>
      <div class="card-body">
        <div class="stats">
          <div class="stat"><div class="stat-label">Selling price</div><div class="stat-value" data-out="price">—</div><div class="stat-sub"><?= $vatRate ? 'VAT-inclusive' : 'non-VAT' ?></div></div>
          <div class="stat"><div class="stat-label">Net of VAT</div><div class="stat-value" data-out="net">—</div><div class="stat-sub"><?= $vatRate ? '÷ ' . number_format(1 + $vatRate, 2) : 'same as price' ?></div></div>
          <div class="stat"><div class="stat-label" data-out="cost-label">Recipe cost</div><div class="stat-value" data-out="cost">—</div></div>
          <div class="stat" data-tone="fc"><div class="stat-label">Food cost %</div><div class="stat-value" data-out="fc">—</div><div class="stat-sub">target 25–35%</div></div>
          <div class="stat" data-tone="margin"><div class="stat-label">Gross margin</div><div class="stat-value" data-out="margin">—</div><div class="stat-sub" data-out="margin-pct"></div></div>
        </div>
        <div class="alert alert-warn mt hidden" data-out="fc-warn">Food cost is high. Consider raising the price or reviewing portion sizes.</div>
      </div>
    </div>

    <?php if ($usedIn): ?>
      <div class="card">
        <div class="card-head"><h3>Used in <?= count($usedIn) ?> recipe<?= count($usedIn) > 1 ? 's' : '' ?></h3></div>
        <div class="card-body row wrap gap-sm">
          <?php foreach ($usedIn as $p): ?><a class="badge badge-blue" href="<?= url('/inventory/items/' . $p['id']) ?>"><?= e($p['name']) ?></a><?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </fieldset>

  <?php if (!$readonly): ?>
    <div class="row gap-sm mt">
      <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create item' : 'Save changes' ?></button>
      <a class="btn" href="<?= url('/inventory/items') ?>">Cancel</a>
    </div>
  <?php endif; ?>
</form>

<script type="application/json" id="inv-items"><?= json_encode($itemData, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="inv-type-hints"><?= json_encode(Items::TYPE_HINTS, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="inv-uom-abbr"><?= json_encode($uomAbbr, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('js/inventory.js') ?>"></script>
