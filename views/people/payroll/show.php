<?php
/** One payroll run. Variables: $run, $columns, $draft */
use App\Core\Table;
use App\Services\Finance\Payroll;
$period = fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']);
?>
<div class="page-header">
  <div>
    <h1>Payroll <?= e($run['doc_no']) ?> <?= badge($run['status']) ?></h1>
    <p class="muted"><a href="<?= url('/payroll') ?>">← Payroll</a> · <?= e($period) ?> · pay date <?= e(fmt_date($run['pay_date'])) ?> ·
      paid from <?= e(Payroll::PAY_METHODS[$run['method']] ?? $run['method']) ?><?= $run['bank_name'] ? ' · ' . e($run['bank_name']) : '' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url("/payroll/attendance", ['from' => $run['period_from'], 'to' => $run['period_to']]) ?>">⌚ Attendance</a>
    <a class="btn" href="<?= url("/payroll/{$run['id']}/payslips") ?>" target="_blank">⎙ Payslips</a>
    <?php if ($draft): ?>
      <form method="post" action="<?= url("/payroll/{$run['id']}/delete") ?>" class="inline" data-confirm="Delete this draft payroll?" data-danger data-ok="Delete">
        <?= csrf_field() ?><button class="btn btn-danger" type="submit">Delete</button>
      </form>
      <button class="btn" type="submit" form="lines-form">Save deductions</button>
      <form method="post" action="<?= url("/payroll/{$run['id']}/post") ?>" class="inline"
            data-confirm="Post payroll <?= e($run['doc_no']) ?>? Salaries of <?= e(peso($run['total_gross'])) ?> and net pay of <?= e(peso($run['total_net'])) ?> are booked and the cash advance repayments recorded. Save the deductions first." data-ok="Post payroll">
        <?= csrf_field() ?><button class="btn btn-success" type="submit">Post payroll</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Gross pay', 'value' => peso($run['total_gross']), 'sub' => count($run['lines']) . ' employee(s) · ' . array_sum(array_column($run['lines'], 'days_worked')) . ' days worked'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Cash advance deductions', 'value' => peso($run['total_ca']), 'sub' => 'posted as CA repayments', 'tone' => 'amber'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'SSS / PhilHealth / Pag-IBIG', 'value' => peso($run['total_other']), 'sub' => 'employee share withheld'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Net pay', 'value' => peso($run['total_net']), 'sub' => Payroll::PAY_METHODS[$run['method']] ?? $run['method'], 'tone' => 'brand'], null) ?>
</div>

<?php if ($draft): ?>
  <form id="lines-form" method="post" action="<?= url("/payroll/{$run['id']}") ?>"><?= csrf_field() ?></form>
  <div class="alert alert-info mb">Draft — adjust the cash advance and government deductions per employee, <b>Save deductions</b>, then <b>Post payroll</b>.</div>
<?php else: ?>
  <div class="alert alert-success mb">Posted <?= e(fmt_datetime($run['posted_at'])) ?> by <?= e($run['posted_by_name']) ?>
    <?php if ($run['journal_entry_id'] && can('finance.view', 'finance.journal')): ?> · <a href="<?= url('/finance/journals/' . $run['journal_entry_id']) ?>">View journal entry</a><?php endif; ?>
    — Dr Salaries &amp; Wages <?= e(peso($run['total_gross'])) ?> · Cr Salaries Payable (cash advances) <?= e(peso($run['total_ca'])) ?> · Cr Gov’t payables <?= e(peso($run['total_other'])) ?> · Cr <?= e(Payroll::PAY_METHODS[$run['method']] ?? $run['method']) ?> <?= e(peso($run['total_net'])) ?></div>
<?php endif; ?>

<div class="card">
  <?= Table::html($columns, $run['lines'], ['export' => true, 'search' => false, 'empty' => 'No employees on this payroll.']) ?>
</div>
<?php if ($run['notes']): ?><p class="muted mt">Notes: <?= e($run['notes']) ?></p><?php endif; ?>
