<?php /** Match device users to employees before importing. Variables: $imp, $people, $employees */
$p = $imp['parsed'];
$empOptions = array_column(array_map(fn ($e) => ['id' => $e['id'], 'l' => $e['full_name'] . ($e['biometric_id'] !== null && $e['biometric_id'] !== '' ? ' (device #' . $e['biometric_id'] . ')' : '')], $employees), 'l', 'id');
?>
<div class="page-header">
  <div>
    <h1>Import attendance</h1>
    <p class="muted"><?= e($imp['file']) ?> · <?= e($p['format']) ?> · <b><?= e(range_label($p['from'], $p['to'])) ?></b> · <?= count($people) ?> device user(s)</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/payroll/attendance') ?>">Cancel</a></div>
</div>

<div class="alert alert-info">Match each device user to an employee. The match is remembered (employee's device ID), so next time this step is automatic.
  Choose <b>New employee</b> to add them, or <b>Skip</b> to leave them out.</div>

<form method="post" action="<?= url('/payroll/attendance/import') ?>">
  <?= csrf_field() ?>
  <div class="card">
    <table class="table">
      <thead><tr><th>Device ID</th><th>Name on device</th><th class="right">Days with punches</th><th>Employee</th></tr></thead>
      <tbody>
      <?php foreach ($people as $x): ?>
        <tr>
          <td class="bold">#<?= e($x['device_id']) ?></td>
          <td><?= e($x['name']) ?></td>
          <td class="right"><?= (int) $x['days'] ?: '<span class="muted">none</span>' ?></td>
          <td>
            <select class="input" name="map[<?= e($x['device_id']) ?>]" style="min-width:260px">
              <option value="skip" <?= !$x['employee_id'] && !$x['days'] ? 'selected' : '' ?>>— Skip —</option>
              <option value="new" <?= !$x['employee_id'] && $x['days'] ? 'selected' : '' ?>>+ New employee “<?= e($x['name']) ?>”</option>
              <?php foreach ($empOptions as $id => $label): ?>
                <option value="<?= (int) $id ?>" <?= (int) $x['employee_id'] === (int) $id ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($x['matched_by'] === 'name'): ?><span class="muted small">suggested by name</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="row gap-sm mt"><button class="btn btn-primary btn-lg" type="submit">Import attendance</button>
    <span class="muted small">Days you already fixed by hand are not overwritten.</span></div>
</form>
