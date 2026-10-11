<?php
/** One payroll run. Variables: $r, $slips, $columns, $banks, $unpaid */
use App\Core\Table;
use App\Services\Payroll\Payroll;

$status = $r['status'];
$tone = ['draft' => 'amber', 'posted' => 'blue', 'paid' => 'green', 'void' => 'gray'][$status] ?? 'gray';
$unpaidTotal = array_sum(array_map(fn ($s) => (float) $s['net_pay'], $unpaid));
?>
<div class="page-header">
  <div>
    <h1>Payroll <?= e($r['run_no']) ?> <?= badge(Payroll::STATUSES[$status] ?? $status, $tone) ?></h1>
    <p class="muted">Cut-off <b><?= e(range_label($r['period_from'], $r['period_to'])) ?></b> · pay-out <b><?= e(fmt_date($r['pay_date'])) ?></b>
      <?php if ($r['posted_at']): ?> · approved by <?= e($r['posted_by_name']) ?> <?= e(fmt_datetime($r['posted_at'])) ?><?php endif; ?>
      <?php if ($r['notes']): ?> · <?= e($r['notes']) ?><?php endif; ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/payroll') ?>">← Payroll</a>
    <a class="btn" href="<?= url('/payroll/' . $r['id'] . '/payslips') ?>" target="_blank">⎙ Payslips</a>
    <?php if ($status === 'draft' && can('payroll.manage')): ?>
      <form method="post" action="<?= url('/payroll/' . $r['id'] . '/recompute') ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" title="After fixing attendance or rates">↻ Recompute</button></form>
    <?php endif; ?>
    <?php if ($status === 'draft' && can('payroll.post')): ?>
      <form method="post" action="<?= url('/payroll/' . $r['id'] . '/post') ?>" class="inline" data-confirm="Approve payroll <?= e($r['run_no']) ?>? It is posted to the books (<?= e(money($r['total_net'])) ?> net pay), cash advance deductions are recorded and employees can see their payslips. It can no longer be edited.">
        <?= csrf_field() ?><button class="btn btn-primary" type="submit">✓ Approve payroll</button></form>
    <?php endif; ?>
    <?php if (in_array($status, ['posted'], true) && can('payroll.post') && $unpaid): ?>
      <button class="btn btn-success" data-open="dlg-payout" data-reset>₱ Pay out salaries</button>
    <?php endif; ?>
  </div>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Net pay', 'value' => peso($r['total_net']), 'sub' => count($slips) . ' employee(s)', 'tone' => 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Gross pay', 'value' => peso($r['total_gross']), 'sub' => 'basic − absences − undertime + OT + bonuses'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Deductions', 'value' => peso($r['total_deductions']), 'sub' => 'cash advances, SSS, PhilHealth, Pag-IBIG, tax, other'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Still to pay out', 'value' => peso($status === 'draft' ? $r['total_net'] : $unpaidTotal),
      'sub' => $status === 'draft' ? 'approve first' : count($unpaid) . ' employee(s)', 'tone' => $status === 'paid' ? 'green' : ($unpaid ? 'amber' : '')], null) ?>
</div>

<?php if ($status === 'draft'): ?>
  <div class="alert alert-info">Draft — click an employee to see the day-by-day attendance and to add <b>bonuses</b> or <b>deductions</b>.
    Fixed a time in <a href="<?= url('/payroll/attendance', ['from' => $r['period_from'], 'to' => $r['period_to']]) ?>">Attendance</a>? Press <b>Recompute</b>.</div>
<?php endif; ?>

<div class="card"><?= Table::html($columns, $slips, ['export' => true, 'link' => fn ($s) => url('/payroll/payslips/' . $s['id']), 'empty' => 'No employees in this payroll.']) ?></div>

<div class="row gap-sm mt">
  <?php if ($status === 'draft' && can('payroll.manage')): ?>
    <form method="post" action="<?= url('/payroll/' . $r['id'] . '/delete') ?>" class="inline" data-confirm="Delete this draft payroll?"><?= csrf_field() ?><button class="btn btn-ghost text-red" type="submit">Delete draft</button></form>
  <?php endif; ?>
  <?php if ($status === 'posted' && can('payroll.post') && count($unpaid) === count(array_filter($slips, fn ($s) => (float) $s['net_pay'] > 0))): ?>
    <button class="btn btn-ghost text-red" data-open="dlg-void" data-reset>Void payroll</button>
  <?php endif; ?>
  <?php if ($r['journal_entry_id'] && can('finance.view')): ?>
    <a class="btn btn-ghost" href="<?= url('/finance/journals/' . $r['journal_entry_id']) ?>">Journal entry →</a>
  <?php endif; ?>
</div>

<?php if ($unpaid): ?>
<dialog class="modal wide" id="dlg-payout">
  <form method="post" action="<?= url('/payroll/' . $r['id'] . '/payout') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Pay out salaries</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <?= view('finance/partials/pay_method', ['name' => 'method', 'label' => 'Paid from', 'methods' => Payroll::PAY_METHODS, 'banks' => $banks], null) ?>
        <label class="field"><span class="field-label">Date paid</span><input class="input" type="date" name="pay_date" value="<?= e(today()) ?>"></label>
        <label class="field"><span class="field-label">Reference</span><input class="input" name="reference" placeholder="e.g. check no. / transfer ref"></label>
      </div>
      <div class="row between mt mb-sm"><b>Employees</b><label class="checkbox small"><input type="checkbox" data-check-all checked> all</label></div>
      <div class="payout-list">
        <?php foreach ($unpaid as $s): ?>
          <label class="checkbox payout-row"><input type="checkbox" name="payslips[]" value="<?= (int) $s['id'] ?>" data-amount="<?= e($s['net_pay']) ?>" checked>
            <span class="grow"><?= e($s['full_name']) ?></span><b class="mono"><?= money($s['net_pay']) ?></b></label>
        <?php endforeach; ?>
      </div>
      <div class="row between mt bold"><span>Total to pay</span><span class="mono" data-payout-total><?= peso($unpaidTotal) ?></span></div>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-success" type="submit">Pay out</button></div>
  </form>
</dialog>
<script>
  (() => {
    const d = document.getElementById('dlg-payout');
    const boxes = () => [...d.querySelectorAll('input[name="payslips[]"]')];
    const paint = () => {
      const t = boxes().filter((b) => b.checked).reduce((s, b) => s + Number(b.dataset.amount), 0);
      d.querySelector('[data-payout-total]').textContent = '₱' + t.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    d.addEventListener('change', (e) => {
      if (e.target.matches('[data-check-all]')) boxes().forEach((b) => { b.checked = e.target.checked; });
      paint();
    });
  })();
</script>
<?php endif; ?>

<dialog class="modal" id="dlg-void">
  <form method="post" action="<?= url('/payroll/' . $r['id'] . '/void') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Void payroll <?= e($r['run_no']) ?></h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <p>The journal entry is reversed and the cash advance deductions go back on the employees' balances.</p>
      <label class="field"><span class="field-label">Reason *</span><input class="input" name="reason" required></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-danger" type="submit">Void payroll</button></div>
  </form>
</dialog>
