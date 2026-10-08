<?php /** AP bill / AR invoice detail with payments. Variables: $cfg, $d, $payments, $columns, $banks */ use App\Core\Table; ?>
<?php
$unpaid = in_array($d['status'], ['open', 'partial'], true);
$overdue = $unpaid && $d['due_date'] && $d['due_date'] < today();
$auto = $d['source_type'] === $cfg['autoSource'];
?>
<div class="page-header">
  <div>
    <h1><?= e($cfg['doc'] . ' ' . $d[$cfg['no']]) ?> <?= badge($d['status']) ?></h1>
    <p class="muted"><a href="<?= url($cfg['base']) ?>">← <?= e($cfg['title']) ?></a></p>
  </div>
  <div class="page-actions">
    <?php if ($d['status'] !== 'void' && !(float) $d['paid_amount'] && !$auto): ?>
      <?= view('finance/partials/void', ['action' => "{$cfg['base']}/{$d['id']}/void", 'label' => 'Void ' . $cfg['docLower'], 'class' => 'btn-danger',
          'title' => "Void this {$cfg['docLower']} of " . peso($d['amount']) . '? Its journal entry will be reversed.'], null) ?>
    <?php endif; ?>
  </div>
</div>

<div class="card mb"><div class="card-body grid-2">
  <dl class="kv">
    <dt><?= e($cfg['party']) ?></dt><dd class="bold"><?= e($d[$cfg['partyName']]) ?></dd>
    <dt><?= e($cfg['doc']) ?> date</dt><dd><?= e(fmt_date($d[$cfg['date']])) ?></dd>
    <dt>Due date</dt><dd class="<?= $overdue ? 'text-red bold' : '' ?>"><?= e(fmt_date($d['due_date'])) ?><?= $overdue ? ' (overdue)' : '' ?></dd>
    <dt>Ref no</dt><dd><?= e($d['ref_no'] ?: '—') ?></dd>
    <dt>Description</dt><dd><?= e($d['description'] ?: '—') ?></dd>
    <dt>Account</dt><dd><?= e($d['account_code'] ? $d['account_code'] . ' · ' . $d['account_name'] : '—') ?></dd>
  </dl>
  <dl class="kv">
    <dt>Amount</dt><dd><?= peso($d['amount']) ?></dd>
    <dt><?= e($cfg['paidLabel']) ?></dt><dd><?= peso($d['paid_amount']) ?></dd>
    <dt>Balance</dt><dd class="bold"><?= peso($d['balance']) ?></dd>
    <?php if ($d['journal_entry_id'] && can('finance.view', 'finance.journal')): ?>
      <dt>Journal entry</dt><dd><a href="<?= url('/finance/journals/' . $d['journal_entry_id']) ?>">View posting</a></dd>
    <?php endif; ?>
  </dl>
</div></div>

<?php if ($auto): ?><div class="alert alert-info"><?= e($cfg['autoNote']) ?></div><?php endif; ?>

<?php if ($unpaid): ?>
  <div class="card mb">
    <div class="card-head"><h3><?= $cfg['payVerb'] === 'Pay' ? 'Record payment' : 'Record collection' ?></h3></div>
    <form method="post" action="<?= url("{$cfg['base']}/{$d['id']}/{$cfg['payAction']}") ?>" class="card-body">
      <?= csrf_field() ?>
      <div class="form-grid">
        <label class="field"><span class="field-label">Date</span><input class="input" type="date" name="<?= e($cfg['payDate']) ?>" value="<?= e(today()) ?>"></label>
        <label class="field"><span class="field-label">Amount (₱)</span>
          <input class="input" type="number" step="0.01" min="0.01" max="<?= e($d['balance']) ?>" name="amount" value="<?= e(old('amount', $d['balance'])) ?>" required>
          <span class="field-hint">Balance <?= peso($d['balance']) ?></span></label>
        <?= view('finance/partials/pay_method', ['label' => $cfg['methodLabel'], 'methods' => $cfg['methods'], 'banks' => $banks], null) ?>
        <label class="field"><span class="field-label"><?= e($cfg['referenceLabel']) ?></span><input class="input" name="reference"></label>
      </div>
      <div class="row mt" style="justify-content: flex-end"><button class="btn btn-primary" type="submit"><?= e($cfg['payVerb']) ?></button></div>
    </form>
  </div>
<?php endif; ?>

<h3><?= $cfg['payVerb'] === 'Pay' ? 'Payments' : 'Collections' ?></h3>
<div class="card"><?= Table::html($columns, $payments, ['export' => true, 'search' => false, 'empty' => "No {$cfg['paymentWord']}s yet."]) ?></div>
