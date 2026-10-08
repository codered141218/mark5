<?php
/**
 * Printer setup for this device (receipt / kitchen printer). Driven by public/assets/js/printer-setup.js;
 * the settings are stored in the browser (localStorage) by printer.js, because each tablet / PC has its own printer.
 */
use App\Services\Settings;

$s = Settings::all();
$business = [
    'name' => $s['business_name'], 'address' => $s['business_address'], 'tin' => $s['business_tin'], 'phone' => $s['business_phone'],
    'receipt_title' => $s['receipt_title'], 'receipt_footer' => $s['receipt_footer'],
];
$methods = [
    'bluetooth' => ['Bluetooth printer (BLE)', 'Prints straight to a Bluetooth thermal printer from Chrome on Android, Windows or Mac. Needs a secure address (https://… or localhost). Press “Connect” once and pick the printer; the POS reconnects by itself afterwards.'],
    'serial' => ['USB / Bluetooth serial (COM port)', 'For Chrome or Edge on a Windows / desktop computer: a USB thermal printer, or a classic Bluetooth printer paired in Windows as a COM port. Needs https:// or localhost.'],
    'rawbt' => ['RawBT app (Android)', 'Install the free “RawBT” app on the Android tablet / phone and pair it with the printer. Works with any Bluetooth thermal printer, even over plain http:// on the shop network.'],
    'browser' => ['Browser print dialog', 'Uses the normal print window of the browser. Works everywhere (also with Wi-Fi / office printers) but asks before every print.'],
];
?>
<div class="page-header">
  <div>
    <h1>Printer setup</h1>
    <p class="muted">Receipt and kitchen printer of <b>this device</b>. Every tablet or PC keeps its own printer settings.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/pos') ?>">▶ Open POS</a></div>
</div>

<div class="grid-2 printer-setup" id="printer-setup">
  <div class="card">
    <div class="card-head"><h3>How this device prints</h3><span class="muted small" id="ps-saved"></span></div>
    <form class="pad" id="ps-form" autocomplete="off">
      <div class="method-list">
        <?php foreach ($methods as $key => [$label, $help]): ?>
          <label class="method-option">
            <input type="radio" name="method" value="<?= e($key) ?>">
            <span><b><?= e($label) ?></b><span class="muted small"><?= e($help) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="form-grid mt-lg">
        <label class="field"><span class="field-label">Paper width</span>
          <select class="input" name="paper"><option value="58">58 mm (small, 32 characters)</option><option value="80">80 mm (wide, 48 characters)</option></select></label>
        <label class="field"><span class="field-label">Receipt copies</span>
          <input class="input" type="number" name="copies" min="1" max="5" step="1"></label>
        <label class="field" data-only="serial"><span class="field-label">Baud rate (serial)</span>
          <select class="input" name="baudRate"><?= options(['9600' => '9600 (most printers)', '19200' => '19200', '38400' => '38400', '115200' => '115200']) ?></select></label>
      </div>
      <div class="col gap-sm mt-lg">
        <label class="checkbox"><input type="checkbox" name="autoPrint"> Print the receipt automatically after payment</label>
        <label class="checkbox"><input type="checkbox" name="kitchenSlip"> Print a kitchen slip when orders are sent to the kitchen</label>
        <label class="checkbox"><input type="checkbox" name="openDrawer"> Open the cash drawer when a receipt prints (drawer connected to the printer)</label>
      </div>
    </form>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Printer</h3><span class="badge" id="ps-status">…</span></div>
      <div class="pad">
        <p class="mt-0" id="ps-status-text"></p>
        <div class="row gap-sm wrap">
          <button class="btn btn-primary btn-lg" type="button" id="ps-connect">Connect printer</button>
          <button class="btn btn-lg" type="button" id="ps-disconnect">Disconnect</button>
          <button class="btn btn-lg" type="button" id="ps-test">⎙ Test print</button>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3>This device</h3></div>
      <div class="pad">
        <ul class="cap-list" id="ps-caps"></ul>
        <div class="alert alert-info small mt" id="ps-advice"></div>
      </div>
    </div>
  </div>
</div>

<script>window.PRINTER_BUSINESS = <?= json_encode($business, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php // The back-office layout only loads app.js; deferred scripts run after it. ?>
<script defer src="<?= asset('js/printer.js') ?>"></script>
<script defer src="<?= asset('js/printer-setup.js') ?>"></script>
<style>
  .method-list { display: flex; flex-direction: column; gap: 8px; }
  .method-option { display: flex; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; }
  .method-option:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }
  .method-option input { margin-top: 3px; width: 18px; height: 18px; accent-color: var(--brand); flex-shrink: 0; }
  .method-option > span { display: flex; flex-direction: column; gap: 3px; }
  .mt-0 { margin-top: 0; }
  .cap-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .cap-list li { display: flex; gap: 10px; align-items: center; }
  .cap-list .yes { color: var(--green); font-weight: 700; } .cap-list .no { color: var(--red); font-weight: 700; }
</style>
