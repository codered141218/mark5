<?php
/**
 * Date range filter (GET form). Usage in a view:
 *   <?= view('partials/daterange', ['from' => $from, 'to' => $to, 'extra' => $extraFiltersHtml], null) ?>
 * $single = true shows only an "as of" date (name="to").
 */
$single = $single ?? false;
$keep = array_diff_key($_GET, array_flip(['from', 'to', 'export']));
?>
<form class="filters" method="get">
  <?php foreach ($keep as $k => $v): if (is_scalar($v) && !in_array($k, $skip ?? [], true)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
  <?php if (!$single): ?>
    <label class="field"><span class="field-label">Period</span>
      <select class="input" data-range-preset>
        <option value="">Custom range</option>
        <?= options(['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'last7' => 'Last 7 days', 'month' => 'This month',
            'lastmonth' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year', 'lastyear' => 'Last year']) ?>
      </select></label>
    <label class="field"><span class="field-label">From</span><input class="input" type="date" name="from" value="<?= e($from) ?>"></label>
  <?php endif; ?>
  <label class="field"><span class="field-label"><?= $single ? 'As of' : 'To' ?></span><input class="input" type="date" name="to" value="<?= e($to) ?>"></label>
  <?= $extra ?? '' ?>
  <button class="btn" type="submit">Apply</button>
</form>
