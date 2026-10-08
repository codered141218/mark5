<?php /** Petty cash list. Variables: $from, $to, $rows, $columns, $stats, $fundBalance, $expenseAccounts, $banks */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Petty Cash</h1>
    <p class="muted">Small cash expenses paid from the petty cash box or the POS cash drawer. Every entry is posted to the general ledger automatically.</p>
  </div>
  <div class="page-actions">
    <?php if (can('pettycash.manage')): ?>
      <button class="btn" data-open="dlg-replenish" data-reset>Replenish fund</button>
    <?php endif; ?>
    <?php if (can('pettycash.manage', 'pos.petty_cash')): ?>
      <button class="btn btn-primary" data-open="dlg-expense" data-reset>+ Record expense</button>
    <?php endif; ?>
  </div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to], null) ?>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Petty cash fund balance', 'value' => peso($fundBalance), 'sub' => 'Current balance (all dates)', 'tone' => $fundBalance < 0 ? 'red' : 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Expenses from fund', 'value' => peso($stats['fund']), 'sub' => 'Paid from the petty cash box'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Replenishments', 'value' => peso($stats['replenish']), 'sub' => 'Added to the fund', 'tone' => 'green'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Drawer payouts', 'value' => peso($stats['drawer']), 'sub' => 'Paid from the POS cash drawer', 'tone' => 'amber'], null) ?>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No petty cash transactions in this period.']) ?></div>

<dialog class="modal" id="dlg-expense">
  <form method="post" action="<?= url('/petty-cash') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="txn_type" value="expense">
    <div class="modal-head"><h3>Record petty cash expense</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="txn_date" value="<?= e(today()) ?>">
        <span class="field-hint">Drawer payouts use the open business day.</span></label>
      <label class="field"><span class="field-label">Paid from</span>
        <select class="input" name="source"><?= options(['fund' => 'Petty cash box (fund)', 'drawer' => 'POS cash drawer']) ?></select></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Expense account *</span>
        <?= view('finance/partials/account_select', ['name' => 'account_id', 'groups' => $expenseAccounts, 'required' => true], null) ?></label>
      <label class="field"><span class="field-label">Payee</span><input class="input" name="payee" placeholder="e.g. Shell, Puregold, tricycle"></label>
      <label class="field"><span class="field-label">OR / receipt no</span><input class="input" name="or_no"></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. LPG refill, ice, dishwashing soap"></label>
      <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" name="amount" required></label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save expense</button></div>
  </form>
</dialog>

<?php if (can('pettycash.manage')): ?>
<dialog class="modal" id="dlg-replenish">
  <form method="post" action="<?= url('/petty-cash') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="txn_type" value="replenish">
    <input type="hidden" name="source" value="fund">
    <div class="modal-head"><h3>Replenish petty cash fund</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="txn_date" value="<?= e(today()) ?>"></label>
      <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" name="amount" required></label>
      <?= view('finance/partials/pay_method', ['name' => 'from_method', 'label' => 'Money comes from', 'methods' => ['cash' => 'Cash on hand', 'bank' => 'Bank account'], 'banks' => $banks], null) ?>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Notes</span><input class="input" name="description" placeholder="e.g. Check no. / withdrawal slip"></label>
    </div>
    <p class="muted small" style="padding: 0 18px">Posts Dr Petty Cash Fund / Cr Cash on Hand or the selected bank.</p>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Replenish</button></div>
  </form>
</dialog>
<?php endif; ?>
