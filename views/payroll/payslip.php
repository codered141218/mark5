<?php
/** One payslip. Variables: $s (Payroll::slip), $edit, $advances, $cfg */
use App\Services\Payroll\Payroll;

$hrs = function ($min) {
    $min = (int) $min;
    return $min ? intdiv($min, 60) . ':' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) : '';
};
$bonuses = array_values(array_filter($s['lines'], fn ($l) => $l['kind'] === 'bonus'));
$deds = array_values(array_filter($s['lines'], fn ($l) => $l['kind'] === 'deduction'));
$back = can('payroll.view', 'payroll.manage', 'payroll.post') ? url('/payroll/' . $s['run_id']) : url('/my/payslips');
?>
<div class="page-header">
  <div>
    <h1><?= e($s['full_name']) ?></h1>
    <p class="muted"><?= e(trim(($s['emp_no'] ?? '') . ' · ' . ($s['position'] ?? ''), ' ·')) ?> · Payroll <?= e($s['run_no']) ?> ·
      cut-off <?= e(range_label($s['period_from'], $s['period_to'])) ?> · pay-out <?= e(fmt_date($s['pay_date'])) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($back) ?>">← Back</a>
    <a class="btn" href="<?= url('/payroll/payslips/' . $s['id'], ['print' => 1]) ?>" target="_blank">⎙ Print payslip</a>
  </div>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Net pay', 'value' => peso($s['net_pay']), 'sub' => $s['paid_at'] ? 'paid ' . fmt_date($s['paid_at']) . ' · ' . (Payroll::PAY_METHODS[$s['paid_method']] ?? '') : 'not paid out yet', 'tone' => 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Gross pay', 'value' => peso($s['gross_pay'])], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Deductions', 'value' => peso($s['deduction_total'])], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Days present', 'value' => rtrim(rtrim(number_format((float) $s['days_present'], 2), '0'), '.') . ' / ' . $s['days_in_period'],
      'sub' => ((float) $s['days_absent'] ? rtrim(rtrim(number_format((float) $s['days_absent'], 2), '0'), '.') . ' absent · ' : '') . ((float) $s['days_rest'] ? rtrim(rtrim(number_format((float) $s['days_rest'], 2), '0'), '.') . ' day-off · ' : '') . $s['late_minutes'] . ' min late'], null) ?>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-head"><h3>Pay computation</h3><span class="muted small"><?= $s['pay_type'] === 'daily' ? 'Daily-paid' : 'Monthly-paid' ?></span></div>
    <table class="table dense">
      <tbody>
        <?php if ($s['pay_type'] === 'daily'): ?>
          <tr><td>Daily rate</td><td class="right"><?= money($s['daily_rate']) ?></td></tr>
          <tr><td>Basic pay <span class="muted small"><?= (float) ($s['days_paid'] ?: (float) $s['days_present'] + (float) $s['days_leave']) ?> day(s) × <?= money($s['daily_rate']) ?><?= (float) $s['days_paid'] > (float) $s['days_present'] + (float) $s['days_leave'] ? ' · incl. paid day-offs' : '' ?></span></td><td class="right"><?= money($s['basic_pay']) ?></td></tr>
        <?php else: ?>
          <tr><td>Monthly rate <span class="muted small">daily <?= money($s['daily_rate']) ?> · hourly <?= money($s['hourly_rate']) ?></span></td><td class="right"><?= money($s['monthly_rate']) ?></td></tr>
          <tr><td>Basic pay <span class="muted small"><?= (float) $s['basic_pay'] > 0 && (float) $s['monthly_rate'] > 0 ? 'monthly ÷ ' . round((float) $s['monthly_rate'] / (float) $s['basic_pay']) . ' cut-offs' : 'per cut-off' ?></span></td><td class="right"><?= money($s['basic_pay']) ?></td></tr>
          <tr><td>Absences <span class="muted small"><?= (float) $s['days_absent'] ?> day(s) × <?= money($s['daily_rate']) ?></span></td><td class="right text-red">−<?= money($s['absence_deduction']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Undertime <span class="muted small"><?= (float) $s['undertime_hours'] ?> hr × <?= money($s['hourly_rate']) ?></span></td><td class="right text-red">−<?= money($s['undertime_deduction']) ?></td></tr>
        <tr><td>Overtime <span class="muted small"><?= (float) $s['ot_hours'] ?> hr × <?= money($s['hourly_rate']) ?><?= $cfg['ot_rate'] != 1 ? ' × ' . $cfg['ot_rate'] : '' ?></span></td><td class="right text-green">+<?= money($s['ot_pay']) ?></td></tr>
        <?php foreach ($bonuses as $l): ?>
          <tr><td><?= e($l['description']) ?> <span class="muted small"><?= e(Payroll::BONUS_TYPES[$l['type']] ?? '') ?></span></td>
            <td class="right text-green">+<?= money($l['amount']) ?><?= $edit ? view('payroll/_line_actions', ['l' => $l], null) : '' ?></td></tr>
        <?php endforeach; ?>
        <tr class="tot"><td><b>Gross pay</b></td><td class="right"><b><?= money($s['gross_pay']) ?></b></td></tr>
        <?php foreach ($deds as $l): ?>
          <tr><td><?= e($l['description']) ?> <span class="muted small"><?= e(Payroll::DEDUCTION_TYPES[$l['type']] ?? '') ?><?= $l['auto'] ? ' · automatic' : '' ?></span></td>
            <td class="right text-red">−<?= money($l['amount']) ?><?= $edit ? view('payroll/_line_actions', ['l' => $l], null) : '' ?></td></tr>
        <?php endforeach; ?>
        <tr class="grand"><td><b>NET PAY</b></td><td class="right"><b><?= peso($s['net_pay']) ?></b></td></tr>
      </tbody>
    </table>
    <?php if ($edit): ?>
      <div class="card-body row gap-sm wrap">
        <button class="btn" data-open="dlg-bonus" data-reset>+ Add bonus</button>
        <button class="btn" data-open="dlg-deduction" data-reset>+ Add deduction</button>
        <form method="post" action="<?= url('/payroll/payslips/' . $s['id'] . '/remove') ?>" class="inline" data-confirm="Remove <?= e($s['full_name']) ?> from this payroll?">
          <?= csrf_field() ?><button class="btn btn-ghost text-red" type="submit">Remove from payroll</button></form>
      </div>
    <?php elseif ($s['paid_at'] && can('payroll.post') && $s['payout_je_id']): ?>
      <div class="card-body">
        <form method="post" action="<?= url('/payroll/payslips/' . $s['id'] . '/undo-payout') ?>" class="row gap-sm" data-confirm="Undo this payout? Its entry is reversed and the payslip(s) paid with it become unpaid.">
          <?= csrf_field() ?><input class="input" name="reason" placeholder="Reason" required style="max-width:260px"><button class="btn btn-ghost text-red" type="submit">Undo payout</button></form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h3>Attendance</h3><span class="muted small"><?= e($schedule) ?></span></div>
    <div class="table-wrap" style="max-height:560px">
      <table class="table dense att-days">
        <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="right">Hours</th><th class="right">Late</th><th class="right">UT</th><th class="right">OT</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($s['days'] as $d): $st = $d['status']; ?>
          <tr class="<?= str_starts_with($st, 'Absent') || str_starts_with($st, 'Incomplete') || $st === 'Day off (unpaid)' ? 'att-absent' : (str_starts_with($st, 'Day off') ? 'att-rest' : '') ?>">
            <td class="nowrap"><?= e(date('D M j', strtotime($d['date']))) ?></td>
            <td><?= e($d['in'] ?? '') ?></td><td><?= e($d['out'] ?? '') ?></td>
            <td class="right"><?= e($hrs($d['hours'])) ?></td>
            <td class="right"><?= $d['late'] ? (int) $d['late'] . 'm' : '' ?></td>
            <td class="right"><?= e($hrs($d['ut'])) ?></td>
            <td class="right"><?= e($hrs($d['ot'])) ?></td>
            <td><?= e($st) ?><?php if (!empty($d['note'])): ?> <span class="muted small"><?= e($d['note']) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
  <?php if (!empty($notes)): ?>
<div class="card mt">
    <div class="card-head"><h3>Staff notes this cut-off</h3><a class="small" href="<?= url('/payroll/notes', ['from' => $s['period_from'], 'to' => $s['period_to'], 'employee_id' => $s['employee_id']]) ?>">All notes →</a></div>
    <table class="table dense"><tbody>
      <?php foreach ($notes as $n): ?>
        <tr><td class="nowrap"><?= e(date('D M j', strtotime($n['note_date']))) ?></td><td><?= e(App\Services\Payroll\StaffNotes::label($n)) ?></td>
          <td class="muted small nowrap"><?= e($n['recorded_by'] ?? '') ?><?= $n['status'] === 'applied' ? ' · applied' : '' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>

<?php if ($edit): ?>
<dialog class="modal" id="dlg-bonus">
  <form method="post" action="<?= url('/payroll/payslips/' . $s['id'] . '/lines') ?>">
    <?= csrf_field() ?><input type="hidden" name="kind" value="bonus">
    <div class="modal-head"><h3>Add bonus</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Type</span><select class="input" name="type"><?= options(Payroll::BONUS_TYPES) ?></select></label>
      <label class="field"><span class="field-label">Amount (₱) *</span><input class="input num" type="number" step="0.01" min="0.01" name="amount" required></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. Perfect attendance, sales incentive"></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Add bonus</button></div>
  </form>
</dialog>
<dialog class="modal" id="dlg-deduction">
  <form method="post" action="<?= url('/payroll/payslips/' . $s['id'] . '/lines') ?>">
    <?= csrf_field() ?><input type="hidden" name="kind" value="deduction">
    <div class="modal-head"><h3>Add deduction</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Type</span><select class="input" name="type" data-ded-type><?= options(Payroll::DEDUCTION_TYPES, 'other') ?></select></label>
      <label class="field"><span class="field-label">Amount (₱) *</span><input class="input num" type="number" step="0.01" min="0.01" name="amount" required></label>
      <label class="field hidden" data-ca-field style="grid-column:1/-1"><span class="field-label">Cash advance</span>
        <select class="input" name="advance_id"><?= options(array_column(array_map(fn ($a) => ['id' => $a['id'], 'l' => $a['doc_no'] . ' · balance ' . money($a['balance'])], $advances), 'l', 'id'), null, $advances ? null : 'No outstanding cash advance') ?></select></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. Cash shortage Oct 3, broken plates, uniform"></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Add deduction</button></div>
  </form>
</dialog>
<script>
  document.querySelector('[data-ded-type]').addEventListener('change', (e) => {
    document.querySelector('[data-ca-field]').classList.toggle('hidden', e.target.value !== 'cash_advance');
  });
</script>
<?php endif; ?>
