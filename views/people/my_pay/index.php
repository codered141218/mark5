<?php
/** Employee self-service. Variables: $emp (or null), $slips, $advances, $balance, $ytd, $statuses, $canRequest */
$firstName = $emp ? explode(' ', trim($emp['full_name']))[0] : '';
?>
<style>
  .mypay { max-width: 720px; }
  .mypay .hello { font-size: 22px; font-weight: 700; }
  .mypay .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
  .mypay .slip-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); color: inherit; text-decoration: none; }
  .mypay .slip-row:last-child { border-bottom: none; }
  .mypay .slip-row:hover { background: var(--surface-2); text-decoration: none; }
  .mypay .slip-row b { font-size: 17px; font-variant-numeric: tabular-nums; }
  .mypay .ca-row { padding: 12px 16px; border-bottom: 1px solid var(--border); }
  .mypay .ca-row:last-child { border-bottom: none; }
  .mypay .bar { height: 6px; background: var(--gray-soft); border-radius: 999px; overflow: hidden; margin-top: 8px; }
  .mypay .bar i { display: block; height: 100%; background: var(--green); }
</style>
<div class="mypay stack">
  <?php if (!$emp): ?>
    <div class="page-header"><div><h1>My Pay</h1></div></div>
    <div class="alert alert-warn">Your user account is not linked to an employee record yet. Ask the administrator (Users → Employee) and your payslips and cash advances will appear here.</div>
  <?php else: ?>
    <div class="page-header">
      <div>
        <div class="hello">Hi <?= e($firstName) ?> 👋</div>
        <p class="muted"><?= e($emp['emp_no'] . ($emp['position'] ? ' · ' . $emp['position'] : '') . ($emp['department'] ? ' · ' . $emp['department'] : '')) ?></p>
      </div>
      <?php if ($canRequest): ?><div class="page-actions"><a class="btn btn-primary" href="<?= url('/cash-advances') ?>">+ Request cash advance</a></div><?php endif; ?>
    </div>

    <div class="cards">
      <?= view('finance/partials/stat', ['label' => 'Last net pay', 'value' => $slips ? peso($slips[0]['net_pay']) : '—', 'sub' => $slips ? fmt_date($slips[0]['pay_date']) : 'no payslip yet', 'tone' => 'brand'], null) ?>
      <?= view('finance/partials/stat', ['label' => 'Cash advance balance', 'value' => peso($balance), 'sub' => $balance > 0 ? 'deducted from salary' : 'nothing outstanding', 'tone' => $balance > 0 ? 'amber' : 'green'], null) ?>
      <?= view('finance/partials/stat', ['label' => 'Net pay this year', 'value' => peso($ytd ?? 0), 'sub' => count($slips) . ' payslip(s)'], null) ?>
    </div>

    <div class="card">
      <div class="card-head"><h3>My payslips</h3><span class="muted small">tap to open</span></div>
      <?php if (!$slips): ?><div class="empty">No payslips yet.</div><?php endif; ?>
      <?php foreach ($slips as $s): ?>
        <a class="slip-row" href="<?= url('/my-pay/payslip/' . $s['id']) ?>">
          <div>
            <div class="bold"><?= e(fmt_date($s['period_from']) . ' – ' . fmt_date($s['period_to'])) ?></div>
            <div class="muted small"><?= (int) $s['days_worked'] ?> days<?= (float) $s['ot_hours'] ? ' · ' . e(qty($s['ot_hours'])) . ' h OT' : '' ?> · gross <?= e(money($s['gross_pay'])) ?><?= (float) $s['ca_deduction'] ? ' · CA −' . e(money($s['ca_deduction'])) : '' ?> · paid <?= e(fmt_date($s['pay_date'])) ?></div>
          </div>
          <div class="right"><b><?= e(peso($s['net_pay'])) ?></b><div class="muted small">net pay ›</div></div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3>My cash advances</h3><?php if ($canRequest): ?><a class="small" href="<?= url('/cash-advances') ?>">All requests →</a><?php endif; ?></div>
      <?php if (!$advances): ?><div class="empty">No cash advances.</div><?php endif; ?>
      <?php foreach ($advances as $c): $paid = (float) $c['amount'] > 0 ? min(100, (float) $c['repaid'] / (float) $c['amount'] * 100) : 0; ?>
        <div class="ca-row">
          <div class="row between gap-sm">
            <div><b><?= e(peso($c['amount'])) ?></b> <span class="muted small">· <?= e($c['doc_no'] ?: 'request') ?> · <?= e(fmt_date($c['request_date'])) ?></span></div>
            <?= badge($c['status']) ?>
          </div>
          <div class="muted small mt" style="margin-top:4px"><?= e($c['reason'] ?: '') ?><?= $c['repayment_terms'] ? ' · ' . e($c['repayment_terms']) : '' ?></div>
          <?php if (in_array($c['status'], ['approved', 'settled'], true)): ?>
            <div class="bar"><i style="width:<?= (int) $paid ?>%"></i></div>
            <div class="row between small mt" style="margin-top:4px"><span class="muted">repaid <?= e(money($c['repaid'])) ?></span><span class="<?= (float) $c['balance'] > 0 ? 'text-amber bold' : 'text-green' ?>"><?= (float) $c['balance'] > 0 ? 'balance ' . e(peso($c['balance'])) : 'fully repaid' ?></span></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
