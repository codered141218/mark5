/**
 * Mark5 receipt printing.
 *
 * One receipt layout ("doc") is rendered two ways:
 *   - ESC/POS bytes for thermal printers (58 mm = 32 characters, 80 mm = 48 characters), sent through
 *       bluetooth : Web Bluetooth (BLE printers) — Chrome on Android / Windows / Mac. Needs HTTPS or localhost.
 *       serial    : Web Serial — a USB printer or a classic Bluetooth printer paired as a COM port (Chrome desktop).
 *       rawbt     : the RawBT app on Android (any Bluetooth thermal printer, works over plain http on the LAN).
 *   - HTML for the browser print dialog (method "browser", and the fallback when a printer is unavailable).
 *
 * Settings are stored per device (localStorage), because every tablet / PC has its own printer.
 *
 * API (window.Printer):
 *   config(), saveConfig(cfg), capabilities(), isConnected(), connect(), disconnect(), onStatus(fn),
 *   printReceipt(ticket, ctx), printKitchen(ticket, lines), printReading(report, ctx, isZ), testPrint(ctx)
 *   ctx = { business: {name, address, tin, phone, receipt_title, receipt_footer}, tax: {vatRegistered, vatRate},
 *           methods: [{key, label}], reprint: bool, method: 'browser' (optional: force the browser dialog) }
 */
