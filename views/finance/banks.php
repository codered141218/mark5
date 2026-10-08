<?php
/** Banks & e-wallets. Variables: $banks, $bankId, $rows, $columns, $from, $to, $bankOptions, $accounts */
use App\Core\Table;
use App\Services\Finance\Banks;
$manage = can('finance.banks');
$active = array_filter($banks, fn ($b) => $b['active']);
$allBanks = array_map(fn ($b) => Banks::label($b), array_column($banks, null, 'id'));
$phBanks = ['BDO', 'BPI', 'Metrobank', 'Landbank', 'PNB', 'UnionBank', 'Security Bank', 'China Bank', 'RCBC', 'EastWest', 'PSBank', 'GCash', 'Maya', 'GoTyme', 'SeaBank'];
?>
<div class="page-header">
  <div>
    <h1>Banks &amp; E-wallets</h1>
    <p class="muted">Bank and e-wallet accounts, deposits, withdrawals and transfers. Each transaction is posted to the general ledger automatically.</p>
  </div>
  <?php if ($manage): ?>
    <div class="page-actions">
      <button class="btn" data-open="dlg-bank" data-reset data-title="Add bank / e-wallet account" data-fill='{"id":"","account_type":"savings","opening_date":"<?= e(today()) ?>"}'>+ Add bank</button>
      <?php if ($active): ?>
        <button class="btn btn-success" data-open="dlg-txn" data-reset data-title="Money in (deposit)" data-fill='{"txn_type":"deposit","bank_account_id":"<?= e($bankId ?: array_key_first($bankOptions)) ?>"}'>↓ Money in</button>
        <button class="btn btn-danger" data-open="dlg-txn" data-reset data-title="Money out (withdrawal / payment)" data-fill='{"txn_type":"withdrawal","bank_account_id":"<?= e($bankId ?: array_key_first($bankOptions)) ?>"}'>↑ Money out</button>
        <?php if (count($active) > 1): ?>
          <button class="btn" data-open="dlg-txn" data-reset data-title="Transfer between banks" data-fill='{"txn_type":"transfer","bank_account_id":"<?= e($bankId ?: array_key_first($bankOptions)) ?>"}'>⇄ Transfer</button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (!$banks): ?>
  <div class="card mb"><div class="empty">No bank accounts yet. Click “Add bank” to set up your BDO, BPI, GCash, Maya… accounts with their opening balances.</div></div>
<?php else: ?>
  <div class="stats mb" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr))">
    <?php foreach ($banks as $b): $isSel = (int) $b['id'] === $bankId; ?>
      <div class="stat" style="<?= $b['active'] ? '' : 'opacity:.6;' ?><?= $isSel ? 'outline:2px solid var(--brand);' : '' ?>">
        <div class="row between">
          <a class="bold" href="<?= e(current_url(['bank_id' => $isSel ? null : $b['id']])) ?>"><?= e($b['bank_name']) ?></a>
          <span class="row gap-sm">
            <?= $b['active'] ? '' : badge('inactive') ?>
            <?= $b['account_type'] ? badge(str_replace('_', ' ', $b['account_type']), 'blue') : '' ?>
          </span>
        </div>
        <div class="stat-sub"><?= e(implode(' · ', array_filter([$b['account_name'], $b['account_no']])) ?: '—') ?></div>
        <div class="stat-value<?= $b['balance'] < 0 ? ' text-red' : '' ?>"><?= peso($b['balance']) ?></div>
        <div class="row between mt">
          <span class="muted small">GL <?= e($b['gl_code']) ?></span>
          <?php if ($manage): ?>
            <button class="btn btn-sm btn-ghost" data-open="dlg-bank" data-title="Edit <?= e($b['bank_name']) ?>"
                    data-fill='<?= e(json_encode(['id' => $b['id'], 'bank_name' => $b['bank_name'], 'account_name' => $b['account_name'], 'account_no' => $b['account_no'],
                        'account_type' => $b['account_type'], 'notes' => $b['notes'], 'active' => $b['active']])) ?>'>Edit</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?= view('finance/partials/stat', ['label' => 'Total in banks & e-wallets', 'value' => peso(array_sum(array_column($active, 'balance'))),
        'sub' => count($active) . ' active account(s)', 'tone' => 'brand'], null) ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head"><h3>Bank transactions</h3></div>
  <div class="card-body" style="padding-bottom:0">
    <?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['bank_id'], 'extra' =>
        '<label class="field"><span class="field-label">Bank</span><select class="input" name="bank_id">' . options($allBanks, $bankId, 'All banks') . '</select></label>'], null) ?>
  </div>
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No bank transactions in this period.']) ?>
</div>

