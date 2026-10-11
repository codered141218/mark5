<?php /** Match device users to employees before importing. Variables: $imp, $people, $employees */
$p = $imp['parsed'];
$empOptions = array_column(array_map(fn ($e) => ['id' => $e['id'], 'l' => $e['full_name'] . ($e['biometric_id'] !== null && $e['biometric_id'] !== '' ? ' (device #' . $e['biometric_id'] . ')' : '') . ($e['active'] ? '' : ' — inactive')], $employees), 'l', 'id');
$linkedTo = array_column(array_filter($employees, fn ($e) => $e['biometric_id'] !== null && $e['biometric_id'] !== ''), 'biometric_id', 'id');
?>
<div class="page-header">
  <div>
    <h1>Import attendance</h1>
    <p class="muted"><?= e($imp['file']) ?> · <?= e($p['format']) ?> · <b><?= e(range_label($p['from'], $p['to'])) ?></b> · <?= count($people) ?> device user(s)</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/payroll/attendance') ?>">Cancel</a></div>
</div>

<div class="alert alert-info">Match each device user to an employee — one device user per employee. The match is remembered (the employee's device ID),
  so next time this step is automatic. Choose <b>New employee</b> to add them, or <b>Skip</b> to leave them out.
  If someone is registered twice on the device, pick the device user who has the punches and skip the other.</div>

<form method="post" action="<?= url('/payroll/attendance/import') ?>" id="att-map" data-linked='<?= e(json_encode((object) $linkedTo)) ?>'>
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
            <?php if ($x['matched_by'] === 'name'): ?><span class="muted small" data-hint>suggested by name</span><?php elseif ($x['matched_by'] === 'device ID'): ?><span class="muted small" data-hint>linked</span><?php else: ?><span class="muted small" data-hint></span><?php endif; ?>
            <div class="small text-red" data-dup hidden></div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="row gap-sm mt"><button class="btn btn-primary btn-lg" type="submit" data-import>Import attendance</button>
    <span class="muted small">Days you already fixed by hand are not overwritten.</span></div>
</form>
<script>
  // one device user per employee: flag the same employee chosen twice, and say when a choice moves an employee's device link
  (() => {
    const form = document.getElementById('att-map');
    const linked = JSON.parse(form.dataset.linked || '{}');
    const check = () => {
      const sels = [...form.querySelectorAll('select[name^="map["]')];
      const count = {};
      sels.forEach((s) => { if (/^\d+$/.test(s.value)) count[s.value] = (count[s.value] || 0) + 1; });
      let bad = false;
      sels.forEach((s) => {
        const row = s.closest('td');
        const dup = row.querySelector('[data-dup]');
        const dev = s.name.slice(4, -1);
        const isDup = /^\d+$/.test(s.value) && count[s.value] > 1;
        bad = bad || isDup;
        dup.hidden = !isDup;
        dup.textContent = isDup ? 'This employee is chosen for another device user too' : '';
        s.classList.toggle('is-invalid', isDup);
        const was = linked[s.value];
        const hint = row.querySelector('[data-hint]');
        if (hint && was !== undefined && String(was) !== dev && !isDup) hint.textContent = 'moves from device #' + was + ' to #' + dev;
      });
      form.querySelector('[data-import]').disabled = bad;
    };
    form.addEventListener('change', check);
    check();
  })();
</script>
