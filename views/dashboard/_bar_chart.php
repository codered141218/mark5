<?php
/**
 * Single-series vertical bar chart as inline SVG (no JavaScript). Hover a bar for its <title> tooltip.
 * Variables: $bars = [['label' => '01', 'value' => 1234.5, 'tip' => 'Oct 1, 2026 · 12 receipts'], ...]
 *            $format = fn ($v) => string (default peso), $height (default 200), $width (default 640; smaller for narrow cards),
 *            $integer = true for counts (keeps axis ticks on whole numbers)
 */
$format = $format ?? 'peso';
$height = $height ?? 200;
$width = $width ?? 640;
if (!$bars) {
    echo '<div class="empty">No data for this period</div>';
    return;
}
$W = $width; $H = $height; $padL = 52; $padR = 8; $padT = 10; $padB = 24;
$plotW = $W - $padL - $padR;
$plotH = $H - $padT - $padB;
$max = max(array_merge([0], array_column($bars, 'value'))) ?: 1;
// Round the axis maximum up to 1, 2 or 5 × 10^n
$p = 10 ** floor(log10($max));
$n = $max / $p;
$nice = ($n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10))) * $p;
if (!empty($integer)) $nice = max($nice, 2);
$short = function ($v) {
    if ($v >= 1e6) return round($v / 1e6, 1) . 'M';
    if ($v >= 1e3) return round($v / 1e3, 1) . 'k';
    return (string) round($v, $v < 10 ? 1 : 0);
};
$slot = $plotW / count($bars);
$bw = max(min($slot - 2, 28), 2);
$labelEvery = (int) ceil(count($bars) / 12);
?>
<svg class="bar-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" role="img" style="display:block">
  <?php foreach ([0, $nice / 2, $nice] as $t): $y = $padT + $plotH - $t / $nice * $plotH; ?>
    <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= $y ?>" y2="<?= $y ?>" stroke="var(--border)" stroke-width="1"/>
    <text x="<?= $padL - 6 ?>" y="<?= $y + 4 ?>" text-anchor="end" font-size="11" fill="var(--muted)"><?= e($short($t)) ?></text>
  <?php endforeach; ?>
  <?php foreach ($bars as $i => $d):
      $h = max((float) $d['value'], 0) / $nice * $plotH;
      $x = $padL + $i * $slot + ($slot - $bw) / 2;
      $y = $padT + $plotH - $h;
      $r = min(4, $bw / 2, $h);
      $base = $padT + $plotH; ?>
    <g>
      <title><?= e(($d['tip'] ?? $d['label']) . ': ' . $format($d['value'])) ?></title>
      <rect x="<?= $padL + $i * $slot ?>" y="<?= $padT ?>" width="<?= $slot ?>" height="<?= $plotH ?>" fill="transparent"/>
      <?php if ($h > 0): ?>
        <path fill="var(--brand)" d="M<?= round($x, 2) ?>,<?= $base ?> V<?= round($y + $r, 2) ?> Q<?= round($x, 2) ?>,<?= round($y, 2) ?> <?= round($x + $r, 2) ?>,<?= round($y, 2) ?> H<?= round($x + $bw - $r, 2) ?> Q<?= round($x + $bw, 2) ?>,<?= round($y, 2) ?> <?= round($x + $bw, 2) ?>,<?= round($y + $r, 2) ?> V<?= $base ?> Z"/>
      <?php endif; ?>
      <?php if ($i % $labelEvery === 0): ?>
        <text x="<?= round($padL + $i * $slot + $slot / 2, 2) ?>" y="<?= $H - 6 ?>" text-anchor="middle" font-size="11" fill="var(--muted)"><?= e($d['label']) ?></text>
      <?php endif; ?>
    </g>
  <?php endforeach; ?>
</svg>
