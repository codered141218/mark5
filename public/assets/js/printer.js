/**
 * Mark5 printing — thermal receipt / order-slip printers set up inside the system (no browser print dialog).
 * Built for portable 58 mm Bluetooth printers (GOOJPRT PT-210 / MTP-II, Xprinter …) on Android tablets, and 80 mm
 * desk printers.
 *
 * A device can have several printers, for example:
 *   - "Cashier"  — Bluetooth, prints receipts, bills, X/Z readings and the Grill order slips
 *   - "Kitchen"  — Bluetooth, prints only the Kitchen order slips
 * Each printer chooses what it prints: receipts (yes/no) and order slips of which prep stations (Kitchen, Grill …,
 * plus "items without a station"). When the cashier presses Done, the new items are grouped per station and every
 * group is printed, as its own slip, on the printers that take that station. If no printer takes a station, its
 * slip goes to the first printer that prints order slips, so nothing is ever lost.
 *
 * How a printer is reached ("method"):
 *   bluetooth : Web Bluetooth (BLE printers) — Chrome / Edge on Android, Windows, Mac. Needs https or localhost.
 *   serial    : Web Serial — a USB printer, or a classic Bluetooth printer paired as a COM port (Chrome / Edge on a PC).
 *   rawbt     : the RawBT app on Android (any Bluetooth thermal printer, also classic-only models). One printer only.
 *
 * Portable printers go to sleep and drop the connection, so every printer is remembered, reconnects automatically
 * (with back-off), keeps jobs that could not print in its own queue and prints them on reconnect, has tunable
 * Bluetooth packet size / delay, and writes to a diagnostic log.
 *
 * Settings are stored per device (localStorage), because every tablet / PC has its own printers.
 *
 * API (window.Printer):
 *   config(), saveConfig(cfg)                         device-wide options (auto print, currency, keep awake …)
 *   printers(), printer(id), addPrinter(p), updatePrinter(id, p), removePrinter(id)
 *   capabilities(), status(), onStatus(fn), log(), onLog(fn)
 *   isConnected(id), connect(id) [needs a click], reconnect(id), disconnect(id), forget(id), channels(id), setChannel(id, svc, chr)
 *   retryQueue(id?), clearQueue(id?), dropJob(jobId), keepAwake(on)
 *   printReceipt(ticket, ctx), printSlips(ticket, lines, ctx), printReading(report, ctx, isZ), testPrint(id, ctx), selfTest(id, ctx)
 *   routeSlips(lines, ctx)                            which printer gets which station group (no printing)
 *   ctx = { business: {...}, tax: {vatRegistered, vatRate}, methods: [{key, label}], stations: [{id, name}],
 *           reprint: bool, only: printerId (print on that printer only, e.g. a retry) }
 * Every print resolves to a list of results [{printer: {id, name}, ok, queued, error, what}] — it never throws,
 * except Error.noPrinter when this device has no printer for the job.
 */