(function () {
  'use strict';

  const STORE_KEY = 'mark5_printer';
  const DEFAULTS = { method: 'browser', paper: 58, autoPrint: true, kitchenSlip: true, copies: 1, openDrawer: false, deviceName: '', baudRate: 9600 };

  // Service UUIDs used by common BLE thermal printers (Goojprt, Xprinter, MTP, PeriPage, generic "BlueTooth Printer"...)
  const BLE_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
    '0000af30-0000-1000-8000-00805f9b34fb',
  ];

  // ------------------------------------------------------------------ settings
  function config() {
    try { return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORE_KEY) || '{}') }; } catch (e) { return { ...DEFAULTS }; }
  }
  function saveConfig(cfg) {
    const next = { ...config(), ...cfg };
    try { localStorage.setItem(STORE_KEY, JSON.stringify(next)); } catch (e) { /* private mode: settings last for this page only */ }
    emit();
    return next;
  }
  function capabilities() {
    return {
      secure: window.isSecureContext,
      bluetooth: !!(navigator.bluetooth && window.isSecureContext),
      serial: !!(navigator.serial && window.isSecureContext),
      android: /Android/i.test(navigator.userAgent),
      ios: /iPhone|iPad|iPod/i.test(navigator.userAgent),
    };
  }

  // ------------------------------------------------------------------ text helpers
  const pesoFmt = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const money = (n) => pesoFmt.format(Number(n) || 0);
  const qtyStr = (n) => String(Math.round((Number(n) || 0) * 1000) / 1000);
  function dt(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : new Date();
    if (Number.isNaN(d.getTime())) return String(s || '');
    return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  /** Thermal printers use old code pages: keep plain ASCII (₱ -> P, ñ -> n). */
  function ascii(s) {
    return String(s ?? '').replace(/₱/g, 'P').replace(/[–—]/g, '-').replace(/[“”]/g, '"').replace(/[‘’]/g, "'")
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
  function escpos(doc, { cut = true, drawer = false } = {}) {
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
    push(0x0a, 0x0a, 0x0a);
    if (cut) push(GS, 0x56, 0x42, 0x00);                // feed and partial cut (ignored by printers without a cutter)
    if (drawer) push(ESC, 0x70, 0x00, 0x19, 0xfa);       // kick cash drawer (pin 2)
    return new Uint8Array(out);
  }

  // ------------------------------------------------------------------ HTML renderer (browser print dialog)
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function html(doc, paper) {
    // Printable width is 48 mm (58 mm paper) or 72 mm (80 mm paper); size the monospace font so that
    // exactly 32 / 48 characters fit (a Courier character is 0.6 em wide): 48 / 32 / 0.6 = 2.5 mm.
    const width = paper === 80 ? 72 : 48;
    let h = `<div class="receipt" style="width:${width}mm;font-size:${(width / doc.cols / 0.6).toFixed(2)}mm;padding:0">`;
    for (const op of doc.ops) {
      const style = `${op.bold ? 'font-weight:bold;' : ''}${op.big ? 'font-size:15px;' : ''}`;
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

  // ------------------------------------------------------------------ transports
  let ble = { device: null, characteristic: null, chunk: 180 };
  let serial = { port: null };
  const listeners = [];
  function status() {
    const c = config();
    return { connected: isConnected(), method: c.method, deviceName: c.deviceName };
  }
  function emit() { const s = status(); listeners.forEach((fn) => { try { fn(s); } catch (e) { /* ignore */ } }); }
  function onStatus(fn) { listeners.push(fn); fn(status()); }

  function isConnected() {
    const m = config().method;
    if (m === 'bluetooth') return !!(ble.device && ble.device.gatt && ble.device.gatt.connected && ble.characteristic);
    if (m === 'serial') return !!(serial.port && serial.port.writable);
    return true;
  }

  async function findWritable(server) {
    const services = await server.getPrimaryServices();
    for (const svc of services) {
      for (const ch of await svc.getCharacteristics()) {
        if (ch.properties.writeWithoutResponse || ch.properties.write) return ch;
      }
    }
    throw new Error('This Bluetooth device has no printable channel. Is it a thermal printer?');
  }

  async function bleAttach(device) {
    const server = await device.gatt.connect();
    ble = { device, characteristic: await findWritable(server), chunk: ble.chunk || 180 };
    if (!device.__mark5) {
      device.__mark5 = true;
      device.addEventListener('gattserverdisconnected', () => { ble.characteristic = null; emit(); });
    }
    saveConfig({ method: 'bluetooth', deviceName: device.name || 'Bluetooth printer' });
  }

  /** Must be called from a button click (the browser shows its device picker). */
  async function connect() {
    const cfg = config();
    if (cfg.method === 'bluetooth') {
      if (!capabilities().bluetooth) throw new Error(window.isSecureContext ? 'Web Bluetooth is not supported in this browser. Use Chrome (Android/Windows/Mac) or the RawBT method.' : 'Bluetooth needs a secure (https://) address. Use https, open the POS on localhost, or choose the RawBT method.');
      const device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE_SERVICES });
      await bleAttach(device);
    } else if (cfg.method === 'serial') {
      if (!capabilities().serial) throw new Error('Web Serial is not available. Use Chrome or Edge on a computer over https or localhost.');
      const port = await navigator.serial.requestPort();
      await port.open({ baudRate: Number(cfg.baudRate) || 9600 });
      serial.port = port;
      saveConfig({ deviceName: 'Serial / COM printer' });
    }
    emit();
  }

  /** Reconnect silently to a printer chosen earlier (no click needed when the browser remembers the permission). */
  async function autoConnect() {
    const cfg = config();
    try {
      if (cfg.method === 'bluetooth' && navigator.bluetooth && navigator.bluetooth.getDevices) {
        const devices = await navigator.bluetooth.getDevices();
        const d = devices.find((x) => x.name === cfg.deviceName) || devices[0];
        if (d) await bleAttach(d);
      } else if (cfg.method === 'serial' && navigator.serial && navigator.serial.getPorts) {
        const ports = await navigator.serial.getPorts();
        if (ports[0]) { await ports[0].open({ baudRate: Number(cfg.baudRate) || 9600 }); serial.port = ports[0]; }
      }
    } catch (e) { /* stays disconnected; the user can press Connect */ }
    emit();
  }

  async function disconnect() {
    try { if (ble.device && ble.device.gatt.connected) ble.device.gatt.disconnect(); } catch (e) { /* ignore */ }
    try { if (serial.port) await serial.port.close(); } catch (e) { /* ignore */ }
    ble = { device: null, characteristic: null, chunk: 180 };
    serial = { port: null };
    emit();
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  async function bleWrite(bytes) {
    if (!isConnected()) {
      if (ble.device) await bleAttach(ble.device);          // known device: reconnect without a picker
      else throw new Error('Printer not connected. Tap “Printer” and connect it first.');
    }
    const ch = ble.characteristic;
    const write = (part) => (ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse
      ? ch.writeValueWithoutResponse(part) : (ch.writeValueWithResponse ? ch.writeValueWithResponse(part) : ch.writeValue(part)));
    for (let i = 0; i < bytes.length;) {
      const part = bytes.slice(i, i + ble.chunk);
      try {
        await write(part);
        i += part.length;
      } catch (e) {
        if (ble.chunk > 20) { ble.chunk = 20; continue; }   // small BLE packets (default MTU) — retry smaller
        throw new Error('Bluetooth printing failed: ' + e.message);
      }
      await sleep(ble.chunk > 20 ? 15 : 8);                 // let cheap printers empty their buffer
    }
  }
  async function serialWrite(bytes) {
    if (!isConnected()) await autoConnect();
    if (!serial.port || !serial.port.writable) throw new Error('Printer not connected. Tap “Printer” and connect it first.');
    const writer = serial.port.writable.getWriter();
    try { await writer.write(bytes); } finally { writer.releaseLock(); }
  }
  function rawbtSend(bytes) {
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    const a = document.createElement('a');
    a.href = 'rawbt:base64,' + btoa(bin);
    document.body.appendChild(a);
    a.click();
    a.remove();
    return Promise.resolve();
  }

  /** Send a doc to the configured printer. opts: {copies, drawer, method} */
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
    if (method === 'bluetooth') return bleWrite(bytes);
    if (method === 'serial') return serialWrite(bytes);
    if (method === 'rawbt') return rawbtSend(bytes);
    throw new Error('Unknown printer method');
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
      if (i.notes) doc.text('  * ' + i.notes);
    }
    doc.hr();
    doc.lr('Subtotal', money(t.subtotal));
    if (t.discount_amount > 0) doc.lr(DISC_LABEL[t.discount_type] || 'Discount', '-' + money(t.discount_amount));
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
    if ((t.discount_type === 'sc' || t.discount_type === 'pwd') && (t.sc_details || []).length) {
      doc.hr();
      for (const d of t.sc_details) {
        doc.text(`${t.discount_type === 'sc' ? 'SC' : 'PWD'}: ${d.name}`);
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

  function kitchenDoc(t, lines) {
    const doc = new Doc(colsFor());
    doc.center('KITCHEN ORDER', { bold: true, big: true });
    doc.lr(t.table_label ? 'TABLE ' + t.table_label : (ORDER_TYPE[t.order_type] || '').toUpperCase(), t.ticket_no, { bold: true, big: true });
    doc.lr(dt(), 'Pax ' + t.pax);
    if (t.customer_name) doc.text('Customer: ' + t.customer_name);
    doc.hr();
    for (const l of lines) {
      doc.text(`${qtyStr(l.qty)} x ${l.name}`, { bold: true, big: true });
      if (l.notes) doc.text('  ** ' + l.notes);
    }
    doc.hr();
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
    if (r.discounts.length) doc.hr();
    for (const d of r.discounts) doc.lr(`${DT[d.discount_type] || d.discount_type} (${d.cnt})`, money(d.amount));
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
    doc.text('0123456789'.repeat(Math.ceil(colsFor() / 10)).slice(0, colsFor()));
    doc.lr('Adobo Rice Meal x2', money(398));
    doc.lr('TOTAL', money(398), { bold: true, big: true });
    doc.center('If you can read this, printing works!');
    return doc;
  }

  // ------------------------------------------------------------------ public API
  const Printer = {
    config, saveConfig, capabilities, isConnected, connect, disconnect, onStatus, autoConnect,
    printReceipt(ticket, ctx = {}) {
      const cfg = config();
      return output(receiptDoc(ticket, ctx), { method: ctx.method, copies: ctx.reprint ? 1 : cfg.copies, drawer: cfg.openDrawer && !ctx.reprint && ticket.status === 'paid' });
    },
    printKitchen(ticket, lines, ctx = {}) { return output(kitchenDoc(ticket, lines), { method: ctx.method }); },
    printReading(report, ctx = {}, isZ = false) { return output(readingDoc(report, ctx, isZ), { method: ctx.method }); },
    testPrint(ctx = {}) { return output(testDoc(ctx), { method: ctx.method }); },
    // exposed for tests / custom layouts
    _internals: { Doc, escpos, html, receiptDoc, kitchenDoc, readingDoc, lrLines, ascii },
  };
  window.Printer = Printer;
  if (document.readyState !== 'loading') autoConnect();
  else document.addEventListener('DOMContentLoaded', autoConnect);
})();
