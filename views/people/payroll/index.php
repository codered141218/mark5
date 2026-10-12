<?php
/** Payroll runs. Variables: $rows, $columns, $banks, $suggest [from, to], $rated (employees with a daily rate) */
use App\Core\Table;
use App\Services\Finance\Payroll;
?>
<div class="page-header">
  <div>
    <h1>Payroll</h1>
    <p class="muted">Days and hours come from the ZKTeco attendance log; pay = daily rate × days + overtime, less cash advances and government deductions. Posting books the salaries and the net pay.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll/attendance') ?>">⌚ Attendance</a>
    <button class="btn btn-primary" data-open="dlg-run" data-reset>+ New payroll run</button>
  </div>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Employees on payroll', 'value' => (string) $rated, 'sub' => 'active, with a daily rate', 'tone' => $rated ? null : 'amber'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Last posted period', 'value' => ($p = array_values(array_filter($rows, fn ($r) => $r['status'] === 'posted'))[0] ?? null) ? fmt_date($p['period_to']) : '—', 'sub' => $p ? $p['doc_no'] . ' · net ' . peso($p['total_net']) : 'nothing posted yet'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Next cutoff', 'value' => fmt_date($suggest[0]) . ' – ' . fmt_date($suggest[1]), 'sub' => 'suggested pay period'], null) ?>
</div>

<?php if (!$rated): ?><div class="alert alert-warn mb">No employee has a daily rate yet. Set the daily rate and the biometric ID under <a href="<?= url('/employees') ?>">People → Employees</a>.</div><?php endif; ?>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'link' => fn ($r) => url('/payroll/' . $r['id']), 'empty' => 'No payroll runs yet. Import the attendance, then start a payroll run.']) ?>
</div>

<dialog class="modal" id="dlg-run">
  <form method="post" action="<?= url('/payroll') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>New payroll run</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <label class="field"><span class="field-label">Period from *</span><input class="input" type="date" name="period_from" value="<?= e($suggest[0]) ?>" required></label>
        <label class="field"><span class="field-label">Period to *</span><input class="input" type="date" name="period_to" value="<?= e($suggest[1]) ?>" required></label>
        <label class="field"><span class="field-label">Pay date</span><input class="input" type="date" name="pay_date" value="<?= e($suggest[1]) ?>"></label>
        <?= view('finance/partials/pay_method', ['name' => 'method', 'label' => 'Paid from', 'methods' => Payroll::PAY_METHODS, 'banks' => $banks], null) ?>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Notes</span><input class="input" name="notes" placeholder="e.g. 1st cutoff October"></label>
      </div>
      <div class="alert alert-info mt" style="margin-bottom:0">Every active employee with a daily rate is included. Days and hours are taken from the attendance punches in the period
        (<?= Payroll::REGULAR_HOURS ?> h regular, overtime at +<?= (int) ((Payroll::OT_MULTIPLIER - 1) * 100) ?>%, <?= Payroll::LUNCH_BREAK_HOURS ?> h break deducted on days longer than <?= Payroll::MIN_SPAN_FOR_BREAK ?> h). You can adjust deductions before posting.</div>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Compute payroll</button></div>
  </form>
</dialog>
