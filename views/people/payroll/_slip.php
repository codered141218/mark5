<?php
/** One payslip card. Variables: $l (payroll line + employee columns), $run (period_from, period_to, pay_date, doc_no), $days (attendance per day), $business */
use App\Services\Finance\Payroll;
$period = fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']);
?>
    <div class="slip">
      <div class="hd">
        <div><h2><?= e($business) ?></h2><div class="muted small">PAYSLIP · <?= e($run['doc_no']) ?> · <?= e($period) ?></div></div>
        <div class="right"><b><?= e($l['employee_name']) ?></b><div class="muted small"><?= e($l['emp_no'] . ($l['position'] ? ' · ' . $l['position'] : '') . ($l['department'] ? ' · ' . $l['department'] : '')) ?></div></div>
      </div>
      <table>
        <tr><td>Daily rate × days worked</td><td><?= e(money($l['daily_rate'])) ?> × <?= (int) $l['days_worked'] ?></td></tr>
        <tr><td>Basic pay</td><td><?= e(money($l['basic_pay'])) ?></td></tr>
        <tr><td>Overtime <?= (float) $l['ot_hours'] ? '(' . e(qty($l['ot_hours'])) . ' h × ' . Payroll::OT_MULTIPLIER . ')' : '' ?></td><td><?= e(money($l['ot_pay'])) ?></td></tr>
        <tr class="sum"><td>Gross pay</td><td><?= e(money($l['gross_pay'])) ?></td></tr>
        <tr><td>Less: cash advance</td><td><?= (float) $l['ca_deduction'] ? '(' . e(money($l['ca_deduction'])) . ')' : '—' ?></td></tr>
        <tr><td>Less: SSS / PhilHealth / Pag-IBIG</td><td><?= (float) $l['other_deductions'] ? '(' . e(money($l['other_deductions'])) . ')' : '—' ?></td></tr>
        <tr class="net"><td>NET PAY · <?= e(fmt_date($run['pay_date'])) ?></td><td><?= e(peso($l['net_pay'])) ?></td></tr>
      </table>
      <div class="att">
        <?php foreach ($days as $d => $x): ?><span><?= e(date('M j', strtotime($d))) ?> <?= e(substr($x['in'], 11, 5)) ?>–<?= e(substr($x['out'], 11, 5)) ?> (<?= e(qty($x['hours'])) ?>h)</span><?php endforeach; ?>
        <?php if (!$days): ?><span>No attendance punches in this period.</span><?php endif; ?>
      </div>
      <div class="sig"><span>Prepared by</span><span>Received by</span></div>
    </div>
