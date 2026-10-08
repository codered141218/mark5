<?php
/**
 * X/Z reading laid out like a printed receipt.
 * Variables: $r (CashSessions::report() data), $z (true = Z-reading), $settings, $vatRegistered
 */
$s = $r['session'];
$line = fn ($l, $v, $bold = false) => '<div class="r' . ($bold ? ' bold' : '') . '"><span>' . e($l) . '</span><span>' . e($v) . '</span></div>';
$discountLabels = ['sc' => 'Senior Citizen', 'pwd' => 'PWD', 'percent' => 'Promo %', 'amount' => 'Promo amount'];
$variance = $r['cash']['variance'] ?? null;
?>
<div class="receipt">
  <div class="c big"><?= e($settings['business_name'] ?? '') ?></div>
  <?php foreach (['business_address', 'business_phone'] as $k): if (!empty($settings[$k])): ?><div class="c"><?= e($settings[$k]) ?></div><?php endif; endforeach; ?>
  <?php if (!empty($settings['business_tin'])): ?><div class="c">TIN: <?= e($settings['business_tin']) ?></div><?php endif; ?>
  <hr>
  <div class="c big"><?= $z ? 'Z-READING (END OF DAY)' : 'X-READING' ?></div>
  <?= $line('Business date:', fmt_date($s['business_date'])) ?>
  <?= $line('Opened:', fmt_datetime($s['opened_at'])) ?>
  <?= $line('Opened by:', $s['opened_by_name'] ?? '') ?>
  <?php if ($z): ?>
    <?= $line('Closed:', fmt_datetime($s['closed_at'])) ?>
    <?= $line('Closed by:', $s['closed_by_name'] ?? '') ?>
  <?php endif; ?>
  <?= $line('Printed:', fmt_datetime(now())) ?>
  <hr>
  <?= $line('Beginning OR', $r['sales']['first_or'] ?: '-') ?>
  <?= $line('Ending OR', $r['sales']['last_or'] ?: '-') ?>
  <?= $line('Receipts', (int) $r['sales']['cnt']) ?>
  <?= $line('Guests (pax)', (int) $r['sales']['pax']) ?>
  <hr>
  <?= $line('Gross Sales', money($r['sales']['gross'])) ?>
  <?= $line('Less: Discounts', money($r['sales']['discounts'])) ?>
  <?= $line('Add: Service Charge', money($r['sales']['svc'])) ?>
  <?= $line('NET SALES', money($r['sales']['net']), true) ?>
  <?php if ($vatRegistered): ?>
    <?= $line('VATable Sales', money($r['sales']['vatable'])) ?>
    <?= $line('VAT Amount', money($r['sales']['vat'])) ?>
    <?= $line('VAT-Exempt Sales', money($r['sales']['exempt'])) ?>
  <?php endif; ?>
  <?php if ($r['discounts']): ?><hr><?php endif; ?>
  <?php foreach ($r['discounts'] as $d): ?>
    <?= $line(($d['label'] ?? $discountLabels[$d['discount_type']] ?? $d['discount_type']) . ' (' . (int) $d['cnt'] . ')', money($d['amount'])) ?>
  <?php endforeach; ?>
  <hr>
  <div class="bold">PAYMENTS</div>
  <?php foreach ($r['payments'] as $p): ?><?= $line(($p['label'] ?? $p['method']) . ' (' . (int) $p['cnt'] . ')', money($p['amount'])) ?><?php endforeach; ?>
  <hr>
  <?= $line('Voided receipts (' . (int) $r['voided']['cnt'] . ')', money($r['voided']['amount'])) ?>
  <?= $line('Voided items (' . (int) $r['item_voids']['cnt'] . ')', money($r['item_voids']['amount'])) ?>
  <?= $line('Cancelled tickets', (int) $r['cancelled']) ?>
  <hr>
  <div class="bold">CASH DRAWER</div>
  <?= $line('Beginning cash', money($r['cash']['opening'])) ?>
  <?= $line('Cash sales', money($r['cash']['cash_sales'])) ?>
  <?= $line('Less: Payouts / petty cash', money($r['cash']['payouts'])) ?>
  <?php if ((float) $r['cash']['refunds'] > 0): ?><?= $line('Less: Refunds', money($r['cash']['refunds'])) ?><?php endif; ?>
  <?= $line('EXPECTED CASH', money($r['cash']['expected']), true) ?>
  <?php if ($z): ?>
    <?= $line('ACTUAL CASH COUNT', money($r['cash']['counted']), true) ?>
    <?= $line($variance < 0 ? 'SHORT' : ($variance > 0 ? 'OVER' : 'VARIANCE'), money($variance), true) ?>
  <?php endif; ?>
  <hr>
  <div class="bold">SALES BY CATEGORY</div>
  <?php foreach ($r['categories'] as $c): ?><?= $line($c['category'] . ' (' . qty($c['qty']) . ')', money($c['amount'])) ?><?php endforeach; ?>
  <hr>
  <?= $line('Accum. Grand Total Beg.', money($r['grand_total']['beginning'])) ?>
  <?= $line('Accum. Grand Total End', money($r['grand_total']['ending'])) ?>
  <hr>
  <div class="c">Cashier: ____________ Manager: ____________</div>
</div>
