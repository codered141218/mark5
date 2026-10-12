<?php
/** Printable payslips (blank layout). Variables: $run, $att (attendance per employee per day), $business */
use App\Services\Finance\Payroll;
$period = fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']);
?>
<style>
  body { background: #e5e7eb; }
  .slips { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 20px; padding: 24px; max-width: 1400px; margin: 0 auto; }
  .slip { background: #fff; border: 1px solid #d1d5db; border-radius: 8px; padding: 18px 22px; font-size: 13px; break-inside: avoid; }
  .slip h2 { margin: 0; font-size: 16px; }
  .slip .hd { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 10px; }
  .slip table { width: 100%; border-collapse: collapse; }
  .slip td { padding: 4px 0; }
  .slip td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
  .slip tr.sum td { border-top: 1px solid #d1d5db; font-weight: 700; }
  .slip tr.net td { border-top: 2px solid #111827; font-weight: 800; font-size: 15px; }
  .slip .att { margin-top: 10px; font-size: 11px; color: #4b5563; display: flex; flex-wrap: wrap; gap: 4px 10px; }
  .slip .sig { display: flex; justify-content: space-between; margin-top: 26px; font-size: 11px; color: #6b7280; }
  .slip .sig span { border-top: 1px solid #9ca3af; padding-top: 4px; width: 42%; text-align: center; }
  .bar { display: flex; justify-content: space-between; align-items: center; padding: 14px 24px; background: #111827; color: #fff; }
  .bar .btn { height: 36px; }
  @media print { .bar { display: none; } body { background: #fff; } .slips { padding: 0; gap: 12px; } .slip { border-radius: 0; } }
</style>
<div class="bar">
  <div><b><?= e($business) ?></b> · Payslips <?= e($run['doc_no']) ?> · <?= e($period) ?> · <?= count($run['lines']) ?> employee(s)</div>
  <div class="row gap-sm"><a class="btn btn-ghost" style="color:#fff" href="<?= url('/payroll/' . $run['id']) ?>">← Back</a><button class="btn btn-primary" type="button" onclick="window.print()">⎙ Print</button></div>
</div>
<div class="slips">
  <?php foreach ($run['lines'] as $l): $days = $att[(int) $l['employee_id']] ?? []; ?>
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
  <?php endforeach; ?>
</div>
