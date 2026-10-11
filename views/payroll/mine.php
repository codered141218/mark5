<?php /** The logged-in employee's payslips. Variables: $rows, $linked, $employee */ ?>
<div class="page-header">
  <div>
    <h1>My payslips</h1>
    <p class="muted"><?= $employee ? e($employee['full_name']) . ($employee['position'] ? ' · ' . e($employee['position']) : '') : 'Your payslips appear here once payroll is approved.' ?></p>
  </div>
</div>
<?php if (!$linked): ?>
  <div class="alert alert-warn">Your user account is not linked to an employee record yet. Ask the administrator to set it under Administration → Users (Employee).</div>
<?php elseif (!$rows): ?>
  <div class="card"><div class="empty">No payslips yet.</div></div>
<?php else: ?>
  <div class="my-slips">
    <?php foreach ($rows as $r): ?>
      <a class="my-slip" href="<?= url('/payroll/payslips/' . $r['id']) ?>">
        <span class="my-slip-period"><?= e(range_label($r['period_from'], $r['period_to'])) ?><small>pay-out <?= e(fmt_date($r['pay_date'])) ?></small></span>
        <span class="my-slip-net"><?= peso($r['net_pay']) ?><small><?= $r['paid_at'] ? 'paid ' . e(fmt_date($r['paid_at'])) : 'not paid out yet' ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
