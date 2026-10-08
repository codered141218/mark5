<?php
/** Chart of accounts. Variables: $rows, $columns, $type, $counts, $total */
use App\Core\Table;
use App\Services\Finance\Accounts;
$subtypes = array_unique(array_merge(...array_values(Accounts::SUBTYPES)));
?>
<div class="page-header">
  <div>
    <h1>Chart of Accounts</h1>
    <p class="muted">All general ledger accounts. System accounts are used by automatic postings (POS, inventory, petty cash, banks, payables…) and cannot be deleted.
      Balances are shown in their normal sign (liabilities, equity and income as positive).</p>
  </div>
  <?php if (can('finance.accounts')): ?>
    <div class="page-actions">
      <button class="btn btn-primary" data-open="dlg-account" data-reset data-title="New account"
              data-fill='<?= e(json_encode(['id' => '', 'type' => $type ?: 'expense', 'active' => 1, 'locked' => ''])) ?>'>+ New account</button>
    </div>
  <?php endif; ?>
</div>

<div class="tabs">
  <a class="tab<?= $type === '' ? ' active' : '' ?>" href="<?= url('/finance/accounts') ?>">All (<?= $total ?>)</a>
  <?php foreach (Accounts::TYPES as $t => $label): ?>
    <a class="tab<?= $type === $t ? ' active' : '' ?>" href="<?= url('/finance/accounts', ['type' => $t]) ?>"><?= e($label) ?> (<?= (int) ($counts[$t] ?? 0) ?>)</a>
  <?php endforeach; ?>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true]) ?></div>

<?php if (can('finance.accounts')): ?>
<dialog class="modal" id="dlg-account">
  <form method="post" action="<?= url('/finance/accounts') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id">
    <input type="hidden" name="locked">
    <div class="modal-head"><h3>Account</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <div class="alert alert-info hidden" data-locked="system">System account used by automatic postings. You may rename it, but its type cannot change and it cannot be deleted.</div>
      <div class="alert alert-info hidden" data-locked="tx">This account has transactions: its type is locked and it cannot be deleted. Deactivate it to hide it from selections.</div>
      <div class="form-grid">
        <label class="field"><span class="field-label">Code *</span><input class="input" name="code" required>
          <span class="field-hint">e.g. 6150 — the first digit follows the type (1 asset … 6 expense)</span></label>
        <label class="field"><span class="field-label">Type *</span><select class="input" name="type"><?= options(Accounts::SINGULAR) ?></select></label>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Account name *</span><input class="input" name="name" required placeholder="e.g. Gas & Fuel Expense"></label>
        <label class="field"><span class="field-label">Subtype</span><input class="input" name="subtype" list="acct-subtypes">
          <span class="field-hint">Used to group accounts on statements</span></label>
        <label class="checkbox" data-edit-only><input type="checkbox" name="active" value="1"> Active (available for new transactions)</label>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Description</span><textarea class="input" name="description" rows="2"></textarea></label>
      </div>
      <datalist id="acct-subtypes"><?php foreach ($subtypes as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
<script>
  // Lock the type of system accounts / accounts with transactions, and hide "Active" for new accounts.
  document.querySelector('#dlg-account [name=locked]').addEventListener('change', (e) => {
    const form = e.target.form;
    form.querySelector('[name=type]').disabled = !!e.target.value;
    form.querySelectorAll('[data-locked]').forEach((el) => el.classList.toggle('hidden', el.dataset.locked !== e.target.value));
    form.querySelector('[data-edit-only]').classList.toggle('hidden', !form.querySelector('[name=id]').value);
  });
</script>
<?php endif; ?>
