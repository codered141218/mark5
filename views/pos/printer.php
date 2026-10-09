<?php
/**
 * Printer setup for this device — built for a portable 58 mm Bluetooth thermal printer on an Android tablet,
 * but also covers RawBT, USB/serial and the browser print dialog. Driven by public/assets/js/printer-setup.js;
 * the settings are stored in the browser (localStorage) by printer.js, because each tablet / PC has its own printer.
 */
use App\Services\Settings;

$s = Settings::all();
$business = [
    'name' => $s['business_name'], 'address' => $s['business_address'], 'tin' => $s['business_tin'], 'phone' => $s['business_phone'],
    'receipt_title' => $s['receipt_title'], 'receipt_footer' => $s['receipt_footer'],
];
$methods = [
    'bluetooth' => ['Bluetooth (BLE) — recommended', 'Chrome prints straight to the printer. Needs a secure address (https://… or localhost), or the Chrome setting in “Using the POS over the shop Wi-Fi” below.'],
    'rawbt' => ['RawBT app (Android)', 'Free app from the Play Store. Works with any Bluetooth thermal printer — also older “classic” Bluetooth models — and over plain http:// on the shop Wi-Fi.'],
    'serial' => ['USB / Bluetooth serial (COM port)', 'Chrome or Edge on a Windows / desktop computer: a USB printer, or a Bluetooth printer paired in Windows as a COM port.'],
    'browser' => ['Browser print dialog', 'The normal print window. Works everywhere (Wi-Fi / office printers) but asks before every print.'],
];
$select = fn (string $name, array $opts) => '<select class="input" name="' . e($name) . '">' . options($opts) . '</select>';
?>
<div class="page-header">
  <div>
    <h1>Printer setup</h1>
    <p class="muted">Receipt and kitchen printer of <b>this device</b>. Every tablet or PC keeps its own printer settings.</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/pos') ?>">▶ Open POS</a></div>
</div>

<div class="grid-2 printer-setup" id="printer-setup">
  <!-- ============================================================ wizard -->
  <div class="card">
    <div class="card-head"><h3>Set up in 3 steps</h3><span class="muted small" id="ps-saved"></span></div>
    <div class="pad wizard">
      <section class="wz-step">
        <div class="wz-num">1</div>
        <div>
          <h4>Turn on the printer</h4>
          <p class="muted small">Charge it, load the paper roll (shiny side facing the print head) and switch it on. Keep it near the tablet.
            Make sure it is <b>not connected to another phone or app</b> — a Bluetooth printer talks to one device at a time.</p>
        </div>
      </section>
      <section class="wz-step">
        <div class="wz-num">2</div>
        <div class="grow">
          <h4>Choose how this device prints</h4>
          <form id="ps-form" autocomplete="off" onsubmit="return false">
            <div class="method-list">
              <?php foreach ($methods as $key => [$label, $help]): ?>
                <label class="method-option" data-method="<?= e($key) ?>">
                  <input type="radio" name="method" value="<?= e($key) ?>">
                  <span><b><?= e($label) ?></b><span class="muted small"><?= e($help) ?></span></span>
                </label>
              <?php endforeach; ?>
            </div>
          </form>
          <div class="alert alert-warn small mt hidden" id="ps-method-warn"></div>
        </div>
      </section>
      <section class="wz-step">
        <div class="wz-num">3</div>
        <div class="grow">
          <h4>Connect and test</h4>
          <p class="muted small" id="ps-step3-help"></p>
          <div class="row gap-sm wrap">
            <button class="btn btn-primary btn-lg" type="button" id="ps-connect">Connect printer</button>
            <button class="btn btn-lg" type="button" id="ps-test">⎙ Test print</button>
            <button class="btn btn-lg" type="button" id="ps-selftest">Self-test page</button>
          </div>
          <label class="checkbox mt" data-only="bluetooth"><input type="checkbox" form="ps-form" name="bleFilter" value="all"> Show all Bluetooth devices (use when your printer is not in the list)</label>
        </div>
      </section>
    </div>
  </div>

  <div class="stack">
    <!-- ============================================================ live status -->
    <div class="card">
      <div class="card-head"><h3>Printer status</h3><span class="badge" id="ps-status">…</span></div>
      <div class="pad">
        <p class="mt-0" id="ps-status-text"></p>
        <div class="alert alert-error small hidden" id="ps-error"></div>
        <div class="row gap-sm wrap" data-only="bluetooth serial">
          <button class="btn" type="button" id="ps-reconnect">↻ Reconnect</button>
          <button class="btn" type="button" id="ps-disconnect">Disconnect</button>
          <button class="btn" type="button" id="ps-forget">Forget printer</button>
        </div>
        <div class="queue-box mt hidden" id="ps-queue">
          <div class="row between"><b id="ps-queue-text"></b>
            <span class="row gap-sm"><button class="btn btn-sm" type="button" id="ps-queue-retry">Print now</button>
              <button class="btn btn-sm" type="button" id="ps-queue-clear">Discard</button></span></div>
          <p class="muted small mb-0">Jobs printed while the printer was off are kept here and print by themselves when it reconnects.</p>
        </div>
      </div>
    </div>

    <!-- ============================================================ this device -->
    <div class="card">
      <div class="card-head"><h3>This device</h3></div>
      <div class="pad">
        <ul class="cap-list" id="ps-caps"></ul>
        <div class="alert alert-info small mt mb-0" id="ps-advice"></div>
      </div>
    </div>
  </div>
