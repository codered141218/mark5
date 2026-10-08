<?php
/**
 * Count sheet. Variables: $s (session), $rows (lines with sys / cost / counted / var / value / category), $totals, $open, $business
 * While open, inventory.js recalculates variances and totals as quantities are typed.
 * #print-root holds the printable sheet (app.css prints only that element).
 */
use App\Controllers\Inventory\CountController;
use App\Services\Items;

$signed = fn ($v, $fmt) => $v === null ? '' : ($v > 0 ? '+' : '') . $fmt($v);
$groups = [];
foreach ($rows as $r) $groups[$r['category']][] = $r;
$scope = $s['category_name'] ?: 'All stocked items';
?>
<div class="page-header">
  <div>
    <h1><?= e($s['doc_no']) ?> <?= badge($s['status']) ?></h1>
    <p class="muted"><?= e('Count date ' . fmt_date($s['count_date']) . ' · ' . $scope . ($s['created_by_name'] ? ' · started by ' . $s['created_by_name'] : '') . ($s['notes'] ? ' · ' . $s['notes'] : '')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/inventory/counts') ?>">← Back</a>
    <button class="btn" type="button" data-print>🖶 Print sheet</button>
    <a class="btn" href="<?= e(current_url(['export' => 'xlsx'])) ?>">⬇ Excel</a>
    <?php if ($open): ?>
      <form method="post" action="<?= url('/inventory/counts/' . $s['id'] . '/cancel') ?>" class="inline" data-danger data-ok="Cancel session"
            data-confirm="Cancel this count? Entered quantities are kept for reference but stock will not be adjusted.">
        <?= csrf_field() ?><button class="btn btn-ghost" type="submit">Cancel session</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($s['status'] === 'posted'): ?>
  <div class="alert alert-success"><b>Posted</b><?= $s['posted_by_name'] ? ' by ' . e($s['posted_by_name']) : '' ?><?= $s['posted_at'] ? ' on ' . e(fmt_datetime($s['posted_at'])) : '' ?>.
    Stock was adjusted to the counted quantities<?= $s['entry_no'] ? ' (journal entry ' . e($s['entry_no']) . ')' : '' ?>.</div>
<?php elseif ($s['status'] === 'cancelled'): ?>
  <div class="alert alert-warn">This count session was cancelled. Stock was not adjusted.</div>
<?php else: ?>
  <div class="alert alert-info">Enter the quantity physically counted for each item, in its base unit. System qty is live stock; leave an item blank to skip it. Save often.</div>
<?php endif; ?>

<div class="stats mb" data-count-stats>
  <div class="stat"><div class="stat-label">Items counted</div><div class="stat-value" data-stat="counted"><?= $totals['counted'] ?> / <?= count($rows) ?></div></div>
  <div class="stat"><div class="stat-label">Shortage value</div><div class="stat-value <?= CountController::tone($totals['short']) ?>" data-stat="short"><?= peso($totals['short']) ?></div></div>
  <div class="stat"><div class="stat-label">Overage value</div><div class="stat-value <?= CountController::tone($totals['over']) ?>" data-stat="over"><?= peso($totals['over']) ?></div></div>
  <div class="stat"><div class="stat-label">Net variance</div><div class="stat-value <?= CountController::tone($totals['net']) ?>" data-stat="net"><?= peso($totals['net']) ?></div>
    <div class="stat-sub">system stock value <?= peso($totals['sys_value']) ?></div></div>
</div>

<form id="count-form" method="post" action="<?= url('/inventory/counts/' . $s['id']) ?>" data-count-sheet>
  <?= csrf_field() ?>
  <div class="card">
    <div class="dt-toolbar">
      <input class="input dt-search" type="search" placeholder="Search item or SKU…" data-count-search>
      <label class="checkbox"><input type="checkbox" data-count-filter="uncounted"> Only uncounted</label>
      <label class="checkbox"><input type="checkbox" data-count-filter="variance"> Only with variance</label>
      <div class="grow"></div>
      <span class="muted small" data-count-shown><?= count($rows) ?> of <?= count($rows) ?> items</span>
    </div>
    <div class="table-wrap">
      <table class="table dense">
        <thead><tr>
          <th>Item</th><th>SKU</th><th>Unit</th><th class="right"><?= $open ? 'System qty (live)' : 'System qty' ?></th>
          <th class="right" style="width:140px">Counted</th><th class="right">Variance</th><th class="right">Unit cost</th><th class="right">Variance value</th>
        </tr></thead>
        <tbody>
          <?php foreach ($groups as $name => $lines): ?>
            <tr data-group><td colspan="8" class="bold" style="background:var(--surface-2)"><?= e($name) ?> <span class="muted">(<?= count($lines) ?>)</span></td></tr>
            <?php foreach ($lines as $r): ?>
              <tr data-line data-sys="<?= e($r['sys']) ?>" data-cost="<?= e($r['cost']) ?>" data-search="<?= e(mb_strtolower($r['item_name'] . ' ' . $r['sku'] . ' ' . $name)) ?>"
                  data-counted="<?= $r['counted'] === null ? '' : '1' ?>" data-variance="<?= $r['var'] !== null && abs($r['var']) >= 0.00005 ? '1' : '' ?>">
                <td class="bold"><?= e($r['item_name']) ?></td>
                <td class="muted"><?= e($r['sku']) ?></td>
                <td><?= e($r['uom']) ?></td>
                <td class="right <?= $r['sys'] < 0 ? 'text-red' : '' ?>"><?= qty($r['sys']) ?>
                  <?php if ($open && abs($r['sys'] - (float) $r['system_qty']) > 0.00005): ?><div class="muted small" title="Quantity when the count was started">start <?= qty($r['system_qty']) ?></div><?php endif; ?></td>
                <td class="right">
                  <?php if ($open): ?>
                    <input class="input num" type="number" step="any" min="0" name="counted[<?= $r['id'] ?>]" value="<?= $r['counted'] === null ? '' : e($r['counted']) ?>" placeholder="—" data-count>
                  <?php else: ?>
                    <?= $r['counted'] === null ? '<span class="muted small">not counted</span>' : qty($r['counted']) ?>
                  <?php endif; ?>
                </td>
                <td class="right <?= CountController::tone($r['var']) ?>" data-var><?= $signed($r['var'], 'qty') ?></td>
                <td class="right muted"><?= Items::cost4($r['cost']) ?></td>
                <td class="right <?= CountController::tone($r['value']) ?>" data-value><?= $signed($r['value'], 'money') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="7">Net variance (all items)</td><td class="right <?= CountController::tone($totals['net']) ?>" data-stat="net-foot"><?= peso($totals['net']) ?></td></tr></tfoot>
      </table>
    </div>
  </div>

  <?php if ($open): ?>
    <div class="row gap-sm wrap mt">
      <button class="btn btn-primary" type="submit">Save counts</button>
      <?php if (can('inventory.post')): ?>
        <button class="btn btn-success" type="submit" formaction="<?= url('/inventory/counts/' . $s['id'] . '/post') ?>" data-ok="Post count"
                data-ask="Post count <?= e($s['doc_no']) ?>?"
                data-ask-message="This saves the quantities, adjusts system stock to them and books the variance to Inventory Variance in the general ledger. Items left blank are not adjusted. This cannot be undone.">Post count</button>
      <?php else: ?>
        <span class="muted small">A manager with posting rights must post this count.</span>
      <?php endif; ?>
      <div class="grow"></div>
      <span class="text-amber small bold" data-dirty-note></span>
    </div>
  <?php endif; ?>
</form>

<div id="print-root">
  <style>
    .count-print { font: 12px Arial, sans-serif; color: #000; }
    .count-print table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .count-print th, .count-print td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
    .count-print .num { text-align: right; }
    .count-print .blank { width: 90px; }
    .count-print .group td { background: #eee; font-weight: bold; }
  </style>
  <div class="count-print">
    <h2 style="margin:0"><?= e($business) ?> — Count Sheet <?= e($s['doc_no']) ?></h2>
    <div>Date: <?= e(fmt_date($s['count_date'])) ?> · Scope: <?= e($scope) ?> · Status: <?= e($s['status']) ?></div>
    <table>
      <thead><tr><th>Item</th><th>SKU</th><th>Unit</th><th class="num">System qty</th><th class="num blank">Counted</th><?php if (!$open): ?><th class="num">Variance</th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($groups as $name => $lines): ?>
          <tr class="group"><td colspan="<?= $open ? 5 : 6 ?>"><?= e($name) ?></td></tr>
          <?php foreach ($lines as $r): ?>
            <tr><td><?= e($r['item_name']) ?></td><td><?= e($r['sku']) ?></td><td><?= e($r['uom']) ?></td><td class="num"><?= qty($r['sys']) ?></td>
              <td class="num blank"><?= $r['counted'] === null ? '' : qty($r['counted']) ?></td><?php if (!$open): ?><td class="num"><?= $signed($r['var'], 'qty') ?></td><?php endif; ?></tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:24px">Counted by: ____________________ &nbsp;&nbsp; Checked by: ____________________</p>
  </div>
</div>
<script src="<?= asset('js/inventory.js') ?>"></script>
