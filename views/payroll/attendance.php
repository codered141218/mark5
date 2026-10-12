<?php
/** Attendance grid + upload. Variables: $from, $to, $grid, $cfg, $imports, $unmapped, $notes (staff notes by employee and day) */
use App\Services\Payroll\Attendance;
use App\Services\Payroll\StaffNotes;

$dates = [];
for ($d = $from; $d <= $to; $d = add_days($d, 1)) $dates[] = $d;
$h = fn ($m) => $m ? intdiv((int) $m, 60) . ':' . str_pad((string) ((int) $m % 60), 2, '0', STR_PAD_LEFT) : '';
?>
<div class="page-header">
  <div>
    <h1>Attendance</h1>
    <p class="muted">Time in / out from the biometric device. Shift starts <?= e($cfg['shift_start']) ?>, <?= (float) $cfg['shift_hours'] ?> hours a day —
      <a href="<?= url('/payroll/settings') ?>">settings</a>. Click a day to fix a missing punch or mark a day off / leave.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll') ?>">← Payroll</a>
    <?php if (can('payroll.manage')): ?><button class="btn btn-primary" data-open="dlg-upload" data-reset>⬆ Upload from device</button><?php endif; ?>
  </div>
</div>

<form class="filters" method="get">
  <label class="field"><span class="field-label">From</span><input class="input" type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="field"><span class="field-label">To</span><input class="input" type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="btn" type="submit">Show</button>
  <span class="grow"></span>
  <span class="att-legend"><i class="att-k ok"></i>present <i class="att-k late"></i>late / undertime <i class="att-k bad"></i>absent / one punch <i class="att-k rest"></i>day off / leave <i class="att-note-k"></i><a href="<?= url('/payroll/notes', ['from' => $from, 'to' => $to]) ?>">staff note</a></span>
</form>

<?php if ($unmapped && can('payroll.manage')): ?>
  <div class="alert alert-info"><?= (int) $unmapped ?> active employee(s) are not linked to a device user yet — they are linked when you upload the device file.</div>
<?php endif; ?>

