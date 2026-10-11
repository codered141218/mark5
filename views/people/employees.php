<?php /** Employees. Variables: $rows, $columns, $showAll, $cfg (payroll settings) */ use App\Core\Table; use App\Services\Payroll\Payroll; ?>
<div class="page-header">
  <div>
    <h1>Employees</h1>
    <p class="muted">Staff records: pay rates and deductions for payroll, the biometric device ID, cash advances and user accounts.</p>
  </div>
  <div class="page-actions">
    <?php if (can('payroll.view', 'payroll.manage')): ?><a class="btn" href="<?= url('/payroll') ?>">₱ Payroll</a><?php endif; ?>
    <button class="btn btn-primary" data-open="dlg-employee" data-reset data-title="Add employee" data-fill='{"id":"","active":1,"pay_type":"monthly","work_days":"1234567"}'>+ Add employee</button>
  </div>
</div>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No employees yet.',
      'toolbar' => '<a class="btn btn-sm' . ($showAll ? ' btn-dark' : '') . '" href="' . e(current_url(['all' => $showAll ? null : 1])) . '">' . ($showAll ? '✓ ' : '') . 'Show inactive</a>']) ?>
</div>

<dialog class="modal wide" id="dlg-employee">
  <form method="post" action="<?= url('/employees') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Employee</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Employee no.</span><input class="input" name="emp_no" placeholder="Auto"><span class="field-hint">Leave blank to auto-number</span></label>
      <label class="field"><span class="field-label">Full name *</span><input class="input" name="full_name" required></label>
      <label class="field"><span class="field-label">Position</span><input class="input" name="position" placeholder="e.g. Cook, Server"></label>
      <label class="field"><span class="field-label">Department</span><input class="input" name="department" placeholder="e.g. Kitchen, Dining"></label>
      <label class="field"><span class="field-label">Phone</span><input class="input" name="phone" placeholder="09xx xxx xxxx"></label>
      <label class="field"><span class="field-label">Date hired</span><input class="input" type="date" name="date_hired"></label>
      <div class="form-section" style="grid-column: 1 / -1">Payroll</div>
      <label class="field"><span class="field-label">Pay type</span><select class="input" name="pay_type"><?= options(['monthly' => 'Monthly (rate ÷ cut-offs, absences deducted)', 'daily' => 'Daily (days worked + paid day-offs × daily rate)'], 'monthly') ?></select></label>
      <label class="field"><span class="field-label">Biometric device ID</span><input class="input" name="biometric_id" placeholder="e.g. 5"><span class="field-hint">User number on the ZKTeco device (set automatically on the first upload)</span></label>
      <label class="field"><span class="field-label">Monthly rate (₱)</span><input class="input num" type="number" step="0.01" min="0" name="monthly_rate"><span class="field-hint">Daily rate = monthly ÷ 30 unless a daily rate is set</span></label>
      <label class="field"><span class="field-label">Daily rate (₱)</span><input class="input num" type="number" step="0.01" min="0" name="daily_rate"><span class="field-hint">For daily-paid staff</span></label>
      <label class="field"><span class="field-label">SSS per pay-out (₱)</span><input class="input num" type="number" step="0.01" min="0" name="sss"></label>
      <label class="field"><span class="field-label">PhilHealth per pay-out (₱)</span><input class="input num" type="number" step="0.01" min="0" name="philhealth"></label>
      <label class="field"><span class="field-label">Pag-IBIG per pay-out (₱)</span><input class="input num" type="number" step="0.01" min="0" name="pagibig"></label>
      <label class="field"><span class="field-label">Withholding tax per pay-out (₱)</span><input class="input num" type="number" step="0.01" min="0" name="wtax"></label>
      <label class="field"><span class="field-label">Cash advance deduction per pay-out (₱)</span><input class="input num" type="number" step="0.01" min="0" name="ca_deduction"><span class="field-hint">0 = deduct the whole outstanding balance</span></label>
      <div class="form-section" style="grid-column: 1 / -1">Work schedule <span class="muted small" style="font-weight:400">— blank fields use the <a href="<?= url('/payroll/settings') ?>">payroll defaults</a></span></div>
      <div class="field" style="grid-column: 1 / -1"><span class="field-label">Working days</span>
        <input type="hidden" name="work_days" value="1234567"><input type="hidden" name="work_days_set" value="1">
        <div class="wd-pick" data-wd>
          <?php foreach (Payroll::WEEKDAYS as $n => $d): ?><label><input type="checkbox" value="<?= $n ?>" checked> <?= $d ?></label><?php endforeach; ?>
        </div>
        <span class="field-hint">Untick the employee's fixed day(s) off (e.g. every Monday). Leave all ticked if the day off changes every week.</span></div>
      <label class="field"><span class="field-label">Paid day-offs a week</span><input class="input num" type="number" step="1" min="0" max="7" name="paid_rest_days" placeholder="<?= (int) $cfg['paid_rest_days'] ?> (default)">
        <span class="field-hint">Days off that are paid / not deducted. Fixed day-offs above count first; any extra are flexible.</span></label>
      <label class="field"><span class="field-label">Shift starts</span><input class="input" type="time" name="shift_start" placeholder="<?= e($cfg['shift_start']) ?>">
        <span class="field-hint">Blank = <?= e($cfg['shift_start']) ?>. Time in after this is late.</span></label>
      <label class="field"><span class="field-label">Regular hours a day</span><input class="input num" type="number" step="0.5" min="1" max="24" name="shift_hours" placeholder="<?= (float) $cfg['shift_hours'] ?> (default)">
        <span class="field-hint">Less = undertime, more = overtime.</span></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Notes</span><textarea class="input" name="notes" rows="2"></textarea></label>
      <label class="checkbox"><input type="checkbox" name="active" value="1"> Active employee</label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
<script>
  // working-day checkboxes <-> the hidden work_days field ("123456" = Sunday off)
  (() => {
    const f = document.querySelector('#dlg-employee form');
    const hidden = f.querySelector('[name="work_days"]');
    const boxes = [...f.querySelectorAll('[data-wd] input')];
    hidden.addEventListener('change', () => { const v = hidden.value || '1234567'; boxes.forEach((b) => { b.checked = v.includes(b.value); }); });
    boxes.forEach((b) => b.addEventListener('change', () => { hidden.value = boxes.filter((x) => x.checked).map((x) => x.value).join(''); }));
  })();
</script>
