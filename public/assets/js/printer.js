/**
 * Mark5 receipt printing — built for portable 58 mm Bluetooth thermal printers on Android tablets,
 * and also 80 mm desk printers.
 *
 * One receipt layout ("doc") is rendered two ways:
 *   - ESC/POS bytes for thermal printers (58 mm = 32 characters, 80 mm = 48 characters), sent through
 *       bluetooth : Web Bluetooth (BLE printers) — Chrome on Android / Windows / Mac. Needs HTTPS or localhost
 *                   (or the Chrome flag "Insecure origins treated as secure" for a LAN address).
 *       serial    : Web Serial — a USB printer or a classic Bluetooth printer paired as a COM port (Chrome desktop).
 *       rawbt     : the RawBT app on Android (any Bluetooth thermal printer, works over plain http on the LAN).
 *   - HTML for the browser print dialog (method "browser", and the fallback when a printer is unavailable).
 *
 * Portable printers go to sleep and drop the connection, so this module: remembers the printer, reconnects
 * automatically (with back-off), keeps jobs that failed while disconnected in a queue and prints them on reconnect,
 * lets you tune the Bluetooth packet size / delay for printers that garble long receipts, and keeps a diagnostic log.
 *
 * Settings are stored per device (localStorage), because every tablet / PC has its own printer.
 *
 * API (window.Printer):
 *   config(), saveConfig(cfg), capabilities(), status(), onStatus(fn), log(), onLog(fn)
 *   isConnected(), connect() [needs a click], reconnect(), disconnect(), forget(), channels(), setChannel(svc, chr)
 *   printReceipt(ticket, ctx), printKitchen(ticket, lines, ctx), printOrderSlip(ticket, ctx), printReading(report, ctx, isZ),
 *   testPrint(ctx), selfTest(ctx), retryQueue(), clearQueue(), dropJob(id), keepAwake(on)
 *   ctx = { business: {name, address, tin, phone, receipt_title, receipt_footer}, tax: {vatRegistered, vatRate},
 *           methods: [{key, label}], reprint: bool, method: 'browser' (optional: force the browser dialog) }
 * A print that cannot reach a disconnected printer rejects with an Error whose .queued = true and .jobId set:
 * the job prints by itself when the printer reconnects (call dropJob(id) if you print it another way instead).
 */
