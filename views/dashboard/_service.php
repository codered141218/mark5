<?php
/**
 * Service time on the dashboard: how long paid orders took from opening (first item) to payment.
 * Variables: $sv (Dashboard::serviceTimes), $singleDay, $from, $to
 */
use App\Services\Reports\Dashboard;
use App\Services\Reports\SalesReports;

$fmt = function ($m): string {
    if ($m === null) return '—';
    $m = (int) round((float) $m);
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm';
};
$tile = fn (string $label, string $value, string $sub = '', string $tone = '') => '<div class="stat svc-stat' . ($tone ? ' stat-' . $tone : '') . '"><div class="stat-label">' . e($label)
    . '</div><div class="stat-value">' . e($value) . '</div>' . ($sub !== '' ? '<div class="stat-sub">' . e($sub) . '</div>' : '') . '</div>';
$types = SalesReports::ORDER_TYPES;
?>
<div class="card svc-card">
  <div class="card-head">
    <div><h3>Service time</h3><span class="muted small">from opening the order to payment · <?= number_format($sv['count']) ?> paid order(s)</span></div>
    <?php if ($sv['skipped']): ?><span class="muted small" title="Orders open longer than <?= Dashboard::SERVICE_MAX_MINUTES / 60 ?> hours (left open by mistake) are not counted"><?= (int) $sv['skipped'] ?> left out (open over <?= Dashboard::SERVICE_MAX_MINUTES / 60 ?> h)</span><?php endif; ?>
  </div>
  <?php if (!$sv['count']): ?>
    <div class="empty">No paid orders in this period yet.</div>
  <?php else: ?>
  <div class="card-body">
    <div class="stats svc-stats">
      <?= $tile('Average', $fmt($sv['avg']), 'per order', 'brand') ?>
      <?= $tile('Typical (median)', $fmt($sv['median']), 'half of the orders were faster') ?>
      <?= $tile('Slowest 10%', $fmt($sv['p90']) . '+', 'longest: ' . $fmt($sv['max'])) ?>
      <?= $tile('Over 1 hour', number_format($sv['slow_pct'], 1) . '%', 'of orders', $sv['slow_pct'] >= 25 ? 'red' : ($sv['slow_pct'] >= 10 ? 'amber' : 'green')) ?>
      <?php foreach (['dine_in', 'takeout', 'delivery'] as $t): if (!empty($sv['by_type'][$t])): ?>
        <?= $tile($types[$t], $fmt($sv['by_type'][$t]['avg']), number_format($sv['by_type'][$t]['count']) . ' orders') ?>
      <?php endif; endforeach; ?>
      <?= $tile('Order taking', $sv['taking_count'] ? $fmt($sv['taking_avg']) : '—', $sv['taking_count'] ? 'opened → Done (' . number_format($sv['taking_count']) . ' orders)' : 'shows after orders are sent with Done') ?>
    </div>

    <div class="grid-2 mt">
      <div>
        <div class="svc-sub"><b>Average by hour the order started</b> <span class="muted small">minutes</span></div>
        <?= view('dashboard/_bar_chart', ['height' => 190, 'width' => 560, 'format' => $fmt, 'bars' => array_map(
            fn ($h, $r) => ['label' => (string) $h, 'value' => (float) $r['avg'], 'tip' => sprintf('Orders started %02d:00 – %02d:59 · %d orders', $h, $h, $r['count'])],
            array_keys($sv['by_hour']), $sv['by_hour'])], null) ?>
      </div>
      <div>
        <div class="svc-sub"><b>How long orders took</b> <span class="muted small">share of orders</span></div>
        <?= view('dashboard/_hbars', ['format' => fn ($v) => number_format($v, 1) . '%', 'bars' => array_map(
            fn ($b) => ['label' => $b['label'], 'value' => $b['pct'], 'sub' => '(' . $b['count'] . ')'], $sv['buckets'])], null) ?>
      </div>
    </div>

    <div class="grid-2 mt">
      <?php if (!$singleDay && count($sv['by_day']) > 1): ?>
        <div>
          <div class="svc-sub"><b>Average per day</b> <span class="muted small">minutes</span></div>
          <?= view('dashboard/_bar_chart', ['height' => 190, 'width' => 560, 'format' => $fmt, 'bars' => array_map(
              fn ($day, $r) => ['label' => date('j', strtotime($day)), 'value' => (float) $r['avg'], 'tip' => fmt_date($day) . ' · ' . $r['count'] . ' orders'],
              array_keys($sv['by_day']), $sv['by_day'])], null) ?>
        </div>
      <?php endif; ?>
      <div>
        <div class="svc-sub"><b>Average by staff who opened the order</b></div>
        <?= view('dashboard/_hbars', ['format' => $fmt, 'bars' => array_map(
            fn ($name, $r) => ['label' => $name, 'value' => (float) $r['avg'], 'sub' => '(' . $r['count'] . ')'], array_keys($sv['by_staff']), $sv['by_staff'])], null) ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
