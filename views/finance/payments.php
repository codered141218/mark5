<?php /** Payments & expenses. Variables: $from, $to, $source, $rows, $columns, $bySource, $total, $accounts, $banks, $suppliers */
use App\Core\Table; use App\Services\Finance\Disbursements; ?>
<div class="page-header">
  <div>
    <h1>Payments &amp; Expenses</h1>
    <p class="muted">Record money paid out — rent, electricity, salaries, supplies, a loan payment, owner's drawings, equipment —
      from cash on hand, the petty cash fund or a bank. Each payment is posted to the books automatically.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= url('/finance/cash-position') ?>">◈ Cash &amp; bank position</a>
    <?php if (can('finance.journal', 'finance.banks', 'pettycash.manage')): ?>
      <button class="btn btn-primary" data-open="dlg-payment" data-reset>+ Record payment / expense</button>
    <?php endif; ?>
  </div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to], null) ?>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => 'Total paid', 'value' => peso($total), 'sub' => range_label($from, $to), 'tone' => 'brand'], null) ?>
  <?php foreach (Disbursements::SOURCES as $k => $label): ?>
    <?= view('finance/partials/stat', ['label' => 'From ' . strtolower($label), 'value' => peso($bySource[$k] ?? 0), 'sub' => ''], null) ?>
  <?php endforeach; ?>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No payments in this period.']) ?></div>
<p class="muted small mt">Paying a supplier's bill? Use <a href="<?= url('/finance/payables') ?>">Accounts Payable</a> so the bill is marked paid.
  Small cash purchases can also go through <a href="<?= url('/petty-cash') ?>">Petty Cash</a>.</p>

<dialog class="modal wide" id="dlg-payment">
  <form method="post" action="<?= url('/finance/payments') ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Record payment / expense</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="txn_date" value="<?= e(today()) ?>"></label>
      <label class="field"><span class="field-label">Amount paid (₱) *</span><input class="input num" type="number" step="0.01" min="0.01" name="amount" required></label>
      <?= view('finance/partials/pay_method', ['name' => 'pay_from', 'label' => 'Paid from', 'methods' => Disbursements::SOURCES, 'banks' => $banks], null) ?>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">What it was for (account) *</span>
        <?= view('finance/partials/account_select', ['name' => 'account_id', 'groups' => $accounts, 'required' => true], null) ?>
        <span class="field-hint">Usually an expense (Rent, Electricity, Salaries …). Also: Equipment, Loans Payable, Owner's Drawings …</span></label>
      <label class="field"><span class="field-label">Paid to</span><input class="input" name="payee" placeholder="e.g. Meralco, landlord, Juan"></label>
      <label class="field"><span class="field-label">Supplier (optional)</span><select class="input" name="supplier_id"><?= options($suppliers, null, '— None —') ?></select></label>
      <label class="field"><span class="field-label">OR / invoice / check no.</span><input class="input" name="reference"></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. October rent, Meralco bill Sept"></label>
      <label class="checkbox" style="grid-column: 1 / -1"><input type="checkbox" name="with_vat" value="1"> The receipt shows VAT (claim the 12% input VAT)</label>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save payment</button></div>
  </form>
</dialog>
