<?php
/** Attendance from ZKTeco devices. Variables: $rows, $columns, $from, $to, $employeeId, $employees, $stats, $uploadLimit */
use App\Core\Table;
?>
<div class="page-header">
  <div>
    <h1>Attendance (ZKTeco)</h1>
    <p class="muted">Punches imported from the biometric device. First punch = time in, last punch = time out; hours and overtime feed the payroll.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll') ?>">₱ Payroll</a>
    <button class="btn btn-primary" data-open="dlg-import" data-reset>⇪ Import from ZKTeco</button>
  </div>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Employees with punches', 'value' => (string) $stats['employees'], 'sub' => range_label($from, $to), 'tone' => 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Days logged', 'value' => number_format($stats['days']), 'sub' => number_format($stats['hours'], 1) . ' hours'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Without a biometric ID', 'value' => (string) $stats['unmatched'], 'sub' => 'active employees not linked to the device', 'tone' => $stats['unmatched'] ? 'amber' : 'green'], null) ?>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['employee_id'], 'extra' =>
    '<label class="field"><span class="field-label">Employee</span><select class="input" name="employee_id">' . options(array_column($employees, 'full_name', 'id'), $employeeId, 'All employees') . '</select></label>'], null) ?>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No punches in this period. Export the attendance log from the ZKTeco device (USB: attlog.dat) or from ZKTime / BioTime (CSV) and import it.']) ?>
</div>

<dialog class="modal" id="dlg-import">
  <form method="post" action="<?= url('/payroll/attendance/import') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Import attendance from ZKTeco</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <ol class="muted small" style="margin:0 0 12px; padding-left:18px; line-height:1.6">
        <li>On the device: <b>Menu → USB Manager → Download → Attendance data</b> (writes <code>1_attlog.dat</code> to the USB stick),</li>
        <li>or in ZKTime / ZKAccess / BioTime: export the <b>attendance log</b> as CSV.</li>
        <li>Each employee’s <b>Biometric ID</b> (People → Employees) must match the user ID enrolled on the device.</li>
      </ol>
      <div class="form-grid">
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Attendance file (.dat / .csv / .txt)</span>
          <input class="input" type="file" name="attlog" accept=".dat,.csv,.txt,.tsv,text/plain,text/csv"><span class="field-hint">Up to <?= e($uploadLimit) ?></span></label>
        <label class="field"><span class="field-label">Device (optional)</span><input class="input" name="device" placeholder="e.g. K40 – kitchen door"></label>
      </div>
      <details class="mt"><summary class="muted small">…or paste the log lines</summary>
        <textarea class="input mt" name="pasted" rows="4" placeholder="7	2026-10-01 07:58:12	1	0	0"></textarea></details>
      <div class="alert alert-info mt" style="margin-bottom:0">Punches already on file are skipped, so the same export can be imported again safely.</div>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Import</button></div>
  </form>
</dialog>