(function () {
  'use strict';

  const STORE_KEY = 'mark5_printer';
  const DEFAULTS = {
    method: 'browser', paper: 58, autoPrint: true, kitchenSlip: true, copies: 1, kitchenCopies: 1, openDrawer: false,
    deviceName: '', deviceId: '', channel: null, baudRate: 9600,
    bleFilter: 'printers', packetSize: 'auto', packetDelay: 'auto', cutter: false, feedLines: 3,
    currency: 'P', bigItems: true, autoReconnect: true, keepAwake: true,
  };

  // Service UUIDs used by common BLE thermal printers (Goojprt PT-210, Xprinter, MTP-II, PeriPage, Rongta,
  // generic "BlueTooth Printer" ...). Web Bluetooth can only talk to services listed here.
  const BLE_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '0000af30-0000-1000-8000-00805f9b34fb',
    '0000ff12-0000-1000-8000-00805f9b34fb',
  ];
  // Name prefixes of common portable printers, used by the "printers only" device filter
  const NAME_PREFIXES = ['Printer', 'BlueTooth Printer', 'BT', 'MTP', 'MPT', 'PT-', 'PT2', 'RPP', 'ZJ-', 'XP-', 'Xprinter', 'GOOJPRT',
    'InnerPrinter', 'POS', 'Thermal', 'P58', 'P80', 'PeriPage', 'HM-', 'MP', 'QR', 'Rongta', 'SP-', 'JP-', 'M58', 'T58', 'T80'];

  // ------------------------------------------------------------------ settings
  let memoryConfig = null;   // used when localStorage is unavailable (private mode)
  function config() {
    try { return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORE_KEY) || '{}') }; } catch (e) { return { ...DEFAULTS, ...(memoryConfig || {}) }; }
  }
  function saveConfig(cfg) {
    const next = { ...config(), ...cfg };
    try { localStorage.setItem(STORE_KEY, JSON.stringify(next)); } catch (e) { memoryConfig = next; }
    if ('keepAwake' in cfg) keepAwake(!!next.keepAwake && wake.wanted);
    emit();
    return next;
  }
  function capabilities() {
    return {
      secure: window.isSecureContext,
      bluetooth: !!(navigator.bluetooth && window.isSecureContext),
      serial: !!(navigator.serial && window.isSecureContext),
      wakeLock: !!navigator.wakeLock,
      android: /Android/i.test(navigator.userAgent),
      ios: /iPhone|iPad|iPod/i.test(navigator.userAgent),
    };
  }

  // ------------------------------------------------------------------ diagnostic log
  const logLines = [];
  const logListeners = [];
  function addLog(level, msg) {
    const entry = { time: new Date().toLocaleTimeString('en-PH'), level, msg: String(msg) };
    logLines.push(entry);
    if (logLines.length > 100) logLines.shift();
    logListeners.forEach((fn) => { try { fn(entry); } catch (e) { /* ignore */ } });
  }
  const log = () => logLines.slice();
  const onLog = (fn) => { logListeners.push(fn); };

  // ------------------------------------------------------------------ text helpers
  const pesoFmt = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const money = (n) => pesoFmt.format(Number(n) || 0);
  const qtyStr = (n) => String(Math.round((Number(n) || 0) * 1000) / 1000);
  function dt(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : new Date();
    if (Number.isNaN(d.getTime())) return String(s || '');
    return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  /** Thermal printers use old code pages: keep plain ASCII (₱ -> P or PHP, ñ -> n). */
  function ascii(s) {
    const peso = config().currency === 'PHP' ? 'PHP ' : 'P';
    return String(s ?? '').replace(/₱\s?/g, peso).replace(/[–—]/g, '-').replace(/[“”]/g, '"').replace(/[‘’]/g, "'")
      .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\x20-\x7E]/g, '?');
  }
  function wrap(text, width) {
    const indent = (String(text).match(/^\s*/) || [''])[0];
    const words = String(text).trim().split(/\s+/);
    const lines = [];
    let cur = indent;
    for (const w of words) {
      if (cur.trim() === '') cur = indent + w;
      else if ((cur + ' ' + w).length <= width) cur += ' ' + w;
      else { lines.push(cur); cur = indent + w; }
      while (cur.length > width) { lines.push(cur.slice(0, width)); cur = cur.slice(width); }
    }
    if (cur.trim() || !lines.length) lines.push(cur);
    return lines;
  }

  // ------------------------------------------------------------------ document model
  /** A receipt as a list of operations, independent of the output format. */
  class Doc {
    constructor(cols) { this.cols = cols; this.ops = []; }
    text(s, o = {}) { this.ops.push({ t: 'text', s: String(s ?? ''), align: o.align || 'left', bold: !!o.bold, big: !!o.big }); return this; }
    center(s, o = {}) { return this.text(s, { ...o, align: 'center' }); }
    lr(left, right, o = {}) { this.ops.push({ t: 'lr', l: String(left ?? ''), r: String(right ?? ''), bold: !!o.bold, big: !!o.big }); return this; }
    hr() { this.ops.push({ t: 'hr' }); return this; }
    feed(n = 1) { this.ops.push({ t: 'feed', n }); return this; }
  }

  /** Lay out a left/right line in `cols` characters (long left text wraps). */
  function lrLines(l, r, cols) {
    if (l.length + r.length + 1 <= cols) return [l + ' '.repeat(cols - l.length - r.length) + r];
    const lines = wrap(l, cols);
    const last = lines[lines.length - 1];
    if (last.length + r.length + 1 <= cols) lines[lines.length - 1] = last + ' '.repeat(cols - last.length - r.length) + r;
    else lines.push(' '.repeat(Math.max(cols - r.length, 0)) + r);
    return lines;
  }

  // ------------------------------------------------------------------ ESC/POS renderer
  const ESC = 0x1b; const GS = 0x1d;
  function escpos(doc, { drawer = false } = {}) {
    const cfg = config();
    const out = [];
    const push = (...b) => out.push(...b);
    const str = (s) => { for (const ch of ascii(s)) out.push(ch.charCodeAt(0)); };
    push(ESC, 0x40);            // initialize
    push(ESC, 0x74, 0x00);      // code page PC437
    for (const op of doc.ops) {
      if (op.t === 'hr') { push(ESC, 0x61, 0); str('-'.repeat(doc.cols)); push(0x0a); continue; }
      if (op.t === 'feed') { for (let i = 0; i < op.n; i++) push(0x0a); continue; }
      push(ESC, 0x45, op.bold ? 1 : 0);                 // bold on/off
      push(GS, 0x21, op.big ? 0x01 : 0x00);             // double height for "big" (keeps the column count)
      if (op.t === 'text') {
        push(ESC, 0x61, op.align === 'center' ? 1 : op.align === 'right' ? 2 : 0);
        for (const line of wrap(ascii(op.s), doc.cols)) { str(line); push(0x0a); }
      } else {
        push(ESC, 0x61, 0);
        for (const line of lrLines(ascii(op.l), ascii(op.r), doc.cols)) { str(line); push(0x0a); }
      }
    }
    push(ESC, 0x45, 0, GS, 0x21, 0, ESC, 0x61, 0);
    const feed = Math.max(0, Math.min(Number(cfg.feedLines) || 0, 8));
    for (let i = 0; i < feed; i++) push(0x0a);            // paper feed so the tear-off clears the print head
    if (cfg.cutter) push(GS, 0x56, 0x42, 0x00);          // feed and partial cut (desk printers with a cutter)
    if (drawer) push(ESC, 0x70, 0x00, 0x19, 0xfa);       // kick cash drawer (pin 2)
    return new Uint8Array(out);
  }

  // ------------------------------------------------------------------ HTML renderer (browser print dialog)
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function html(doc, paper) {
    // Printable width is 48 mm (58 mm paper) or 72 mm (80 mm paper); size the monospace font so that
    // exactly 32 / 48 characters fit (a Courier character is 0.6 em wide): 48 / 32 / 0.6 = 2.5 mm.
    const width = Number(paper) === 80 ? 72 : 48;
    let h = `<div class="receipt" style="width:${width}mm;font-size:${(width / doc.cols / 0.6).toFixed(2)}mm;padding:0">`;
    for (const op of doc.ops) {
      const style = `${op.bold ? 'font-weight:bold;' : ''}${op.big ? 'font-size:1.25em;line-height:1.5;' : ''}`;
      if (op.t === 'hr') h += '<hr>';
      else if (op.t === 'feed') h += '<br>'.repeat(op.n);
      else if (op.t === 'text') h += `<div style="text-align:${op.align};${style}">${esc(op.s)}</div>`;
      else h += `<div class="r" style="${style}"><span>${esc(op.l)}</span><span>${esc(op.r)}</span></div>`;
    }
    return h + '</div>';
  }
  function browserPrint(markup) {
    return new Promise((resolve) => {
      let root = document.getElementById('print-root');
      if (!root) { root = document.createElement('div'); root.id = 'print-root'; document.body.appendChild(root); }
      root.innerHTML = markup;
      setTimeout(() => { window.print(); resolve(); }, 60);
    });
  }

  // ------------------------------------------------------------------ connection state
  const state = {
    ble: { device: null, characteristic: null, channels: [], packet: 180 },
    serial: { port: null },
    connecting: false,
    lastError: '',
    queue: [],                 // [{id, label, bytes, time}]
    reconnectTimer: null,
    reconnectDelay: 1000,
  };
  const listeners = [];
  function status() {
    const c = config();
    return { connected: isConnected(), connecting: state.connecting, method: c.method, deviceName: c.deviceName, queue: state.queue.length, lastError: state.lastError };
  }
  function emit() { const s = status(); listeners.forEach((fn) => { try { fn(s); } catch (e) { /* ignore */ } }); }
  function onStatus(fn) { listeners.push(fn); fn(status()); }

  function isConnected() {
    const m = config().method;
    if (m === 'bluetooth') return !!(state.ble.device && state.ble.device.gatt && state.ble.device.gatt.connected && state.ble.characteristic);
    if (m === 'serial') return !!(state.serial.port && state.serial.port.writable);
    return true;
  }

  function fail(msg) {
    state.lastError = msg;
    addLog('error', msg);
    emit();
    return new Error(msg);
  }

  const withTimeout = (p, ms, msg) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error(msg)), ms))]);

  /** Find every writable characteristic in the printer's services (the user can pick one under Advanced). */
  async function discoverChannels(server) {
    const found = [];
    for (const svc of await server.getPrimaryServices()) {
      for (const ch of await svc.getCharacteristics()) {
        const p = ch.properties;
        const props = ['read', 'write', 'writeWithoutResponse', 'notify', 'indicate'].filter((k) => p[k]);
        found.push({ service: svc.uuid, characteristic: ch.uuid, properties: props, writable: p.write || p.writeWithoutResponse, ref: ch });
      }
    }
    return found;
  }

  async function bleAttach(device) {
    state.connecting = true;
    emit();
    try {
      addLog('info', `Connecting to ${device.name || 'Bluetooth device'}…`);
      const server = await withTimeout(device.gatt.connect(), 12000, 'The printer did not answer. Is it switched on and close by?');
      const channels = await discoverChannels(server);
      const writable = channels.filter((c) => c.writable);
      if (!writable.length) throw new Error('This Bluetooth device has no printable channel. Is it a thermal printer? (Classic-only printers: use RawBT.)');
      const saved = config().channel;
      const chosen = (saved && writable.find((c) => c.service === saved.service && c.characteristic === saved.characteristic))
        || writable.find((c) => c.properties.includes('writeWithoutResponse')) || writable[0];
      state.ble = { device, characteristic: chosen.ref, channels, packet: state.ble.packet || 180 };
      if (!device.__mark5) {
        device.__mark5 = true;
        device.addEventListener('gattserverdisconnected', onBleDisconnected);
      }
      state.lastError = '';
      state.reconnectDelay = 1000;
      saveConfig({ method: 'bluetooth', deviceName: device.name || 'Bluetooth printer', deviceId: device.id || '' });
      addLog('info', `Connected: ${device.name || 'printer'} (channel ${chosen.characteristic.slice(4, 8)})`);
    } catch (e) {
      throw fail(e.message || String(e));
    } finally {
      state.connecting = false;
      emit();
    }
    retryQueue();
  }

  function onBleDisconnected() {
    state.ble.characteristic = null;
    addLog('warn', 'Printer disconnected (switched off, out of range or asleep).');
    emit();
    scheduleReconnect();
  }

  /** Try again after 1 s, 2 s, 5 s, 10 s, then every 30 s while the page is visible. */
  function scheduleReconnect() {
    const cfg = config();
    if (!cfg.autoReconnect || cfg.method !== 'bluetooth' || !state.ble.device || state.reconnectTimer) return;
    const delay = state.reconnectDelay;
    state.reconnectTimer = setTimeout(async () => {
      state.reconnectTimer = null;
      if (isConnected() || document.hidden) { if (!isConnected()) scheduleReconnect(); return; }
      try { await bleAttach(state.ble.device); } catch (e) {
        state.reconnectDelay = Math.min(delay < 2000 ? 2000 : delay < 5000 ? 5000 : delay < 10000 ? 10000 : 30000, 30000);
        scheduleReconnect();
      }
    }, delay);
  }

  /** Must be called from a button click (the browser shows its device picker). */
  async function connect() {
    const cfg = config();
    if (cfg.method === 'bluetooth') {
      if (!capabilities().bluetooth) {
        throw fail(window.isSecureContext
          ? 'Web Bluetooth is not supported in this browser. Use Chrome on Android/Windows/Mac, or choose the RawBT method.'
          : 'Bluetooth needs a secure address (https:// or localhost). Use RawBT, or allow this address in chrome://flags (see Printer setup).');
      }
      const options = cfg.bleFilter === 'all'
        ? { acceptAllDevices: true, optionalServices: BLE_SERVICES }
        : { filters: [...BLE_SERVICES.map((s) => ({ services: [s] })), ...NAME_PREFIXES.map((p) => ({ namePrefix: p }))], optionalServices: BLE_SERVICES };
      let device;
      try {
        device = await navigator.bluetooth.requestDevice(options);
      } catch (e) {
        if (e.name === 'NotFoundError') throw fail('No printer chosen. If yours was not listed, switch on "Show all Bluetooth devices" and try again.');
        throw fail(e.message);
      }
      await bleAttach(device);
    } else if (cfg.method === 'serial') {
      if (!capabilities().serial) throw fail('Web Serial is not available. Use Chrome or Edge on a computer over https or localhost.');
      const port = await navigator.serial.requestPort();
      await port.open({ baudRate: Number(cfg.baudRate) || 9600 });
      state.serial.port = port;
      saveConfig({ deviceName: 'Serial / COM printer' });
      addLog('info', 'Serial printer connected');
      retryQueue();
    }
    emit();
  }

  /** Reconnect to the printer chosen earlier — no picker. Works while this page stays open, or after a reload
   *  when the browser remembers the permission (navigator.bluetooth.getDevices). */
  async function reconnect() {
    const cfg = config();
    if (cfg.method === 'bluetooth') {
      let device = state.ble.device;
      if (!device && navigator.bluetooth && navigator.bluetooth.getDevices) {
        const devices = await navigator.bluetooth.getDevices();
        device = devices.find((d) => d.id === cfg.deviceId) || devices.find((d) => d.name === cfg.deviceName);
      }
      if (!device) throw fail('Tap “Connect printer” to choose the printer again.');
      await bleAttach(device);
    } else if (cfg.method === 'serial') {
      const ports = navigator.serial && navigator.serial.getPorts ? await navigator.serial.getPorts() : [];
      if (!ports[0]) throw fail('Tap “Connect printer” to choose the printer again.');
      if (!ports[0].writable) await ports[0].open({ baudRate: Number(cfg.baudRate) || 9600 });
      state.serial.port = ports[0];
      emit();
      retryQueue();
    }
  }

  async function autoConnect() {
    const cfg = config();
    if (cfg.method !== 'bluetooth' && cfg.method !== 'serial') { emit(); return; }
    try { await reconnect(); } catch (e) { /* stays disconnected; the user can press Connect */ }
    emit();
  }

  async function disconnect() {
    clearTimeout(state.reconnectTimer);
    state.reconnectTimer = null;
    const dev = state.ble.device;
    state.ble = { device: null, characteristic: null, channels: [], packet: 180 };
    try { if (dev && dev.gatt.connected) dev.gatt.disconnect(); } catch (e) { /* ignore */ }
    try { if (state.serial.port) await state.serial.port.close(); } catch (e) { /* ignore */ }
    state.serial = { port: null };
    addLog('info', 'Disconnected');
    emit();
  }

  /** Disconnect and forget the remembered printer (and its permission, where the browser supports it). */
  async function forget() {
    const dev = state.ble.device;
    await disconnect();
    try { if (dev && dev.forget) await dev.forget(); } catch (e) { /* ignore */ }
    saveConfig({ deviceName: '', deviceId: '', channel: null });
  }

  function channels() {
    const cur = state.ble.characteristic;
    return state.ble.channels.map((c) => ({ service: c.service, characteristic: c.characteristic, properties: c.properties, writable: c.writable, selected: !!cur && c.ref === cur }));
  }
  function setChannel(service, characteristic) {
    const c = state.ble.channels.find((x) => x.service === service && x.characteristic === characteristic && x.writable);
    if (!c) throw fail('That channel is not available on the connected printer.');
    state.ble.characteristic = c.ref;
    saveConfig({ channel: { service, characteristic } });
    addLog('info', `Using channel ${characteristic}`);
  }

  // ------------------------------------------------------------------ writing bytes
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  async function bleWrite(bytes) {
    const cfg = config();
    const ch = state.ble.characteristic;
    const fixed = cfg.packetSize !== 'auto' ? Number(cfg.packetSize) : 0;
    let packet = fixed || state.ble.packet || 180;
    const pause = () => (cfg.packetDelay !== 'auto' ? Number(cfg.packetDelay) || 0 : packet > 20 ? 20 : 10);
    const write = (part) => (ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse
      ? ch.writeValueWithoutResponse(part) : (ch.writeValueWithResponse ? ch.writeValueWithResponse(part) : ch.writeValue(part)));
    const started = Date.now();
    for (let i = 0; i < bytes.length;) {
      const part = bytes.slice(i, i + packet);
      try {
        await write(part);
        i += part.length;
      } catch (e) {
        if (!fixed && packet > 20) {                    // default BLE packets are 20 bytes — fall back once
          packet = 20;
          state.ble.packet = 20;
          addLog('warn', 'Large Bluetooth packets refused; switching to 20-byte packets.');
          continue;
        }
        throw new Error('Bluetooth printing failed: ' + e.message);
      }
      await sleep(pause());                             // let small printers empty their buffer
    }
    addLog('info', `Sent ${bytes.length} bytes in ${Date.now() - started} ms (${packet}-byte packets)`);
  }
  async function serialWrite(bytes) {
    const writer = state.serial.port.writable.getWriter();
    try { await writer.write(bytes); } finally { writer.releaseLock(); }
    addLog('info', `Sent ${bytes.length} bytes to the serial printer`);
  }
  function rawbtSend(bytes) {
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    const a = document.createElement('a');
    a.href = 'rawbt:base64,' + btoa(bin);
    document.body.appendChild(a);
    a.click();
    a.remove();
    addLog('info', `Sent ${bytes.length} bytes to RawBT`);
    return Promise.resolve();
  }

  /** Send to the connected Bluetooth / serial printer; reconnect once if the printer went to sleep. */
  async function sendBytes(bytes) {
    const m = config().method;
    if (!isConnected()) {
      try { await reconnect(); } catch (e) { /* handled below */ }
    }
    if (!isConnected()) {
      const err = new Error('Printer not connected.');
      err.notConnected = true;
      throw err;
    }
    return m === 'bluetooth' ? bleWrite(bytes) : serialWrite(bytes);
  }

  // ------------------------------------------------------------------ print queue (jobs that failed while disconnected)
  let jobSeq = 1;
  function enqueue(label, bytes) {
    const job = { id: jobSeq++, label, bytes, time: new Date().toLocaleTimeString('en-PH') };
    state.queue.push(job);
    if (state.queue.length > 20) state.queue.shift();
    addLog('warn', `Queued "${label}" — it will print when the printer reconnects.`);
    emit();
    return job;
  }
  function dropJob(id) {
    state.queue = state.queue.filter((j) => j.id !== id);
    emit();
  }
  function clearQueue() {
    state.queue = [];
    addLog('info', 'Print queue cleared');
    emit();
  }
  let flushing = false;
  async function retryQueue() {
    if (flushing || !state.queue.length || !isConnected()) return;
    flushing = true;
    try {
      while (state.queue.length && isConnected()) {
        const job = state.queue[0];
        await sendBytes(job.bytes);
        state.queue.shift();
        addLog('info', `Printed queued "${job.label}"`);
        emit();
      }
    } catch (e) {
      addLog('error', 'Queue paused: ' + e.message);
    } finally {
      flushing = false;
    }
  }

  /** Send a doc to the configured printer. opts: {copies, drawer, method, label} */
  async function output(doc, opts = {}) {
    const cfg = config();
    const method = opts.method || cfg.method;
    if (method === 'browser') return browserPrint(html(doc, cfg.paper));
    const copies = Math.max(1, Math.min(Number(opts.copies || 1), 5));
    const parts = [];
    for (let i = 0; i < copies; i++) parts.push(escpos(doc, { drawer: opts.drawer && i === 0 }));
    const bytes = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let o = 0;
    parts.forEach((p) => { bytes.set(p, o); o += p.length; });
    if (method === 'rawbt') return rawbtSend(bytes);
    if (method !== 'bluetooth' && method !== 'serial') throw fail('Unknown printer method');
    try {
      await sendBytes(bytes);
      state.lastError = '';
      emit();
    } catch (e) {
      if (e.notConnected && cfg.autoReconnect) {
        const job = enqueue(opts.label || 'print job', bytes);
        scheduleReconnect();
        const err = fail('Printer not connected — the job is saved and will print when the printer reconnects.');
        err.queued = true;
        err.jobId = job.id;
        throw err;
      }
      throw fail(e.notConnected ? 'Printer not connected. Tap the printer button to connect it.' : e.message);
    }
  }

  // ------------------------------------------------------------------ layouts
  const colsFor = () => (Number(config().paper) === 80 ? 48 : 32);
  const ORDER_TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };
  const DISC_LABEL = { sc: 'Senior Citizen Disc.', pwd: 'PWD Disc.', percent: 'Discount', amount: 'Discount' };

  function header(doc, b) {
    doc.center(b.name || '', { bold: true, big: true });
    if (b.address) doc.center(b.address);
    if (b.tin) doc.center('TIN: ' + b.tin);
    if (b.phone) doc.center('Tel: ' + b.phone);
    doc.hr();
  }

  function receiptDoc(t, ctx) {
    const doc = new Doc(colsFor());
    const b = ctx.business || {};
    const isBill = t.status === 'open';
    const label = (m) => ((ctx.methods || []).find((x) => x.key === m) || {}).label || m;
    header(doc, b);
    doc.center(isBill ? 'BILL / STATEMENT' : t.status === 'void' ? 'VOIDED RECEIPT' : (b.receipt_title || 'RECEIPT'), { bold: true });
    if (ctx.reprint) doc.center('*** REPRINT ***');
    if (t.receipt_no) doc.lr('Receipt No:', t.receipt_no);
    doc.lr('Order:', t.ticket_no);
    doc.lr('Date:', dt(t.paid_at || t.created_at));
    doc.lr(ORDER_TYPE[t.order_type] || t.order_type, t.table_label ? 'Table ' + t.table_label : '');
    if (t.customer_name) doc.lr('Customer:', t.customer_name);
    doc.lr('Pax:', String(t.pax));
    doc.lr('Cashier:', t.paid_by_name || t.created_by_name || '');
    doc.hr();
    for (const i of t.items.filter((x) => x.status === 'active')) {
      doc.text(i.name);
      doc.lr(`  ${qtyStr(i.qty)} x ${money(i.price)}`, money(i.line_total));
      if (Number(i.discount_amount) > 0) doc.lr(`  Less: ${i.discount_name || DISC_LABEL[i.discount_kind] || 'Discount'}`, '-' + money(i.discount_amount));
      if (i.notes) doc.text('  * ' + i.notes);
    }
    doc.hr();
    doc.lr('Subtotal', money(t.subtotal));
    const hasSplit = t.sc_discount !== undefined && t.promo_discount !== undefined;
    if (hasSplit) {
      if (t.sc_discount > 0) doc.lr(t.discount_type === 'pwd' ? 'PWD Discount' : 'SC/PWD Discount', '-' + money(t.sc_discount));
      if (t.promo_discount > 0) doc.lr(['percent', 'amount'].includes(t.discount_type) && t.discount_name ? t.discount_name : 'Other Discounts', '-' + money(t.promo_discount));
    } else if (t.discount_amount > 0) {
      doc.lr(DISC_LABEL[t.discount_type] || 'Discount', '-' + money(t.discount_amount));
    }
    if (t.service_charge > 0) doc.lr('Service Charge', money(t.service_charge));
    doc.lr('TOTAL DUE', money(t.total), { bold: true, big: true });
    if (!isBill) {
      for (const p of t.payments) doc.lr(label(p.method) + (p.reference ? ' #' + p.reference : ''), money(p.method === 'cash' ? p.tendered : p.amount));
      if (t.change_amount > 0) doc.lr('CHANGE', money(t.change_amount), { bold: true });
    }
    doc.hr();
    if (ctx.tax && ctx.tax.vatRegistered) {
      doc.lr('VATable Sales', money(t.vatable_sales));
      doc.lr(`VAT (${Math.round(ctx.tax.vatRate * 100)}%)`, money(t.vat_amount));
      doc.lr('VAT-Exempt Sales', money(t.vat_exempt_sales));
      doc.lr('Zero-Rated Sales', money(0));
    } else {
      doc.center('NON-VAT REGISTERED');
    }
    if ((t.sc_details || []).length) {
      doc.hr();
      for (const d of t.sc_details) {
        doc.text(`${t.discount_type === 'pwd' ? 'PWD' : 'SC/PWD'}: ${d.name}`);
        doc.text(`ID: ${d.id_no}`);
        doc.text('Signature: ______________');
      }
    }
    if (isBill) {
      doc.hr();
      doc.text('Name: ____________________');
      doc.text('Address: _________________');
      doc.text('TIN: _____________________');
    }
    doc.hr();
    if (t.status === 'void') doc.center('VOID: ' + (t.void_reason || ''));
    if (b.receipt_footer) doc.center(b.receipt_footer);
    return doc;
  }

  /** Kitchen slip (new items) and order slip (whole order, reprint) share this layout. */
  function slipDoc(t, lines, title, reprint) {
    const doc = new Doc(colsFor());
    const big = !!config().bigItems;
    doc.center(title, { bold: true, big: true });
    if (reprint) doc.center('*** REPRINT ***', { bold: true });
    doc.lr(t.table_label ? 'TABLE ' + t.table_label : (ORDER_TYPE[t.order_type] || '').toUpperCase(), t.ticket_no, { bold: true, big: true });
    doc.lr(dt(), 'Pax ' + t.pax);
    if (t.customer_name) doc.text('Customer: ' + t.customer_name);
    if (t.notes) doc.text('Note: ' + t.notes);
    doc.hr();
    for (const l of lines) {
      doc.text(`${qtyStr(l.qty)} x ${l.name}`, { bold: true, big });
      if (l.notes) doc.text('  ** ' + l.notes);
    }
    doc.hr();
    doc.lr('Items', String(lines.reduce((n, l) => n + Number(l.qty), 0)));
    return doc;
  }

  function readingDoc(r, ctx, isZ) {
    const doc = new Doc(colsFor());
    const s = r.session;
    header(doc, ctx.business || {});
    doc.center(isZ ? 'Z-READING (END OF DAY)' : 'X-READING', { bold: true });
    doc.lr('Business date:', s.business_date);
    doc.lr('Opened:', dt(s.opened_at));
    doc.lr('Opened by:', s.opened_by_name || '');
    if (isZ) { doc.lr('Closed:', dt(s.closed_at)); doc.lr('Closed by:', s.closed_by_name || ''); }
    doc.lr('Printed:', dt());
    doc.hr();
    doc.lr('Beginning OR', r.sales.first_or || '-');
    doc.lr('Ending OR', r.sales.last_or || '-');
    doc.lr('Receipts', String(r.sales.cnt));
    doc.lr('Guests (pax)', String(r.sales.pax));
    doc.hr();
    doc.lr('Gross Sales', money(r.sales.gross));
    doc.lr('Less: Discounts', money(r.sales.discounts));
    doc.lr('Add: Service Charge', money(r.sales.svc));
    doc.lr('NET SALES', money(r.sales.net), { bold: true });
    if (ctx.tax && ctx.tax.vatRegistered) {
      doc.lr('VATable Sales', money(r.sales.vatable));
      doc.lr('VAT Amount', money(r.sales.vat));
      doc.lr('VAT-Exempt Sales', money(r.sales.exempt));
    }
    const DT = { sc: 'Senior Citizen', pwd: 'PWD', percent: 'Promo %', amount: 'Promo amount' };
    if (r.discounts.length) { doc.hr(); doc.text('DISCOUNTS', { bold: true }); }
    for (const d of r.discounts) doc.lr(`${d.label || DT[d.discount_type] || d.discount_type} (${d.cnt})`, money(d.amount));
    doc.hr();
    doc.text('PAYMENTS', { bold: true });
    for (const p of r.payments) doc.lr(`${p.label} (${p.cnt})`, money(p.amount));
    doc.hr();
    doc.lr(`Voided receipts (${r.voided.cnt})`, money(r.voided.amount));
    doc.lr(`Voided items (${r.item_voids.cnt})`, money(r.item_voids.amount));
    doc.lr('Cancelled orders', String(r.cancelled));
    doc.hr();
    doc.text('CASH DRAWER', { bold: true });
    doc.lr('Beginning cash', money(r.cash.opening));
    doc.lr('Cash sales', money(r.cash.cash_sales));
    doc.lr('Less: Payouts', money(r.cash.payouts));
    if (r.cash.refunds > 0) doc.lr('Less: Refunds', money(r.cash.refunds));
    doc.lr('EXPECTED CASH', money(r.cash.expected), { bold: true });
    if (isZ) {
      doc.lr('ACTUAL CASH COUNT', money(r.cash.counted), { bold: true });
      doc.lr(r.cash.variance < 0 ? 'SHORT' : r.cash.variance > 0 ? 'OVER' : 'VARIANCE', money(r.cash.variance), { bold: true });
    }
    doc.hr();
    doc.text('SALES BY CATEGORY', { bold: true });
    for (const c of r.categories) doc.lr(`${c.category} (${qtyStr(c.qty)})`, money(c.amount));
    doc.hr();
    doc.lr('Accum. Grand Total Beg.', money(r.grand_total.beginning));
    doc.lr('Accum. Grand Total End', money(r.grand_total.ending));
    doc.hr();
    doc.feed(1);
    doc.text('Cashier: ________________');
    doc.feed(1);
    doc.text('Manager: ________________');
    return doc;
  }

  function testDoc(ctx) {
    const doc = new Doc(colsFor());
    header(doc, (ctx && ctx.business) || { name: 'Mark5 Restaurant Suite' });
    doc.center('PRINTER TEST', { bold: true, big: true });
    doc.lr('Paper', `${config().paper} mm / ${colsFor()} chars`);
    doc.lr('Method', config().method);
    doc.lr('Adobo Rice Meal x2', money(398));
    doc.lr('TOTAL', money(398), { bold: true, big: true });
    doc.center('If you can read this, printing works!');
    return doc;
  }

  /** Longer diagnostic page: alignment, bold, sizes, column ruler, currency, long text wrap. */
  function selfTestDoc(ctx) {
    const cols = colsFor();
    const cfg = config();
    const doc = new Doc(cols);
    header(doc, (ctx && ctx.business) || { name: 'Mark5 Restaurant Suite' });
    doc.center('PRINTER SELF-TEST', { bold: true, big: true });
    doc.lr('Printer', cfg.deviceName || cfg.method);
    doc.lr('Paper / columns', `${cfg.paper} mm / ${cols}`);
    doc.lr('Packets / delay', `${cfg.packetSize} / ${cfg.packetDelay}`);
    doc.lr('Printed', dt());
    doc.hr();
    doc.text('Column ruler (must fit on one line):');
    doc.text('1234567890'.repeat(Math.ceil(cols / 10)).slice(0, cols));
    doc.text('|' + '-'.repeat(cols - 2) + '|');
    doc.hr();
    doc.text('Left aligned');
    doc.center('Centered');
    doc.text('Right aligned', { align: 'right' });
    doc.text('Bold text', { bold: true });
    doc.text('Double height', { big: true });
    doc.lr('Currency ₱1,234.50', money(1234.5));
    doc.lr('Ñ / é becomes', 'N / e');
    doc.text('Long text wraps neatly: Pancit Canton (Good for 3) with extra toppings and calamansi on the side.');
    doc.hr();
    doc.center('END OF SELF-TEST');
    return doc;
  }

  // ------------------------------------------------------------------ screen wake lock (keeps the tablet awake)
  const wake = { wanted: false, lock: null };
  async function keepAwake(on) {
    wake.wanted = !!on;
    try {
      if (on && navigator.wakeLock && !wake.lock && !document.hidden) {
        wake.lock = await navigator.wakeLock.request('screen');
        wake.lock.addEventListener('release', () => { wake.lock = null; });
        addLog('info', 'Screen will stay on while the POS is open');
      } else if (!on && wake.lock) {
        await wake.lock.release();
        wake.lock = null;
      }
    } catch (e) {
      addLog('warn', 'Could not keep the screen on: ' + e.message);
    }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    if (wake.wanted && config().keepAwake) keepAwake(true);          // the lock is released when the tab is hidden
    if (config().autoReconnect && !isConnected() && state.ble.device) scheduleReconnect();
  });

  // ------------------------------------------------------------------ public API
  const Printer = {
    config, saveConfig, capabilities, status, onStatus, log, onLog,
    isConnected, connect, reconnect, disconnect, forget, autoConnect, channels, setChannel,
    retryQueue, clearQueue, dropJob, keepAwake,
    printReceipt(ticket, ctx = {}) {
      const cfg = config();
      return output(receiptDoc(ticket, ctx), {
        method: ctx.method, copies: ctx.reprint ? 1 : cfg.copies, label: `Receipt ${ticket.receipt_no || ticket.ticket_no}`,
        drawer: cfg.openDrawer && !ctx.reprint && ticket.status === 'paid',
      });
    },
    printKitchen(ticket, lines, ctx = {}) {
      return output(slipDoc(ticket, lines, 'KITCHEN ORDER', false), { method: ctx.method, copies: config().kitchenCopies, label: `Kitchen ${ticket.ticket_no}` });
    },
    printOrderSlip(ticket, ctx = {}) {
      const lines = ticket.items.filter((i) => i.status === 'active');
      return output(slipDoc(ticket, lines, 'ORDER SLIP', true), { method: ctx.method, label: `Order slip ${ticket.ticket_no}` });
    },
    printReading(report, ctx = {}, isZ = false) { return output(readingDoc(report, ctx, isZ), { method: ctx.method, label: isZ ? 'Z-reading' : 'X-reading' }); },
    testPrint(ctx = {}) { return output(testDoc(ctx), { method: ctx.method, label: 'Test print' }); },
    selfTest(ctx = {}) { return output(selfTestDoc(ctx), { method: ctx.method, label: 'Self-test' }); },
    // exposed for previews, tests and custom layouts
    _internals: { Doc, escpos, html, receiptDoc, kitchenDoc: (t, l) => slipDoc(t, l, 'KITCHEN ORDER', false), slipDoc, readingDoc, selfTestDoc, lrLines, ascii },
  };
  window.Printer = Printer;
  if (document.readyState !== 'loading') autoConnect();
  else document.addEventListener('DOMContentLoaded', autoConnect);
})();
