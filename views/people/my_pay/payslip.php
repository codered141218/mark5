<?php /** One payslip, phone / print friendly (blank layout). Variables: $l, $run, $days, $business */ ?>
<style>
  body { background: #e5e7eb; }
  .bar { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #111827; color: #fff; gap: 8px; }
  .bar .btn { height: 36px; }
  .slips { padding: 16px; max-width: 560px; margin: 0 auto; }
  .slip { background: #fff; border: 1px solid #d1d5db; border-radius: 10px; padding: 18px 20px; font-size: 14px; }
  .slip h2 { margin: 0; font-size: 16px; }
  .slip .hd { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 10px; }
  .slip table { width: 100%; border-collapse: collapse; }
  .slip td { padding: 5px 0; }
  .slip td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
  .slip tr.sum td { border-top: 1px solid #d1d5db; font-weight: 700; }
  .slip tr.net td { border-top: 2px solid #111827; font-weight: 800; font-size: 17px; color: #15803d; }
  .slip .att { margin-top: 12px; font-size: 12px; color: #4b5563; display: flex; flex-wrap: wrap; gap: 4px 10px; }
  .slip .sig { display: flex; justify-content: space-between; margin-top: 26px; font-size: 11px; color: #6b7280; }
  .slip .sig span { border-top: 1px solid #9ca3af; padding-top: 4px; width: 42%; text-align: center; }
  @media print { .bar { display: none; } body { background: #fff; } }
</style>
<div class="bar">
  <a class="btn btn-ghost" style="color:#fff" href="<?= url('/my-pay') ?>">← My Pay</a>
  <b><?= e($l['doc_no']) ?></b>
  <button class="btn btn-primary" type="button" onclick="window.print()">⎙ Print / Save</button>
</div>
<div class="slips">
  <?= view('people/payroll/_slip', ['l' => $l, 'run' => $run, 'days' => $days, 'business' => $business], null) ?>
</div>
