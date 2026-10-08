<?php
/**
 * Horizontal bars for ranked lists (categories, top items, payment mix).
 * Variables: $bars = [['label' => 'Rice Meals', 'value' => 1234.5, 'sub' => '×12'], ...], $format (default money)
 */
$format = $format ?? 'money';
if (!$bars) {
    echo '<div class="empty">No data for this period</div>';
    return;
}
$max = max(array_merge([0], array_column($bars, 'value'))) ?: 1;
?>
<?php foreach ($bars as $d): ?>
  <div class="hbar" title="<?= e($d['label'] . ': ' . $format($d['value'])) ?>">
    <span class="nowrap" style="overflow:hidden;text-overflow:ellipsis"><?= e($d['label']) ?></span>
    <div class="hbar-track"><div class="hbar-fill" style="width:<?= round(max((float) $d['value'], 0) / $max * 100, 2) ?>%"></div></div>
    <span class="mono right nowrap"><?= e($format($d['value'])) ?><?php if (!empty($d['sub'])): ?> <span class="muted small"><?= e($d['sub']) ?></span><?php endif; ?></span>
  </div>
<?php endforeach; ?>
