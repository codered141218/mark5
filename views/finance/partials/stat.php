<?php
/** One stat card. Variables: $label, $value (already formatted), $sub (optional), $tone (optional: brand|green|red|amber) */
?>
<div class="stat<?= !empty($tone) ? ' stat-' . e($tone) : '' ?>">
  <div class="stat-label"><?= e($label) ?></div>
  <div class="stat-value"><?= e($value) ?></div>
  <?php if (!empty($sub)): ?><div class="stat-sub"><?= e($sub) ?></div><?php endif; ?>
</div>
