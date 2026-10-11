<?php /** Payroll runs. Variables: $rows, $columns, $link, $suggest */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Payroll</h1>
    <p class="muted">Salaries per cut-off, computed from the biometric attendance. Approved payroll is posted to the books; payouts come from cash, petty cash or a bank.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll/attendance') ?>">⏱ Attendance</a>
    <a class="btn" href="<?= url('/payroll/settings') ?>">⚙ Settings</a>
    <?php if (can('payroll.manage')): ?><a class="btn btn-primary" href="<?= url('/payroll/new') ?>">+ New payroll</a><?php endif; ?>
  </div>
</div>

<div class="pay-steps mb">
  <a class="pay-step" href="<?= url('/payroll/attendance') ?>"><b>1</b><span>Import attendance<small>upload the file from the ZKTeco device</small></span></a>
  <a class="pay-step" href="<?= url('/payroll/new') ?>"><b>2</b><span>New payroll<small>pick the cut-off — pay is computed</small></span></a>
  <div class="pay-step"><b>3</b><span>Review<small>bonuses, deductions, cash advances</small></span></div>
  <div class="pay-step"><b>4</b><span>Approve<small>posted to the books · payslips visible</small></span></div>
  <div class="pay-step"><b>5</b><span>Pay out<small>cash, petty cash or bank</small></span></div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'link' => $link,
    'empty' => 'No payroll yet. Next cut-off: ' . range_label($suggest['from'], $suggest['to']) . ', pay-out ' . fmt_date($suggest['pay_date']) . '.']) ?></div>