</div>

<div class="grid-2 mt-lg">
  <!-- ============================================================ receipt & slips -->
  <div class="card">
    <div class="card-head"><h3>Receipts &amp; kitchen slips</h3></div>
    <div class="pad">
      <div class="form-grid">
        <label class="field"><span class="field-label">Paper width</span><?= $select('paper', ['58' => '58 mm (portable, 32 characters)', '80' => '80 mm (desk, 48 characters)']) ?></label>
        <label class="field"><span class="field-label">Receipt copies</span><?= $select('copies', ['1' => '1', '2' => '2', '3' => '3']) ?></label>
        <label class="field"><span class="field-label">Kitchen slip copies</span><?= $select('kitchenCopies', ['1' => '1', '2' => '2', '3' => '3']) ?></label>
        <label class="field"><span class="field-label">Peso sign on paper</span><?= $select('currency', ['P' => 'P 1,234.00', 'PHP' => 'PHP 1,234.00']) ?>
          <span class="field-hint">Thermal printers cannot print ₱.</span></label>
        <label class="field"><span class="field-label">Blank lines after each slip</span><?= $select('feedLines', array_combine(range(0, 8), range(0, 8))) ?>
          <span class="field-hint">So the slip can be torn off.</span></label>
      </div>
      <div class="col gap-sm mt-lg">
        <label class="checkbox"><input type="checkbox" form="ps-form" name="autoPrint"> Print the receipt automatically after payment</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="kitchenSlip"> Print a kitchen slip when orders are sent to the kitchen</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="bigItems"> Big item lines on kitchen / order slips (double height)</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="cutter"> The printer has a paper cutter (desk printers; portable printers have none)</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="openDrawer"> Open the cash drawer when a receipt prints (drawer plugged into the printer)</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="autoReconnect"> Reconnect automatically when the printer wakes up, and keep missed prints</label>
        <label class="checkbox"><input type="checkbox" form="ps-form" name="keepAwake"> Keep the tablet screen on while the POS is open</label>
      </div>
    </div>
  </div>

  <!-- ============================================================ advanced -->
  <div class="card">
    <div class="card-head"><h3>Advanced (Bluetooth)</h3></div>
    <div class="pad">
      <p class="muted small mt-0">If prints are cut off, garbled or stop half-way, choose a <b>smaller packet size</b> and a <b>longer delay</b>, then test again.</p>
      <div class="form-grid">
        <label class="field"><span class="field-label">Packet size</span>
          <?= $select('packetSize', ['auto' => 'Automatic', '20' => '20 bytes (safest, slow)', '50' => '50 bytes', '100' => '100 bytes', '180' => '180 bytes', '244' => '244 bytes', '512' => '512 bytes (fast)']) ?></label>
        <label class="field"><span class="field-label">Delay between packets</span>
          <?= $select('packetDelay', ['auto' => 'Automatic', '0' => '0 ms', '10' => '10 ms', '20' => '20 ms', '30' => '30 ms', '50' => '50 ms', '80' => '80 ms', '120' => '120 ms']) ?></label>
        <label class="field" data-only="serial"><span class="field-label">Baud rate (serial)</span>
          <?= $select('baudRate', ['9600' => '9600 (most printers)', '19200' => '19200', '38400' => '38400', '115200' => '115200']) ?></label>
      </div>
      <h4 class="mt-lg">Printer channel</h4>
      <p class="muted small">Chosen automatically. Change it only if the test print stays blank while the printer is connected.</p>
      <div id="ps-channels" class="muted small">Connect the printer to see its channels.</div>
      <h4 class="mt-lg row between"><span>Diagnostic log</span><button class="btn btn-sm" type="button" id="ps-log-copy">Copy</button></h4>
      <pre class="ps-log" id="ps-log"></pre>
    </div>
  </div>
</div>

