<?php
/** Manual journal entry form. Variables: $accounts (Accounts::grouped()). Re-fills the lines after a validation error. */
$lines = array_values(array_filter((array) old('lines', []), 'is_array'));
while (count($lines) < 2) $lines[] = [];
$accountOptions = fn ($selected) => implode('', array_map(fn ($g, $list) => '<optgroup label="' . e($g) . '">' . options($list, $selected) . '</optgroup>', array_keys($accounts), $accounts));
$row = fn ($i, $l) => '<tr data-row>
    <td><select class="input" name="lines[' . $i . '][account_id]" data-combo data-field="account_id" data-placeholder="Type code or name…"><option value=""></option>' . $accountOptions($l['account_id'] ?? null) . '</select></td>
    <td><input class="input right" type="number" step="0.01" min="0" name="lines[' . $i . '][debit]" data-field="debit" value="' . e($l['debit'] ?? '') . '"></td>
    <td><input class="input right" type="number" step="0.01" min="0" name="lines[' . $i . '][credit]" data-field="credit" value="' . e($l['credit'] ?? '') . '"></td>
    <td><input class="input" name="lines[' . $i . '][memo]" data-field="memo" value="' . e($l['memo'] ?? '') . '"></td>
    <td><button class="icon-btn" type="button" title="Remove line" data-remove-row>✕</button></td>
  </tr>';
?>
<div class="page-header">
  <div>
    <h1>New journal entry</h1>
    <p class="muted"><a href="<?= url('/finance/journals') ?>">← Journal entries</a> · Debits must equal credits. “Add line” pre-fills the amount needed to balance the entry.</p>
  </div>
</div>

<form method="post" action="<?= url('/finance/journals') ?>" id="je-form" data-confirm="Post this entry to the general ledger?" data-ok="Post">
  <?= csrf_field() ?>
  <div class="card mb"><div class="card-body form-grid">
    <label class="field"><span class="field-label">Date *</span><input class="input" type="date" name="entry_date" value="<?= e(old('entry_date', today())) ?>" required></label>
    <label class="field"><span class="field-label">Reference no</span><input class="input" name="ref_no" value="<?= e(old('ref_no')) ?>" placeholder="e.g. OR/CV no"></label>
    <label class="field" style="grid-column: span 2"><span class="field-label">Memo</span>
      <input class="input" name="memo" value="<?= e(old('memo')) ?>" placeholder="e.g. Opening balances as of Jan 1 / Monthly depreciation"></label>
  </div></div>

  <div class="card">
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:38%">Account</th><th class="right" style="width:150px">Debit</th><th class="right" style="width:150px">Credit</th><th>Line memo</th><th style="width:40px"></th></tr></thead>
        <tbody data-rows="lines"><?php foreach ($lines as $i => $l) echo $row($i, $l); ?></tbody>
        <tfoot>
          <tr>
            <td><button class="btn btn-sm" type="button" id="je-add">+ Add line</button></td>
            <td class="right bold" id="je-dr">0.00</td>
            <td class="right bold" id="je-cr">0.00</td>
            <td id="je-status" colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <template id="lines-template"><?= $row('__i__', []) ?></template>

  <div class="row mt" style="justify-content: flex-end; gap: 8px">
    <a class="btn" href="<?= url('/finance/journals') ?>">Cancel</a>
    <button class="btn btn-primary" type="submit" id="je-post" disabled>Post entry</button>
  </div>
</form>

<script>
  // Live debit/credit totals: the entry can be posted only when it balances and every line with an amount has an account.
  (function () {
    const form = document.getElementById('je-form');
    const num = (el) => Number(el.value) || 0;
    const r2 = (n) => Math.round(n * 100) / 100;
    const totals = () => {
      let dr = 0; let cr = 0; let missing = false;
      form.querySelectorAll('[data-row]').forEach((tr) => {
        const d = num(tr.querySelector('[data-field=debit]'));
        const c = num(tr.querySelector('[data-field=credit]'));
        dr += d; cr += c;
        if ((d || c) && !tr.querySelector('[data-field=account_id]').value) missing = true;
      });
      return { dr: r2(dr), cr: r2(cr), diff: r2(dr - cr), missing };
    };
    const refresh = () => {
      const t = totals();
      document.getElementById('je-dr').textContent = App.money(t.dr);
      document.getElementById('je-cr').textContent = App.money(t.cr);
      const status = document.getElementById('je-status');
      const ok = Math.abs(t.diff) < 0.005 && t.dr > 0 && !t.missing;
      if (t.missing) status.innerHTML = '<span class="text-red">Select an account on every line with an amount.</span>';
      else if (ok) status.innerHTML = '<span class="text-green bold">✓ Balanced</span>';
      else if (!t.dr && !t.cr) status.innerHTML = '<span class="muted">Enter debit and credit amounts.</span>';
      else status.innerHTML = `<span class="text-red bold">Out of balance by ${App.peso(Math.abs(t.diff))} (${t.diff > 0 ? 'more debits' : 'more credits'})</span>`;
      document.getElementById('je-post').disabled = !ok;
    };
    form.addEventListener('input', (e) => {
      // A line is either a debit or a credit.
      const f = e.target.dataset.field;
      if ((f === 'debit' || f === 'credit') && num(e.target)) e.target.closest('tr').querySelector(`[data-field=${f === 'debit' ? 'credit' : 'debit'}]`).value = '';
      refresh();
    });
    form.addEventListener('change', refresh);
    form.addEventListener('rows:change', refresh);
    document.getElementById('je-add').addEventListener('click', () => {
      const { diff } = totals();
      const row = App.addRow('lines', diff > 0 ? { credit: diff } : diff < 0 ? { debit: -diff } : {});
      row.querySelector('input').focus();
    });
    document.addEventListener('DOMContentLoaded', refresh);
  })();
</script>
