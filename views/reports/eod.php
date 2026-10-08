<?php
/** Printable X/Z reading of one business day. Variables: $r, $z, $settings, $vatRegistered */
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
<div class="card"><div class="card-body"><?= $receipt ?></div></div>
<?php /* Only #print-root is printed (see app.css @media print) */ ?>
<div id="print-root"><?= $receipt ?></div>
