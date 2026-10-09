<?php
/**
 * Printer setup for this device: one or more thermal printers (portable Bluetooth printers such as GOOJPRT,
 * desk printers over USB), each printing receipts and / or the order slips of chosen prep stations.
 * Driven by public/assets/js/printer-setup.js; settings are stored in the browser (localStorage) by printer.js,
 * because each tablet / PC has its own printers. There is no browser print dialog.
 * Variables: $stations [{id, name}]
 */
use App\Services\Settings;

$s = Settings::all();
$business = [
    'name' => $s['business_name'], 'address' => $s['business_address'], 'tin' => $s['business_tin'], 'phone' => $s['business_phone'],
    'receipt_title' => $s['receipt_title'], 'receipt_footer' => $s['receipt_footer'],
];
?>
<div class="page-header">
  <div>
    <h1>Printer setup</h1>
    <p class="muted">The printers of <b>this device</b> — every tablet or PC keeps its own. Receipts and order slips go straight to these printers;
      the browser print window is never used.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/pos') ?>">▶ Open POS</a></div>
</div>

<div class="alert alert-info">
  <b>How it works:</b> when the cashier presses <b>Done</b>, the new items are grouped by prep station
  (<?= e(implode(', ', array_column($stations, 'name')) ?: 'none set up yet') ?>) and each group prints as its own slip on the printer that takes that station.
  With one printer, every slip comes out of it — tear them apart and hand them to the kitchen or the grill.
  Stations are set under <a href="<?= url('/admin/stations') ?>">Administration → Prep Stations</a>.
</div>

<div class="ps-printers" id="ps-printers"></div>
<div class="row gap-sm mb-lg">
  <button class="btn btn-primary btn-lg" type="button" id="ps-add">＋ Add a printer</button>
  <span class="muted small">e.g. one at the cashier for receipts &amp; grill slips, one in the kitchen for kitchen slips.</span>
</div>

<div class="grid-2 mt-lg">
  <div class="card">
    <div class="card-head"><h3>Options for this device</h3><span class="muted small" id="ps-saved"></span></div>
    <div class="pad">
      <div class="col gap-sm">
        <label class="checkbox"><input type="checkbox" data-opt="autoPrint"> Print the receipt automatically after payment</label>
        <label class="checkbox"><input type="checkbox" data-opt="slipOnDone"> Print the order slips when the cashier presses <b>Done</b></label>
        <label class="checkbox"><input type="checkbox" data-opt="slipPerStation"> One slip per station (untick: one slip with all the items)</label>
        <label class="checkbox"><input type="checkbox" data-opt="bigItems"> Big item lines on order slips (double height)</label>
        <label class="checkbox"><input type="checkbox" data-opt="autoReconnect"> Reconnect automatically when a printer wakes up, and keep missed prints</label>
        <label class="checkbox"><input type="checkbox" data-opt="keepAwake"> Keep the tablet screen on while the POS is open</label>
      </div>
      <label class="field mt"><span class="field-label">Peso sign on paper</span>
        <select class="input" data-opt="currency"><option value="P">P 1,234.00</option><option value="PHP">PHP 1,234.00</option></select>
        <span class="field-hint">Thermal printers cannot print ₱.</span></label>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>This device</h3></div>
      <div class="pad">
        <ul class="cap-list" id="ps-caps"></ul>
        <div class="alert alert-info small mt mb-0" id="ps-advice"></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Diagnostic log</h3><button class="btn btn-sm" type="button" id="ps-log-copy">Copy</button></div>
      <div class="pad"><pre class="ps-log" id="ps-log"></pre></div>
    </div>
  </div>
</div>

