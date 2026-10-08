/**
 * Printer setup page (views/pos/printer.php).
 * Every field whose name is a printer setting is saved on change through Printer.saveConfig() (printer.js keeps it
 * in this browser). The page also connects / tests the printer, shows its live status, the print queue, the
 * Bluetooth channels and the diagnostic log, and explains what this device can do.
 */
(function () {
  'use strict';
  const $ = (sel) => document.querySelector(sel);
  const esc = App.esc;
  const NAMES = { bluetooth: 'Bluetooth (BLE)', serial: 'USB / serial', rawbt: 'RawBT app', browser: 'Browser print dialog' };
  const BUSINESS = window.PRINTER_BUSINESS;

  // ------------------------------------------------------------------ settings <-> form fields
  /** Setting name -> how to read it from its field. */
  const FIELDS = {
    method: 'radio', paper: 'number', copies: 'number', kitchenCopies: 'number', currency: 'text', feedLines: 'number',
    packetSize: 'auto', packetDelay: 'auto', baudRate: 'number', bleFilter: 'filter',
    autoPrint: 'bool', kitchenSlip: 'bool', bigItems: 'bool', cutter: 'bool', openDrawer: 'bool', autoReconnect: 'bool', keepAwake: 'bool',
  };
  const fieldsOf = (key) => document.querySelectorAll(`#printer-setup [name="${key}"], .printer-setup ~ .grid-2 [name="${key}"]`);

  function fill() {
    const c = Printer.config();
    Object.entries(FIELDS).forEach(([key, kind]) => {
      fieldsOf(key).forEach((el) => {
        if (kind === 'radio') el.checked = el.value === c[key];
        else if (kind === 'bool') el.checked = !!c[key];
        else if (kind === 'filter') el.checked = c[key] === 'all';
        else el.value = String(c[key]);
      });
    });
    methodChanged(c.method);
  }

  function read(key, el) {
    const kind = FIELDS[key];
    if (kind === 'bool') return el.checked;
    if (kind === 'filter') return el.checked ? 'all' : 'printers';
    if (kind === 'number') return Number(el.value);
    if (kind === 'auto') return el.value === 'auto' ? 'auto' : Number(el.value);
    return el.value;
  }

  document.addEventListener('change', async (e) => {
    const key = e.target.name;
    if (!FIELDS[key]) return;
    const value = read(key, e.target);
    if (key === 'method' && value !== Printer.config().method) await Printer.disconnect(); // a different way of printing needs a new connection
    Printer.saveConfig({ [key]: value });
    $('#ps-saved').textContent = 'Saved on this device ✓';
    if (key === 'method') methodChanged(value);
  });

  /** Show the parts that apply to the chosen method (data-only="bluetooth serial") and its step-3 help. */
  function methodChanged(method) {
    document.querySelectorAll('[data-only]').forEach((el) => el.classList.toggle('hidden', !el.dataset.only.split(' ').includes(method)));
    const help = {
      bluetooth: 'Press “Connect printer” and pick your printer from the list Chrome shows (names like “BlueTooth Printer”, “MTP-II”, “PT-210”). Then press Test print.',
      serial: 'Press “Connect printer” and choose the printer’s COM port. Then press Test print.',
      rawbt: 'Press Test print: the RawBT app opens and prints. The first time, Android may ask which app to use — choose RawBT and “Always”.',
      browser: 'Press Test print: the print window opens. Choose your printer there.',
    };
    $('#ps-step3-help').textContent = help[method] || '';
    $('#ps-connect').classList.toggle('hidden', method !== 'bluetooth' && method !== 'serial');
    advice();
  }

  // ------------------------------------------------------------------ connect / test / queue
  async function busy(btn, fn) {
    btn.disabled = true;
    try { await fn(); } catch (err) { App.toast(err.message, err.queued ? 'info' : 'error'); } finally { btn.disabled = false; }
  }
  const click = (id, fn) => $(id).addEventListener('click', (e) => busy(e.currentTarget, fn));
  click('#ps-connect', async () => { await Printer.connect(); App.toast('Printer connected'); });
  click('#ps-reconnect', async () => { await Printer.reconnect(); App.toast('Printer connected'); });
  click('#ps-disconnect', () => Printer.disconnect());
  click('#ps-forget', async () => {
    if (await App.ask({ title: 'Forget this printer?', message: 'You will have to choose it again with “Connect printer”.', okText: 'Forget', danger: true })) await Printer.forget();
  });
  click('#ps-test', async () => { await Printer.testPrint({ business: BUSINESS }); App.toast('Test page sent to the printer'); });
  click('#ps-selftest', async () => { await Printer.selfTest({ business: BUSINESS }); App.toast('Self-test page sent to the printer'); });
  click('#ps-queue-retry', () => Printer.retryQueue());
  click('#ps-queue-clear', () => Printer.clearQueue());

  // ------------------------------------------------------------------ live status, channels
  function status(s) {
    const linkable = s.method === 'bluetooth' || s.method === 'serial';
    const badge = $('#ps-status');
    badge.className = 'badge ' + (s.connecting ? 'badge-blue' : s.connected ? 'badge-green' : 'badge-amber');
    badge.textContent = s.connecting ? 'Connecting…' : s.connected ? (linkable ? 'Connected' : 'Ready') : 'Not connected';
    $('#ps-status-text').innerHTML = linkable
      ? (s.connected ? `Connected to <b>${esc(s.deviceName || 'the printer')}</b> via ${NAMES[s.method]}.`
        : s.deviceName ? `${NAMES[s.method]}: <b>${esc(s.deviceName)}</b> is not connected. Switch it on, then press Reconnect.`
          : `${NAMES[s.method]}: no printer chosen yet. Press “Connect printer” (step 3).`)
      : (s.method === 'rawbt' ? 'Prints through the RawBT app.' : 'Prints with the browser print dialog.');
    $('#ps-error').classList.toggle('hidden', !s.lastError || s.connected);
    $('#ps-error').textContent = s.lastError || '';
    $('#ps-connect').textContent = s.deviceName && linkable ? 'Connect a different printer' : 'Connect printer';
    $('#ps-reconnect').disabled = s.connected || s.connecting || !s.deviceName;
    $('#ps-disconnect').disabled = !s.connected;
    $('#ps-forget').disabled = !s.deviceName;
    $('#ps-queue').classList.toggle('hidden', !s.queue);
    $('#ps-queue-text').textContent = `${s.queue} print job${s.queue === 1 ? '' : 's'} waiting for the printer`;
    channels();
  }

  /** Writable channels of the connected Bluetooth printer (Advanced). */
  function channels() {
    const list = Printer.channels().filter((c) => c.writable);
    const box = $('#ps-channels');
    if (!list.length) { box.innerHTML = 'Connect the printer to see its channels.'; return; }
    box.innerHTML = list.map((c, i) => `<label class="channel-row"><input type="radio" name="ps-channel" value="${i}" ${c.selected ? 'checked' : ''}>
      <span>${esc(c.characteristic)}<br><span class="muted">service ${esc(c.service)} · ${esc(c.properties.join(', '))}</span></span></label>`).join('');
    box.querySelectorAll('input').forEach((r) => r.addEventListener('change', () => {
      const c = list[Number(r.value)];
      try { Printer.setChannel(c.service, c.characteristic); App.toast('Channel changed — press Test print'); } catch (err) { App.toast(err.message, 'error'); }
    }));
  }

  // ------------------------------------------------------------------ diagnostic log
  const logLine = (l) => `<span class="${l.level}">${esc(l.time)}  ${esc(l.msg)}</span>\n`;
  function showLog() {
    const el = $('#ps-log');
    el.innerHTML = Printer.log().map(logLine).join('') || '<span>No messages yet.</span>';
    el.scrollTop = el.scrollHeight;
  }
  Printer.onLog(showLog);
  $('#ps-log-copy').addEventListener('click', async () => {
    const text = Printer.log().map((l) => `${l.time} [${l.level}] ${l.msg}`).join('\n') + `\n\n${navigator.userAgent}\n${location.origin}\n${JSON.stringify(Printer.config())}`;
    try { await navigator.clipboard.writeText(text); App.toast('Log copied'); } catch (err) {
      const range = document.createRange();          // no clipboard on plain http: select the text instead
      range.selectNodeContents($('#ps-log'));
      getSelection().removeAllRanges();
      getSelection().addRange(range);
      App.toast('Log selected — long-press to copy', 'info');
    }
  });

  // ------------------------------------------------------------------ what this device / browser supports
  function advice() {
    const c = Printer.capabilities();
    const method = Printer.config().method;
    const row = (ok, label, hint) => `<li><span class="${ok ? 'yes' : 'no'}">${ok ? '✓' : '✕'}</span><span>${esc(label)}${hint ? ` <span class="muted small">— ${esc(hint)}</span>` : ''}</span></li>`;
    $('#ps-caps').innerHTML = [
      row(c.secure, 'Secure address (https:// or localhost)', c.secure ? '' : `this page is ${location.origin}`),
      row(c.bluetooth, 'Bluetooth printing in this browser', c.bluetooth ? '' : navigator.bluetooth ? 'needs a secure address' : 'needs Chrome on Android, Windows or Mac'),
      row(c.serial, 'USB / COM port printing', c.serial ? '' : 'Chrome or Edge on a computer only'),
      row(c.wakeLock, 'Keep the screen on', c.wakeLock ? '' : 'not supported here'),
      row(c.android, 'Android device', c.android ? 'the RawBT app works here' : ''),
    ].join('');

    let tip;
    if (c.ios) tip = 'iPhone / iPad browsers cannot talk to Bluetooth printers. Use the Browser print dialog (AirPrint or a network printer).';
    else if (c.bluetooth) tip = 'Recommended: Bluetooth (BLE). Turn the printer on, press “Connect printer” and pick it from the list.';
    else if (c.android) tip = 'This address is not secure, so Chrome blocks Bluetooth here. Use the RawBT app, or allow this address in Chrome (see “Using the POS over the shop Wi-Fi” below).';
    else if (c.serial) tip = 'On a computer, use USB / serial for a USB printer or a Bluetooth printer paired as a COM port.';
    else tip = 'This browser cannot reach thermal printers directly. Use the Browser print dialog, or open the POS in Chrome over https / localhost.';
    $('#ps-advice').textContent = tip;
    const warn = $('#ps-method-warn');
    const blocked = (method === 'bluetooth' && !c.bluetooth) || (method === 'serial' && !c.serial);
    warn.classList.toggle('hidden', !blocked);
    warn.textContent = blocked ? `${NAMES[method]} does not work in this browser / at this address. ${tip}` : '';
  }

  // The real address of this POS, for the Chrome flag instructions
  document.querySelectorAll('#ps-origin, .ps-origin-copy').forEach((el) => { el.textContent = location.origin; });

  fill();
  showLog();
  Printer.onStatus(status);
})();
