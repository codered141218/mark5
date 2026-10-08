/**
 * Printer setup page (views/pos/printer.php).
 * Reads / saves this device's printer settings through window.Printer (printer.js), connects the
 * Bluetooth or serial printer, sends a test print and explains what this browser can do.
 */
(function () {
  'use strict';
  const $ = (sel) => document.querySelector(sel);
  const form = $('#ps-form');
  const NAMES = { bluetooth: 'Bluetooth (BLE)', serial: 'USB / serial', rawbt: 'RawBT app', browser: 'Browser print dialog' };

  // ------------------------------------------------------------------ settings form
  function fill() {
    const c = Printer.config();
    form.querySelectorAll('[name=method]').forEach((r) => { r.checked = r.value === c.method; });
    form.paper.value = String(c.paper);
    form.copies.value = c.copies;
    form.baudRate.value = String(c.baudRate || 9600);
    form.autoPrint.checked = !!c.autoPrint;
    form.kitchenSlip.checked = !!c.kitchenSlip;
    form.openDrawer.checked = !!c.openDrawer;
    showOnly(c.method);
  }

  function read() {
    return {
      method: (form.querySelector('[name=method]:checked') || {}).value || 'browser',
      paper: Number(form.paper.value) === 80 ? 80 : 58,
      copies: Math.max(1, Math.min(5, Number(form.copies.value) || 1)),
      baudRate: Number(form.baudRate.value) || 9600,
      autoPrint: form.autoPrint.checked,
      kitchenSlip: form.kitchenSlip.checked,
      openDrawer: form.openDrawer.checked,
    };
  }

  /** Fields that only apply to one method (data-only="serial"). */
  function showOnly(method) {
    form.querySelectorAll('[data-only]').forEach((el) => el.classList.toggle('hidden', el.dataset.only !== method));
  }

  // Settings save as soon as they change
  form.addEventListener('change', async (e) => {
    const before = Printer.config().method;
    const cfg = read();
    if (cfg.method !== before) await Printer.disconnect(); // a different method needs a new connection
    Printer.saveConfig(cfg);
    showOnly(cfg.method);
    $('#ps-saved').textContent = 'Saved on this device ✓';
    if (e.target.name === 'method') advice();
  });
  form.addEventListener('submit', (e) => e.preventDefault());

  // ------------------------------------------------------------------ connection + test print
  function status(s) {
    const linkable = s.method === 'bluetooth' || s.method === 'serial';
    const badge = $('#ps-status');
    badge.className = 'badge ' + (s.connected ? 'badge-green' : 'badge-amber');
    badge.textContent = s.connected ? (linkable ? 'Connected' : 'Ready') : 'Not connected';
    $('#ps-status-text').textContent = linkable
      ? (s.connected ? `Connected to ${s.deviceName || 'the printer'} via ${NAMES[s.method]}.` : `${NAMES[s.method]}: press “Connect printer” and choose the printer in the list the browser shows.`)
      : (s.method === 'rawbt' ? 'Prints through the RawBT app. Make sure RawBT is installed and paired with the printer.' : 'Prints with the browser print dialog.');
    $('#ps-connect').classList.toggle('hidden', !linkable);
    $('#ps-connect').textContent = s.connected ? 'Reconnect' : 'Connect printer';
    $('#ps-disconnect').classList.toggle('hidden', !linkable || !s.connected);
  }

  async function busy(btn, fn) {
    btn.disabled = true;
    try { await fn(); } catch (err) { App.toast(err.message, 'error'); } finally { btn.disabled = false; }
  }
  $('#ps-connect').addEventListener('click', (e) => busy(e.currentTarget, async () => { await Printer.connect(); App.toast('Printer connected'); }));
  $('#ps-disconnect').addEventListener('click', (e) => busy(e.currentTarget, () => Printer.disconnect()));
  $('#ps-test').addEventListener('click', (e) => busy(e.currentTarget, async () => {
    await Printer.testPrint({ business: window.PRINTER_BUSINESS });
    App.toast('Test page sent to the printer');
  }));

  // ------------------------------------------------------------------ what this device / browser supports
  function advice() {
    const c = Printer.capabilities();
    const method = read().method;
    const row = (ok, label, hint) => `<li><span class="${ok ? 'yes' : 'no'}">${ok ? '✓' : '✕'}</span><span>${App.esc(label)}${hint ? ` <span class="muted small">— ${App.esc(hint)}</span>` : ''}</span></li>`;
    $('#ps-caps').innerHTML = [
      row(c.secure, 'Secure address (https:// or localhost)', c.secure ? '' : `this page is ${location.protocol}//${location.host}`),
      row(c.bluetooth, 'Web Bluetooth (Bluetooth / BLE printers)', navigator.bluetooth ? '' : 'needs Chrome on Android, Windows or Mac'),
      row(c.serial, 'Web Serial (USB / COM port printers)', navigator.serial ? '' : 'needs Chrome or Edge on a computer'),
      row(c.android, 'Android device', c.android ? 'the RawBT app works here' : ''),
    ].join('');

    let tip;
    if (c.ios) tip = 'iPhone / iPad browsers cannot talk to Bluetooth printers. Use the Browser print dialog (AirPrint or a network printer).';
    else if (c.bluetooth) tip = 'Recommended: Bluetooth (BLE). Turn the printer on, press “Connect printer” and pick it from the list.';
    else if (c.android) tip = c.secure ? 'Use RawBT, or open the POS in Chrome to print over Bluetooth directly.'
      : 'This page is not on https, so Chrome cannot use Bluetooth here. Recommended: install the RawBT app, pair the printer in RawBT and choose “RawBT app”.';
    else if (c.serial) tip = 'On a computer, use USB / serial for a USB printer or a Bluetooth printer paired as a COM port.';
    else tip = 'This browser cannot reach thermal printers directly. Use the Browser print dialog, or open the POS in Chrome over https / localhost.';
    if ((method === 'bluetooth' && !c.bluetooth) || (method === 'serial' && !c.serial)) tip = `⚠ ${NAMES[method]} is not available in this browser / address. ${tip}`;
    $('#ps-advice').textContent = tip;
  }

  fill();
  advice();
  Printer.onStatus(status);
})();
