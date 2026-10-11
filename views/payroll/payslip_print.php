<?php
/** Printable payslips, one per page (layout of the restaurant's payslip template). Variables: $slips, $settings */
use App\Services\Payroll\Payroll;

$biz = $settings['business_name'] ?? '';
$addr = $settings['business_address'] ?? '';
$n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') ?: '0';
?>
<div class="ps-toolbar no-print">
  <button class="btn btn-primary" type="button" onclick="window.print()">⎙ Print / save as PDF</button>
  <span class="muted small"><?= count($slips) ?> payslip(s)</span>
</div>
<?php foreach ($slips as $s):
    $gov = array_filter($s['lines'], fn ($l) => $l['kind'] === 'deduction' && $l['type'] !== 'cash_advance' && $l['type'] !== 'other');
    $other = array_filter($s['lines'], fn ($l) => $l['kind'] === 'deduction' && in_array($l['type'], ['cash_advance', 'other'], true));
    $bonus = array_filter($s['lines'], fn ($l) => $l['kind'] === 'bonus'); ?>
  <section class="ps">
    <header class="ps-head">
      <div class="ps-biz"><?= e(mb_strtoupper($biz)) ?></div>
      <?php if ($addr): ?><div class="ps-addr"><?= e($addr) ?></div><?php endif; ?>
      <div class="ps-title">PAYSLIP</div>
    </header>
    <div class="ps-meta">
      <div><span>Employee ID:</span><b><?= e($s['emp_no']) ?></b></div>
      <div><span>Pay-out date:</span><b><?= e(fmt_date($s['pay_date'])) ?></b></div>
      <div><span>Employee name:</span><b><?= e($s['full_name']) ?></b></div>
      <div><span>Cut-off period:</span><b><?= e(range_label($s['period_from'], $s['period_to'])) ?></b></div>
      <?php if ($s['position']): ?><div><span>Position:</span><b><?= e($s['position']) ?></b></div><?php endif; ?>
      <div><span>Payroll no.:</span><b><?= e($s['run_no']) ?></b></div>
    </div>
    <div class="ps-cols">
      <div>
        <h4>Pay computation</h4>
        <div class="ps-row"><span>Basic pay<?= $s['pay_type'] === 'daily' ? ' (' . $n((float) $s['days_paid'] ?: $s['days_present'] + $s['days_leave']) . ' days)' : '' ?></span><b><?= money($s['basic_pay']) ?></b></div>
        <div class="ps-row"><span>Days present</span><b><?= $n($s['days_present']) ?> / <?= (int) $s['days_in_period'] ?></b></div>
        <div class="ps-row"><span>OT (hrs)</span><b><?= $n($s['ot_hours']) ?></b></div>
        <div class="ps-row"><span>OT pay</span><b><?= money($s['ot_pay']) ?></b></div>
        <?php foreach ($bonus as $l): ?><div class="ps-row"><span><?= e($l['description']) ?></span><b><?= money($l['amount']) ?></b></div><?php endforeach; ?>
        <div class="ps-row ps-sum"><span>Gross pay</span><b><?= money((float) $s['basic_pay'] + (float) $s['ot_pay'] + (float) $s['bonus_total']) ?></b></div>
      </div>
      <div>
        <h4>Deductions</h4>
        <div class="ps-row"><span>Absent day(s)</span><b><?= $n($s['days_absent']) ?></b></div>
        <div class="ps-row"><span>Absent deduction</span><b><?= money($s['absence_deduction']) ?></b></div>
        <div class="ps-row"><span>Undertime (hrs)</span><b><?= $n($s['undertime_hours']) ?></b></div>
        <div class="ps-row"><span>Undertime deduction</span><b><?= money($s['undertime_deduction']) ?></b></div>
        <?php foreach ($gov as $l): ?><div class="ps-row"><span><?= e($l['description']) ?></span><b><?= money($l['amount']) ?></b></div><?php endforeach; ?>
        <?php foreach ($other as $l): ?><div class="ps-row"><span><?= e($l['description']) ?></span><b><?= money($l['amount']) ?></b></div><?php endforeach; ?>
        <div class="ps-row ps-sum"><span>Total deductions</span><b><?= money((float) $s['absence_deduction'] + (float) $s['undertime_deduction'] + (float) $s['deduction_total']) ?></b></div>
      </div>
    </div>
    <div class="ps-net"><span>NET PAY</span><b><?= peso($s['net_pay']) ?></b></div>
    <?php if ($s['paid_at']): ?><div class="ps-paid">Paid <?= e(fmt_date($s['paid_at'])) ?> · <?= e(Payroll::PAY_METHODS[$s['paid_method']] ?? '') ?><?= $s['bank_name'] ? ' · ' . e($s['bank_name']) : '' ?></div><?php endif; ?>
    <div class="ps-sign"><div>Prepared by</div><div>Received by: <?= e($s['full_name']) ?></div></div>
  </section>
<?php endforeach; ?>
<style>
  body { background: #eef1f6; }
  .ps-toolbar { display: flex; gap: 12px; align-items: center; padding: 14px 18px; }
  .ps { background: #fff; width: 760px; max-width: calc(100% - 24px); margin: 0 auto 18px; padding: 28px 32px; border-radius: 12px; box-shadow: 0 4px 18px rgba(15,23,42,.08); color: #111827; font-size: 13px; }
  .ps-head { text-align: center; margin-bottom: 16px; }
  .ps-biz { font-weight: 800; font-size: 17px; letter-spacing: .02em; }
  .ps-addr { color: #4b5563; font-size: 12px; }
  .ps-title { margin-top: 8px; font-weight: 800; letter-spacing: .3em; font-size: 14px; }
  .ps-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; padding: 10px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
  .ps-meta span { color: #6b7280; margin-right: 6px; }
  .ps-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 14px; }
  .ps-cols h4 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #6b7280; }
  .ps-row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; }
  .ps-row b { font-weight: 600; font-variant-numeric: tabular-nums; }
  .ps-sum { border-top: 1px solid #e5e7eb; margin-top: 4px; padding-top: 6px; font-weight: 700; }
  .ps-net { display: flex; justify-content: space-between; margin-top: 16px; padding: 12px 14px; border-radius: 10px; background: #fff7ed; border: 1px solid #fed7aa; font-size: 16px; font-weight: 800; }
  .ps-paid { margin-top: 6px; color: #059669; font-weight: 600; font-size: 12px; text-align: right; }
  .ps-sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 46px; color: #6b7280; font-size: 12px; }
  .ps-sign div { border-top: 1px solid #9ca3af; padding-top: 4px; }
  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .ps { box-shadow: none; border-radius: 0; width: auto; max-width: none; margin: 0; page-break-after: always; }
    .ps:last-child { page-break-after: auto; }
  }
</style>
