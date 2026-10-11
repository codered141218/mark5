<?php /** Payroll settings. Variables: $cfg */ $ro = !can('payroll.manage'); ?>
<div class="page-header">
  <div>
    <h1>Payroll settings</h1>
    <p class="muted">How attendance turns into pay. The defaults follow your payroll template.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/payroll') ?>">← Payroll</a></div>
</div>
<form method="post" action="<?= url('/payroll/settings') ?>" class="card">
  <?= csrf_field() ?>
  <div class="card-body form-grid">
    <label class="field"><span class="field-label">Shift starts</span><input class="input" type="time" name="shift_start" value="<?= e($cfg['shift_start']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Time in after this counts as late.</span></label>
    <label class="field"><span class="field-label">Regular hours a day</span><input class="input" type="number" step="0.5" min="1" max="24" name="shift_hours" value="<?= e($cfg['shift_hours']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Fewer hours = undertime, more = overtime. Hourly rate = daily rate ÷ this.</span></label>
    <label class="field"><span class="field-label">Days per month</span><input class="input" type="number" step="1" min="1" max="31" name="days_per_month" value="<?= e($cfg['days_per_month']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Daily rate = monthly rate ÷ this.</span></label>
    <label class="field"><span class="field-label">Overtime rate</span><input class="input" type="number" step="0.05" min="0" max="5" name="ot_rate" value="<?= e($cfg['ot_rate']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Overtime pay = hours × hourly rate × this (1 = same as regular, 1.25 = +25%).</span></label>
    <label class="field"><span class="field-label">Minimum overtime (minutes a day)</span><input class="input" type="number" step="1" min="0" name="ot_min_minutes" value="<?= e($cfg['ot_min_minutes']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Less overtime than this in a day is not paid (0 = pay every minute).</span></label>
    <label class="field"><span class="field-label">Undertime grace (minutes a day)</span><input class="input" type="number" step="1" min="0" name="grace_minutes" value="<?= e($cfg['grace_minutes']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Undertime up to this is not deducted.</span></label>
    <label class="field"><span class="field-label">Paid day-offs per week</span><input class="input" type="number" step="1" min="0" max="7" name="paid_rest_days" value="<?= e($cfg['paid_rest_days']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Days without punches that are not deducted as absent (1 a week = 2 in a 15-day cut-off). 0 = every day not worked is deducted, like the template.</span></label>
    <label class="field"><span class="field-label">Day starts at</span><input class="input" type="time" name="day_starts" value="<?= e($cfg['day_starts']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Punches before this time (closing after midnight) belong to the previous day's shift.</span></label>
  </div>
  <div class="card-body muted small" style="padding-top:0">
    Cut-offs: <b>26–10</b> paid on the <b>15th</b>, <b>11–25</b> paid on the <b>30th</b>. Monthly-paid: half the monthly rate per cut-off, minus absences and undertime, plus overtime.
    Daily-paid: days present × daily rate. Each employee's rate, SSS / PhilHealth / Pag-IBIG / tax and cash advance deduction are set under People → Employees.
  </div>
  <?php if (!$ro): ?><div class="modal-foot"><button class="btn btn-primary" type="submit">Save settings</button></div><?php endif; ?>
</form>
