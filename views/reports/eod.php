<?php
/** X/Z reading of one business day with the cash count and every payout / void. Variables: $r, $z, $settings, $vatRegistered, $detail */
$receipt = view('reports/_reading', ['r' => $r, 'z' => $z, 'settings' => $settings, 'vatRegistered' => $vatRegistered], null);
$back = url('/reports/sales', ['report' => 'eod', 'from' => $r['session']['business_date'], 'to' => $r['session']['business_date']]);
?>
<div class="page-header">
  <div>
    <h1><?= $z ? 'Z-Reading' : 'X-Reading' ?> · <?= e(fmt_date($r['session']['business_date'])) ?></h1>
    <p class="muted"><?= $z ? 'End of day reading (day closed)' : 'Business day still open — current X-reading' ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e($back) ?>">← End of day reports</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">⎙ Print</button>
  </div>
</div>
<?php
$c = $r['cash'];
$denTable = function (?array $rows, string $title) {
    if (!$rows) return '<div class="card"><div class="card-head"><h3>' . e($title) . '</h3></div><div class="empty">Not counted by denomination</div></div>';
    $h = '<div class="card"><div class="card-head"><h3>' . e($title) . '</h3></div><table class="table"><thead><tr><th>Denomination</th><th class="right">Count</th><th class="right">Amount</th></tr></thead><tbody>';
    foreach ($rows as $d) {
        if (!$d['qty']) continue;
        $h .= '<tr><td class="bold">' . ($d['denomination'] >= 1 ? '₱' . number_format($d['denomination']) : number_format($d['denomination'] * 100) . '¢') . '</td>'
            . '<td class="right">' . qty($d['qty']) . '</td><td class="right">' . money($d['amount']) . '</td></tr>';
    }
    $h .= '</tbody><tfoot><tr><td>TOTAL</td><td></td><td class="right">' . money(array_sum(array_column($rows, 'amount'))) . '</td></tr></tfoot></table></div>';
    return $h;
};
?>
<div class="stats mb">
  <div class="stat"><div class="stat-label">Beginning cash</div><div class="stat-value"><?= peso($c['opening']) ?></div></div>
  <div class="stat"><div class="stat-label">Cash sales</div><div class="stat-value"><?= peso($c['cash_sales']) ?></div></div>
  <div class="stat"><div class="stat-label">Payouts</div><div class="stat-value"><?= peso($c['payouts']) ?></div><div class="stat-sub"><?= count(array_filter($detail['payouts'], fn ($p) => $p['status'] === 'posted')) ?> voucher(s)</div></div>
  <div class="stat"><div class="stat-label">Expected cash</div><div class="stat-value"><?= peso($c['expected']) ?></div></div>
  <?php if ($c['counted'] !== null): ?>
    <div class="stat <?= $c['variance'] < 0 ? 'stat-red' : ($c['variance'] > 0 ? 'stat-amber' : 'stat-green') ?>"><div class="stat-label">Counted · <?= $c['variance'] < 0 ? 'short' : ($c['variance'] > 0 ? 'over' : 'balanced') ?></div>
      <div class="stat-value"><?= peso($c['counted']) ?></div><div class="stat-sub">variance <?= money($c['variance']) ?></div></div>
  <?php endif; ?>
</div>

<div class="grid-2" style="align-items:start">
  <div class="card"><div class="card-head"><h3><?= $z ? 'Z-reading' : 'X-reading' ?></h3></div><div class="card-body"><?= $receipt ?></div></div>
  <div class="stack">
    <?= $denTable($detail['closing'], 'Closing cash count by denomination') ?>
    <?= $denTable($detail['opening'], 'Beginning cash by denomination') ?>

    <div class="card">
      <div class="card-head"><h3>Payouts from the drawer</h3><span class="muted small"><?= count($detail['payouts']) ?> transaction(s)</span></div>
      <?php if ($detail['payouts']): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Time</th><th>Voucher</th><th>Paid to / for</th><th>Account</th><th class="right">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($detail['payouts'] as $p): ?>
            <tr class="<?= $p['status'] === 'void' ? 'muted-row' : '' ?>">
              <td class="nowrap"><?= e(date('g:i A', strtotime((string) $p['created_at']))) ?></td>
              <td class="nowrap"><?= e($p['doc_no']) ?><?= $p['status'] === 'void' ? ' ' . badge('void') : '' ?></td>
              <td><b><?= e($p['payee'] ?: '—') ?></b><?= $p['description'] ? '<div class="muted small">' . e($p['description']) . '</div>' : '' ?>
                <?= $p['txn_type'] === 'replenish' ? '<div class="small text-green">cash put in</div>' : '' ?></td>
              <td class="small"><?= e(trim(($p['account_code'] ?? '') . ' ' . ($p['account_name'] ?? ''))) ?></td>
              <td class="right"><?= money($p['txn_type'] === 'replenish' ? -$p['amount'] : $p['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4">TOTAL PAYOUTS</td><td class="right"><?= money($c['payouts']) ?></td></tr></tfoot>
        </table></div>
      <?php else: ?><div class="empty">No payouts this day</div><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-head"><h3>Payments received</h3></div>
      <table class="table"><thead><tr><th>Method</th><th class="right">Receipts</th><th class="right">Amount</th></tr></thead><tbody>
        <?php foreach ($r['payments'] as $p): ?><tr><td class="bold"><?= e($p['label']) ?></td><td class="right"><?= (int) $p['cnt'] ?></td><td class="right"><?= money($p['amount']) ?></td></tr><?php endforeach; ?>
        <?php if (!$r['payments']): ?><tr><td colspan="3" class="muted">No payments</td></tr><?php endif; ?>
      </tbody></table>
    </div>

    <div class="card">
      <div class="card-head"><h3>Voids &amp; cancellations</h3></div>
      <?php if ($detail['voids'] || $detail['item_voids']): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Time</th><th>What</th><th>Reason</th><th>By</th><th class="right">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($detail['voids'] as $v): ?>
            <tr><td class="nowrap"><?= e($v['voided_at'] ? date('g:i A', strtotime($v['voided_at'])) : '') ?></td>
              <td><b><?= e($v['kind']) ?></b> <span class="muted small"><?= e($v['receipt_no'] ?: $v['ticket_no']) ?><?= $v['table_label'] ? ' · Table ' . e($v['table_label']) : '' ?></span></td>
              <td class="small"><?= e($v['void_reason']) ?></td><td class="small"><?= e($v['voided_by_name']) ?></td><td class="right"><?= money($v['total']) ?></td></tr>
          <?php endforeach; ?>
          <?php foreach ($detail['item_voids'] as $v): ?>
            <tr><td class="nowrap"><?= e($v['voided_at'] ? date('g:i A', strtotime($v['voided_at'])) : '') ?></td>
              <td>Item: <b><?= e(qty($v['qty']) . ' × ' . $v['name']) ?></b> <span class="muted small"><?= e($v['ticket_no']) ?><?= $v['table_label'] ? ' · Table ' . e($v['table_label']) : '' ?></span></td>
              <td class="small"><?= e($v['void_reason']) ?></td><td class="small"><?= e($v['voided_by_name']) ?></td><td class="right"><?= money($v['line_total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><div class="empty">No voids this day</div><?php endif; ?>
    </div>
  </div>
</div>
<?php /* Only #print-root is printed (see app.css @media print) */ ?>
<div id="print-root"><?= $receipt ?></div>