<div class="card att-card">
  <div class="att-scroll">
    <table class="att-grid">
      <thead>
        <tr>
          <th class="att-name">Employee</th>
          <?php foreach ($dates as $d): ?><th class="<?= in_array(date('N', strtotime($d)), ['6', '7'], true) ? 'we' : '' ?>"><?= date('D', strtotime($d)) ?><br><b><?= date('j', strtotime($d)) ?></b></th><?php endforeach; ?>
          <th>Present</th><th>Absent</th><th>Late</th><th>UT</th><th>OT</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($grid as $g): $e = $g['employee']; ?>
        <tr>
          <th class="att-name"><?= e($e['full_name']) ?><div class="muted small"><?= $e['biometric_id'] !== null && $e['biometric_id'] !== '' ? 'device #' . e($e['biometric_id']) : 'not linked' ?><?= $g['off'] ? ' · ' . e($g['off']) . ' off' : '' ?></div></th>
          <?php foreach ($g['days'] as $d):
              $st = $d['status'];
              $cls = str_starts_with($st, 'Present') ? ($d['late'] || $d['ut'] ? 'late' : 'ok') : ((str_starts_with($st, 'Day off') && $st !== 'Day off (unpaid)') || $st === 'Paid leave' ? 'rest' : 'bad');
              $dn = $notes[(int) $e['id']][$d['date']] ?? [];
              $tip = date('D M j', strtotime($d['date'])) . ' · ' . $st . ($d['hours'] ? ' · ' . $h($d['hours']) . ' h' : '') . ($d['late'] ? ' · late ' . $d['late'] . ' min' : '') . ($d['ot'] ? ' · OT ' . $h($d['ot']) : '') . ($d['note'] ? ' · ' . $d['note'] : '')
                  . implode('', array_map(fn ($n) => "\n✎ " . StaffNotes::label($n) . ($n['status'] === 'applied' ? ' (applied)' : ''), $dn)); ?>
            <td class="att-c <?= $cls ?><?= $dn ? ' has-note' : '' ?>" title="<?= e($tip) ?>"
                <?php if (can('payroll.manage')): ?>data-day='<?= e(json_encode(['employee_id' => $e['id'], 'name' => $e['full_name'], 'work_date' => $d['date'], 'time_in' => $d['in'], 'time_out' => $d['out'], 'note' => $d['note'], 'type' => $d['type'] ?? 'auto', 'label' => date('D, M j, Y', strtotime($d['date']))])) ?>'<?php endif; ?>>
              <?php if ($d['in'] || $d['out']): ?><span><?= e($d['in'] ?? '—') ?></span><span><?= e($d['out'] ?? '—') ?></span>
              <?php else: ?><span class="att-st"><?= e($st === 'Absent' ? '✕' : ($st === 'Paid leave' ? 'leave' : (str_starts_with($st, 'Day off') ? 'off' : ($st === 'Present' ? '✓' : '')))) ?></span><?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="num"><b><?= (float) $g['sum']['days_present'] ?></b></td>
          <td class="num <?= $g['sum']['days_absent'] ? 'text-red' : '' ?>"><?= (float) $g['sum']['days_absent'] ?></td>
          <td class="num"><?= $g['sum']['late_minutes'] ? $g['sum']['late_minutes'] . 'm' : '' ?></td>
          <td class="num"><?= (float) $g['sum']['undertime_hours'] ?: '' ?></td>
          <td class="num"><?= (float) $g['sum']['ot_hours'] ?: '' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$grid): ?><tr><td colspan="<?= count($dates) + 6 ?>" class="empty">No active employees.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($imports): ?>
  <div class="muted small mt">Last uploads:
    <?php foreach ($imports as $i): ?><span class="nowrap"> · <?= e($i['file_name'] ?: 'file') ?> (<?= e(range_label($i['period_from'], $i['period_to'])) ?>, <?= (int) $i['employees'] ?> employees, <?= e(fmt_datetime($i['created_at'])) ?>)</span><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (can('payroll.manage')): ?>
<dialog class="modal" id="dlg-upload">
  <form method="post" action="<?= url('/payroll/attendance/upload') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Upload attendance from the device</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <p class="muted">On the ZKTeco device: <b>Menu → USB Manager → Download → Attendance</b> (or Report). Upload the file it saves —
        <code>1_report_1.xls</code> or <code>1_attlog.dat</code>. A CSV / Excel list with user ID, date and time also works.</p>
      <label class="field"><span class="field-label">File *</span><input class="input" type="file" name="file" accept=".xls,.xlsx,.dat,.txt,.csv" required></label>
      <p class="muted small">Next you match each device user to an employee (only the first time). Days you fixed by hand are kept.</p>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Read file</button></div>
  </form>
</dialog>
<dialog class="modal" id="dlg-day">
  <form method="post" action="<?= url('/payroll/attendance/day') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="employee_id"><input type="hidden" name="work_date">
    <div class="modal-head"><h3 data-day-title>Attendance</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Time in</span><input class="input" type="time" name="time_in"></label>
      <label class="field"><span class="field-label">Time out</span><input class="input" type="time" name="time_out"></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Day</span><select class="input" name="day_type"><?= options(Attendance::DAY_TYPES, 'auto') ?></select>
        <span class="field-hint">"From the device": present when both times are filled. Day off / paid leave are never deducted.</span></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Note</span><input class="input" name="note" placeholder="e.g. forgot to punch out, approved by manager"></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
<script>
  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-day]');
    if (!c) return;
    const d = JSON.parse(c.dataset.day);
    const dlg = document.getElementById('dlg-day');
    const f = dlg.querySelector('form');
    f.employee_id.value = d.employee_id; f.work_date.value = d.work_date;
    f.time_in.value = d.time_in || ''; f.time_out.value = d.time_out || ''; f.note.value = d.note || ''; f.day_type.value = d.type || 'auto';
    dlg.querySelector('[data-day-title]').textContent = d.name + ' · ' + d.label;
    dlg.showModal();
  });
</script>
<?php endif; ?>
