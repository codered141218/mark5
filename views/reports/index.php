<?php
/**
 * Report page: report picker, filters, then a table, a financial statement or the petty cash layout.
 * Variables: $groupTitle, $reports, $key, $rep, $mode, $from, $to, $f, $subtitle, $options
 *            plus one of $needsPick, $rows, $statement + $lines + $statementTitle, $petty
 */
use App\Core\Table;

$filterDefs = [
    'item' => ['item_id', 'Item', 'Select item…'], 'account' => ['account_id', 'Account', 'Select account…'],
    'bank' => ['bank_account_id', 'Bank account', 'Select bank…'], 'category' => ['category_id', 'Category', 'All categories'],
    'status' => ['status', 'Status', 'Paid & void'],
];
$filters = $rep['filters'] ?? [];
$extra = '';
foreach ($filters as $name) {
    [$param, $label, $placeholder] = $filterDefs[$name];
    $combo = in_array($name, ['item', 'account'], true) ? ' data-combo' : '';
    $extra .= '<label class="field"><span class="field-label">' . e($label) . '</span><select class="input" name="' . $param . '"' . $combo . '>'
        . options($options[$name] ?? [], $f[$param], $placeholder) . '</select></label>';
}
$filterParams = array_map(fn ($n) => $filterDefs[$n][0], $filters);
?>
<div class="page-header">
  <div>
    <h1><?= e($groupTitle) ?></h1>
    <p class="muted"><?= e($rep['label']) ?> · <?= e($subtitle) ?></p>
  </div>
  <div class="page-actions">
    <form method="get" class="row gap-sm">
      <?php foreach (['from', 'to'] as $k): if (isset($_GET[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($_GET[$k]) ?>"><?php endif; endforeach; ?>
      <select class="input" name="report" aria-label="Report" onchange="this.form.submit()" style="min-width:280px">
        <?= options(array_map(fn ($r) => $r['label'], $reports), $key) ?>
      </select>
      <noscript><button class="btn" type="submit">Open</button></noscript>
    </form>
  </div>
</div>

<?php if ($mode !== 'none'): ?>
  <?= view('partials/daterange', ['from' => $from, 'to' => $to, 'single' => $mode === 'asof', 'extra' => $extra, 'skip' => $filterParams], null) ?>
<?php elseif ($filters): ?>
  <form class="filters" method="get">
    <input type="hidden" name="report" value="<?= e($key) ?>">
    <?= $extra ?>
    <button class="btn" type="submit">Apply</button>
  </form>
<?php endif; ?>

<?php if (!empty($needsPick)): ?>
  <div class="card"><div class="empty">Select the <?= e(strtolower($filterDefs[$filters[0]][1])) ?> to view this report.</div></div>

<?php elseif (isset($statement)): ?>
  <div class="card">
    <div class="card-body statement">
      <div class="row between mb">
        <div>
          <h3><?= e(App\Services\Settings::get('business_name', '')) ?></h3>
          <div class="muted"><?= e($statementTitle) ?> · <?= e($subtitle) ?></div>
        </div>
        <a class="btn btn-sm" href="<?= e(current_url(['export' => 'xlsx'])) ?>">⬇ Excel</a>
      </div>
      <?php if (isset($statement['check']) && abs($statement['check']) > 0.009): ?>
        <div class="alert alert-error">Out of balance by <?= money($statement['check']) ?></div>
      <?php endif; ?>
      <table>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <?php if ($l['kind'] === 'sec'): ?>
            <tr class="sec"><td colspan="2"><?= e($l['name']) ?></td></tr>
          <?php elseif ($l['kind'] === 'line'): ?>
            <tr>
              <td style="padding-left:20px">
                <?php if ($l['account_id'] && can('reports.finance')): ?>
                  <a href="<?= e(url('/reports/finance', ['report' => 'gl', 'account_id' => $l['account_id'], 'from' => $mode === 'range' ? $from : date('Y-01-01', strtotime($to)), 'to' => $to])) ?>"><?= e($l['name']) ?></a>
                <?php else: ?><?= e($l['name']) ?><?php endif; ?>
              </td>
              <td class="amt"><?= money($l['amount']) ?></td>
            </tr>
          <?php else: ?>
            <tr class="<?= $l['kind'] ?>">
              <td><?= e($l['name']) ?><?php if (!empty($l['note'])): ?> <span class="muted small"><?= e($l['note']) ?></span><?php endif; ?></td>
              <td class="amt<?= $l['kind'] === 'grand' && $l['amount'] < 0 ? ' text-red' : '' ?>"><?= money($l['amount']) ?></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif (isset($petty)): ?>
  <div class="stats mb">
    <div class="stat"><div class="stat-label">Fund beginning balance</div><div class="stat-value"><?= peso($petty['beginning']) ?></div></div>
    <div class="stat"><div class="stat-label">Replenishments</div><div class="stat-value"><?= peso($petty['added']) ?></div></div>
    <div class="stat"><div class="stat-label">Expenses (all sources)</div><div class="stat-value"><?= peso($petty['spent']) ?></div></div>
    <div class="stat stat-brand"><div class="stat-label">Fund ending balance</div><div class="stat-value"><?= peso($petty['ending']) ?></div>
      <div class="stat-sub">Drawer payouts do not affect the fund</div></div>
  </div>
  <div class="grid-2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr)">
    <div class="card"><?= Table::html($rep['columns'], $petty['rows'], ['export' => true]) ?></div>
    <div class="card">
      <div class="card-head"><h3>Expenses by account</h3>
        <?php if ($petty['by_account']): ?><a class="btn btn-sm" href="<?= e(current_url(['export' => 'xlsx', 'part' => 'accounts'])) ?>">⬇ Excel</a><?php endif; ?>
      </div>
      <?= Table::html($rep['by_account'], $petty['by_account'], ['search' => false]) ?>
    </div>
  </div>

<?php else: ?>
  <div class="card"><?= Table::html($rep['columns'], $rows, ['export' => true, 'link' => $rep['link'] ?? null]) ?></div>
<?php endif; ?>