<!-- ============================================================ help -->
<div class="grid-2 mt-lg">
  <div class="card">
    <div class="card-head"><h3>Using the POS over the shop Wi-Fi (Android)</h3></div>
    <div class="pad help">
      <p class="mt-0">Chrome only allows Bluetooth on secure addresses (<code>https://…</code> or <code>localhost</code>).
        When the tablet opens the POS by a local address such as <code id="ps-origin">http://192.168.1.10</code>, choose one of these:</p>
      <h4>A. RawBT app (easiest)</h4>
      <ol>
        <li>Install <b>RawBT</b> from the Play Store.</li>
        <li>Pair the printer in Android <b>Settings → Bluetooth</b> (PIN is usually 0000 or 1234).</li>
        <li>Open RawBT, choose the printer and print its test page.</li>
        <li>Here, choose <b>RawBT app</b> in step 2, then press Test print.</li>
      </ol>
      <h4>B. Allow Bluetooth for this address in Chrome</h4>
      <ol>
        <li>In Chrome on the tablet open <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code></li>
        <li>Type the POS address in the box, e.g. <code class="ps-origin-copy">http://192.168.1.10</code></li>
        <li>Set it to <b>Enabled</b> and tap <b>Relaunch</b>.</li>
        <li>Come back here, choose <b>Bluetooth (BLE)</b> and press Connect printer.</li>
      </ol>
      <p class="muted small">Tip: install the POS as an app (Chrome menu → <b>Add to Home screen</b> / <b>Install app</b>) so it opens full-screen.</p>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Troubleshooting</h3></div>
    <div class="pad help">
      <dl class="trouble">
        <dt>The printer is not in the Bluetooth list</dt>
        <dd>Tick <b>Show all Bluetooth devices</b> (step 3) and connect again. Switch the printer off and on. Make sure no other phone or app is connected to it.</dd>
        <dt>It connects but nothing prints, or it is not found at all</dt>
        <dd>Some printers only speak “classic” Bluetooth, not BLE: use the <b>RawBT app</b>. Also try another channel under Advanced.</dd>
        <dt>Prints are garbled, cut off or stop half-way</dt>
        <dd>Choose a smaller <b>packet size</b> (e.g. 50 or 20) and a longer <b>delay</b> (e.g. 30 ms) under Advanced.</dd>
        <dt>Nothing comes out at all</dt>
        <dd>Run <b>Test print</b>. Check the paper roll is the right way round (blank paper = roll upside down), the cover is closed and the battery is charged.</dd>
        <dt>It stops printing after a while</dt>
        <dd>Portable printers fall asleep. Keep <b>Reconnect automatically</b> on: missed prints are kept and come out when the printer wakes up. Turn off the printer’s auto power-off if it has one.</dd>
        <dt>Text is too wide / wraps</dt>
        <dd>Set the right <b>paper width</b> (58 mm for most portable printers).</dd>
      </dl>
    </div>
  </div>
</div>

<script>window.PRINTER_BUSINESS = <?= json_encode($business, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php // printer.js and printer-setup.js are loaded by the layout (PosController passes them as $scripts). ?>
<style>
  .mt-0 { margin-top: 0; } .mb-0 { margin-bottom: 0; }
  .printer-setup .btn-lg, .printer-setup .btn { min-height: 44px; }
  .wizard { display: flex; flex-direction: column; gap: 18px; }
  .wz-step { display: flex; gap: 14px; align-items: flex-start; }
  .wz-step h4 { margin: 4px 0 6px; font-size: 15px; }
  .wz-num { width: 34px; height: 34px; border-radius: 50%; background: var(--brand); color: #fff; font-weight: 800; display: grid; place-items: center; flex-shrink: 0; }
  .method-list { display: flex; flex-direction: column; gap: 8px; }
  .method-option { display: flex; gap: 12px; align-items: flex-start; padding: 12px; border: 1px solid var(--border); border-radius: 8px; cursor: pointer; }
  .method-option:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }
  .method-option input { margin-top: 3px; width: 20px; height: 20px; accent-color: var(--brand); flex-shrink: 0; }
  .method-option > span { display: flex; flex-direction: column; gap: 3px; }
  .checkbox { min-height: 32px; }
  .checkbox input { width: 20px; height: 20px; }
  .cap-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .cap-list li { display: flex; gap: 10px; align-items: center; }
  .cap-list .yes { color: var(--green); font-weight: 700; } .cap-list .no { color: var(--red); font-weight: 700; }
  .queue-box { padding: 10px 12px; border-radius: 8px; background: var(--amber-soft); }
  .ps-log { background: #111827; color: #d1d5db; font-size: 11.5px; line-height: 1.5; padding: 10px; border-radius: 8px; max-height: 220px; overflow: auto; white-space: pre-wrap; margin: 6px 0 0; }
  .ps-log .warn { color: #fde68a; } .ps-log .error { color: #fca5a5; }
  .channel-row { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid var(--border); font-family: monospace; font-size: 12px; word-break: break-all; }
  .help ol { padding-left: 20px; margin: 6px 0 14px; line-height: 1.6; }
  .help h4 { margin: 12px 0 4px; }
  .help code { word-break: break-all; }
  .trouble dt { font-weight: 700; margin-top: 10px; }
  .trouble dd { margin: 2px 0 0; color: var(--text-2); line-height: 1.5; }
</style>
