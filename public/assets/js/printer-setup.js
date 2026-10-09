/**
 * Printer setup page (views/pos/printer.php): the printers of this device.
 * One card per printer (from <template id="ps-printer-template">). Every field with data-f="<setting>" is saved on
 * change with Printer.updatePrinter(); device-wide options (data-opt) with Printer.saveConfig(). The page also
 * connects / tests each printer, shows its live status, queue and Bluetooth channels, the diagnostic log and what
 * this device / browser can do.
 */
(function () {
  'use strict';
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const esc = App.esc;
  const NAMES = { bluetooth: 'Bluetooth', serial: 'USB / serial', rawbt: 'RawBT app' };
  const BUSINESS = window.PRINTER_BUSINESS;
  const STATIONS = window.PRINTER_STATIONS || [];
  const list = $('#ps-printers');

  const saved = () => { $('#ps-saved').textContent = 'Saved on this device ✓'; };

  // ------------------------------------------------------------------ device-wide options
  function fillOptions() {
    const c = Printer.config();
    $$('[data-opt]').forEach((el) => {
      if (el.type === 'checkbox') el.checked = !!c[el.dataset.opt];
      else el.value = String(c[el.dataset.opt]);
    });
  }
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-opt]');
    if (!el) return;
    Printer.saveConfig({ [el.dataset.opt]: el.type === 'checkbox' ? el.checked : el.value });
    saved();
  });

  // ------------------------------------------------------------------ printer cards
  function render() {
    const ps = Printer.printers();
    list.innerHTML = ps.length ? '' : `<div class="card ps-empty"><p class="mt-0"><b>No printer on this device yet.</b></p>
      <p class="muted">Add your Bluetooth printer to print receipts and order slips. Nothing is printed through the browser print window.</p></div>`;
    ps.forEach((p) => list.appendChild(card(p)));
    paintStatus(Printer.status());
    advice();
  }

  function card(p) {
    const el = $('#ps-printer-template').content.firstElementChild.cloneNode(true);
    el.dataset.id = p.id;
    $('[data-stations]', el).innerHTML = [
      `<label class="checkbox"><input type="checkbox" data-st="all"> <b>All stations</b></label>`,
      ...STATIONS.map((s) => `<label class="checkbox"><input type="checkbox" data-st="${s.id}"> ${esc(s.name)}</label>`),
      `<label class="checkbox"><input type="checkbox" data-st="0"> Items without a station</label>`,
    ].join('');
    fill(el, p);
    return el;
  }

  function fill(el, p) {
    $$('[data-f]', el).forEach((f) => {
      const k = f.dataset.f;
      if (f.type === 'radio') f.checked = f.value === p.method;
      else if (k === 'bleFilter') f.checked = p.bleFilter === 'all';
      else if (f.type === 'checkbox') f.checked = !!p[k];
      else f.value = String(p[k]);
    });
    // radio groups need a unique name per card
    $$('[data-f=method]', el).forEach((r) => { r.name = 'method-' + p.id; });
    const all = p.stations === 'all';
    $$('[data-st]', el).forEach((c) => {
      c.checked = c.dataset.st === 'all' ? all : all || (Array.isArray(p.stations) && p.stations.map(Number).includes(Number(c.dataset.st)));
      c.disabled = !p.slips || (c.dataset.st !== 'all' && all);
    });
    $$('[data-only]', el).forEach((x) => x.classList.toggle('hidden', !x.dataset.only.split(' ').includes(p.method)));
    $('[data-act=connect]', el).classList.toggle('hidden', p.method === 'rawbt');
    $('[data-act=reconnect]', el).classList.toggle('hidden', p.method === 'rawbt');
    const caps = Printer.capabilities();
    const blocked = (p.method === 'bluetooth' && !caps.bluetooth) || (p.method === 'serial' && !caps.serial) || (p.method === 'rawbt' && !caps.android);
    const warn = $('[data-method-warn]', el);
    warn.classList.toggle('hidden', !blocked);
    warn.textContent = !blocked ? '' : p.method === 'rawbt' ? 'RawBT is an Android app — this device is not Android.'
      : `${NAMES[p.method]} does not work in this browser / at this address. ${adviceText(caps)}`;
    const rawbts = Printer.printers().filter((x) => x.method === 'rawbt');
    if (p.method === 'rawbt' && rawbts.length > 1) {
      warn.classList.remove('hidden');
      warn.textContent = 'Only one RawBT printer works per tablet: RawBT always prints on the printer chosen in the RawBT app.';
    }
  }

  const cardOf = (el) => el.closest('.ps-card');
  const idOf = (el) => cardOf(el).dataset.id;

  list.addEventListener('change', async (e) => {
    const f = e.target;
    if (!cardOf(f)) return;
    const id = idOf(f);
    const p = Printer.printer(id);
    if (f.dataset.st !== undefined) {
      const boxes = $$('[data-st]', cardOf(f));
      let stations;
      if (f.dataset.st === 'all') stations = f.checked ? 'all' : [];
      else stations = boxes.filter((b) => b.dataset.st !== 'all' && b.checked).map((b) => Number(b.dataset.st));
      Printer.updatePrinter(id, { stations });
      fill(cardOf(f), Printer.printer(id));
      saved();
      return;
    }
    const k = f.dataset.f;
    if (!k) return;
    let v;
    if (f.type === 'radio') v = f.value;
    else if (k === 'bleFilter') v = f.checked ? 'all' : 'printers';
    else if (f.type === 'checkbox') v = f.checked;
    else if (['paper', 'copies', 'slipCopies', 'feedLines', 'baudRate'].includes(k)) v = Number(f.value);
    else if (k === 'packetSize' || k === 'packetDelay') v = f.value === 'auto' ? 'auto' : Number(f.value);
    else v = f.value.trim() || p.name;
    if (k === 'method' && v !== p.method) await Printer.forget(id);    // a different way of printing needs a new connection
    Printer.updatePrinter(id, { [k]: v });
    if (k === 'method' || k === 'slips' || k === 'receipts') fill(cardOf(f), Printer.printer(id));
    if (k === 'method') render();
    saved();
  });

  async function busy(btn, fn) {
    btn.disabled = true;
    try { await fn(); } catch (err) { App.toast(err.message, 'error'); } finally { btn.disabled = false; }
  }
  /** Show the result of a test print. */
  function told(results, what) {
    const r = results[0];
    if (r.ok) App.toast(`${what} sent to ${r.printer.name}`);
    else App.toast(r.error, r.queued ? 'info' : 'error');
  }

  list.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b || !cardOf(b)) return;
    const id = idOf(b);
    const act = b.dataset.act;
    busy(b, async () => {
      if (act === 'connect') { await Printer.connect(id); App.toast('Printer connected'); }
      if (act === 'reconnect') { await Printer.reconnect(id, { pick: true }); App.toast('Printer connected'); }
      if (act === 'disconnect') await Printer.disconnect(id);
      if (act === 'forget') {
        if (await App.ask({ title: 'Forget this device?', message: 'You will have to choose it again with “Connect”.', okText: 'Forget', danger: true })) await Printer.forget(id);
      }
      if (act === 'remove') {
        if (await App.ask({ title: `Remove “${Printer.printer(id).name}”?`, message: 'It will no longer print anything on this device.', okText: 'Remove', danger: true })) {
          await Printer.removePrinter(id);
          render();
        }
      }
      if (act === 'test') told(await Printer.testPrint(id, { business: BUSINESS }), 'Test page');
      if (act === 'selftest') told(await Printer.selfTest(id, { business: BUSINESS }), 'Self-test page');
      if (act === 'retry') await Printer.retryQueue(id);
      if (act === 'clear') Printer.clearQueue(id);
    });
  });

  $('#ps-add').addEventListener('click', () => {
    const first = !Printer.printers().length;
    const id = Printer.addPrinter({ method: Printer.capabilities().bluetooth || !Printer.capabilities().android ? 'bluetooth' : 'rawbt',
      receipts: first, slips: true, stations: 'all' });
    render();
    const el = list.querySelector(`[data-id="${id}"]`);
    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); $('.ps-name', el).select(); }
    saved();
  });

  // ------------------------------------------------------------------ live status, queue, channels
  function paintStatus(st) {
    for (const s of st.printers) {
      const el = list.querySelector(`[data-id="${s.id}"]`);
      if (!el) continue;
      const badge = $('[data-status]', el);
      badge.className = 'badge ' + (!s.linkable ? 'badge-green' : s.connecting ? 'badge-blue' : s.connected ? 'badge-green' : 'badge-amber');
      badge.textContent = !s.linkable ? 'Ready' : s.connecting ? 'Connecting…' : s.connected ? 'Connected' : 'Not connected';
      $('[data-status-text]', el).innerHTML = !s.linkable ? 'Prints through the RawBT app. Press Test print.'
        : s.connected ? `Connected to <b>${esc(s.deviceName || 'the printer')}</b>.`
          : s.deviceName ? `<b>${esc(s.deviceName)}</b> is not connected. Switch it on, then press Reconnect.`
            : 'No printer chosen yet. Switch the printer on and press <b>Connect</b>.';
      const err = $('[data-error]', el);
      err.classList.toggle('hidden', !s.lastError || s.connected);
      err.textContent = s.lastError || '';
      $('[data-act=connect]', el).textContent = s.deviceName && s.linkable ? 'Connect a different printer' : 'Connect';
      $('[data-act=reconnect]', el).disabled = s.connected || s.connecting || !s.deviceName;
      $('[data-act=disconnect]', el).disabled = !s.connected;
      $('[data-queue]', el).classList.toggle('hidden', !s.queue);
      $('[data-queue-text]', el).textContent = `${s.queue} print job${s.queue === 1 ? '' : 's'} waiting for this printer`;
      channels(el, s.id);
    }
  }

  /** Writable channels of a connected Bluetooth printer (Advanced). */
  function channels(el, id) {
    const box = $('[data-channels]', el);
    const chs = Printer.channels(id).filter((c) => c.writable);
    if (!chs.length) { box.innerHTML = 'Connect the printer to see its channels.'; return; }
    box.innerHTML = chs.map((c, i) => `<label class="channel-row"><input type="radio" name="ch-${id}" value="${i}" ${c.selected ? 'checked' : ''}>
      <span>${esc(c.characteristic)}<br><span class="muted">service ${esc(c.service)} · ${esc(c.properties.join(', '))}</span></span></label>`).join('');
    box.querySelectorAll('input').forEach((r) => r.addEventListener('change', (e) => {
      e.stopPropagation();
      const c = chs[Number(r.value)];
      try { Printer.setChannel(id, c.service, c.characteristic); App.toast('Channel changed — press Test print'); } catch (err) { App.toast(err.message, 'error'); }
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
    const text = Printer.log().map((l) => `${l.time} [${l.level}] ${l.msg}`).join('\n')
      + `\n\n${navigator.userAgent}\n${location.origin}\n${JSON.stringify({ options: Printer.config(), printers: Printer.printers() })}`;
    try { await navigator.clipboard.writeText(text); App.toast('Log copied'); } catch (err) {
      const range = document.createRange();          // no clipboard on plain http: select the text instead
      range.selectNodeContents($('#ps-log'));
      getSelection().removeAllRanges();
      getSelection().addRange(range);
      App.toast('Log selected — long-press to copy', 'info');
    }
  });

  // ------------------------------------------------------------------ what this device / browser supports
  function adviceText(c) {
    if (c.ios) return 'iPhone / iPad browsers cannot talk to Bluetooth printers. Use an Android tablet or a Windows PC for the POS.';
    if (c.bluetooth) return 'Bluetooth works here: add a printer, choose Bluetooth and press Connect.';
    if (c.android) return 'This address is not secure, so Chrome blocks Bluetooth here. Open the POS through https, use the RawBT app, or allow this address in Chrome (see Troubleshooting).';
    if (c.serial) return 'On a computer, use USB / serial for a USB printer or a Bluetooth printer paired in Windows (COM port).';
    return 'This browser cannot reach thermal printers. Open the POS in Chrome or Edge over https.';
  }
  function advice() {
    const c = Printer.capabilities();
    const row = (ok, label, hint) => `<li><span class="${ok ? 'yes' : 'no'}">${ok ? '✓' : '✕'}</span><span>${esc(label)}${hint ? ` <span class="muted small">— ${esc(hint)}</span>` : ''}</span></li>`;
    $('#ps-caps').innerHTML = [
      row(c.secure, 'Secure address (https:// or localhost)', c.secure ? '' : `this page is ${location.origin}`),
      row(c.bluetooth, 'Bluetooth printing in this browser', c.bluetooth ? '' : navigator.bluetooth ? 'needs a secure address' : 'needs Chrome or Edge on Android, Windows or Mac'),
      row(c.serial, 'USB / COM port printing', c.serial ? '' : 'Chrome or Edge on a computer only'),
      row(c.wakeLock, 'Keep the screen on', c.wakeLock ? '' : 'not supported here'),
      row(c.android, 'Android device', c.android ? 'the RawBT app works here' : ''),
    ].join('');
    $('#ps-advice').textContent = adviceText(c);
  }

  document.querySelectorAll('#ps-origin').forEach((el) => { el.textContent = location.origin; });

  fillOptions();
  render();
  showLog();
  Printer.onStatus(paintStatus);
})();