<?php if ($manage): ?>
<dialog class="modal" id="dlg-bank">
  <form method="post" action="<?= url('/finance/banks') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <div class="modal-head"><h3>Bank account</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label">Bank / wallet *</span><input class="input" name="bank_name" list="ph-banks" required>
        <span class="field-hint">e.g. BDO, BPI, Metrobank, GCash, Maya</span></label>
      <datalist id="ph-banks"><?php foreach ($phBanks as $pb): ?><option value="<?= e($pb) ?>"><?php endforeach; ?></datalist>
      <label class="field"><span class="field-label">Account type</span><select class="input" name="account_type"><?= options(Banks::ACCOUNT_TYPES) ?></select></label>
      <label class="field"><span class="field-label">Account name</span><input class="input" name="account_name" placeholder="e.g. Juan's Eatery Inc."></label>
      <label class="field"><span class="field-label">Account / mobile no</span><input class="input" name="account_no"></label>
      <label class="field" data-new-only><span class="field-label">Opening balance (₱)</span><input class="input" type="number" step="0.01" name="opening_balance">
        <span class="field-hint">Current balance per bank statement / app</span></label>
      <label class="field" data-new-only><span class="field-label">Balance as of</span><input class="input" type="date" name="opening_date"></label>
      <label class="checkbox" data-edit-only><input type="checkbox" name="active" value="1"> Active</label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Notes</span><textarea class="input" name="notes" rows="2" placeholder="Branch, signatories, etc."></textarea></label>
    </div>
    <p class="muted small" style="padding: 0 18px" data-new-only>A “Cash in Bank” GL account is created automatically. The opening balance is posted against Opening Balance Equity.</p>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>

<dialog class="modal" id="dlg-txn">
  <form method="post" action="<?= url('/finance/banks/txns') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="txn_type">
    <div class="modal-head"><h3>Bank transaction</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body form-grid">
      <label class="field"><span class="field-label" data-label-bank>Bank account</span><select class="input" name="bank_account_id" required><?= options($bankOptions, null, 'Select bank…') ?></select></label>
      <label class="field" data-only="transfer"><span class="field-label">To bank</span><select class="input" name="transfer_bank_id"><?= options($bankOptions, null, 'Select bank…') ?></select></label>
      <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="txn_date" value="<?= e(today()) ?>" required></label>
      <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" name="amount" required></label>
      <label class="field" data-only="deposit withdrawal" style="grid-column: 1 / -1"><span class="field-label" data-label-counter>Source of money</span>
        <?= view('finance/partials/account_select', ['name' => 'counter_account_id', 'groups' => $accounts], null) ?>
        <span class="field-hint" data-hint-counter></span></label>
      <label class="field"><span class="field-label">Reference</span><input class="input" name="reference"><span class="field-hint">Deposit slip, check no, transaction ID</span></label>
      <label class="field" data-only="deposit withdrawal"><span class="field-label">Bank / card charges (₱)</span><input class="input" type="number" step="0.01" min="0" name="bank_charges">
        <span class="field-hint" data-hint-charges></span></label>
      <label class="field" style="grid-column: 1 / -1"><span class="field-label">Description</span><input class="input" name="description"></label>
    </div>
    <div class="alert alert-info hidden" style="margin: 0 18px 12px" data-charges-note></div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Post</button></div>
  </form>
</dialog>

<script>
  (function () {
    // Bank dialog: opening balance only for a new bank, "Active" only when editing.
    const bankForm = document.querySelector('#dlg-bank form');
    bankForm.querySelector('[name=id]').addEventListener('change', (e) => {
      bankForm.querySelectorAll('[data-new-only]').forEach((el) => el.classList.toggle('hidden', !!e.target.value));
      bankForm.querySelectorAll('[data-edit-only]').forEach((el) => el.classList.toggle('hidden', !e.target.value));
    });

    // Transaction dialog: fields and texts depend on deposit / withdrawal / transfer; show the effect of charges.
    const form = document.querySelector('#dlg-txn form');
    const text = {
      deposit: ['Bank account', 'Source of money', 'Where did the money come from?', 'Optional — e.g. card MDR deducted from settlement'],
      withdrawal: ['Bank account', 'Used for', 'What was the money used for?', 'Optional — e.g. transfer fee'],
      transfer: ['From bank', '', '', ''],
    };
    const sync = () => {
      const type = form.txn_type.value || 'deposit';
      form.querySelectorAll('[data-only]').forEach((el) => el.classList.toggle('hidden', !el.dataset.only.split(' ').includes(type)));
      const [bank, counter, hint, charges] = text[type];
      form.querySelector('[data-label-bank]').textContent = bank;
      form.querySelector('[data-label-counter]').textContent = counter + ' *';
      form.querySelector('[data-hint-counter]').textContent = hint;
      form.querySelector('[data-hint-charges]').textContent = charges;
      const amount = Number(form.amount.value) || 0;
      const fee = Number(form.bank_charges.value) || 0;
      const note = form.querySelector('[data-charges-note]');
      note.classList.toggle('hidden', type === 'transfer' || !(fee > 0 && amount > 0));
      note.innerHTML = type === 'deposit'
        ? `Bank receives <b>${App.peso(amount - fee)}</b> (${App.peso(amount)} less ${App.peso(fee)} charges). Charges are posted to Bank &amp; Card Charges.`
        : `Bank is debited <b>${App.peso(amount + fee)}</b> (${App.peso(amount)} plus ${App.peso(fee)} charges). Charges are posted to Bank &amp; Card Charges.`;
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
  })();
</script>
<?php endif; ?>
