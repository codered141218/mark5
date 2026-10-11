<?php /** Payroll settings. Variables: $cfg */ use App\Services\Payroll\Payroll; $ro = !can('payroll.manage'); ?>
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
    <label class="field"><span class="field-label">Shift starts (default)</span><input class="input" type="time" name="shift_start" value="<?= e($cfg['shift_start']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Time in after this counts as late.</span></label>
    <label class="field"><span class="field-label">Regular hours a day (default)</span><input class="input" type="number" step="0.5" min="1" max="24" name="shift_hours" value="<?= e($cfg['shift_hours']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Fewer hours = undertime, more = overtime. Hourly rate = daily rate ÷ this.</span></label>
    <label class="field"><span class="field-label">Days per month</span><input class="input" type="number" step="1" min="1" max="31" name="days_per_month" value="<?= e($cfg['days_per_month']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Daily rate = monthly rate ÷ this.</span></label>
    <label class="field"><span class="field-label">Overtime rate</span><input class="input" type="number" step="0.05" min="0" max="5" name="ot_rate" value="<?= e($cfg['ot_rate']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Overtime pay = hours × hourly rate × this (1 = same as regular, 1.25 = +25%).</span></label>
    <label class="field"><span class="field-label">Minimum overtime (minutes a day)</span><input class="input" type="number" step="1" min="0" name="ot_min_minutes" value="<?= e($cfg['ot_min_minutes']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Less overtime than this in a day is not paid (0 = pay every minute).</span></label>
    <label class="field"><span class="field-label">Undertime grace (minutes a day)</span><input class="input" type="number" step="1" min="0" name="grace_minutes" value="<?= e($cfg['grace_minutes']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Undertime up to this is not deducted.</span></label>
    <label class="field"><span class="field-label">Paid day-offs per week (default)</span><input class="input" type="number" step="1" min="0" max="7" name="paid_rest_days" value="<?= e($cfg['paid_rest_days']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Days off that are paid / not deducted as absent (1 a week = 2 in a 15-day cut-off). 0 = every day not worked is deducted, like the template. Each employee can have their own.</span></label>
    <label class="field"><span class="field-label">Day starts at</span><input class="input" type="time" name="day_starts" value="<?= e($cfg['day_starts']) ?>" <?= $ro ? 'disabled' : '' ?>>
      <span class="field-hint">Punches before this time (closing after midnight) belong to the previous day's shift.</span></label>
  </div>
  <div class="card-body" style="padding-top:0">
    <div class="form-section">Cut-offs and pay-out days</div>
    <p class="muted small">Each cut-off starts the day after the previous one ends. Use <b>31</b> for the month's last day. A pay-out day before the end day is in the next month.
      Monthly-paid staff get the monthly rate ÷ the number of cut-offs, minus absences and undertime, plus overtime.</p>
    <table class="table dense cutoff-table" id="cutoffs">
      <thead><tr><th>Cut-off ends on day</th><th>Pay-out day</th><th>Covers</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cfg['cutoffs'] as $c): ?>
        <tr>
          <td><input class="input num" type="number" min="1" max="31" name="cut_end[]" value="<?= (int) $c['end'] ?>" required <?= $ro ? 'disabled' : '' ?>></td>
          <td><input class="input num" type="number" min="1" max="31" name="cut_pay[]" value="<?= (int) $c['pay'] ?>" required <?= $ro ? 'disabled' : '' ?>></td>
          <td class="muted small" data-covers></td>
          <td><?php if (!$ro): ?><button class="btn btn-sm btn-ghost text-red" type="button" data-cut-del title="Remove">✕</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$ro): ?><button class="btn btn-sm mt-sm" type="button" data-cut-add>+ Add a cut-off</button><?php endif; ?>
    <p class="muted small mt">This month and next: <?php foreach (array_slice(array_merge(Payroll::cutoffs(date('Y-m-01')), Payroll::cutoffs(date('Y-m-01', strtotime('+1 month')))), 0, 4) as $c): ?>
      <span class="nowrap"> · <b><?= e(range_label($c['from'], $c['to'])) ?></b> paid <?= e(fmt_date($c['pay_date'])) ?></span><?php endforeach; ?></p>
    <p class="muted small">Daily-paid: (days present + paid leave + paid day-offs) × daily rate. Each employee's rate, deductions, working days, paid day-offs a week and shift
      are set under <a href="<?= url('/employees') ?>">People → Employees</a>; the values above are the defaults.</p>
  </div>
  <?php if (!$ro): ?><div class="modal-foot"><button class="btn btn-primary" type="submit">Save settings</button></div><?php endif; ?>
</form>
<script>
  (() => {
    const t = document.getElementById('cutoffs');
    const body = t.querySelector('tbody');
    const ord = (n) => n + ([11, 12, 13].includes(n % 100) ? 'th' : (['th', 'st', 'nd', 'rd'][n % 10] || 'th'));
    const day = (n) => (n >= 31 ? 'month end' : String(n));
    const refresh = () => {
      const rows = [...body.rows];
      const ends = rows.map((r) => parseInt(r.querySelector('[name="cut_end[]"]').value, 10) || 0);
      const sorted = [...ends].filter((x) => x > 0).sort((a, b) => a - b);
      rows.forEach((r, i) => {
        const end = ends[i];
        const pay = parseInt(r.querySelector('[name="cut_pay[]"]').value, 10) || 0;
        const k = sorted.indexOf(end);
        const prev = sorted.length ? sorted[(k - 1 + sorted.length) % sorted.length] : 0;
        const start = prev >= 31 || (sorted.length === 1 && end >= 31) ? 1 : prev + 1;
        r.querySelector('[data-covers]').textContent = end && pay ? day(start) + ' – ' + day(end) + ', paid on the ' + (pay >= 31 ? 'last day' : ord(pay)) + (pay < end ? ' of the next month' : '') : '';
      });
      body.querySelectorAll('[data-cut-del]').forEach((b) => { b.disabled = rows.length < 2; });
      const add = document.querySelector('[data-cut-add]');
      if (add) add.disabled = rows.length >= 4;
    };
    t.addEventListener('input', refresh);
    t.addEventListener('click', (e) => { const b = e.target.closest('[data-cut-del]'); if (b) { b.closest('tr').remove(); refresh(); } });
    document.querySelector('[data-cut-add]')?.addEventListener('click', () => {
      const r = body.rows[body.rows.length - 1].cloneNode(true);
      r.querySelectorAll('input').forEach((i) => { i.value = ''; });
      body.appendChild(r);
      refresh();
      r.querySelector('input').focus();
    });
    refresh();
  })();
</script>