<!-- ============================================================ help -->
<div class="grid-2 mt-lg">
  <div class="card">
    <div class="card-head"><h3>GOOJPRT and other portable printers</h3></div>
    <div class="pad help">
      <ol class="mt-0">
        <li>Charge the printer, load the paper (shiny side towards the print head) and hold the power button until the light comes on.</li>
        <li>Make sure it is <b>not connected to another phone or app</b> — a Bluetooth printer talks to one device at a time.</li>
        <li>Add a printer here, choose <b>Bluetooth</b> and press <b>Connect</b>. Pick it from the list (GOOJPRT models show as <code>PT-210</code>,
          <code>MTP-II</code>, <code>Printer001</code> or <code>BlueTooth Printer</code>). Then press <b>Test print</b>.</li>
      </ol>
      <h4>Not in the list?</h4>
      <p class="mt-0">Tick <b>Show all Bluetooth devices</b> and connect again. If it still does not appear, your model only speaks “classic” Bluetooth:</p>
      <ul>
        <li><b>Android tablet:</b> pair it in Android Settings → Bluetooth (PIN <code>0000</code> or <code>1234</code>), install the free <b>RawBT</b> app,
          choose the printer in RawBT, then choose <b>RawBT app</b> here.</li>
        <li><b>Windows PC:</b> pair it in Windows Settings → Bluetooth (PIN <code>0000</code> or <code>1234</code>). Windows gives it a COM port.
          Choose <b>USB / serial</b> here, press Connect and pick that COM port (“Standard Serial over Bluetooth link”).</li>
      </ul>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Troubleshooting</h3></div>
    <div class="pad help">
      <dl class="trouble">
        <dt>Bluetooth says it needs a secure address</dt>
        <dd>Open the POS through its <code>https://</code> address (your online test site is). On a local <code id="ps-origin">http://192.168.1.10</code>
          address: in Chrome open <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>, add the address, Enable, Relaunch — or use RawBT.</dd>
        <dt>It connects but nothing prints</dt>
        <dd>Open the printer's <b>Advanced</b> settings and try another channel, or a smaller packet size.</dd>
        <dt>Prints are garbled, cut off or stop half-way</dt>
        <dd>Advanced: choose a smaller <b>packet size</b> (50 or 20) and a longer <b>delay</b> (30 ms).</dd>
        <dt>Blank paper comes out</dt>
        <dd>The paper roll is upside down. Turn it so the shiny side faces the print head.</dd>
        <dt>It stops printing after a while</dt>
        <dd>Portable printers fall asleep. Keep <b>Reconnect automatically</b> on: missed prints wait in the printer's queue and come out when it wakes up.</dd>
        <dt>Text is too wide / wraps</dt>
        <dd>Set the right <b>paper width</b> (58 mm for most portable printers).</dd>
      </dl>
    </div>
  </div>
</div>

<template id="ps-printer-template">
  <div class="card ps-card">
    <div class="card-head">
      <input class="input ps-name" data-f="name" maxlength="40" aria-label="Printer name">
      <span class="badge" data-status>…</span>
    </div>
    <div class="pad">
      <div class="ps-grid">
        <div>
          <div class="field-label">How it connects</div>
          <div class="method-list">
            <label class="method-option"><input type="radio" data-f="method" value="bluetooth">
              <span><b>Bluetooth</b><span class="muted small">Prints straight from Chrome / Edge. Most portable printers (GOOJPRT PT-210, Xprinter …).</span></span></label>
            <label class="method-option"><input type="radio" data-f="method" value="serial">
              <span><b>USB / serial (COM port)</b><span class="muted small">Windows PC: a USB printer, or a Bluetooth printer paired in Windows.</span></span></label>
            <label class="method-option"><input type="radio" data-f="method" value="rawbt">
              <span><b>RawBT app (Android)</b><span class="muted small">For classic-Bluetooth printers on Android. One RawBT printer per tablet.</span></span></label>
          </div>
          <div class="alert alert-warn small mt hidden" data-method-warn></div>
          <p class="small mt" data-status-text></p>
          <div class="alert alert-error small hidden" data-error></div>
          <div class="row gap-sm wrap">
            <button class="btn btn-primary" type="button" data-act="connect">Connect</button>
            <button class="btn" type="button" data-act="reconnect">↻ Reconnect</button>
            <button class="btn" type="button" data-act="test">⎙ Test print</button>
            <button class="btn" type="button" data-act="selftest">Self-test page</button>
          </div>
          <label class="checkbox mt" data-only="bluetooth"><input type="checkbox" data-f="bleFilter"> Show all Bluetooth devices (when the printer is not in the list)</label>
          <div class="queue-box mt hidden" data-queue>
            <div class="row between"><b data-queue-text></b>
              <span class="row gap-sm"><button class="btn btn-sm" type="button" data-act="retry">Print now</button>
                <button class="btn btn-sm" type="button" data-act="clear">Discard</button></span></div>
          </div>
        </div>
        <div>
          <div class="field-label">What it prints</div>
          <label class="checkbox"><input type="checkbox" data-f="receipts"> Receipts, bills and X / Z readings</label>
          <label class="checkbox"><input type="checkbox" data-f="slips"> Order slips</label>
          <div class="ps-stations" data-stations></div>
          <div class="form-grid mt">
            <label class="field"><span class="field-label">Paper width</span>
              <select class="input" data-f="paper"><option value="58">58 mm (portable, 32 characters)</option><option value="80">80 mm (desk, 48 characters)</option></select></label>
            <label class="field"><span class="field-label">Receipt copies</span>
              <select class="input" data-f="copies"><option>1</option><option>2</option><option>3</option></select></label>
            <label class="field"><span class="field-label">Order slip copies</span>
              <select class="input" data-f="slipCopies"><option>1</option><option>2</option><option>3</option></select></label>
          </div>
          <label class="checkbox mt"><input type="checkbox" data-f="openDrawer"> Open the cash drawer with each receipt (drawer plugged into this printer)</label>
        </div>
      </div>
      <details class="mt">
        <summary class="bold">Advanced</summary>
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Bluetooth packet size</span>
            <select class="input" data-f="packetSize"><option value="auto">Automatic</option><option value="20">20 bytes (safest, slow)</option><option value="50">50 bytes</option>
              <option value="100">100 bytes</option><option value="180">180 bytes</option><option value="244">244 bytes</option><option value="512">512 bytes (fast)</option></select></label>
          <label class="field"><span class="field-label">Delay between packets</span>
            <select class="input" data-f="packetDelay"><option value="auto">Automatic</option><option value="0">0 ms</option><option value="10">10 ms</option><option value="20">20 ms</option>
              <option value="30">30 ms</option><option value="50">50 ms</option><option value="80">80 ms</option><option value="120">120 ms</option></select></label>
          <label class="field"><span class="field-label">Blank lines after each slip</span>
            <select class="input" data-f="feedLines"><?php foreach (range(0, 8) as $n): ?><option value="<?= $n ?>"><?= $n ?></option><?php endforeach; ?></select></label>
          <label class="field" data-only="serial"><span class="field-label">Baud rate (serial)</span>
            <select class="input" data-f="baudRate"><option value="9600">9600 (most printers)</option><option value="19200">19200</option><option value="38400">38400</option><option value="115200">115200</option></select></label>
        </div>
        <label class="checkbox mt"><input type="checkbox" data-f="cutter"> This printer has a paper cutter (desk printers)</label>
        <div data-only="bluetooth">
          <h4 class="mt">Printer channel</h4>
          <p class="muted small">Chosen automatically. Change it only if the test print stays blank while the printer is connected.</p>
          <div class="muted small" data-channels>Connect the printer to see its channels.</div>
        </div>
        <div class="row gap-sm mt">
          <button class="btn" type="button" data-act="disconnect">Disconnect</button>
          <button class="btn" type="button" data-act="forget">Forget device</button>
          <span class="grow"></span>
          <button class="btn btn-danger" type="button" data-act="remove">Remove this printer</button>
        </div>
      </details>
    </div>
  </div>
