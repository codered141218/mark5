<?php
/** Settings tabs. Variables: $tab, $tabs, $s (all current settings) */
$v = fn (string $k) => e(old($k, $s[$k] ?? ''));
$on = fn (string $k) => ($s[$k] ?? '') === '1' ? 'checked' : '';
?>
<div class="page-header">
  <div>
    <h1>Settings</h1>
    <p class="muted">Business details printed on receipts, tax rules, POS options and backups.</p>
  </div>
</div>

<div class="tabs">
  <?php foreach ($tabs as $key => $t): ?>
    <a class="tab <?= $key === $tab ? 'active' : '' ?>" href="<?= url('/admin/settings', ['tab' => $key]) ?>"><?= e($t['label']) ?></a>
  <?php endforeach; ?>
</div>

<form class="card card-body stack" method="post" action="<?= url('/admin/settings') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">

  <?php if ($tab === 'business'): ?>
    <div class="form-grid">
      <label class="field" style="grid-column:span 2"><span class="field-label">Business / trade name</span><input class="input" name="business_name" value="<?= $v('business_name') ?>"></label>
      <label class="field"><span class="field-label">TIN</span><input class="input" name="business_tin" value="<?= $v('business_tin') ?>"><span class="field-hint">e.g. 000-000-000-00000</span></label>
      <label class="field" style="grid-column:span 2"><span class="field-label">Address</span><input class="input" name="business_address" value="<?= $v('business_address') ?>"></label>
      <label class="field"><span class="field-label">Phone</span><input class="input" name="business_phone" value="<?= $v('business_phone') ?>"></label>
    </div>
    <h3>Receipt</h3>
    <div class="form-grid">
      <label class="field"><span class="field-label">Receipt title</span><input class="input" name="receipt_title" value="<?= $v('receipt_title') ?>">
        <span class="field-hint">Printed at the top of every receipt</span></label>
      <label class="field"><span class="field-label">Receipt number prefix</span><input class="input" name="receipt_prefix" maxlength="10" value="<?= $v('receipt_prefix') ?>">
        <span class="field-hint">Receipts are numbered like <?= e(($s['receipt_prefix'] ?? '') ?: 'OR') ?>-00000123</span></label>
      <label class="field" style="grid-column:1/-1"><span class="field-label">Receipt footer</span><textarea class="input" name="receipt_footer" rows="2"><?= $v('receipt_footer') ?></textarea>
        <span class="field-hint">e.g. thank-you message, wifi password, social media</span></label>
    </div>
    <div class="alert alert-info" style="margin-bottom:0">
      Receipt printers and kitchen printers are set up on each POS terminal: <a href="<?= url('/printer') ?>"><b>Printer Setup →</b></a>
    </div>

  <?php elseif ($tab === 'tax'): ?>
    <div>
      <label class="checkbox"><input type="checkbox" name="vat_registered" value="1" <?= $on('vat_registered') ?>> <b>VAT-registered business</b></label>
      <p class="muted small" style="margin:4px 0 0 24px">
        <b>VAT-registered:</b> receipts show VATable sales and the VAT amount. Senior Citizen / PWD sales are VAT-exempt.<br>
        <b>Non-VAT:</b> prices carry no VAT and receipts show no VAT breakdown. Non-VAT businesses generally pay the 3% percentage tax on gross sales instead.
      </p>
    </div>
    <div>
      <span class="field-label">Selling prices (VAT-registered only)</span>
      <div class="col gap-sm" style="align-items:flex-start;margin-top:6px">
      <label class="checkbox"><input type="radio" name="prices_include_vat" value="1" <?= ($s['prices_include_vat'] ?? '1') !== '0' ? 'checked' : '' ?>>
        <b>VAT-inclusive</b> — the menu price is what the customer pays (₱112 includes ₱12 VAT)</label>
      <label class="checkbox"><input type="radio" name="prices_include_vat" value="0" <?= ($s['prices_include_vat'] ?? '1') === '0' ? 'checked' : '' ?>>
        <b>VAT-exclusive</b> — VAT is added on top at the POS (₱100 + ₱12 VAT = ₱112)</label>
      </div>
      <p class="muted small" style="margin:4px 0 0 24px">Applies to new orders. Orders already open keep the way they were started.</p>
    </div>
    <div class="form-grid">
      <label class="field"><span class="field-label">VAT rate (%)</span><input class="input" type="number" step="any" min="0" max="100" name="vat_rate" value="<?= $v('vat_rate') ?>">
        <span class="field-hint">Philippine VAT is 12%. Ignored when not VAT-registered.</span></label>
      <label class="field"><span class="field-label">Senior Citizen / PWD discount (%)</span><input class="input" type="number" step="any" min="0" max="100" name="sc_discount_rate" value="<?= $v('sc_discount_rate') ?>">
        <span class="field-hint">20% under RA 9994 (Seniors) and RA 10754 (PWD)</span></label>
      <label class="field"><span class="field-label">Service charge (%)</span><input class="input" type="number" step="any" min="0" max="100" name="service_charge_rate" value="<?= $v('service_charge_rate') ?>">
        <span class="field-hint">0 = no service charge</span></label>
    </div>
    <div class="col gap-sm" style="align-items:flex-start">
      <label class="checkbox"><input type="checkbox" name="service_charge_dine_in_only" value="1" <?= $on('service_charge_dine_in_only') ?>> Apply service charge to dine-in orders only (not take-out / delivery)</label>
      <label class="checkbox"><input type="checkbox" name="require_payment_ref" value="1" <?= $on('require_payment_ref') ?>> Require approval / reference no. for card and e-wallet (GCash, Maya) payments</label>
    </div>

  <?php elseif ($tab === 'pos'): ?>
    <div>
      <label class="checkbox"><input type="checkbox" name="require_table_dine_in" value="1" <?= $on('require_table_dine_in') ?>> <b>Dine-in orders need a table number</b></label>
      <p class="muted small" style="margin:4px 0 0 24px">The cashier cannot press <b>Done</b> or take payment on a dine-in order until a table is assigned.
        Take-out and delivery orders don't need a table.</p>
    </div>
    <div class="form-grid">
      <label class="field"><span class="field-label">Order of the menu tiles</span>
        <select class="input" name="pos_menu_sort"><?= options(App\Services\Admin\SettingsForm::MENU_SORTS, $s['pos_menu_sort'] ?? 'custom') ?></select>
        <span class="field-hint">“My arrangement” uses the order set under <a href="<?= url('/inventory/arrange') ?>">Inventory → Arrange menu</a>.</span></label>
    </div>
    <div class="alert alert-info" style="margin-bottom:0">Prep stations (Kitchen, Grill …) decide which order slip an item prints on:
      <a href="<?= url('/admin/stations') ?>">Administration → Prep Stations</a>. Printers are set up on each tablet under <a href="<?= url('/printer') ?>">Printer Setup</a>.</div>

  <?php else: ?>
    <div>
      <label class="checkbox"><input type="checkbox" name="auto_backup" value="1" <?= $on('auto_backup') ?>> <b>Daily automatic backup</b></label>
      <p class="muted small" style="margin:4px 0 0 24px">A copy of the database is saved automatically once a day, right after the first page someone opens that day.</p>
    </div>
    <div class="form-grid">
      <label class="field"><span class="field-label">Automatic backups to keep</span><input class="input" type="number" min="1" step="1" name="backup_retention" value="<?= $v('backup_retention') ?>">
        <span class="field-hint">Older automatic backups are deleted. Manual backups are never deleted automatically.</span></label>
    </div>
    <p class="small"><a href="<?= url('/admin/backup') ?>">Go to Backup &amp; Restore →</a></p>
  <?php endif; ?>

  <div class="row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form>