(function () {
  'use strict';

  const STORE_KEY = 'mark5_printers_v3';
  const OLD_KEY = 'mark5_printer';
  const OPTIONS = {
    autoPrint: true,          // receipt after payment
    slipOnDone: true,         // order slips when the cashier presses Done
    slipPerStation: true,     // one slip per station (else one slip with every item, grouped by station)
    currency: 'P', bigItems: true, autoReconnect: true, keepAwake: true,
  };
  const PRINTER_DEFAULTS = {
    id: '', name: 'Printer', method: 'bluetooth', paper: 58,
    receipts: true,           // receipts, bills, X/Z readings
    slips: true,              // order slips
    stations: 'all',          // 'all' or [station ids], 0 = items without a station
    copies: 1, slipCopies: 1, openDrawer: false,
    deviceName: '', deviceId: '', channel: null, portInfo: null, baudRate: 9600,
    bleFilter: 'printers', packetSize: 'auto', packetDelay: 'auto', cutter: false, feedLines: 3,
  };

  // Service UUIDs used by common BLE thermal printers (GOOJPRT PT-210, Xprinter, MTP-II, PeriPage, Rongta,
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
  const NAME_PREFIXES = ['Printer', 'BlueTooth Printer', 'BT', 'MTP', 'MPT', 'PT-', 'PT2', 'RPP', 'ZJ-', 'XP-', 'Xprinter', 'GOOJPRT', 'Goojprt',
    'InnerPrinter', 'POS', 'Thermal', 'P58', 'P80', 'PeriPage', 'HM-', 'MP', 'QR', 'Rongta', 'SP-', 'JP-', 'M58', 'T58', 'T80'];

  // ------------------------------------------------------------------ settings
  let memory = null;   // used when localStorage is unavailable (private mode)
  function load() {
    let raw = null;
    try { raw = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) { raw = memory; }
    if (!raw) raw = migrate();
    raw.printers = (raw.printers || []).map((p) => ({ ...PRINTER_DEFAULTS, ...p }));
    return { ...OPTIONS, ...raw };
  }
  function store(data) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(data)); } catch (e) { memory = data; }
  }
  /** Settings of the one-printer version: becomes printer "Printer 1" doing everything. */
  function migrate() {
    let old = null;
    try { old = JSON.parse(localStorage.getItem(OLD_KEY) || 'null'); } catch (e) { old = null; }
    const out = { printers: [] };
    if (old) {
      out.autoPrint = old.autoPrint !== false;
      out.slipOnDone = old.kitchenSlip !== false;
      ['currency', 'bigItems', 'autoReconnect', 'keepAwake'].forEach((k) => { if (k in old) out[k] = old[k]; });
      if (['bluetooth', 'serial', 'rawbt'].includes(old.method)) {
        out.printers.push({ ...PRINTER_DEFAULTS, id: 'p1', name: old.deviceName || 'Printer 1', method: old.method, paper: Number(old.paper) || 58,
          copies: Number(old.copies) || 1, slipCopies: Number(old.kitchenCopies) || 1, openDrawer: !!old.openDrawer,
          deviceName: old.deviceName || '', deviceId: old.deviceId || '', channel: old.channel || null, baudRate: old.baudRate || 9600,
          bleFilter: old.bleFilter || 'printers', packetSize: old.packetSize || 'auto', packetDelay: old.packetDelay || 'auto',
          cutter: !!old.cutter, feedLines: old.feedLines ?? 3 });
      }
    }
    store(out);
    return out;
  }

  const config = () => { const c = load(); delete c.printers; return c; };
  function saveConfig(cfg) {
    const data = load();
    Object.keys(OPTIONS).forEach((k) => { if (k in cfg) data[k] = cfg[k]; });
    store(data);
    if ('keepAwake' in cfg) keepAwake(!!data.keepAwake && wake.wanted);
    emit();
    return config();
  }
  const printers = () => load().printers;
  const printer = (id) => printers().find((p) => p.id === id) || null;
  function addPrinter(p = {}) {
    const data = load();
    let n = data.printers.length + 1;
    while (data.printers.some((x) => x.id === 'p' + n)) n++;
    const first = !data.printers.length;
    const np = { ...PRINTER_DEFAULTS, receipts: first, slips: true, ...p, id: 'p' + n };
    if (!p.name) np.name = first ? 'Cashier printer' : `Printer ${n}`;
    data.printers.push(np);
    store(data);
    addLog('info', `Added printer "${np.name}"`);
    emit();
    return np.id;
  }
  function updatePrinter(id, p) {
    const data = load();
    const i = data.printers.findIndex((x) => x.id === id);
    if (i < 0) throw new Error('Printer not found');
    data.printers[i] = { ...data.printers[i], ...p, id };
    store(data);
    emit();
    return data.printers[i];
  }
  async function removePrinter(id) {
    await forget(id).catch(() => {});
    const data = load();
    data.printers = data.printers.filter((p) => p.id !== id);
    store(data);
    delete conns[id];
    emit();
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
  function addLog(level, msg, p) {
    const entry = { time: new Date().toLocaleTimeString('en-PH'), level, msg: (p ? `[${p.name}] ` : '') + String(msg) };
    logLines.push(entry);
    if (logLines.length > 150) logLines.shift();
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
  function escpos(doc, p, { drawer = false } = {}) {
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
    const feed = Math.max(0, Math.min(Number(p.feedLines) || 0, 8));
    for (let i = 0; i < feed; i++) push(0x0a);            // paper feed so the tear-off clears the print head
    if (p.cutter) push(GS, 0x56, 0x42, 0x00);            // feed and partial cut (desk printers with a cutter)
    if (drawer) push(ESC, 0x70, 0x00, 0x19, 0xfa);       // kick cash drawer (pin 2)
    return new Uint8Array(out);
  }

  // ------------------------------------------------------------------ HTML renderer (on-screen previews only)
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

  // ------------------------------------------------------------------ connections (one per printer)
  const conns = {};   // printer id -> connection state
  function conn(id) {
    if (!conns[id]) {
      conns[id] = { ble: { device: null, characteristic: null, channels: [], packet: 180 }, serial: { port: null },
        connecting: false, lastError: '', queue: [], reconnectTimer: null, reconnectDelay: 1000, flushing: false };
    }
    return conns[id];
  }
  const listeners = [];
  function printerStatus(p) {
    const c = conn(p.id);
    const linkable = p.method === 'bluetooth' || p.method === 'serial';
    return { id: p.id, name: p.name, method: p.method, linkable, connected: isConnected(p.id), connecting: c.connecting,
      deviceName: p.deviceName, queue: c.queue.length, lastError: c.lastError, receipts: !!p.receipts, slips: !!p.slips };
  }
  function status() {
    const list = printers().map(printerStatus);
    const down = list.filter((s) => s.linkable && !s.connected);
    return { printers: list, configured: list.length, problems: down.length, connecting: list.some((s) => s.connecting),
      queue: list.reduce((n, s) => n + s.queue, 0), ready: list.length > 0 && !down.length };
  }
  function emit() { const s = status(); listeners.forEach((fn) => { try { fn(s); } catch (e) { /* ignore */ } }); }
  function onStatus(fn) { listeners.push(fn); fn(status()); }

  function isConnected(id) {
    const p = printer(id);
    if (!p) return false;
    const c = conn(id);
    if (p.method === 'bluetooth') return !!(c.ble.device && c.ble.device.gatt && c.ble.device.gatt.connected && c.ble.characteristic);
    if (p.method === 'serial') return !!(c.serial.port && c.serial.port.writable);
    return true;   // rawbt: always "ready"
  }

  function fail(p, msg) {
    if (p) conn(p.id).lastError = msg;
    addLog('error', msg, p);
    emit();
    return new Error(msg);
  }

  const withTimeout = (pr, ms, msg) => Promise.race([pr, new Promise((_, rej) => setTimeout(() => rej(new Error(msg)), ms))]);

  /** Find every writable characteristic in the printer's services (the user can pick one under Advanced). */
  async function discoverChannels(server) {
    const found = [];
    for (const svc of await server.getPrimaryServices()) {
      for (const ch of await svc.getCharacteristics()) {
        const pr = ch.properties;
        const props = ['read', 'write', 'writeWithoutResponse', 'notify', 'indicate'].filter((k) => pr[k]);
        found.push({ service: svc.uuid, characteristic: ch.uuid, properties: props, writable: pr.write || pr.writeWithoutResponse, ref: ch });
      }
    }
    return found;
  }

  async function bleAttach(id, device) {
    const p = printer(id);
    const c = conn(id);
    c.connecting = true;
    emit();
    try {
      addLog('info', `Connecting to ${device.name || 'Bluetooth device'}…`, p);
      const server = await withTimeout(device.gatt.connect(), 12000, 'The printer did not answer. Is it switched on and close by?');
      const channels = await discoverChannels(server);
      const writable = channels.filter((x) => x.writable);
      if (!writable.length) throw new Error('This Bluetooth device has no printable channel. Is it a thermal printer? (Classic-only printers: use RawBT or USB / serial.)');
      const saved = p.channel;
      const chosen = (saved && writable.find((x) => x.service === saved.service && x.characteristic === saved.characteristic))
        || writable.find((x) => x.properties.includes('writeWithoutResponse')) || writable[0];
      c.ble = { device, characteristic: chosen.ref, channels, packet: c.ble.packet || 180 };
      if (!device.__mark5) {
        device.__mark5 = id;
        device.addEventListener('gattserverdisconnected', () => onBleDisconnected(device.__mark5));
      }
      c.lastError = '';
      c.reconnectDelay = 1000;
      updatePrinter(id, { deviceName: device.name || 'Bluetooth printer', deviceId: device.id || '' });
      addLog('info', `Connected: ${device.name || 'printer'} (channel ${chosen.characteristic.slice(4, 8)})`, p);
    } catch (e) {
      throw fail(p, e.message || String(e));
    } finally {
      c.connecting = false;
      emit();
    }
    retryQueue(id);
  }

  function onBleDisconnected(id) {
    const c = conn(id);
    c.ble.characteristic = null;
    addLog('warn', 'Printer disconnected (switched off, out of range or asleep).', printer(id));
    emit();
    scheduleReconnect(id);
  }

  /** Try again after 1 s, 2 s, 5 s, 10 s, then every 30 s while the page is visible. */
  function scheduleReconnect(id) {
    const p = printer(id);
    const c = conn(id);
    if (!p || !config().autoReconnect || p.method !== 'bluetooth' || !c.ble.device || c.reconnectTimer) return;
    const delay = c.reconnectDelay;
    c.reconnectTimer = setTimeout(async () => {
      c.reconnectTimer = null;
      if (!printer(id)) return;
      if (isConnected(id) || document.hidden) { if (!isConnected(id)) scheduleReconnect(id); return; }
      try { await bleAttach(id, c.ble.device); } catch (e) {
        c.reconnectDelay = delay < 2000 ? 2000 : delay < 5000 ? 5000 : delay < 10000 ? 10000 : 30000;
        scheduleReconnect(id);
      }
    }, delay);
  }

  /** Choose and connect the printer. Must be called from a button click (the browser shows its device picker). */
  async function connect(id) {
    const p = printer(id);
    if (!p) throw new Error('Printer not found');
    if (p.method === 'bluetooth') {
      if (!capabilities().bluetooth) {
        throw fail(p, window.isSecureContext
          ? 'Web Bluetooth is not supported in this browser. Use Chrome or Edge on Android / Windows / Mac, or choose RawBT or USB / serial.'
          : 'Bluetooth needs a secure address (https:// or localhost). Use RawBT, or allow this address in chrome://flags (see Printer setup).');
      }
      const options = p.bleFilter === 'all'
        ? { acceptAllDevices: true, optionalServices: BLE_SERVICES }
        : { filters: [...BLE_SERVICES.map((s) => ({ services: [s] })), ...NAME_PREFIXES.map((n) => ({ namePrefix: n }))], optionalServices: BLE_SERVICES };
      let device;
      try {
        device = await navigator.bluetooth.requestDevice(options);
      } catch (e) {
        if (e.name === 'NotFoundError') throw fail(p, 'No printer chosen. If yours was not listed, tick "Show all Bluetooth devices" and try again.');
        throw fail(p, e.message);
      }
      // a different device than before: forget the old channel choice
      if (device.id !== p.deviceId) updatePrinter(id, { channel: null });
      const old = conn(id).ble.device;
      if (old && old !== device) { try { old.gatt.disconnect(); } catch (e) { /* ignore */ } }
      await bleAttach(id, device);
    } else if (p.method === 'serial') {
      if (!capabilities().serial) throw fail(p, 'Web Serial is not available. Use Chrome or Edge on a computer over https or localhost.');
      let port;
      try { port = await navigator.serial.requestPort(); } catch (e) { throw fail(p, e.name === 'NotFoundError' ? 'No port chosen.' : e.message); }
      await openSerial(id, port);
    } else {
      addLog('info', 'RawBT needs no connection: press Test print.', p);
    }
    emit();
  }

  async function openSerial(id, port) {
    const p = printer(id);
    const c = conn(id);
    try {
      if (!port.writable) await port.open({ baudRate: Number(p.baudRate) || 9600 });
    } catch (e) {
      throw fail(p, `Could not open the port: ${e.message}. Is another program (or another printer here) using it?`);
    }
    c.serial.port = port;
    c.lastError = '';
    const info = port.getInfo ? port.getInfo() : {};
    updatePrinter(id, { deviceName: info.usbProductId ? `USB printer ${info.usbVendorId}:${info.usbProductId}` : 'Serial / COM printer', portInfo: info });
    addLog('info', 'Serial printer connected', p);
    retryQueue(id);
  }

  /** Reconnect to the printer chosen earlier — no picker. Works while this page stays open, or after a reload
   *  when the browser remembers the permission (navigator.bluetooth.getDevices / navigator.serial.getPorts). */
  async function reconnect(id) {
    const p = printer(id);
    if (!p) throw new Error('Printer not found');
    const c = conn(id);
    if (p.method === 'bluetooth') {
      let device = c.ble.device;
      if (!device && navigator.bluetooth && navigator.bluetooth.getDevices) {
        const devices = await navigator.bluetooth.getDevices();
        device = devices.find((d) => d.id === p.deviceId) || devices.find((d) => p.deviceName && d.name === p.deviceName);
      }
      if (!device) throw fail(p, 'Tap “Connect” to choose the printer again.');
      await bleAttach(id, device);
    } else if (p.method === 'serial') {
      const ports = navigator.serial && navigator.serial.getPorts ? await navigator.serial.getPorts() : [];
      const same = (port) => JSON.stringify(port.getInfo ? port.getInfo() : {}) === JSON.stringify(p.portInfo || {});
      const taken = new Set(Object.entries(conns).filter(([k]) => k !== id).map(([, x]) => x.serial.port).filter(Boolean));
      const port = c.serial.port || ports.find((x) => same(x) && !taken.has(x)) || (ports.filter((x) => !taken.has(x)).length === 1 ? ports.find((x) => !taken.has(x)) : null);
      if (!port) throw fail(p, 'Tap “Connect” to choose the printer’s port again.');
      await openSerial(id, port);
    }
    emit();
  }

  async function autoConnect() {
    for (const p of printers()) {
      if (p.method !== 'bluetooth' && p.method !== 'serial') continue;
      try { await reconnect(p.id); } catch (e) { /* stays disconnected; the user can press Connect */ }
    }
    emit();
  }

  async function disconnect(id) {
    const c = conn(id);
    clearTimeout(c.reconnectTimer);
    c.reconnectTimer = null;
    const dev = c.ble.device;
    c.ble = { device: null, characteristic: null, channels: [], packet: 180 };
    try { if (dev && dev.gatt.connected) dev.gatt.disconnect(); } catch (e) { /* ignore */ }
    try { if (c.serial.port) await c.serial.port.close(); } catch (e) { /* ignore */ }
    c.serial = { port: null };
    addLog('info', 'Disconnected', printer(id));
    emit();
  }

  /** Disconnect and forget the remembered device (and its permission, where the browser supports it). */
  async function forget(id) {
    const dev = conn(id).ble.device;
    await disconnect(id);
    try { if (dev && dev.forget) await dev.forget(); } catch (e) { /* ignore */ }
    if (printer(id)) updatePrinter(id, { deviceName: '', deviceId: '', channel: null, portInfo: null });
  }

  function channels(id) {
    const c = conn(id);
    const cur = c.ble.characteristic;
    return c.ble.channels.map((x) => ({ service: x.service, characteristic: x.characteristic, properties: x.properties, writable: x.writable, selected: !!cur && x.ref === cur }));
  }
  function setChannel(id, service, characteristic) {
    const c = conn(id);
    const x = c.ble.channels.find((ch) => ch.service === service && ch.characteristic === characteristic && ch.writable);
    if (!x) throw fail(printer(id), 'That channel is not available on the connected printer.');
    c.ble.characteristic = x.ref;
    updatePrinter(id, { channel: { service, characteristic } });
    addLog('info', `Using channel ${characteristic}`, printer(id));
  }

  // ------------------------------------------------------------------ writing bytes
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  async function bleWrite(p, bytes) {
    const c = conn(p.id);
    const ch = c.ble.characteristic;
    const fixed = p.packetSize !== 'auto' ? Number(p.packetSize) : 0;
    let packet = fixed || c.ble.packet || 180;
    const pause = () => (p.packetDelay !== 'auto' ? Number(p.packetDelay) || 0 : packet > 20 ? 20 : 10);
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
          c.ble.packet = 20;
          addLog('warn', 'Large Bluetooth packets refused; switching to 20-byte packets.', p);
          continue;
        }
        throw new Error('Bluetooth printing failed: ' + e.message);
      }
      await sleep(pause());                             // let small printers empty their buffer
    }
    addLog('info', `Sent ${bytes.length} bytes in ${Date.now() - started} ms (${packet}-byte packets)`, p);
  }
  async function serialWrite(p, bytes) {
    const writer = conn(p.id).serial.port.writable.getWriter();
    try { await writer.write(bytes); } finally { writer.releaseLock(); }
    addLog('info', `Sent ${bytes.length} bytes to the serial printer`, p);
  }
  function rawbtSend(p, bytes) {
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    const a = document.createElement('a');
    a.href = 'rawbt:base64,' + btoa(bin);
    document.body.appendChild(a);
    a.click();
    a.remove();
    addLog('info', `Sent ${bytes.length} bytes to RawBT`, p);
    return Promise.resolve();
  }

  /** Send to a connected Bluetooth / serial printer; reconnect once if the printer went to sleep. */
  async function sendBytes(p, bytes) {
    if (p.method === 'rawbt') return rawbtSend(p, bytes);
    if (!isConnected(p.id)) {
      try { await reconnect(p.id); } catch (e) { /* handled below */ }
    }
    if (!isConnected(p.id)) {
      const err = new Error('Printer not connected.');
      err.notConnected = true;
      throw err;
    }
    return p.method === 'bluetooth' ? bleWrite(p, bytes) : serialWrite(p, bytes);
  }

  // ------------------------------------------------------------------ print queue (jobs that could not print yet)
  let jobSeq = 1;
  function enqueue(p, label, bytes) {
    const c = conn(p.id);
    const job = { id: jobSeq++, label, bytes, time: new Date().toLocaleTimeString('en-PH') };
    c.queue.push(job);
    if (c.queue.length > 30) c.queue.shift();
    addLog('warn', `Queued "${label}" — it will print when the printer reconnects.`, p);
    emit();
    return job;
  }
  function dropJob(jobId) {
    Object.values(conns).forEach((c) => { c.queue = c.queue.filter((j) => j.id !== jobId); });
    emit();
  }
  function clearQueue(id) {
    (id ? [conn(id)] : Object.values(conns)).forEach((c) => { c.queue = []; });
    addLog('info', 'Print queue cleared', id ? printer(id) : null);
    emit();
  }
  async function retryQueue(id) {
    if (!id) { await Promise.all(printers().map((p) => retryQueue(p.id))); return; }
    const p = printer(id);
    const c = conn(id);
    if (!p || c.flushing || !c.queue.length || !isConnected(id)) return;
    c.flushing = true;
    try {
      while (c.queue.length && isConnected(id)) {
        const job = c.queue[0];
        await sendBytes(p, job.bytes);
        c.queue.shift();
        addLog('info', `Printed queued "${job.label}"`, p);
        emit();
      }
    } catch (e) {
      addLog('error', 'Queue paused: ' + e.message, p);
    } finally {
      c.flushing = false;
    }
  }

  /**
   * Print docs on one printer as a single job. Resolves {printer, ok, queued, error}.
   * opts: {copies, drawer, label}
   */
  async function output(p, docs, opts = {}) {
    const copies = Math.max(1, Math.min(Number(opts.copies || 1), 5));
    const parts = [];
    for (let i = 0; i < copies; i++) docs.forEach((d, j) => parts.push(escpos(d, p, { drawer: opts.drawer && i === 0 && j === 0 })));
    const bytes = new Uint8Array(parts.reduce((n, x) => n + x.length, 0));
    let o = 0;
    parts.forEach((x) => { bytes.set(x, o); o += x.length; });
    const res = { printer: { id: p.id, name: p.name }, what: opts.label, ok: false, queued: false, error: '' };
    try {
      await sendBytes(p, bytes);
      conn(p.id).lastError = '';
      res.ok = true;
      emit();
    } catch (e) {
      if (e.notConnected && config().autoReconnect && p.method === 'bluetooth') {
        const job = enqueue(p, opts.label || 'print job', bytes);
        scheduleReconnect(p.id);
        res.queued = true;
        res.jobId = job.id;
        res.error = 'Printer not connected — saved, it prints when the printer reconnects.';
        fail(p, res.error);
      } else {
        res.error = e.notConnected ? 'Printer not connected. Switch it on and tap Connect.' : e.message;
        fail(p, res.error);
      }
    }
    return res;
  }

  function noPrinter(what) {
    const err = new Error(`No printer on this device prints ${what}. Set one up under Printer setup.`);
    err.noPrinter = true;
    return err;
  }

  // ------------------------------------------------------------------ layouts
  const colsFor = (p) => (Number(p && p.paper) === 80 ? 48 : 32);
  const ORDER_TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };
  const DISC_LABEL = { sc: 'Senior Citizen Disc.', pwd: 'PWD Disc.', percent: 'Discount', amount: 'Discount' };

  function header(doc, b) {
    doc.center(b.name || '', { bold: true, big: true });
    if (b.address) doc.center(b.address);
    if (b.tin) doc.center('TIN: ' + b.tin);
    if (b.phone) doc.center('Tel: ' + b.phone);
    doc.hr();
  }

  function receiptDoc(t, ctx, cols = 32) {
    const doc = new Doc(cols);
    const b = ctx.business || {};
    const isBill = t.status === 'open';
    const vatAdded = Number(t.vat_inclusive) === 0 && ctx.tax && ctx.tax.vatRegistered;   // VAT-exclusive prices
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
    doc.lr(vatAdded ? 'Subtotal (VAT-exclusive)' : 'Subtotal', money(t.subtotal));
    if (t.sc_discount > 0) doc.lr(t.discount_type === 'pwd' ? 'PWD Discount' : 'SC/PWD Discount', '-' + money(t.sc_discount));
    if (t.promo_discount > 0) doc.lr(['percent', 'amount'].includes(t.discount_type) && t.discount_name ? t.discount_name : 'Other Discounts', '-' + money(t.promo_discount));
    if (vatAdded) doc.lr(`Add: VAT (${Math.round(ctx.tax.vatRate * 100)}%)`, money(t.vat_amount));
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

  /**
   * Order slip for one station (or the whole order): big table number, the items, notes.
   * title: e.g. "KITCHEN", "GRILL", "ORDER SLIP".
   */
  function slipDoc(t, lines, title, { reprint = false, cols = 32, big = true } = {}) {
    const doc = new Doc(cols);
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
    if (t.created_by_name) doc.lr('Taken by', t.created_by_name);
    return doc;
  }

  function readingDoc(r, ctx, isZ, cols = 32) {
    const doc = new Doc(cols);
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
    if (Number(r.sales.vat_added) > 0) doc.lr('Add: VAT (on top)', money(r.sales.vat_added));
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

  function testDoc(p, ctx) {
    const doc = new Doc(colsFor(p));
    header(doc, (ctx && ctx.business) || { name: 'Mark5 Restaurant Suite' });
    doc.center('PRINTER TEST', { bold: true, big: true });
    doc.lr('Printer', p.name);
    doc.lr('Paper', `${p.paper} mm / ${colsFor(p)} chars`);
    doc.lr('Prints', [p.receipts ? 'receipts' : '', p.slips ? 'order slips' : ''].filter(Boolean).join(' + ') || 'nothing yet');
    doc.lr('Adobo Rice Meal x2', money(398));
    doc.lr('TOTAL', money(398), { bold: true, big: true });
    doc.center('If you can read this, printing works!');
    return doc;
  }

  /** Longer diagnostic page: alignment, bold, sizes, column ruler, currency, long text wrap. */
  function selfTestDoc(p, ctx) {
    const cols = colsFor(p);
    const doc = new Doc(cols);
    header(doc, (ctx && ctx.business) || { name: 'Mark5 Restaurant Suite' });
    doc.center('PRINTER SELF-TEST', { bold: true, big: true });
    doc.lr('Printer', p.deviceName || p.name);
    doc.lr('Paper / columns', `${p.paper} mm / ${cols}`);
    doc.lr('Packets / delay', `${p.packetSize} / ${p.packetDelay}`);
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

  // ------------------------------------------------------------------ routing
  const takesStation = (p, sid) => p.slips && (p.stations === 'all' || (Array.isArray(p.stations) && p.stations.map(Number).includes(Number(sid || 0))));

  /**
   * Which printer prints which station's items: [{printer, groups: [{stationId, title, lines}]}].
   * lines need .station_id (null = no station). A station no printer takes goes to the first order-slip printer.
   */
  function routeSlips(lines, ctx = {}) {
    const names = {};
    (ctx.stations || []).forEach((s) => { names[s.id] = s.name; });
    const groups = new Map();   // station id (0 = none) -> lines
    for (const l of lines) {
      const sid = l.station_id && names[l.station_id] !== undefined ? Number(l.station_id) : 0;
      if (!groups.has(sid)) groups.set(sid, []);
      groups.get(sid).push(l);
    }
    const order = [...(ctx.stations || []).map((s) => Number(s.id)), 0];
    const sorted = [...groups.entries()].sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]));
    const slipPrinters = printers().filter((p) => p.slips);
    const out = new Map();
    for (const [sid, ls] of sorted) {
      const title = sid ? String(names[sid]).toUpperCase() : 'ORDER SLIP';
      let targets = slipPrinters.filter((p) => takesStation(p, sid));
      if (!targets.length && slipPrinters.length) targets = [slipPrinters[0]];
      for (const p of targets) {
        if (!out.has(p.id)) out.set(p.id, { printer: p, groups: [] });
        out.get(p.id).groups.push({ stationId: sid, title, lines: ls });
      }
    }
    return [...out.values()].filter((r) => !ctx.only || r.printer.id === ctx.only);   // ctx.only: retry on one printer
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
    if (config().autoReconnect) printers().forEach((p) => { if (!isConnected(p.id) && conn(p.id).ble.device) scheduleReconnect(p.id); });
  });

  // ------------------------------------------------------------------ public API
  const receiptPrinters = (ctx = {}) => printers().filter((p) => p.receipts && (!ctx.only || p.id === ctx.only));
  const Printer = {
    config, saveConfig, printers, printer, addPrinter, updatePrinter, removePrinter,
    capabilities, status, onStatus, log, onLog,
    isConnected, connect, reconnect, disconnect, forget, autoConnect, channels, setChannel,
    retryQueue, clearQueue, dropJob, keepAwake, routeSlips,

    /** Receipt / bill on the receipt printer (the first one, or ctx.only). */
    async printReceipt(ticket, ctx = {}) {
      const p = receiptPrinters(ctx)[0];
      if (!p) throw noPrinter('receipts');
      const label = `${ticket.status === 'open' ? 'Bill' : 'Receipt'} ${ticket.receipt_no || ticket.ticket_no}`;
      return [await output(p, [receiptDoc(ticket, ctx, colsFor(p))], {
        copies: ctx.reprint ? 1 : p.copies, label, drawer: p.openDrawer && !ctx.reprint && ticket.status === 'paid',
      })];
    },

    /** Order slips for `lines`, routed per prep station (see routeSlips). */
    async printSlips(ticket, lines, ctx = {}) {
      if (!lines.length) return [];
      const routes = routeSlips(lines, ctx);
      if (!routes.length) throw noPrinter('order slips');
      const opts = config();
      return Promise.all(routes.map(({ printer: p, groups }) => {
        const cols = colsFor(p);
        const docs = opts.slipPerStation || groups.length === 1
          ? groups.map((g) => slipDoc(ticket, g.lines, g.title, { reprint: ctx.reprint, cols, big: opts.bigItems }))
          : [slipDoc(ticket, groups.flatMap((g) => g.lines), 'ORDER SLIP', { reprint: ctx.reprint, cols, big: opts.bigItems })];
        return output(p, docs, { copies: p.slipCopies, label: `${ctx.reprint ? 'Reprint ' : ''}${groups.map((g) => g.title.toLowerCase()).join(' + ')} slip ${ticket.ticket_no}` });
      }));
    },

    async printReading(report, ctx = {}, isZ = false) {
      const p = receiptPrinters(ctx)[0];
      if (!p) throw noPrinter('receipts and readings');
      return [await output(p, [readingDoc(report, ctx, isZ, colsFor(p))], { label: isZ ? 'Z-reading' : 'X-reading' })];
    },
    async testPrint(id, ctx = {}) { const p = printer(id); if (!p) throw new Error('Printer not found'); return [await output(p, [testDoc(p, ctx)], { label: 'Test print' })]; },
    async selfTest(id, ctx = {}) { const p = printer(id); if (!p) throw new Error('Printer not found'); return [await output(p, [selfTestDoc(p, ctx)], { label: 'Self-test' })]; },

    /** Paper width used for on-screen previews: the receipt printer's, else 58 mm. */
    previewPaper() { const p = receiptPrinters()[0] || printers()[0]; return p && Number(p.paper) === 80 ? 80 : 58; },

    // exposed for previews, tests and custom layouts
    _internals: { Doc, escpos, html, receiptDoc, slipDoc, readingDoc, selfTestDoc, testDoc, lrLines, ascii, takesStation, BLE_SERVICES, NAME_PREFIXES },
  };
  window.Printer = Printer;
  if (document.readyState !== 'loading') autoConnect();
  else document.addEventListener('DOMContentLoaded', autoConnect);
})();