</template>

<script>
  window.PRINTER_BUSINESS = <?= json_encode($business, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  window.PRINTER_STATIONS = <?= json_encode(array_map(fn ($st) => ['id' => $st['id'], 'name' => $st['name']], $stations), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php // printer.js and printer-setup.js are loaded by the layout (PosController passes them as $scripts). ?>
<style>
  .mt-0 { margin-top: 0; } .mb-0 { margin-bottom: 0; } .mb-lg { margin-bottom: 20px; }
  .ps-printers { display: flex; flex-direction: column; gap: 16px; margin: 16px 0; }
  .ps-card .card-head { gap: 12px; }
  .ps-name { font-weight: 700; font-size: 16px; max-width: 320px; }
  .ps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  @media (max-width: 900px) { .ps-grid { grid-template-columns: 1fr; } }
  .ps-card .btn { min-height: 44px; }
  .ps-stations { margin: 4px 0 0 28px; display: flex; flex-direction: column; gap: 2px; }
  .method-list { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
  .method-option { display: flex; gap: 12px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; }
  .method-option:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }
  .method-option input { margin-top: 3px; width: 20px; height: 20px; accent-color: var(--brand); flex-shrink: 0; }
  .method-option > span { display: flex; flex-direction: column; gap: 3px; }
  .checkbox { min-height: 32px; }
  .checkbox input { width: 20px; height: 20px; }
  .cap-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .cap-list li { display: flex; gap: 10px; align-items: center; }
  .cap-list .yes { color: var(--green); font-weight: 700; } .cap-list .no { color: var(--red); font-weight: 700; }
  .queue-box { padding: 10px 12px; border-radius: 8px; background: var(--amber-soft); }
  .ps-log { background: #111827; color: #d1d5db; font-size: 11.5px; line-height: 1.5; padding: 10px; border-radius: 8px; max-height: 220px; overflow: auto; white-space: pre-wrap; margin: 0; }
  .ps-log .warn { color: #fde68a; } .ps-log .error { color: #fca5a5; }
  .channel-row { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-family: monospace; font-size: 12px; word-break: break-all; }
  .help ol, .help ul { padding-left: 20px; margin: 6px 0 14px; line-height: 1.6; }
  .help h4 { margin: 12px 0 4px; }
  .help code { word-break: break-all; }
  .trouble dt { font-weight: 700; margin-top: 10px; }
  .trouble dd { margin: 2px 0 0; color: var(--text-2); line-height: 1.5; }
  .ps-empty { padding: 24px; text-align: center; }
</style>
