<?php
/** Cash advances list. Variables: $rows, $columns, $from, $to, $status, $counts, $total, $stats, $seeAll, $employeeId, $employees, $requestFor */
use App\Core\Table;
use App\Services\Finance\CashAdvances;
?>
<div class="page-header">
  <div>
    <h1>Cash Advances</h1>
    <p class="muted"><?= $seeAll
        ? 'Employee cash advance (vale) requests, approvals, releases and repayments. Approvals and repayments are posted to the general ledger (Advances to Employees).'
        : 'File a cash advance (vale) request and follow its approval and repayment.' ?></p>
  </div>
  <?php if (can('ca.request')): ?>
    <div class="page-actions"><button class="btn btn-primary" data-open="dlg-request" data-reset>+ Request cash advance</button></div>
  <?php endif; ?>
</div>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Pending requests', 'value' => (string) $stats['pending'], 'sub' => peso($stats['pending_amount']) . ' requested · all dates', 'tone' => $stats['pending'] ? 'amber' : null], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Outstanding balance', 'value' => peso($stats['outstanding']), 'sub' => $stats['outstanding_n'] . ' approved advance(s) not yet fully repaid', 'tone' => 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Released in period', 'value' => peso($stats['released']), 'sub' => range_label($from, $to)], null) ?>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['employee_id'], 'extra' => $seeAll
    ? '<label class="field"><span class="field-label">Employee</span><select class="input" name="employee_id">' . options(array_column($employees, 'full_name', 'id'), $employeeId, 'All employees') . '</select></label>'
    : ''], null) ?>

<div class="card">
  <div class="tabs" style="padding: 0 12px; margin-bottom: 0">
    <a class="tab<?= $status === '' ? ' active' : '' ?>" href="<?= e(current_url(['status' => null])) ?>">All (<?= $total ?>)</a>
    <?php foreach (CashAdvances::STATUSES as $s => $label): ?>
      <a class="tab<?= $status === $s ? ' active' : '' ?>" href="<?= e(current_url(['status' => $s])) ?>"><?= e($label) ?> (<?= (int) ($counts[$s] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>
  <?= Table::html($columns, $rows, ['export' => true, 'link' => fn ($r) => url('/cash-advances/' . $r['id']), 'empty' => 'No cash advances in this period.']) ?>
</div>

<?php if (can('ca.request')): ?>
<dialog class="modal" id="dlg-request">
  <form method="post" action="<?= url('/cash-advances') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Request cash advance</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <?php if (!$requestFor): ?>
        <div class="alert alert-warn">Your user account is not linked to an employee record, so you cannot file a cash advance yet.
          Ask the administrator to link your user to your employee record (Users → Employee).</div>
      <?php else: ?>
        <div class="form-grid">
          <label class="field" style="grid-column: 1 / -1"><span class="field-label">Employee *</span>
            <select class="input" name="employee_id" required data-employee>
              <?php if (count($requestFor) > 1): ?><option value="">Select employee…</option><?php endif; ?>
              <?php foreach ($requestFor as $emp): ?>
                <option value="<?= e($emp['id']) ?>" data-balance="<?= e($emp['ca_balance']) ?>" data-name="<?= e($emp['full_name']) ?>"><?= e($emp['full_name'] . ($emp['position'] ? ' – ' . $emp['position'] : '')) ?></option>
              <?php endforeach; ?>
            </select></label>
          <div class="alert alert-warn hidden" style="grid-column: 1 / -1; margin-bottom: 0" data-balance-warning></div>
          <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" name="amount" required></label>
          <label class="field"><span class="field-label">Request date</span><input class="input" type="date" name="request_date" value="<?= e(today()) ?>"></label>
          <label class="field" style="grid-column: 1 / -1"><span class="field-label">Reason</span><textarea class="input" name="reason" rows="2" placeholder="e.g. Tuition fee, medical, emergency"></textarea></label>
          <label class="field" style="grid-column: 1 / -1"><span class="field-label">Repayment terms</span><input class="input" name="repayment_terms" placeholder="e.g. ₱500 per payroll"></label>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit"<?= $requestFor ? '' : ' disabled' ?>>Submit request</button></div>
  </form>
</dialog>
<script>
  // Warn when the employee still has an outstanding advance.
  document.querySelector('#dlg-request form').addEventListener('change', (e) => {
    const sel = e.currentTarget.querySelector('[data-employee]');
    const warn = e.currentTarget.querySelector('[data-balance-warning]');
    if (!sel || !warn) return;
    const opt = sel.selectedOptions[0];
    const bal = opt ? Number(opt.dataset.balance) || 0 : 0;
    warn.classList.toggle('hidden', !(bal > 0));
    if (bal > 0) warn.textContent = `${opt.dataset.name} still has an outstanding cash advance balance of ${App.peso(bal)}.`;
  });
</script>
<?php endif; ?>
