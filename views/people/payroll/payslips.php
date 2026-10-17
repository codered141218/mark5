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
  <?php foreach ($run['lines'] as $l): ?>
    <?= view('people/payroll/_slip', ['l' => $l, 'run' => $run, 'days' => $att[(int) $l['employee_id']] ?? [], 'business' => $business], null) ?>
  <?php endforeach; ?>
</div>
