<?php
/** Cash advance detail. Variables: $c, $repayments, $columns, $banks */
use App\Core\Table;
use App\Services\Finance\CashAdvances;
$canCancel = $c['status'] === 'pending' && can('ca.request') && ((int) $c['requested_by'] === user()['id'] || can('ca.approve'));
?>
<div class="page-header">
  <div>
    <h1>Cash advance <?= e($c['doc_no']) ?> <?= badge($c['status']) ?></h1>
    <p class="muted"><a href="<?= url('/cash-advances') ?>">← Cash advances</a></p>
  </div>
  <div class="page-actions">
    <?php if ($canCancel): ?>
      <form method="post" action="<?= url("/cash-advances/{$c['id']}/cancel") ?>" class="inline" data-confirm="Cancel this cash advance request?" data-danger data-ok="Cancel request">
        <?= csrf_field() ?><button class="btn" type="submit">Cancel request</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mb"><div class="card-body grid-2">
  <dl class="kv">
    <dt>Employee</dt><dd class="bold"><?= e($c['employee_name']) ?><?= $c['emp_no'] ? ' <span class="muted">· ' . e($c['emp_no']) . '</span>' : '' ?></dd>
    <dt>Request date</dt><dd><?= e(fmt_date($c['request_date'])) ?></dd>
    <dt><?= $c['status'] === 'pending' ? 'Requested amount' : 'Amount' ?></dt><dd><?= peso($c['amount']) ?></dd>
    <?php if (in_array($c['status'], ['approved', 'settled'], true)): ?><dt>Balance</dt><dd class="bold"><?= peso($c['balance']) ?></dd><?php endif; ?>
    <dt>Reason</dt><dd><?= e($c['reason'] ?: '—') ?></dd>
    <dt>Repayment terms</dt><dd><?= e($c['repayment_terms'] ?: '—') ?></dd>
  </dl>
  <dl class="kv">
    <dt>Requested by</dt><dd><?= e($c['requested_by_name'] ?: '—') ?></dd>
    <?php if ($c['approved_by']): ?>
      <dt><?= $c['status'] === 'rejected' ? 'Rejected by' : 'Approved by' ?></dt><dd><?= e($c['approved_by_name'] . ' · ' . fmt_datetime($c['approved_at'])) ?></dd>
    <?php endif; ?>
    <?php if ($c['release_date']): ?>
      <dt>Released</dt><dd><?= e(fmt_date($c['release_date']) . ' · ' . (CashAdvances::RELEASE_METHODS[$c['release_method']] ?? $c['release_method']) . ($c['bank_name'] ? ' · ' . $c['bank_name'] : '')) ?></dd>
    <?php endif; ?>
    <?php if ($c['remarks']): ?><dt>Remarks</dt><dd><?= e($c['remarks']) ?></dd><?php endif; ?>
    <?php if ($c['journal_entry_id'] && can('finance.view', 'finance.journal')): ?>
      <dt>Journal entry</dt><dd><a href="<?= url('/finance/journals/' . $c['journal_entry_id']) ?>">View posting</a></dd>
    <?php endif; ?>
  </dl>
</div></div>

<?php if ($c['status'] === 'pending' && can('ca.approve')): ?>
  <div class="card mb">
    <div class="card-head"><h3>Approve &amp; release</h3>
      <form method="post" action="<?= url("/cash-advances/{$c['id']}/reject") ?>" class="inline" data-confirm="Reject <?= e($c['doc_no']) ?>? The employee will see this request as rejected."
            data-prompt="Remarks" data-prompt-name="remarks" data-danger data-ok="Reject">
        <?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Reject</button>
      </form>
    </div>
    <form method="post" action="<?= url("/cash-advances/{$c['id']}/approve") ?>" class="card-body"
          data-confirm="Approve and release this cash advance to <?= e($c['employee_name']) ?>? This posts to the general ledger." data-ok="Approve &amp; release">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field"><span class="field-label">Approved amount (₱)</span>
          <input class="input" type="number" step="0.01" min="0.01" name="amount" placeholder="<?= e($c['amount']) ?>">
          <span class="field-hint">Requested <?= peso($c['amount']) ?> (blank = approve as requested)</span></label>
        <label class="field"><span class="field-label">Release date</span><input class="input" type="date" name="release_date" value="<?= e(today()) ?>"></label>
        <?= view('finance/partials/pay_method', ['name' => 'release_method', 'label' => 'Released from', 'methods' => CashAdvances::RELEASE_METHODS, 'banks' => $banks], null) ?>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Remarks</span><input class="input" name="remarks"></label>
      </div>
      <div class="alert alert-info mt">Approval posts to the GL: <b>Dr Advances to Employees / Cr Cash on Hand, Petty Cash Fund or the bank</b> (the release source above).</div>
      <div class="row" style="justify-content: flex-end"><button class="btn btn-success" type="submit">Approve &amp; release</button></div>
    </form>
  </div>
<?php endif; ?>

<?php if ($c['status'] === 'approved' && can('ca.manage')): ?>
  <div class="card mb">
    <div class="card-head"><h3>Record repayment</h3></div>
    <form method="post" action="<?= url("/cash-advances/{$c['id']}/repay") ?>" class="card-body">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="pay_date" value="<?= e(today()) ?>"></label>
        <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" max="<?= e($c['balance']) ?>" name="amount" required>
          <span class="field-hint">Balance <?= peso($c['balance']) ?></span></label>
        <?= view('finance/partials/pay_method', ['label' => 'Repaid via', 'methods' => CashAdvances::REPAY_METHODS, 'banks' => $banks], null) ?>
        <label class="field"><span class="field-label">Reference</span><input class="input" name="reference" placeholder="e.g. Payroll Oct 1–15 / OR no"></label>
      </div>
      <p class="muted small">Salary deduction posts Dr Salaries Payable / Cr Advances to Employees.</p>
      <div class="row" style="justify-content: flex-end"><button class="btn btn-primary" type="submit">Record repayment</button></div>
    </form>
  </div>
<?php endif; ?>

<?php if ($repayments || in_array($c['status'], ['approved', 'settled'], true)): ?>
  <h3 class="mb">Repayments</h3>
  <div class="card"><?= Table::html($columns, $repayments, ['export' => true, 'search' => false, 'empty' => 'No repayments yet.']) ?></div>
<?php endif; ?>
