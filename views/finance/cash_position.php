<?php /** Cash & bank position. Variables: $asOf, $p (Disbursements::position) */
$section = function (string $title, array $rows, float $total, string $empty) use ($asOf) {
    $h = '<div class="card"><div class="card-head"><h3>' . e($title) . '</h3><b class="mono">' . peso($total) . '</b></div>';
    if (!$rows) return $h . '<div class="empty">' . e($empty) . '</div></div>';
    $h .= '<table class="table"><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td><b>' . e($r['label']) . '</b><div class="muted small">' . e($r['code'] . ' · ' . $r['account']) . '</div></td>'
            . '<td class="right"><span class="bold mono ' . ($r['balance'] < 0 ? 'text-red' : '') . '" style="font-size:16px">' . peso($r['balance']) . '</span></td>'
            . '<td class="right" style="width:110px"><a class="btn btn-sm btn-ghost" href="' . e(url('/reports/finance', ['report' => 'gl', 'account_id' => $r['account_id'], 'to' => $asOf])) . '">Ledger →</a></td></tr>';
    }
    return $h . '</tbody></table></div>';
};
?>
<div class="page-header">
  <div>
    <h1>Cash &amp; Bank Position</h1>
    <p class="muted">All your money in one place: cash on hand, the petty cash fund and every bank / e-wallet, from the books.</p>
  </div>
  <div class="page-actions">
    <form method="get" class="row gap-sm"><input class="input" type="date" name="as_of" value="<?= e($asOf) ?>" style="width:auto">
      <button class="btn" type="submit">Show as of date</button></form>
    <a class="btn btn-primary" href="<?= url('/finance/payments') ?>">Payments &amp; expenses</a>
  </div>
</div>

<div class="stats mb">
  <div class="stat stat-brand"><div class="stat-label">Total cash &amp; banks</div><div class="stat-value"><?= peso($p['totals']['all']) ?></div>
    <div class="stat-sub">as of <?= e(fmt_date($asOf)) ?></div></div>
  <div class="stat"><div class="stat-label">Cash</div><div class="stat-value"><?= peso($p['totals']['cash']) ?></div><div class="stat-sub">on hand + petty cash</div></div>
  <div class="stat"><div class="stat-label">Banks &amp; e-wallets</div><div class="stat-value"><?= peso($p['totals']['banks']) ?></div><div class="stat-sub"><?= count($p['banks']) ?> account(s)</div></div>
  <div class="stat stat-amber"><div class="stat-label">Not yet settled</div><div class="stat-value"><?= peso($p['totals']['pending']) ?></div><div class="stat-sub">card &amp; e-wallet sales to receive</div></div>
</div>

<?php if ($p['drawer'] && $asOf === today()): $d = $p['drawer']; ?>
  <div class="alert alert-info">Business day <b><?= e(fmt_date($d['business_date'])) ?></b> is open. The POS drawer should now hold
    <b><?= peso($d['expected']) ?></b> (beginning <?= money($d['opening']) ?> + cash sales <?= money($d['cash_sales']) ?> − payouts <?= money($d['payouts']) ?>).
    It is part of Cash on hand below.</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start">
  <div class="stack">
    <?= $section('Cash', $p['cash'], $p['totals']['cash'], 'No cash accounts set (Finance → GL Account Setup).') ?>
    <?= $section('Card & e-wallet sales not yet settled', $p['pending'], $p['totals']['pending'], 'Nothing pending.') ?>
  </div>
  <?= $section('Banks & e-wallets', $p['banks'], $p['totals']['banks'], 'No bank accounts yet — add them under Banks.') ?>
</div>
