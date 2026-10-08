<?php
/** AP bills / AR invoices list. Variables: $cfg, $rows, $columns, $from, $to, $status, $partyId, $stats, $parties, $accounts */
use App\Core\Table;
$statuses = ['unpaid' => 'Unpaid (open + partial)', 'open' => 'Open', 'partial' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void'];
$partyNames = array_column($parties, 'name', 'id');
?>
<?= view('finance/docs/_tabs', ['cfg' => $cfg, 'tab' => 'docs'], null) ?>

<div class="stats mb">
  <?= view('finance/partials/stat', ['label' => "Total unpaid {$cfg['docs']}", 'value' => peso($stats['total']), 'sub' => "{$stats['n']} {$cfg['docs']} · all dates", 'tone' => 'brand'], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Overdue', 'value' => peso($stats['overdue']), 'sub' => "{$stats['n_overdue']} past due date", 'tone' => $stats['overdue'] > 0 ? 'red' : null], null) ?>
  <?= view('finance/partials/stat', ['label' => 'Due in the next 7 days', 'value' => peso($stats['soon']), 'sub' => "{$stats['n_soon']} {$cfg['docs']}", 'tone' => $stats['soon'] > 0 ? 'amber' : null], null) ?>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['status', $cfg['partyId']], 'extra' =>
    '<label class="field"><span class="field-label">Status</span><select class="input" name="status">' . options($statuses, $status, 'All statuses') . '</select></label>'
    . '<label class="field"><span class="field-label">' . e($cfg['party']) . '</span><select class="input" name="' . e($cfg['partyId']) . '">'
    . options($partyNames, $partyId, 'All ' . strtolower($cfg['party']) . 's') . '</select></label>'], null) ?>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'link' => fn ($r) => url($cfg['base'] . '/' . $r['id']), 'empty' => "No {$cfg['docs']} in this period."]) ?></div>

<dialog class="modal wide" id="dlg-doc">
  <form method="post" action="<?= url($cfg['base']) ?>">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>New <?= e($cfg['docLower']) ?></h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <?php if (!$parties): ?><div class="alert alert-warn">No <?= e(strtolower($cfg['party'])) ?>s yet — add one under <?= e(ucfirst($cfg['partyTable'])) ?>.</div><?php endif; ?>
      <div class="form-grid">
        <label class="field" style="grid-column: 1 / -1"><span class="field-label"><?= e($cfg['party']) ?> *</span>
          <select class="input" name="<?= e($cfg['partyId']) ?>" required data-party>
            <option value="">Select <?= e(strtolower($cfg['party'])) ?>…</option>
            <?php foreach ($parties as $p): ?>
              <option value="<?= e($p['id']) ?>" data-terms="<?= (int) $p['terms_days'] ?>" data-balance="<?= e($p['balance']) ?>" data-limit="<?= e($p['credit_limit'] ?? 0) ?>">
                <?= e($p['name'] . ((float) $p['balance'] ? ' — balance ' . peso($p['balance']) : '')) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span class="field-label"><?= e($cfg['doc']) ?> date *</span><input class="input" type="date" name="<?= e($cfg['date']) ?>" value="<?= e(today()) ?>" required></label>
        <label class="field"><span class="field-label">Due date</span><input class="input" type="date" name="due_date">
          <span class="field-hint" data-terms-hint>Blank = based on terms</span></label>
        <label class="field"><span class="field-label"><?= e($cfg['refLabel']) ?></span><input class="input" name="ref_no"></label>
        <label class="field"><span class="field-label">Amount (₱) *</span><input class="input" type="number" step="0.01" min="0.01" name="amount" required></label>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label"><?= e($cfg['accountLabel']) ?> *</span>
          <?= view('finance/partials/account_select', ['name' => $cfg['account'], 'groups' => $accounts, 'selected' => $cfg['defaultAccount'], 'required' => true], null) ?></label>
        <label class="field" style="grid-column: 1 / -1"><span class="field-label">Description</span><input class="input" name="description"></label>
      </div>
      <div class="alert alert-warn mt hidden" data-limit-warning></div>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save <?= e($cfg['docLower']) ?></button></div>
  </form>
</dialog>
<script>
  // Due-date hint from the party's terms, and a credit limit warning for customers.
  (function () {
    const form = document.querySelector('#dlg-doc form');
    const sync = () => {
      const opt = form.querySelector('[data-party]').selectedOptions[0];
      const hint = form.querySelector('[data-terms-hint]');
      const warn = form.querySelector('[data-limit-warning]');
      if (!opt || !opt.value) { hint.textContent = 'Blank = based on terms'; warn.classList.add('hidden'); return; }
      const terms = Number(opt.dataset.terms) || 0;
      hint.textContent = terms ? `Blank = ${terms}-day terms` : 'Blank = due on the same day (no terms)';
      const limit = Number(opt.dataset.limit) || 0;
      const balance = Number(opt.dataset.balance) || 0;
      const amount = Number(form.amount.value) || 0;
      const over = limit > 0 && balance + amount > limit;
      warn.classList.toggle('hidden', !over);
      warn.textContent = over ? `This exceeds the customer's credit limit of ${App.peso(limit)} (current balance ${App.peso(balance)}).` : '';
    };
    form.addEventListener('change', sync);
    form.addEventListener('input', sync);
  })();
</script>
