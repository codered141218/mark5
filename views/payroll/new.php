<?php /** New payroll. Variables: $suggest, $employees, $months */ ?>
<div class="page-header">
  <div>
    <h1>New payroll</h1>
    <p class="muted">Choose the cut-off. Pay is computed from the attendance of that period, the employees' rates and their standing deductions and cash advances.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/payroll') ?>">← Payroll</a></div>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.1fr) minmax(0,1fr)">
  <div class="card">
    <div class="card-head"><h3>Cut-off</h3></div>
    <form method="post" action="<?= url('/payroll') ?>" class="card-body">
      <?= csrf_field() ?>
      <div class="cutoff-pick mb">
        <?php foreach ($months as $c): $on = $c['from'] === $suggest['from']; ?>
          <button type="button" class="seg-btn<?= $on ? ' on' : '' ?>" data-cutoff='<?= e(json_encode($c)) ?>'>
            <b><?= e(range_label($c['from'], $c['to'])) ?></b><small>pay-out <?= e(fmt_date($c['pay_date'])) ?></small></button>
        <?php endforeach; ?>
      </div>
      <div class="form-grid">
        <label class="field"><span class="field-label">From *</span><input class="input" type="date" name="period_from" value="<?= e($suggest['from']) ?>" required></label>
        <label class="field"><span class="field-label">To *</span><input class="input" type="date" name="period_to" value="<?= e($suggest['to']) ?>" required></label>
        <label class="field"><span class="field-label">Pay-out date *</span><input class="input" type="date" name="pay_date" value="<?= e($suggest['pay_date']) ?>" required></label>
        <label class="field"><span class="field-label">Notes</span><input class="input" name="notes" maxlength="255"></label>
      </div>
      <div class="mt"><button class="btn btn-primary btn-lg" type="submit">Compute payroll</button></div>
    </form>
  </div>
  <div class="card">
    <div class="card-head"><h3>Employees</h3><a class="small" href="<?= url('/employees') ?>">Edit rates →</a></div>
    <table class="table dense">
      <thead><tr><th>Employee</th><th>Pay</th><th class="right">Rate</th><th>Device ID</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $e): $rate = $e['pay_type'] === 'daily' ? (float) $e['daily_rate'] : (float) $e['monthly_rate']; ?>
        <tr class="<?= $rate > 0 ? '' : 'muted-row' ?>">
          <td><?= e($e['full_name']) ?><div class="muted small"><?= e($e['position']) ?></div></td>
          <td><?= $e['pay_type'] === 'daily' ? 'Daily' : 'Monthly' ?></td>
          <td class="right"><?= $rate > 0 ? money($rate) : '<span class="text-amber small">no rate — not included</span>' ?></td>
          <td><?= $e['biometric_id'] !== null && $e['biometric_id'] !== '' ? e($e['biometric_id']) : '<span class="text-amber small">not linked</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$employees): ?><tr><td colspan="4" class="empty">No employees yet — add them under People → Employees.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
  document.querySelectorAll('[data-cutoff]').forEach((b) => b.addEventListener('click', () => {
    const c = JSON.parse(b.dataset.cutoff);
    const f = b.closest('form');
    f.period_from.value = c.from; f.period_to.value = c.to; f.pay_date.value = c.pay_date;
    document.querySelectorAll('[data-cutoff]').forEach((x) => x.classList.toggle('on', x === b));
  }));
</script>
