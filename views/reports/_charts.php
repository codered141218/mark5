<?php
/**
 * Charts above a sales report (drawn by js/charts.js from the JSON below; the report table stays underneath).
 * Variables: $charts = ['spec', 'grain', 'trend'?, 'series'?, 'bars'?], $rep (report definition)
 */
use App\Services\Reports\SalesReports;

$grain = $charts['grain'];
$trend = $charts['trend'] ?? null;
$series = $charts['series'] ?? null;
$bars = $charts['bars'] ?? null;
$grainLinks = function () use ($grain) {
    $h = '<div class="seg-tabs" role="group" aria-label="Group by">';
    foreach (SalesReports::GRAINS as $g => $label) {
        $h .= '<a class="seg-tab' . ($g === $grain ? ' on' : '') . '" href="' . e(current_url(['grain' => $g])) . '"' . ($g === $grain ? ' aria-current="true"' : '') . '>' . e($label) . '</a>';
    }
    return $h . '</div>';
};
$delta = function (float $now, float $before) {
    if ($before == 0.0) return '<span class="kpi-delta muted">' . ($now > 0 ? 'no sales in the previous period' : 'same as previous period') . '</span>';
    $pct = ($now - $before) / abs($before) * 100;
    if (abs($pct) < 0.05) return '<span class="kpi-delta muted">– same as previous period</span>';
    $up = $pct > 0;
    return '<span class="kpi-delta ' . ($up ? 'up' : 'down') . '"><span aria-hidden="true">' . ($up ? '▲' : '▼') . '</span> '
        . ($up ? '+' : '−') . number_format(abs($pct), 1) . '% <span class="muted">vs previous period</span></span>';
};
$json = fn ($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<?php if ($trend):
    $t = $trend['totals']; $p = $trend['prev_totals']; ?>
  <div class="stats mb kpis">
    <div class="stat stat-brand"><div class="stat-label">Net sales</div><div class="stat-value"><?= peso($t['net_sales']) ?></div><?= $delta($t['net_sales'], $p['net_sales']) ?></div>
    <div class="stat"><div class="stat-label">Receipts</div><div class="stat-value"><?= number_format($t['receipts']) ?></div><?= $delta($t['receipts'], $p['receipts']) ?></div>
    <div class="stat"><div class="stat-label">Average ticket</div><div class="stat-value"><?= peso($t['avg_ticket']) ?></div><?= $delta($t['avg_ticket'], $p['avg_ticket']) ?></div>
    <div class="stat"><div class="stat-label">Guests</div><div class="stat-value"><?= number_format($t['pax']) ?></div><?= $delta($t['pax'], $p['pax']) ?></div>
  </div>

  <div class="card mb chart-card" data-chart="trend" data-json="<?= e($json([
      'labels' => array_column($trend['current'], 'label'),
      'ranges' => array_map(fn ($b) => [$b['from'], $b['to']], $trend['current']),
      'prevRanges' => array_map(fn ($b) => [$b['from'], $b['to']], $trend['previous']),
      'current' => $trend['current'], 'previous' => $trend['previous'], 'grain' => $grain,
      'periods' => [range_label($trend['from'], $trend['to']), range_label($trend['prev_from'], $trend['prev_to'])],
  ])) ?>">
    <div class="card-head chart-head">
      <h3>Sales trend</h3>
      <div class="chart-controls">
        <div class="seg-tabs" role="group" aria-label="Measure">
          <button type="button" class="seg-tab on" data-metric="net_sales">Net sales</button>
          <button type="button" class="seg-tab" data-metric="receipts">Receipts</button>
          <button type="button" class="seg-tab" data-metric="avg_ticket">Avg ticket</button>
          <button type="button" class="seg-tab" data-metric="pax">Guests</button>
        </div>
        <?= $grainLinks() ?>
      </div>
    </div>
    <div class="chart-legend">
      <span class="lg"><i class="sw" style="background:var(--chart-1)"></i>This period · <?= e(range_label($trend['from'], $trend['to'])) ?></span>
      <label class="lg lg-toggle"><input type="checkbox" data-compare checked> <i class="sw sw-dash"></i>Previous period · <?= e(range_label($trend['prev_from'], $trend['prev_to'])) ?></label>
    </div>
    <div class="chart-body" data-plot></div>
  </div>
<?php endif; ?>

<?php if ($series || $bars): ?>
  <div class="chart-grid mb<?= $series && $bars ? ' two' : '' ?>">
    <?php if ($bars): ?>
      <div class="card chart-card" data-chart="bars" data-json="<?= e($json($bars)) ?>">
        <div class="card-head"><h3><?= e($bars['title']) ?></h3>
          <?php if ($bars['more']): ?><span class="muted small">+ <?= (int) $bars['more'] ?> more in the table below</span><?php endif; ?></div>
        <div class="chart-body" data-plot></div>
      </div>
    <?php endif; ?>
    <?php if ($series): ?>
      <div class="card chart-card" data-chart="series" data-json="<?= e($json($series)) ?>">
        <div class="card-head chart-head">
          <h3>Top <?= count($series['series']) ?: 5 ?> <?= $rep['chart']['series'] === 'category' ? 'categories' : 'items' ?> — sales over time</h3>
          <div class="chart-controls">
            <?= $grainLinks() ?>
            <button type="button" class="btn btn-sm" data-table-toggle aria-pressed="false">View as table</button>
          </div>
        </div>
        <div class="chart-legend" data-legend></div>
        <div class="chart-body" data-plot></div>
        <div class="chart-table hidden" data-table></div>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
