<?php
/** Staff notes per cut-off. Variables: $from, $to, $empId, $cutoffs, $rows, $columns, $summary, $employees */
use App\Core\Table;
use App\Services\Payroll\StaffNotes;

$h = fn ($v) => $v ? rtrim(rtrim(number_format((float) $v, 2), '0'), '.') : '';
?>
<div class="page-header">
  <div>
    <h1>Staff notes</h1>
    <p class="muted">Absent, leave, undertime and late notes recorded by the cashier at the POS (<b>Staff log</b>), per cut-off — a reference when preparing payroll.
      Absent, leave and day-off notes can be applied to the attendance in one click.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll/attendance', ['from' => $from, 'to' => $to]) ?>">⏱ Attendance</a>
    <?php if (can('payroll.manage')): ?><button class="btn btn-primary" data-open="dlg-note" data-reset>+ Add note</button><?php endif; ?>
  </div>
</div>

<form class="filters" method="get">
  <label class="field"><span class="field-label">Cut-off</span>
    <select class="input" onchange="const [f,t]=this.value.split('|'); this.form.from.value=f; this.form.to.value=t; this.form.submit()">
      <?php foreach ($cutoffs as $c): ?><option value="<?= e($c['from'] . '|' . $c['to']) ?>" <?= $c['from'] === $from && $c['to'] === $to ? 'selected' : '' ?>><?= e(range_label($c['from'], $c['to'])) ?></option><?php endforeach; ?>
      <?php if (!array_filter($cutoffs, fn ($c) => $c['from'] === $from && $c['to'] === $to)): ?><option selected>Custom: <?= e(range_label($from, $to)) ?></option><?php endif; ?>
    </select></label>
  <label class="field"><span class="field-label">From</span><input class="input" type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="field"><span class="field-label">To</span><input class="input" type="date" name="to" value="<?= e($to) ?>"></label>
  <label class="field"><span class="field-label">Employee</span><select class="input" name="employee_id"><?= options(array_column($employees, 'full_name', 'id'), $empId, 'All employees') ?></select></label>
  <button class="btn" type="submit">Show</button>
</form>

<?php if ($summary): ?>
<div class="card mb">
  <div class="card-head"><h3>Per employee · <?= e(range_label($from, $to)) ?></h3></div>
  <table class="table dense">
    <thead><tr><th>Employee</th><th class="right">Absent (days)</th><th class="right">Leave (days)</th><th class="right">Day off swaps</th><th class="right">Undertime (h)</th><th class="right">Late (h)</th><th class="right">Other</th></tr></thead>
    <tbody>
    <?php foreach ($summary as $s): ?>
      <tr><td><b><?= e($s['employee']) ?></b></td>
        <td class="right <?= !empty($s['absent']) ? 'text-red' : '' ?>"><?= $h($s['absent'] ?? 0) ?></td><td class="right"><?= $h($s['leave'] ?? 0) ?></td>
        <td class="right"><?= $h($s['dayoff'] ?? 0) ?></td><td class="right"><?= $h($s['undertime'] ?? 0) ?></td><td class="right"><?= $h($s['late'] ?? 0) ?></td><td class="right"><?= $h($s['other'] ?? 0) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'page' => 25, 'empty' => 'No notes in this period.']) ?></div>

<?php if (can('payroll.manage')): ?>
<dialog class="modal" id="dlg-note">
  <form method="post" action="<?= url('/payroll/notes') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Add a staff note</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Employee *</span><select class="input" name="employee_id" required><?= options(array_column($employees, 'full_name', 'id'), '', 'Select…') ?></select></label>
      <label class="field"><span class="field-label">Date *</span><input class="input" type="date" name="note_date" value="<?= e(min(today(), $to)) ?>" required></label>
      <label class="field"><span class="field-label">What happened *</span><select class="input" name="kind" required><?= options(StaffNotes::KINDS, 'absent') ?></select></label>
      <label class="field"><span class="field-label">Hours</span><input class="input num" type="number" step="0.25" min="0" max="24" name="hours"><span class="field-hint">For undertime / late</span></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Reason / note</span><input class="input" name="note" maxlength="255"></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
<?php endif; ?>
