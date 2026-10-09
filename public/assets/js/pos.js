/**
 * POS cashier screen (plain JavaScript, no framework).
 *
 * The page (views/pos/index.php) stays loaded all shift: everything happens through the JSON API under
 * /api/pos (app/Controllers/Pos/PosApiController.php), so a Bluetooth printer connection is never lost.
 *
 * Screens
 *   Orders board  - cards of the open orders + "New order / Take-out / Delivery" buttons.
 *   Order screen  - menu tiles on the left, the order panel on the right (phones: one at a time + bottom bar).
 * Workflow: customer buys -> cashier takes the order -> assigns a table (any time, or when sending / paying).
 *
 * Built for Android tablets: numbers (amounts, quantities, tables, cash counts, PINs) are typed on an on-screen
 * keypad so the phone keyboard never covers the screen; text fields keep the normal keyboard and the layout
 * shrinks above it (see section 8, "Screen & keyboard").
 *
 * Sections of this file
 *   1. Helpers            small formatting / DOM utilities
 *   2. State              everything the screen knows (S) + start-up data from PHP (B)
 *   3. API calls          one function per endpoint
 *   4. Order actions      the business flow (add item, send, discount, pay, ...)
 *   5. Rendering          top bar, board, menu, order panel, phone bottom bar
 *   6. Dialogs            generic modal + keypad / PIN pad, then one function per dialog
 *   7. Printing hooks     calls into window.Printer (public/assets/js/printer.js) with error handling
 *   8. Screen & keyboard  on-screen keyboard handling, install as an app
 *   9. Events & start-up  click delegation (data-act="..."), search box, auto-refresh
 *
 * Buttons carry data-act="name"; one click handler per area maps the name to a function (see section 9).
 */
(function () {
  'use strict';

  // =====================================================================================================
  // 1. Helpers
  // =====================================================================================================
  const $ = (sel, root = document) => root.querySelector(sel);
  const esc = App.esc;
  const peso = App.peso;
  const money = App.money;
  const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
  const qtyStr = (n) => App.qty(n);
  const TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };
  const NOTE_CHIPS = ['No onions', 'Extra spicy', 'Not spicy', 'Less ice', 'No ice', 'Well done', 'Extra rice', 'Take-out'];
  const MONEY_KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'];
  const INT_KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', 'C', '0', '⌫'];
  const finePointer = window.matchMedia('(pointer: fine)').matches; // mouse: autofocus inputs; touch: avoid popping the keyboard

  /** "2026-10-08 13:05:00" -> Date (server times are local times). */
  const toDate = (s) => new Date(String(s).replace(' ', 'T'));
  const fmtTime = (s) => (s ? toDate(s).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '');
  function fmtDate(s) {
    if (!s) return '';
    const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  }
  /** Server clock minus this device's clock (time zone / clock differences), so elapsed times are right. */
  const clockOffset = toDate(window.POS_BOOT.server_now).getTime() - Date.now();
  const minutesSince = (s) => (Date.now() + clockOffset - toDate(s).getTime()) / 60000;
  /** Minutes since an order was created, as "12m" / "1h 05m". */
  function elapsed(s) {
    const m = Math.max(0, Math.floor(minutesSince(s)));
    return m < 60 ? `${m}m` : `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
  }
  const denomLabel = (d) => (d >= 1 ? '₱' + d.toLocaleString() : `${Math.round(d * 100)}¢`);
  const plural = (n, word) => `${qtyStr(n)} ${word}${Number(n) === 1 ? '' : 's'}`;

  // =====================================================================================================
  // 2. State
  // =====================================================================================================
  /** Start-up data embedded by views/pos/index.php (= PosApiController::bootstrap() + accounts/links). */
  const B = window.POS_BOOT;
  const CAN = B.can || {};
  const can = (...perms) => perms.some((p) => CAN[p]);
  /** True when the user lacks a permission, so a manager PIN must be asked first. */
  const needPin = (perm) => !can(perm);

  const S = {
    session: B.session,             // open business day (cash session) or null
    menu: { categories: [], items: [] },
    orders: [],                     // open orders for the board
    tables: [],                     // table labels currently in use
    order: null,                    // order being edited: a ticket from the server, or a local draft (no items yet)
    view: 'board',                  // 'board' | 'order'
    pane: 'menu',                   // phones only: 'menu' | 'order'
    cat: 'all',                     // selected category id or 'all'
    queue: Promise.resolve(),       // order changes run one after another (fast taps never get lost)
    installPrompt: null,            // Chrome's "install app" event, when available
  };

  const activeLines = (t) => (t ? t.items.filter((i) => i.status === 'active') : []);
  const unsentLines = (t) => activeLines(t).filter((i) => !i.kitchen_sent);
  const itemCount = (t) => activeLines(t).reduce((s, i) => s + Number(i.qty), 0);
  const needsTable = (t) => t.order_type === 'dine_in' && !t.table_label;
  const isSc = (kind) => kind === 'sc' || kind === 'pwd';
  function orderTitle(t) {
    if (t.table_label) return 'Table ' + t.table_label;
    return t.customer_name || TYPE[t.order_type] || 'Order';
  }

  /** A new order lives only in the browser until its first item is added (so no empty orders are left behind). */
  function draftOrder(orderType, customerName) {
    return {
      draft: true, id: null, ticket_no: 'New order', order_type: orderType, table_label: null, customer_name: customerName || null,
      pax: 1, notes: null, status: 'open', items: [], payments: [], discount_type: 'none', discount_name: null, discount_rate: 0,
      sc_count: 0, sc_details: [], subtotal: 0, discount_amount: 0, sc_discount: 0, promo_discount: 0, service_charge: 0,
      vat_amount: 0, vat_exempt_sales: 0, vatable_sales: 0, total: 0, created_at: null, created_by_name: B.user.name,
    };
  }

  // ---- discount presets (Administration → Discounts): {id, name, kind: sc|pwd|percent|amount, value|null, scope, requires_approval}
  const presetsFor = (scope) => (B.discounts || []).filter((p) => p.scope === 'both' || p.scope === scope);
  const presetNeedsPin = (p) => !!p.requires_approval && needPin('pos.discount');
  function presetHint(p) {
    if (isSc(p.kind)) return `VAT-exempt + ${Math.round(B.tax.scRate * 100)}%`;
    if (p.value === null) return p.kind === 'percent' ? 'enter %' : 'enter ₱';
    return p.kind === 'percent' ? `${Number(p.value)}%` : peso(p.value);
  }

  // =====================================================================================================
  // 3. API calls (App.api adds the CSRF header and throws Error(message) on failure)
  // =====================================================================================================
  const get = (path) => App.api('GET', '/api/pos' + path);
  const post = (path, body) => App.api('POST', '/api/pos' + path, body || {});
  const Api = {
    menu: () => get('/menu'),
    orders: () => get('/orders'),                                   // {orders, tables, session}
    create: (data) => post('/orders', data),
    show: (id) => get(`/orders/${id}`),
    update: (id, data) => post(`/orders/${id}`, data),              // customer_name, pax, order_type, notes
    table: (id, label) => post(`/orders/${id}/table`, { table_label: label }),
    addItem: (id, itemId, qty = 1) => post(`/orders/${id}/items`, { item_id: itemId, qty }),
    updateLine: (id, line, data) => post(`/orders/${id}/items/${line}`, data),
    voidLine: (id, line, reason, pin) => post(`/orders/${id}/items/${line}/void`, { reason, pin }),
    discountLine: (id, line, data) => post(`/orders/${id}/items/${line}/discount`, data),  // {discount_id, value, sc_person, pin}
    send: (id) => post(`/orders/${id}/send`),                       // {ticket, sent}
    discount: (id, data) => post(`/orders/${id}/discount`, data),   // {discount_id, value, sc_count, pax, sc_details, pin}
    split: (id, data) => post(`/orders/${id}/split`, data),         // {source, target}
    merge: (id, sourceId) => post(`/orders/${id}/merge`, { source_id: sourceId }),
    pay: (id, payments) => post(`/orders/${id}/pay`, { payments }),
    void: (id, reason, pin) => post(`/orders/${id}/void`, { reason, pin }),
    reprint: (id) => post(`/orders/${id}/reprint`),
    reprintOrder: (id) => post(`/orders/${id}/reprint-order`),
    receipts: (q) => get('/receipts' + (q ? '?q=' + encodeURIComponent(q) : '')),
    customers: () => get('/customers'),
    openDay: (data) => post('/day/open', data),
    xreading: () => get('/day/xreading'),
    closeDay: (data) => post('/day/close', data),
    payouts: () => get('/payouts'),
    addPayout: (data) => post('/payouts', data),
    voidPayout: (id, reason, pin) => post(`/payouts/${id}/void`, { reason, pin }),
  };

  const showError = (e) => App.toast(e.message, 'error');

  /** Show a spinner on a button while fn() runs (errors are passed on). */
  async function spin(btn, fn) {
    if (!btn) return fn();
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = label; }
  }
  /** Run an API call with a busy button. Returns the result, or null after showing the error. */
  async function run(fn, btn) {
    try { return await spin(btn, fn); } catch (e) { showError(e); return null; }
  }
  /**
   * Run an action that may need a manager's approval: fn(pin) calls the API.
   * ask = true asks for the PIN first (the user's role lacks the permission); if the server still says a PIN is
   * needed (or it was wrong) the PIN pad opens again. Returns the result, or null when cancelled / failed.
   */
  async function withPin(fn, { ask = false, btn = null } = {}) {
    let pin = '';
    if (ask) { pin = await askPin(); if (pin === null) return null; }
    for (;;) {
      try { return await spin(btn, () => fn(pin)); } catch (e) {
        if (!/manager (authorization|PIN)/i.test(e.message)) { showError(e); return null; }
        pin = await askPin(pin ? 'Wrong PIN, or that manager may not approve this. Try again.' : e.message);
        if (pin === null) return null;
      }
    }
  }

  async function loadMenu() {
    try { S.menu = await Api.menu(); renderMenu(); } catch (e) { showError(e); }
  }
  async function loadOrders() {
    try {
      const r = await Api.orders();
      S.orders = r.orders;
      S.tables = r.tables;
      if ((r.session && r.session.id) !== (S.session && S.session.id)) S.session = r.session; // opened / closed on another terminal
      renderTop();
      if (S.view === 'board') renderBoard();
    } catch (e) { showError(e); }
  }

  // =====================================================================================================
  // 4. Order actions
  // =====================================================================================================
  /** Create a draft order on the server (when its first item is added). Returns the server ticket. */
  async function ensureOrder(d) {
    if (!d.draft) return d;
    if (!d.created) {
      d.created = await Api.create({ order_type: d.order_type, table_label: d.table_label, customer_name: d.customer_name, pax: d.pax, notes: d.notes });
      if (S.order === d) S.order = d.created;
    }
    return d.created;
  }

  /**
   * Queue a change to the current order: fn(order) calls the API and returns the updated ticket.
   * Changes run one after another, each on the order that was open when the button was pressed.
   */
  function mutate(fn) {
    const target = S.order;
    const job = S.queue.then(async () => setOrder(await fn(await ensureOrder(target))));
    S.queue = job.catch(() => {});
    return job;
  }

  /** Show an updated ticket, unless the cashier already left that order. */
  function setOrder(t) {
    if (S.view === 'order' && S.order && S.order.id === t.id) {
      S.order = t;
      renderOrder();
    }
    return t;
  }

  function newOrder(orderType) {
    if (!S.session) { App.toast('Open the business day first', 'error'); return; }
    if (orderType === 'dine_in') { openOrder(draftOrder('dine_in'), 'menu'); return; }
    // Take-out / delivery: a name helps call the customer (optional)
    App.ask({ title: `New ${TYPE[orderType]} order`, input: 'Customer name (optional)', okText: 'Start order' }).then((r) => {
      if (r) openOrder(draftOrder(orderType, r.value.trim()), 'menu');
    });
  }

  async function openOrderById(id) {
    const t = await run(() => Api.show(id));
    if (t) openOrder(t, 'order');
  }

  function openOrder(t, pane) {
    S.order = t;
    S.view = 'order';
    S.pane = pane;
    $('#pos-search').value = '';
    render();
    if (finePointer) $('#pos-search').focus();   // never on tablets: it would pop the keyboard
  }

  /** Back to the orders board (Done). */
  function closeOrder() {
    S.order = null;
    S.view = 'board';
    render();
    loadOrders();
  }

  function addItem(item) {
    if (!S.session) { App.toast('Open the business day first', 'error'); return; }
    if (item.item_type === 'retail' && item.stock_qty <= 0) App.toast(`${item.name}: out of stock in the system`, 'info');
    mutate((t) => Api.addItem(t.id, item.id)).catch(showError);
  }

  /** Change the table. Drafts are changed locally. Returns false if it failed. */
  async function setTable(label) {
    if (S.order.draft) {
      S.order.table_label = label || null;
      if (label) S.order.order_type = 'dine_in';
      renderOrder();
      return true;
    }
    try {
      await mutate((t) => Api.table(t.id, label));
      App.toast(label ? `Table ${label} assigned` : 'Table removed');
      return true;
    } catch (e) { showError(e); return false; }
  }

  /** Open the table dialog. prompt = asked before Send / Pay (has a Skip button). Resolves false when cancelled. */
  async function assignTable(prompt = false) {
    const r = await tableDialog(S.order, { prompt });
    if (!r) return false;
    if (r.skip) return true;
    return setTable(r.label);
  }

  async function sendToKitchen() {
    if (!unsentLines(S.order).length) return;
    if (needsTable(S.order) && !(await assignTable(true))) return;
    await S.queue;
    const r = await run(() => Api.send(S.order.id), $('[data-act="send"]'));
    if (!r) return;
    setOrder(r.ticket);
    if (!r.sent.length) { App.toast('Nothing new to send', 'info'); return; }
    App.toast(`${r.sent.length} item(s) sent to the kitchen`);
    if (Printer.config().kitchenSlip) Print.kitchen(r.ticket, r.sent);
  }

  /**
   * Apply a discount preset to the whole receipt (line = null) or one order line.
   * Open presets (no value) ask the % / ₱ on the keypad; extra = SC/PWD details. Returns the ticket or null.
   */
  async function applyPreset(p, line = null, extra = {}, btn = null) {
    let value;
    if (p.value === null && !isSc(p.kind)) {
      value = await askNumber({ title: p.name, label: p.kind === 'percent' ? 'Discount in %' : 'Discount amount in ₱', mode: 'money',
        prefix: p.kind === 'amount' ? '₱' : '', suffix: p.kind === 'percent' ? '%' : '', max: p.kind === 'percent' ? 100 : null, okText: 'Apply' });
      if (value === null) return null;
    }
    const data = { discount_id: p.id, value, ...extra };
    const t = await withPin((pin) => mutate((o) => (line
      ? Api.discountLine(o.id, line.id, { ...data, pin })
      : Api.discount(o.id, { ...data, pin }))), { ask: presetNeedsPin(p), btn });
    if (t) App.toast(`${p.name} applied${line ? ' to ' + line.name : ''}`);
    return t;
  }

  async function startPay() {
    if (!activeLines(S.order).length) return;
    if (needsTable(S.order) && !(await assignTable(true))) return;
    await S.queue;
    payDialog(S.order);
  }

  /** After a successful payment: success screen, receipt, kitchen slip for items never sent. */
  async function afterPaid(t, unsent) {
    closeOrder();
    loadMenu(); // retail stock changed
    const cfg = Printer.config();
    const job = paidDialog(t, cfg.autoPrint);
    if (cfg.autoPrint) job.printed = await Print.receipt(t, false);
    if (cfg.kitchenSlip && unsent.length) Print.kitchen(t, unsent);
  }

  async function cancelOrder() {
    const o = S.order;
    if (o.draft) { closeOrder(); return; }
    const sent = activeLines(o).some((i) => i.kitchen_sent);
    const r = await App.ask({ title: `Cancel order ${o.ticket_no}?`, message: 'All items on this order will be cancelled.', input: 'Reason', required: true, danger: true, okText: 'Cancel order' });
    if (!r) return;
    await S.queue;
    if (await withPin((pin) => Api.void(o.id, r.value, pin), { ask: sent && needPin('pos.void_item') })) {
      App.toast(`Order ${o.ticket_no} cancelled`);
      closeOrder();
    }
  }

  /** Reprint the order slip (all items on the order) — e.g. the kitchen lost the slip. */
  async function reprintOrder(id, btn) {
    const t = await run(() => Api.reprintOrder(id), btn);
    if (t) Print.orderSlip(t);
  }

  function logout() {
    App.ask({ title: 'Log out?', message: S.order && !S.order.draft ? 'The current order stays open on the board.' : '', okText: 'Log out' })
      .then((ok) => { if (ok) $('#logout-form').submit(); });
  }

  // =====================================================================================================
  // 5. Rendering
  // =====================================================================================================
  function render() {
    const root = $('#pos');
    root.classList.toggle('is-board', S.view === 'board');
    root.classList.toggle('is-order', S.view === 'order');
    root.classList.toggle('pane-menu', S.pane === 'menu');
    root.classList.toggle('pane-order', S.pane === 'order');
    renderTop();
    if (S.view === 'board') renderBoard();
    else { renderMenu(); renderOrder(); }
  }

  function renderTop() {
    const s = S.session;
    const p = printerState();
    const top = [
      `<span class="title">${esc(B.business.name)}</span>`,
      s ? `<span class="chip chip-open">● Day open · ${esc(fmtDate(s.business_date))}</span>` : '<span class="chip chip-closed">Day not open</span>',
      '<span class="grow"></span>',
      `<button class="btn btn-ghost${S.view === 'board' ? ' on' : ''}" type="button" data-act="board">▦ Orders <span class="count">${S.orders.length}</span></button>`,
      '<button class="btn btn-ghost" type="button" data-act="receipts">Receipts</button>',
      can('pos.petty_cash') && s ? '<button class="btn btn-ghost" type="button" data-act="payout">Payout</button>' : '',
      can('pos.xreading', 'pos.close_day') && s ? '<button class="btn btn-ghost" type="button" data-act="xread">X-Read</button>' : '',
      can('pos.close_day') && s ? '<button class="btn btn-ghost" type="button" data-act="eod">End of Day</button>' : '',
      can('pos.open_day') && !s ? '<button class="btn btn-success" type="button" data-act="openday">Open Day</button>' : '',
      `<button class="btn btn-ghost printer-chip ${p.cls}" type="button" data-act="printer" title="Printer">⎙ ${esc(p.text)}${p.queue ? ` <span class="count">${p.queue}</span>` : ''}</button>`,
      S.installPrompt ? '<button class="btn btn-ghost install-btn" type="button" data-act="install">⬇ Install app</button>' : '',
      B.links.backOffice ? `<a class="btn btn-ghost" href="${esc(B.links.backOffice)}">Back office</a>` : '',
      `<span class="chip user-chip">${esc(B.user.name)}</span>`,
      '<button class="btn btn-ghost" type="button" data-act="logout">Log out</button>',
    ];
    $('#pos-top').innerHTML = top.join('');
  }

  function renderBoard() {
    const total = S.orders.reduce((sum, o) => sum + o.total, 0);
    const cards = S.orders.map((o) => {
      const sub = [o.table_label && o.customer_name ? o.customer_name : '', TYPE[o.order_type], o.ticket_no].filter(Boolean).join(' · ');
      const mins = minutesSince(o.created_at);
      return `<div class="order-card t-${o.order_type}" role="button" tabindex="0" data-act="open" data-id="${o.id}">
          <span class="oc-head"><span class="oc-title">${esc(orderTitle(o))}</span>
            <span class="oc-time${mins > 60 ? ' late' : mins > 30 ? ' slow' : ''}">${elapsed(o.created_at)}</span>
            <button type="button" class="oc-more" data-act="cardMenu" data-id="${o.id}" aria-label="More">⋯</button></span>
          <span class="oc-sub">${esc(sub)}</span>
          <span class="oc-foot"><span>${plural(o.item_count, 'item')}${o.pax > 1 ? ` · ${o.pax} pax` : ''}</span><b>${peso(o.total)}</b></span>
          ${Number(o.unsent) ? `<span class="oc-unsent">● ${o.unsent} not sent to kitchen</span>` : ''}
        </div>`;
    }).join('');
    const off = S.session ? '' : 'disabled';
    const closedMsg = can('pos.open_day') ? 'Press “Open Day” and enter the beginning cash to start selling.' : 'Ask a cashier or manager to open the day.';
    $('#board').innerHTML = `
      ${S.session ? '' : `<div class="alert alert-warn">The business day is not open. ${closedMsg}</div>`}
      <div class="board-actions">
        <button class="btn btn-primary btn-xl" type="button" data-act="new" data-type="dine_in" ${off}>+ New order</button>
        <button class="btn btn-xl" type="button" data-act="new" data-type="takeout" ${off}>+ Take-out</button>
        <button class="btn btn-xl" type="button" data-act="new" data-type="delivery" ${off}>+ Delivery</button>
        <span class="grow"></span>
        <span class="muted board-sum">${S.orders.length} open · ${peso(total)}</span>
        <button class="btn btn-lg" type="button" data-act="refresh" title="Refresh">↻</button>
      </div>
      <div class="board-grid">${cards || '<div class="empty board-empty">No open orders. Tap “+ New order” to start.</div>'}</div>`;
  }

  /** Items shown as tiles: search (exact barcode / SKU first, then name) or the selected category. */
  function visibleItems() {
    const q = $('#pos-search').value.trim();
    const items = S.menu.items;
    if (q) {
      const lq = q.toLowerCase();
      const exact = items.filter((i) => (i.barcode && i.barcode === q) || (i.sku && i.sku.toLowerCase() === lq));
      return exact.length ? exact : items.filter((i) => i.name.toLowerCase().includes(lq));
    }
    return S.cat === 'all' ? items : items.filter((i) => i.category_id === Number(S.cat));
  }

  function renderMenu() {
    if (S.view !== 'order') return;
    const q = $('#pos-search').value.trim();
    $('#pos-search-clear').classList.toggle('hidden', !q);
    const catColor = {};
    S.menu.categories.forEach((c) => { catColor[c.id] = c.color; });
    $('#cat-bar').innerHTML = `<button type="button" class="cat-btn${S.cat === 'all' && !q ? ' active' : ''}" data-cat="all">All</button>`
      + S.menu.categories.map((c) => `<button type="button" class="cat-btn${String(S.cat) === String(c.id) && !q ? ' active' : ''}" data-cat="${c.id}"
          ${c.color ? `style="--c:${esc(c.color)}"` : ''}>${esc(c.name)}</button>`).join('');
    const tiles = visibleItems().map((i) => {
      const retail = i.item_type === 'retail';
      const out = retail && i.stock_qty <= 0;
      const color = i.color || catColor[i.category_id];
      return `<button type="button" class="tile${out ? ' out' : ''}" data-item="${i.id}" ${color ? `style="--c:${esc(color)}"` : ''}>
          <span class="tile-name">${esc(i.name)}</span>
          <span><span class="tile-price">${peso(i.price)}</span>${retail ? `<span class="tile-stock"> · ${out ? 'out of stock' : Math.floor(i.stock_qty) + ' left'}</span>` : ''}</span>
        </button>`;
    }).join('');
    $('#tiles').innerHTML = tiles || `<div class="empty" style="grid-column:1/-1">${q ? 'No item matches “' + esc(q) + '”.' : 'No items. Add sellable items under Inventory → Items.'}</div>`;
  }

  function renderOrder() {
    const t = S.order;
    if (S.view !== 'order' || !t) return;
    const active = activeLines(t);
    const unsent = unsentLines(t).length;
    const lines = t.items.map((l) => `
      <div class="tline${l.status === 'void' ? ' voided' : ''}" ${l.status === 'active' ? `data-act="line" data-id="${l.id}"` : ''}>
        <span class="tline-qty">${qtyStr(l.qty)}</span>
        <div>
          <div class="tline-name">${esc(l.name)}${l.kitchen_sent ? '<span class="sent-dot" title="Sent to kitchen"></span>' : ''}</div>
          ${l.notes ? `<div class="tline-note">${esc(l.notes)}</div>` : ''}
          ${l.status === 'active' && l.discount_kind ? `<div class="tline-disc"><span>${esc(l.discount_name || 'Discount')}</span><span>−${money(l.discount_amount)}</span></div>` : ''}
          ${l.status === 'void' ? `<div class="small">VOID: ${esc(l.void_reason || '')}</div>` : ''}
          <div class="muted small">@ ${money(l.price)}</div>
        </div>
        <span class="tline-amt">${money(l.line_total)}</span>
      </div>`).join('');
    const receiptDisc = t.discount_type !== 'none' && t.discount_name ? t.discount_name : '';
    const scLabel = `SC/PWD discount${t.sc_count ? ' ×' + t.sc_count : ''}`;
    const promoLabel = `Discounts${receiptDisc && !isSc(t.discount_type) ? ' (' + receiptDisc + ')' : ''}`;
    const sub = [t.draft ? 'New order' : t.ticket_no, `${t.pax} pax`, t.created_at ? fmtTime(t.created_at) : '', t.created_by_name].filter(Boolean).join(' · ');
    const split = can('pos.split_move');
    const off = (cond) => (cond ? '' : 'disabled');
    $('#order-pane').innerHTML = `
      <div class="ticket-head">
        <div class="row between gap-sm">
          <button type="button" class="order-title" data-act="details" title="Order details">
            <b>${esc(TYPE[t.order_type])}${t.customer_name ? ' · ' + esc(t.customer_name) : ''}</b> <span class="muted small">✎</span>
          </button>
          <button type="button" class="table-badge${t.table_label ? ' set' : ''}" data-act="table">${t.table_label ? 'Table ' + esc(t.table_label) + ' ✎' : '+ Table'}</button>
        </div>
        <div class="muted small">${esc(sub)}</div>
      </div>
      <div class="ticket-lines">${lines || '<div class="empty">Tap items on the menu to add them.</div>'}</div>
      <div class="ticket-totals">
        <div class="row"><span>Subtotal</span><span>${money(t.subtotal)}</span></div>
        ${t.sc_discount > 0 ? `<div class="row text-green"><span>${esc(scLabel)}</span><span>−${money(t.sc_discount)}</span></div>` : ''}
        ${t.promo_discount > 0 ? `<div class="row text-green"><span>${esc(promoLabel)}</span><span>−${money(t.promo_discount)}</span></div>` : ''}
        ${t.service_charge > 0 ? `<div class="row"><span>Service charge</span><span>${money(t.service_charge)}</span></div>` : ''}
        ${B.tax.vatRegistered ? `<div class="row muted small"><span>VAT incl. ${money(t.vat_amount)}${t.vat_exempt_sales > 0 ? ' · VAT-exempt ' + money(t.vat_exempt_sales) : ''}</span></div>` : ''}
        <div class="row grand"><span>TOTAL</span><span>${peso(t.total)}</span></div>
      </div>
      <div class="ticket-actions">
        <button type="button" class="btn" data-act="send" ${off(unsent)}>Send${unsent ? ` (${unsent})` : ''}</button>
        <button type="button" class="btn" data-act="discount" ${off(active.length)}>Discount</button>
        ${split ? `<button type="button" class="btn" data-act="split" ${off(active.length)}>Split</button>` : ''}
        ${split ? `<button type="button" class="btn" data-act="merge" ${off(!t.draft)}>Merge</button>` : ''}
        <button type="button" class="btn" data-act="table">Table</button>
        <button type="button" class="btn" data-act="bill" ${off(active.length)}>Print bill</button>
        <button type="button" class="btn" data-act="reprintOrder" ${off(active.length && !t.draft)}>Reprint order</button>
        <button type="button" class="btn btn-danger" data-act="cancel">Cancel order</button>
        <button type="button" class="btn" data-act="done">Done</button>
        ${can('pos.settle') ? `<button type="button" class="btn btn-success pay" data-act="pay" ${off(active.length)}>PAY ${peso(t.total)}</button>` : ''}
      </div>`;
    renderBottom();
  }

  /** Phones: "Menu | Order · N items · ₱total" switcher. */
  function renderBottom() {
    const t = S.order;
    if (!t) { $('#pos-bottom').innerHTML = ''; return; }
    const n = itemCount(t);
    $('#pos-bottom').innerHTML = `
      <button type="button" class="${S.pane === 'menu' ? 'on' : ''}" data-act="pane" data-pane="menu">Menu</button>
      <button type="button" class="${S.pane === 'order' ? 'on' : ''}" data-act="pane" data-pane="order">Order · ${plural(n, 'item')} · <b>${peso(t.total)}</b></button>`;
  }

  // =====================================================================================================
  // 6. Dialogs
  // =====================================================================================================
  /**
   * Open a modal <dialog>. Returns {el, $, close}.
   *   title, body, foot (HTML), cls (extra class), onSubmit (Enter / submit button), onClose,
   *   actions: { name: fn(button, event) } for buttons with data-act="name" inside the dialog.
   * Buttons with data-x close the dialog.
   */
  function modal({ title, body, foot = '', cls = '', onSubmit, onClose, actions = {} }) {
    const d = document.createElement('dialog');
    d.className = `modal pos-dlg ${cls}`;
    d.innerHTML = `<form method="dialog" novalidate>
        <div class="modal-head"><h3>${esc(title)}</h3><button type="button" class="icon-btn" data-x aria-label="Close">✕</button></div>
        <div class="modal-body">${body}</div>
        ${foot ? `<div class="modal-foot">${foot}</div>` : ''}
      </form>`;
    document.body.appendChild(d);
    let closed = false;
    const m = {
      el: d,
      $: (sel) => d.querySelector(sel),
      close() {
        if (closed) return;
        closed = true;
        d.close();
        d.remove();
        if (onClose) onClose();
      },
    };
    d.addEventListener('cancel', (e) => { e.preventDefault(); m.close(); });
    d.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); if (onSubmit) onSubmit(e.submitter); });
    d.addEventListener('click', (e) => {
      if (e.target.closest('[data-x]')) { m.close(); return; }
      const b = e.target.closest('[data-act]');
      if (b && actions[b.dataset.act] && !b.disabled) actions[b.dataset.act](b, e);
    });
    d.showModal();
    // Focus the main field: number fields never open the keyboard; text fields only with a mouse
    const first = d.querySelector('[autofocus]');
    if (first && (finePointer || first.readOnly)) { first.focus(); if (first.select && !first.readOnly) first.select(); }
    return m;
  }

  // ------------------------------------------------------------------ on-screen number entry
  /** A number field typed with the on-screen keypad (never opens the phone keyboard). mode: money (decimals) | int. */
  const numField = (name, value = '', mode = 'money', attrs = '') =>
    `<input class="input num-field" name="${name}" value="${esc(value)}" data-num="${mode}" readonly inputmode="none" autocomplete="off" ${attrs}>`;
  const keypad = (keys, cls = '', extra = '') => `<div class="keypad ${cls}">${keys.map((k) => `<button type="button" class="btn" data-key="${k}">${k}</button>`).join('')}${extra}</div>`;
  /** A − value + stepper for small whole numbers (pax, counts). */
  const stepper = (name, value, min = 1) => `<div class="stepper" data-min="${min}"><button type="button" class="btn" data-step="-1">−</button>
      ${numField(name, value, 'int')}<button type="button" class="btn" data-step="1">+</button></div>`;

  /** Apply a keypad key to a value. mode: money (one dot, no leading zeros) | int (no leading zeros) | text (PINs, table numbers). */
  function keyInto(value, k, mode = 'money') {
    if (k === '⌫') return value.slice(0, -1);
    if (k === 'C') return '';
    if (k === '.' && (mode !== 'money' || value.includes('.'))) return value;
    const v = value + k;
    return mode === 'text' ? v : v.replace(/^0+(?=\d)/, '');
  }

  /**
   * Wire a dialog's keypad to its number fields (data-num). Tap a field to select it; the first key replaces
   * its value. Steppers (data-step) change the field next to them. A physical keyboard works too.
   * onChange(field) runs after every change.
   */
  function numpad(root, onChange) {
    let active = null;
    let fresh = true;
    const fields = () => [...root.querySelectorAll('[data-num]')].filter((el) => el.offsetParent !== null);
    const select = (el) => {
      if (active) active.classList.remove('active');
      active = el;
      fresh = true;
      if (el) el.classList.add('active');
    };
    const changed = (el) => { el.dispatchEvent(new Event('input', { bubbles: true })); if (onChange) onChange(el); };
    const type = (k) => {
      if (k === 'next') { const all = fields(); select(all[all.indexOf(active) + 1] || all[0]); return; }
      if (!active) return;
      active.value = keyInto(fresh && k !== '⌫' ? '' : active.value, k, active.dataset.num);
      fresh = false;
      changed(active);
    };
    root.addEventListener('click', (e) => {
      const step = e.target.closest('[data-step]');
      if (step) {
        const box = step.closest('.stepper');
        const f = box.querySelector('[data-num]');
        f.value = String(Math.max(Number(box.dataset.min) || 0, (Number(f.value) || 0) + Number(step.dataset.step)));
        select(f);
        changed(f);
        return;
      }
      const f = e.target.closest('[data-num]');
      if (f) { select(f); return; }
      const k = e.target.closest('[data-key]');
      if (k) type(k.dataset.key);
    });
    root.addEventListener('keydown', (e) => {
      if (e.target.matches('input:not([data-num]), textarea, select') || e.ctrlKey || e.metaKey || e.altKey) return;
      const k = /^[0-9.]$/.test(e.key) ? e.key : e.key === 'Backspace' ? '⌫' : null;
      if (k) { e.preventDefault(); type(k); }
    });
    select(root.querySelector('[data-num]'));
    return { select, type };
  }

  /** Ask one number on the keypad. Resolves the number, or null when cancelled. */
  function askNumber({ title, label = '', value = '', mode = 'money', prefix = '', suffix = '', max = null, okText = 'OK' }) {
    return new Promise((resolve) => {
      let result = null;
      const m = modal({
        title,
        cls: 'dlg-number',
        body: `${label ? `<div class="field-label mb-sm">${esc(label)}</div>` : ''}
          <div class="num-display">${prefix ? `<span>${esc(prefix)}</span>` : ''}${numField('n', value, mode, 'autofocus')}${suffix ? `<span>${esc(suffix)}</span>` : ''}</div>
          ${keypad(mode === 'money' ? MONEY_KEYS : INT_KEYS, 'mt')}`,
        foot: `<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">${esc(okText)}</button>`,
        onSubmit: () => {
          const v = Number(m.$('[name=n]').value);
          if (!(v > 0)) { App.toast('Enter a number greater than zero', 'error'); return; }
          if (max !== null && v > max) { App.toast(`The maximum is ${max}`, 'error'); return; }
          result = v;
          m.close();
        },
        onClose: () => resolve(result),
      });
      numpad(m.el);
    });
  }

  /** Manager PIN pad. Resolves the PIN, or null when cancelled. */
  function askPin(message = 'This needs a manager’s approval. Ask a manager to enter their PIN.') {
    return new Promise((resolve) => {
      let result = null;
      const m = modal({
        title: 'Manager PIN',
        cls: 'dlg-pin',
        body: `<p class="muted mt-0">${esc(message)}</p>
          <div class="pin-dots"></div>
          <input type="hidden" name="pin" data-num="text">
          ${keypad(INT_KEYS, 'mt')}`,
        foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Approve</button>',
        onSubmit: () => {
          const pin = m.$('[name=pin]').value;
          if (!pin) return;
          result = pin;
          m.close();
        },
        onClose: () => resolve(result),
      });
      const dots = () => {
        const n = m.$('[name=pin]').value.length;
        m.$('.pin-dots').innerHTML = n ? '●'.repeat(n) : '<span class="muted">Enter PIN</span>';
      };
      // The hidden field cannot be tapped: select it by hand so the keypad types into it
      numpad(m.el, dots).select(m.$('[name=pin]'));
      m.$('[type=submit]').focus();
      dots();
    });
  }

  // ------------------------------------------------------------------ assign / change table
  /** Resolves {label} ('' = no table), {skip: true} or null (cancelled). */
  function tableDialog(order, { prompt = false } = {}) {
    return new Promise((resolve) => {
      let result = null;
      const busy = S.tables.filter((l) => l !== order.table_label);
      const labels = Array.from({ length: 20 }, (_, i) => String(i + 1));
      busy.forEach((l) => { if (!labels.includes(l)) labels.push(l); });
      const chips = labels.map((l) => `<button type="button" class="table-chip${busy.includes(l) ? ' busy' : ''}${l === order.table_label ? ' current' : ''}"
          data-act="chip" data-label="${esc(l)}">${esc(l)}${busy.includes(l) ? '<small>in use</small>' : ''}</button>`).join('');
      const m = modal({
        title: prompt ? 'Assign a table?' : (order.table_label ? `Change table (now ${order.table_label})` : 'Assign table'),
        cls: 'dlg-table',
        body: `${prompt ? '<p class="muted mt-0">This dine-in order has no table yet. Pick the table the customer sits at, or skip.</p>' : ''}
          <div class="table-entry">
            <input class="input table-input" name="label" maxlength="30" autocomplete="off" placeholder="Table no." data-num="text" readonly inputmode="none" value="${esc(order.table_label || '')}">
            <button type="button" class="btn btn-lg abc-btn" data-act="abc" title="Type letters, e.g. Patio 2">ABC</button>
          </div>
          <div class="table-warn small"></div>
          <div class="table-layout">
            <div><div class="field-label mb-sm">Tap a table</div><div class="table-chips">${chips}</div></div>
            <div class="table-keys-box"><div class="field-label mb-sm">or type the number</div>${keypad(INT_KEYS, 'table-keys')}</div>
          </div>`,
        foot: `<button type="button" class="btn" data-act="none">No table</button>
          ${prompt ? '<button type="button" class="btn" data-act="skip">Skip</button>' : ''}
          <span class="grow"></span>
          <button type="button" class="btn" data-x>Cancel</button>
          <button type="submit" class="btn btn-primary btn-lg">Assign table</button>`,
        onSubmit: () => {
          const v = input.value.trim();
          if (!v) { App.toast('Enter or tap a table', 'error'); return; }
          done({ label: v });
        },
        onClose: () => resolve(result),
        actions: {
          chip: (b) => {
            input.value = b.dataset.label;
            if (busy.includes(b.dataset.label)) warn(); else done({ label: b.dataset.label });
          },
          // Letters (e.g. "Patio 2"): switch the field to the phone keyboard; tap again for the keypad
          abc: (b) => {
            const typing = input.readOnly;
            input.readOnly = !typing;
            input.inputMode = typing ? 'text' : 'none';
            b.textContent = typing ? '123' : 'ABC';
            m.el.classList.toggle('typing', typing);
            if (typing) input.focus(); else input.blur();
          },
          none: () => done({ label: '' }),
          skip: () => done({ skip: true }),
        },
      });
      const input = m.$('[name=label]');
      function warn() {
        const v = input.value.trim();
        const other = S.orders.find((o) => o.table_label === v && o.id !== order.id);
        m.$('.table-warn').innerHTML = busy.includes(v)
          ? `⚠ Table ${esc(v)} already has an open order${other ? ` (${esc(other.ticket_no)}, ${peso(other.total)})` : ''}. You can still use it.` : '';
      }
      function done(v) { result = v; m.close(); }
      numpad(m.el, warn);
      input.addEventListener('input', warn);
      warn();
    });
  }

  // ------------------------------------------------------------------ order details (customer, pax, type, notes)
  function detailsDialog() {
    const t = S.order;
    const m = modal({
      title: 'Order details',
      body: `<label class="field"><span class="field-label">Customer name</span><input class="input" name="customer_name" value="${esc(t.customer_name || '')}" autocomplete="off"></label>
        <div class="form-grid mt">
          <label class="field"><span class="field-label">No. of guests (pax)</span>${stepper('pax', t.pax)}</label>
          <label class="field"><span class="field-label">Order type</span><select class="input" name="order_type">
            ${Object.entries(TYPE).map(([k, v]) => `<option value="${k}"${k === t.order_type ? ' selected' : ''}>${v}</option>`).join('')}</select></label>
        </div>
        <label class="field mt"><span class="field-label">Order notes</span><input class="input" name="notes" value="${esc(t.notes || '')}" autocomplete="off"></label>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Save</button>',
      onSubmit: async (btn) => {
        const data = { customer_name: m.$('[name=customer_name]').value.trim(), pax: Math.max(1, Number(m.$('[name=pax]').value) || 1),
          order_type: m.$('[name=order_type]').value, notes: m.$('[name=notes]').value.trim() };
        if (S.order.draft) { Object.assign(S.order, data, { customer_name: data.customer_name || null }); renderOrder(); m.close(); return; }
        if (await run(() => mutate((o) => Api.update(o.id, data)), btn)) m.close();
      },
    });
    numpad(m.el);
  }

  // ------------------------------------------------------------------ order line: quantity, kitchen note, discount, void
  function lineDialog(line) {
    const sent = !!line.kitchen_sent;
    const order = S.order;
    const presets = presetsFor('item');
    const receiptSc = isSc(order.discount_type);
    const m = modal({
      title: line.name,
      cls: 'dlg-line',
      body: `<div class="qty-row">
          <button type="button" class="btn btn-lg" data-act="minus">−</button>
          ${numField('qty', qtyStr(line.qty), 'money', 'data-act="qty"')}
          <button type="button" class="btn btn-lg" data-act="plus">+</button>
        </div>
        <p class="muted small">${peso(line.price)} each · ${sent ? '<b class="text-green">Already sent to the kitchen</b>' : 'Not yet sent to the kitchen'}</p>
        <label class="field"><span class="field-label">Kitchen note</span>
          <input class="input" name="notes" value="${esc(line.notes || '')}" placeholder="e.g. no onions, extra spicy, less ice" autocomplete="off"></label>
        <div class="note-chips">${NOTE_CHIPS.map((n) => `<button type="button" class="btn btn-sm" data-act="note" data-note="${esc(n)}">${esc(n)}</button>`).join('')}</div>

        <div class="line-section">
          <div class="row between"><span class="field-label">Discount this item</span>
            ${line.discount_kind ? `<span class="tline-disc">${esc(line.discount_name)} −${money(line.discount_amount)}</span>` : ''}</div>
          ${receiptSc ? '<p class="muted small">The whole receipt has a Senior Citizen / PWD discount. Remove it to discount single items.</p>' : `
          <div class="preset-grid">
            ${presets.map((p) => `<button type="button" class="preset${line.discount_id === p.id ? ' sel' : ''}" data-act="preset" data-id="${p.id}">
              <b>${esc(p.name)}</b><small>${esc(presetHint(p))}${presetNeedsPin(p) ? ' · PIN' : ''}</small></button>`).join('')}
            ${line.discount_kind ? '<button type="button" class="preset preset-remove" data-act="removeDisc"><b>Remove</b><small>no discount</small></button>' : ''}
          </div>
          <div class="sc-person hidden"></div>`}
        </div>

        ${sent ? `<div class="line-section">
            <label class="field"><span class="field-label">Void reason (to void or reduce a sent item)</span>
              <input class="input" name="reason" placeholder="e.g. wrong order, customer changed mind" autocomplete="off"></label>
          </div>` : ''}`,
      foot: `<button type="button" class="btn btn-danger btn-lg" data-act="void">${sent ? 'Void item' : 'Remove'}</button>
        <span class="grow"></span>
        <button type="button" class="btn btn-lg" data-x>Cancel</button>
        <button type="submit" class="btn btn-primary btn-lg">Update</button>`,
      actions: {
        minus: () => { qty.value = qtyStr(Math.max(1, (Number(qty.value) || 1) - 1)); },
        plus: () => { qty.value = qtyStr((Number(qty.value) || 0) + 1); },
        qty: async () => {
          const v = await askNumber({ title: `Quantity — ${line.name}`, value: '', okText: 'Set quantity' });
          if (v !== null) qty.value = qtyStr(v);
        },
        note: (b) => { notes.value = (notes.value ? notes.value + ', ' : '') + b.dataset.note; },
        preset: async (b) => {
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (isSc(p.kind)) { scPersonForm(p); return; }
          if (await applyPreset(p, line, {}, b)) m.close();
        },
        scApply: async (b) => {
          const person = { name: val('sc_name'), id_no: val('sc_id') };
          if (!person.name || !person.id_no) { App.toast('Enter the name and ID number', 'error'); return; }
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (await applyPreset(p, line, { sc_person: person }, b)) m.close();
        },
        pickPerson: (b) => {
          const p = order.sc_details[Number(b.dataset.i)];
          m.$('[name=sc_name]').value = p.name;
          m.$('[name=sc_id]').value = p.id_no;
        },
        removeDisc: async (b) => {
          if (await run(() => mutate((o) => Api.discountLine(o.id, line.id, { discount_type: 'none' })), b)) { App.toast('Discount removed'); m.close(); }
        },
        void: async (b) => {
          const reason = val('reason');
          if (sent && !reason) { App.toast('Enter the void reason', 'error'); m.$('[name=reason]').focus(); return; }
          const ok = await withPin((pin) => mutate((o) => Api.voidLine(o.id, line.id, reason || 'Removed', pin)), { ask: sent && needPin('pos.void_item'), btn: b });
          if (ok) m.close();
        },
      },
      onSubmit: async (btn) => {
        const q = Number(qty.value);
        if (!(q > 0)) { App.toast('Quantity must be greater than zero', 'error'); return; }
        const reducing = sent && q < line.qty;
        if (reducing && !val('reason')) { App.toast('Enter the void reason for the reduced quantity', 'error'); m.$('[name=reason]').focus(); return; }
        const ok = await withPin((pin) => mutate((o) => Api.updateLine(o.id, line.id, { qty: q, notes: notes.value, reason: val('reason'), pin })),
          { ask: reducing && needPin('pos.void_item'), btn });
        if (ok) m.close();
      },
    });
    const qty = m.$('[name=qty]');
    const notes = m.$('[name=notes]');
    const val = (name) => { const el = m.$(`[name=${name}]`); return el ? el.value.trim() : ''; };

    /** SC / PWD on one item: the person's name + ID (people already on this order can be picked). */
    function scPersonForm(p) {
      const box = m.$('.sc-person');
      box.classList.remove('hidden');
      box.innerHTML = `<div class="field-label mt">${esc(p.name)} — who is this item for?</div>
        ${order.sc_details.length ? `<div class="note-chips">${order.sc_details.map((x, i) => `<button type="button" class="btn btn-sm" data-act="pickPerson" data-i="${i}">${esc(x.name)} · ${esc(x.id_no)}</button>`).join('')}</div>` : ''}
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Name</span><input class="input" name="sc_name" autocomplete="off"></label>
          <label class="field"><span class="field-label">${p.kind === 'sc' ? 'OSCA' : 'PWD'} ID no.</span><input class="input" name="sc_id" autocomplete="off"></label>
        </div>
        <button type="button" class="btn btn-primary btn-lg mt" data-act="scApply" data-id="${p.id}">Apply ${esc(p.name)}</button>`;
      box.scrollIntoView({ block: 'nearest' });
    }
  }

  // ------------------------------------------------------------------ discount on the whole receipt (presets)
  function discountDialog() {
    const t = S.order;
    const presets = presetsFor('order');
    const itemDiscounts = activeLines(t).filter((l) => l.discount_kind).length;
    const m = modal({
      title: 'Discount — whole receipt',
      cls: 'dlg-discount',
      body: `${t.discount_type !== 'none' ? `<div class="alert alert-success small">Now: <b>${esc(t.discount_name || 'Discount')}</b> on the whole receipt.</div>` : ''}
        ${itemDiscounts ? `<p class="muted small mt-0">${plural(itemDiscounts, 'item')} on this order already ${itemDiscounts === 1 ? 'has' : 'have'} its own discount (tap the item to change it).</p>` : ''}
        <div class="preset-grid big">
          ${presets.map((p) => `<button type="button" class="preset${t.discount_id === p.id ? ' sel' : ''}" data-act="preset" data-id="${p.id}">
            <b>${esc(p.name)}</b><small>${esc(presetHint(p))}${presetNeedsPin(p) ? ' · PIN' : ''}</small></button>`).join('')}
        </div>
        <div class="sc-form hidden"></div>`,
      foot: `${t.discount_type !== 'none' ? '<button type="button" class="btn btn-danger btn-lg" data-act="remove">Remove discount</button>' : ''}
        <span class="grow"></span><button type="button" class="btn btn-lg" data-x>Close</button>
        <button type="button" class="btn btn-primary btn-lg hidden" data-act="scApply">Apply</button>`,
      actions: {
        preset: async (b) => {
          const p = presets.find((x) => x.id === Number(b.dataset.id));
          if (isSc(p.kind)) { scForm(p); return; }
          if (await applyPreset(p, null, {}, b)) m.close();
        },
        remove: async (b) => {
          if (await run(() => mutate((o) => Api.discount(o.id, { discount_type: 'none' })), b)) { App.toast('Discount removed'); m.close(); }
        },
        scApply: async (b) => {
          const n = Math.max(Number(f.count) || 1, 1);
          const people = Array.from({ length: n }, (_, i) => f.people[i] || { name: '', id_no: '' });
          if (people.some((x) => !x.name.trim() || !x.id_no.trim())) { App.toast('Enter the name and ID number of each person', 'error'); return; }
          if (await applyPreset(f.preset, null, { sc_count: n, pax: Number(f.pax) || t.pax, sc_details: people }, b)) m.close();
        },
      },
    });
    // SC / PWD on the whole receipt: guests, number of SC/PWD, name + ID of each
    const f = { preset: null, pax: String(t.pax), count: String(Math.max(t.sc_count || 1, 1)), people: t.sc_details.map((p) => ({ ...p })) };
    function scForm(p) {
      f.preset = p;
      m.el.querySelectorAll('[data-act=preset]').forEach((b) => b.classList.toggle('sel', Number(b.dataset.id) === p.id));
      const apply = m.$('[data-act=scApply]');
      apply.classList.remove('hidden');
      apply.textContent = `Apply ${p.name}`;
      paintSc();
      m.$('.sc-form').scrollIntoView({ block: 'nearest' });
    }
    function paintSc() {
      const box = m.$('.sc-form');
      const p = f.preset;
      const n = Math.max(Number(f.count) || 1, 1);
      const who = p.kind === 'sc' ? 'senior citizen' : 'PWD';
      box.classList.remove('hidden');
      box.innerHTML = `<div class="alert alert-info small mt">${B.tax.vatRegistered ? 'VAT-exempt + ' : ''}${Math.round(B.tax.scRate * 100)}% discount on the qualified share
          of the bill (${n} of ${esc(f.pax || '1')} guests). Enter each ${who}’s name and ID number for the BIR sales book.</div>
        <div class="form-grid">
          <label class="field"><span class="field-label">Total guests (pax)</span>${stepper('pax', f.pax)}</label>
          <label class="field"><span class="field-label">No. of ${who}s</span>${stepper('count', f.count)}</label>
        </div>
        ${Array.from({ length: n }, (_, i) => `<div class="form-grid mt">
          <label class="field"><span class="field-label">Name #${i + 1}</span><input class="input" data-p="${i}" data-k="name" autocomplete="off" value="${esc((f.people[i] || {}).name || '')}"></label>
          <label class="field"><span class="field-label">${p.kind === 'sc' ? 'OSCA' : 'PWD'} ID no.</span><input class="input" data-p="${i}" data-k="id_no" autocomplete="off" value="${esc((f.people[i] || {}).id_no || '')}"></label>
        </div>`).join('')}`;
    }
    // Keep typed names in f; changing the number of people redraws the name rows
    m.el.addEventListener('input', (e) => {
      const el = e.target;
      if (el.dataset.p !== undefined) {
        const i = Number(el.dataset.p);
        f.people[i] = { name: '', id_no: '', ...f.people[i], [el.dataset.k]: el.value };
      } else if (el.name === 'pax' || el.name === 'count') {
        f[el.name] = el.value;
        if (el.name === 'count') paintSc();
      }
    });
    numpad(m.el);
  }

  // ------------------------------------------------------------------ split: move lines to a new or another order
  async function splitDialog() {
    await S.queue;
    await loadOrders();
    const t = S.order;
    const lines = activeLines(t);
    const sel = {};   // line id -> qty to move
    const others = S.orders.filter((o) => o.id !== t.id);
    const m = modal({
      title: 'Split order — choose the items to move',
      cls: 'dlg-split',
      body: `<div class="row gap-sm mb"><button type="button" class="btn" data-act="all">Select all</button><button type="button" class="btn" data-act="clear">Clear</button></div>
        <div class="split-lines"></div>
        <div class="form-grid mt">
          <label class="field"><span class="field-label">Move the selected items to</span><select class="input" name="target">
            <option value="">A new order (separate bill)</option>
            ${others.map((o) => `<option value="${o.id}">${esc(o.ticket_no)} · ${esc(orderTitle(o))} · ${peso(o.total)}</option>`).join('')}</select></label>
          <div class="field new-only"><span class="field-label">Table of the new order</span>
            <button type="button" class="btn btn-lg split-table" data-act="table">${t.table_label ? 'Table ' + esc(t.table_label) : 'No table'}</button></div>
          <label class="field new-only"><span class="field-label">Customer name (optional)</span><input class="input" name="customer_name" autocomplete="off"></label>
        </div>`,
      foot: '<span class="muted split-sum"></span><span class="grow"></span><button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-primary btn-lg">Split</button>',
      actions: {
        all: () => { lines.forEach((l) => { sel[l.id] = l.qty; }); paint(); },
        clear: () => { lines.forEach((l) => { sel[l.id] = 0; }); paint(); },
        less: (b) => { setQ(b.dataset.id, (sel[b.dataset.id] || 0) - 1); },
        more: (b) => { setQ(b.dataset.id, (sel[b.dataset.id] || 0) + 1); },
        whole: (b) => { setQ(b.dataset.id, Infinity); },
        table: async (b) => {
          const r = await tableDialog({ id: null, table_label: newTable || null });
          if (!r) return;
          newTable = r.label || '';
          b.textContent = newTable ? 'Table ' + newTable : 'No table';
        },
      },
      onSubmit: async (btn) => {
        const chosen = Object.entries(sel).filter(([, q]) => q > 0).map(([id, q]) => ({ id: Number(id), qty: q }));
        if (!chosen.length) { App.toast('Select the items to move', 'error'); return; }
        const target = m.$('[name=target]').value;
        const data = target ? { lines: chosen, target_id: Number(target) }
          : { lines: chosen, table_label: newTable, customer_name: m.$('[name=customer_name]').value.trim() };
        const r = await run(() => Api.split(t.id, data), btn);
        if (!r) return;
        m.close();
        App.toast(`Items moved to ${r.target.ticket_no}`);
        // When everything was moved, continue with the order that now has the items
        if (activeLines(r.source).length) setOrder(r.source); else openOrder(r.target, 'order');
        loadOrders();
      },
    });
    let newTable = t.table_label || '';
    function setQ(id, q) {
      const l = lines.find((x) => String(x.id) === String(id));
      sel[id] = Math.max(0, Math.min(l.qty, q));
      paint();
    }
    function paint() {
      m.$('.split-lines').innerHTML = lines.map((l) => `<div class="split-line">
          <div><b>${esc(l.name)}</b><div class="muted small">${qtyStr(l.qty)} × ${money(l.price)}${l.notes ? ' · ' + esc(l.notes) : ''}</div></div>
          <div class="row gap-sm">
            <button type="button" class="btn" data-act="less" data-id="${l.id}">−</button>
            <span class="split-q">${qtyStr(sel[l.id] || 0)}</span>
            <button type="button" class="btn" data-act="more" data-id="${l.id}">+</button>
            <button type="button" class="btn btn-ghost" data-act="whole" data-id="${l.id}">All</button>
          </div></div>`).join('');
      const amt = lines.reduce((s, l) => s + (sel[l.id] || 0) * l.price, 0);
      m.$('.split-sum').textContent = `Moving ${peso(amt)}`;
    }
    m.$('[name=target]').addEventListener('change', (e) => {
      m.el.querySelectorAll('.new-only').forEach((el) => el.classList.toggle('hidden', !!e.target.value));
    });
    paint();
  }

  // ------------------------------------------------------------------ merge another open order into this one
  async function mergeDialog() {
    await S.queue;
    await loadOrders();
    const t = S.order;
    const others = S.orders.filter((o) => o.id !== t.id);
    const m = modal({
      title: `Merge another order into ${orderTitle(t)}`,
      body: `<p class="muted mt-0">All items of the chosen order move to this order; the other order is closed.</p>
        ${others.map((o) => `<div class="pick-row">
          <div><b>${esc(orderTitle(o))}</b> <span class="muted">· ${esc(o.ticket_no)}${o.table_label && o.customer_name ? ' · ' + esc(o.customer_name) : ''}</span>
            <div class="muted small">${plural(o.item_count, 'item')} · ${peso(o.total)} · ${elapsed(o.created_at)}</div></div>
          <button type="button" class="btn btn-primary btn-lg" data-act="merge" data-id="${o.id}">Merge</button></div>`).join('') || '<div class="empty">No other open orders.</div>'}`,
      actions: {
        merge: async (b) => {
          const r = await run(() => Api.merge(t.id, Number(b.dataset.id)), b);
          if (r) { m.close(); setOrder(r); App.toast('Orders merged'); loadOrders(); }
        },
      },
    });
  }

  // ------------------------------------------------------------------ board card ⋯ menu
  function cardMenu(id) {
    const o = S.orders.find((x) => x.id === id);
    if (!o) return;
    const m = modal({
      title: `${orderTitle(o)} · ${o.ticket_no}`,
      cls: 'dlg-card',
      body: `<div class="col gap-sm">
          <button type="button" class="btn btn-xl" data-act="open">Open order</button>
          <button type="button" class="btn btn-xl" data-act="reprint">⎙ Reprint order slip</button>
          <button type="button" class="btn btn-xl" data-act="bill">⎙ Print bill</button>
        </div>`,
      actions: {
        open: () => { m.close(); openOrderById(id); },
        reprint: async (b) => { await reprintOrder(id, b); m.close(); },
        bill: async (b) => { const t = await run(() => Api.show(id), b); if (t) { m.close(); Print.receipt(t, false); } },
      },
    });
  }

  // ------------------------------------------------------------------ payment
  function payDialog(t) {
    const methods = B.payment_methods;
    const label = (k) => (methods.find((x) => x.key === k) || {}).label || k;
    const pays = [];           // [{method, amount, reference, customer_id}]
    let method = 'cash';
    let customers = null;      // loaded when "charge" is chosen
    const unsent = unsentLines(t);
    const total = t.total;
    const sums = () => {
      const paid = r2(pays.reduce((s, p) => s + p.amount, 0));
      const nonCash = r2(pays.filter((p) => p.method !== 'cash').reduce((s, p) => s + p.amount, 0));
      return { paid, nonCash, remaining: r2(Math.max(total - paid, 0)), change: r2(Math.max(paid - total, 0)) };
    };

    const m = modal({
      title: `Payment — ${orderTitle(t)} · ${t.ticket_no}`,
      cls: 'wide dlg-pay',
      body: `<div class="pay-grid">
          <div>
            <div class="pay-stats">
              <div class="stat stat-brand"><div class="stat-label">Amount due</div><div class="stat-value">${peso(total)}</div></div>
              <div class="stat pay-rem"><div class="stat-label"></div><div class="stat-value"></div></div>
            </div>
            <div class="field-label mt mb-sm">Payment method</div>
            <div class="pay-methods">${methods.map((x) => `<button type="button" class="btn" data-act="method" data-m="${x.key}">${esc(x.label)}</button>`).join('')}</div>
            <label class="field mt pay-ref"><span class="field-label">Approval / reference no.</span>
              <input class="input" name="reference" placeholder="e.g. card approval code or GCash ref no." autocomplete="off"></label>
            <label class="field mt pay-cust"><span class="field-label">Customer account</span><select class="input" name="customer"><option value="">Loading…</option></select></label>
            <div class="field-label mt mb-sm">Payments</div>
            <div class="pay-list"></div>
          </div>
          <div>
            ${numField('entry', '', 'money', 'autofocus')}
            <div class="quick-cash mt"></div>
            ${keypad(MONEY_KEYS, 'mt')}
            <div class="row gap-sm mt"><button type="button" class="btn btn-lg grow" data-key="C">Clear</button>
              <button type="button" class="btn btn-dark btn-lg grow pay-add" data-act="add"></button></div>
          </div>
        </div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-success btn-lg pay-complete"></button>',
      actions: {
        method: (b) => { method = b.dataset.m; if (method === 'charge') loadCustomers(); paint(); },
        add: () => add(),
        quick: (b) => add(Number(b.dataset.v)),
        removePay: (b) => { pays.splice(Number(b.dataset.i), 1); paint(); },
      },
      onSubmit: (btn) => complete(btn),
    });
    const entryEl = m.$('[name=entry]');
    entryEl.classList.add('pay-entry');
    const entry = () => entryEl.value;
    const pad = numpad(m.el, () => paint());

    async function loadCustomers() {
      if (customers) return;
      try {
        customers = await Api.customers();
        m.$('[name=customer]').innerHTML = '<option value="">Select customer…</option>'
          + customers.map((c) => `<option value="${c.id}">${esc(c.name)} (bal ${money(c.balance)})</option>`).join('');
      } catch (e) { showError(e); }
    }

    /** Add a payment line. Non-cash payments are capped at what is still due. */
    function add(amountArg) {
      const { nonCash, remaining } = sums();
      let amount = r2(amountArg !== undefined ? amountArg : entry() === '' ? remaining : entry());
      if (!(amount > 0)) return false;
      if (method !== 'cash' && r2(nonCash + amount) > total) {
        amount = r2(total - nonCash);
        if (!(amount > 0)) { App.toast('Non-cash payments cannot exceed the amount due', 'error'); return false; }
      }
      const p = current(amount);
      if (!p) return false;
      pays.push(p);
      entryEl.value = '';
      pad.select(entryEl);
      m.$('[name=reference]').value = '';
      method = 'cash';
      paint();
      return true;
    }
    function current(amount) {
      const customerId = Number(m.$('[name=customer]').value) || null;
      if (method === 'charge' && !customerId) { App.toast('Select the customer account to charge', 'error'); return null; }
      return { method, amount, reference: method === 'cash' ? null : m.$('[name=reference]').value.trim() || null, customer_id: method === 'charge' ? customerId : null };
    }

    async function complete(btn) {
      let list = pays.slice();
      // Nothing added yet: pay the typed amount (or the full amount) with the selected method
      if (!list.length) {
        const p = current(r2(entry() === '' ? total : entry()));
        if (!p) return;
        list = [p];
      } else if (entry() !== '') {
        if (!add()) return;
        list = pays.slice();
      }
      const paid = await run(() => Api.pay(t.id, list), btn);
      if (!paid) return;
      m.close();
      afterPaid(paid, unsent);
    }

    function paint() {
      const { paid, remaining, change } = sums();
      const rem = m.$('.pay-rem');
      rem.className = `stat pay-rem ${change > 0 ? 'stat-green' : remaining > 0 ? 'stat-amber' : ''}`;
      rem.querySelector('.stat-label').textContent = change > 0 ? 'Change' : 'Remaining';
      rem.querySelector('.stat-value').textContent = peso(change > 0 ? change : remaining);
      m.el.querySelectorAll('[data-act=method]').forEach((b) => b.classList.toggle('sel', b.dataset.m === method));
      m.$('.pay-ref').classList.toggle('hidden', method === 'cash' || method === 'charge');
      m.$('.pay-cust').classList.toggle('hidden', method !== 'charge');
      entryEl.placeholder = money(remaining || total);
      // Quick cash: exact amount + next round bills
      const due = remaining || total;
      const quick = [...new Set([due, ...[20, 50, 100, 500, 1000].map((s) => Math.ceil(due / s) * s)])].filter((v) => v >= due).sort((a, b) => a - b).slice(0, 5);
      m.$('.quick-cash').innerHTML = method === 'cash' && remaining > 0
        ? quick.map((v) => `<button type="button" class="btn btn-lg" data-act="quick" data-v="${v}">${v === due ? 'Exact' : '₱' + v.toLocaleString()}</button>`).join('') : '';
      m.$('.pay-add').textContent = `Add ${label(method)}`;
      m.$('.pay-list').innerHTML = pays.length ? pays.map((p, i) => `<div class="pay-line">
          <span>${esc(label(p.method))}${p.reference ? ` <span class="muted small">#${esc(p.reference)}</span>` : ''}</span>
          <span class="row gap-sm"><b>${peso(p.amount)}</b><button type="button" class="icon-btn" data-act="removePay" data-i="${i}" aria-label="Remove">✕</button></span>
        </div>`).join('') : `<div class="muted small">Add one or more payments, or just press Complete to pay the full amount with ${esc(label(method))}.</div>`;
      const ready = pays.length ? paid >= total || entry() !== '' : true;
      const btn = m.$('.pay-complete');
      btn.disabled = !ready;
      btn.textContent = `Complete payment${change > 0 ? ' · Change ' + peso(change) : ''}`;
    }
    paint();
  }

  /** Success screen after payment. Returns {printed} so the caller can record the automatic print. */
  function paidDialog(t, autoPrint) {
    const job = { printed: false };
    const m = modal({
      title: 'Payment complete',
      cls: 'dlg-paid',
      body: `<div class="paid-box">
          <div class="paid-check">✓</div>
          <div class="muted">${esc(t.receipt_no)} · ${esc(orderTitle(t))} · Total ${peso(t.total)}</div>
          <div class="paid-change-label">CHANGE</div>
          <div class="paid-change">${peso(t.change_amount)}</div>
        </div>`,
      foot: `<button type="button" class="btn btn-lg" data-act="print">⎙ ${autoPrint ? 'Print again' : 'Print receipt'}</button><span class="grow"></span>
        <button type="button" class="btn btn-lg" data-act="board">Orders</button>
        <button type="submit" class="btn btn-primary btn-lg">New order</button>`,
      actions: {
        print: async () => { const ok = await Print.receipt(t, job.printed); job.printed = job.printed || ok; },
        board: () => m.close(),
      },
      onSubmit: () => { m.close(); newOrder('dine_in'); },
    });
    m.$('[type=submit]').focus(); // Enter = next customer
    return job;
  }

  // ------------------------------------------------------------------ receipts: search, view, reprint, void
  function receiptsDialog() {
    let selected = null;
    let timer = null;
    let rows = [];
    const m = modal({
      title: 'Receipts',
      cls: 'wide dlg-receipts',
      body: `<div class="rcpt-grid">
          <div class="rcpt-left">
            <input class="input" name="q" type="search" placeholder="Receipt / order no., customer, table (blank = current day)" autocomplete="off" enterkeyhint="search">
            <div class="rcpt-list"><div class="empty"><span class="spinner dark"></span></div></div>
          </div>
          <div class="rcpt-right"><div class="empty">Select a receipt</div></div>
        </div>`,
      actions: {
        pick: async (b) => {
          const t = await run(() => Api.show(Number(b.dataset.id)));
          if (t) { selected = t; paintList(); paintPreview(); }
        },
        reprint: async (b) => {
          const t = await run(() => Api.reprint(selected.id), b);
          if (t) Print.receipt(t, true);
        },
        voidReceipt: async (b) => {
          const r = await App.ask({
            title: `Void receipt ${selected.receipt_no}?`, danger: true, okText: 'Void receipt', input: 'Reason', required: true,
            message: 'Stock returns to inventory, the sale is reversed in the books and any cash refund comes out of the drawer.',
          });
          if (!r) return;
          const t = await withPin((pin) => Api.void(selected.id, r.value, pin), { ask: needPin('pos.void_receipt'), btn: b });
          if (t) { selected = t; App.toast('Receipt voided'); load(); paintPreview(); }
        },
      },
    });
    async function load() {
      try { rows = await Api.receipts(m.$('[name=q]').value.trim()); paintList(); } catch (e) { showError(e); }
    }
    function paintList() {
      m.$('.rcpt-list').innerHTML = rows.map((t) => `<div class="tline rcpt-row${selected && selected.id === t.id ? ' selected' : ''}" data-act="pick" data-id="${t.id}">
          <div><b>${esc(t.receipt_no || t.ticket_no)}</b> <span class="muted">${t.table_label ? '· Table ' + esc(t.table_label) : ''}</span>
            <div class="muted small">${esc(fmtTime(t.paid_at || t.created_at))} · ${esc(t.cashier || '')}${t.customer_name ? ' · ' + esc(t.customer_name) : ''}</div></div>
          <div class="right"><b>${peso(t.total)}</b><div><span class="badge ${t.status === 'paid' ? 'badge-green' : 'badge-red'}">${t.status === 'void' && !t.receipt_no ? 'cancelled' : esc(t.status)}</span></div></div>
        </div>`).join('') || '<div class="empty">No receipts.</div>';
    }
    function paintPreview() {
      const t = selected;
      m.$('.rcpt-right').innerHTML = `<div class="receipt-preview">${previewReceipt(t)}</div>
        <div class="row gap-sm mt">
          ${can('pos.reprint') && t.receipt_no ? '<button type="button" class="btn btn-lg grow" data-act="reprint">⎙ Reprint</button>' : ''}
          ${t.status === 'paid' ? '<button type="button" class="btn btn-danger btn-lg grow" data-act="voidReceipt">Void receipt</button>' : ''}
        </div>`;
      m.$('.rcpt-right').scrollIntoView({ block: 'nearest' });
    }
    m.$('[name=q]').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 300); });
    load();
  }

  // ------------------------------------------------------------------ drawer payout (petty cash from the cash drawer)
  function payoutDialog() {
    const accts = B.expense_accounts;
    const m = modal({
      title: 'Cash payout from the drawer',
      cls: 'wide dlg-payout',
      body: `<p class="muted mt-0">Small expenses paid with cash from this drawer (ice, LPG, fare…). They reduce the expected cash at end of day.</p>
        <div class="form-grid">
          <label class="field"><span class="field-label">Expense type *</span><select class="input" name="account_id"><option value="">Select…</option>
            ${accts.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select></label>
          <label class="field"><span class="field-label">Amount (₱) *</span>${numField('amount', '', 'money', 'data-act="amount" placeholder="Tap to enter"')}</label>
          <label class="field"><span class="field-label">Paid to</span><input class="input" name="payee" placeholder="e.g. Petron, palengke" autocomplete="off"></label>
          <label class="field"><span class="field-label">Description</span><input class="input" name="description" placeholder="e.g. LPG refill, ice, tricycle fare" autocomplete="off"></label>
          <label class="field"><span class="field-label">OR / receipt no.</span><input class="input" name="or_no" autocomplete="off"></label>
        </div>
        <div class="right mt"><button type="submit" class="btn btn-primary btn-lg">Record payout</button></div>
        <h3 class="mt-lg mb">Payouts today</h3>
        <div class="payout-list"><span class="spinner dark"></span></div>`,
      actions: {
        amount: async (b) => {
          const v = await askNumber({ title: 'Payout amount', prefix: '₱', okText: 'OK' });
          if (v !== null) b.value = money(v).replace(/,/g, '');
        },
        voidPayout: async (b) => {
          const r = await App.ask({ title: `Void payout ${b.dataset.no}?`, input: 'Reason', required: true, danger: true, okText: 'Void payout' });
          if (!r) return;
          if (await withPin((pin) => Api.voidPayout(Number(b.dataset.id), r.value, pin), { btn: b })) { App.toast('Payout voided'); load(); }
        },
      },
      onSubmit: async (btn) => {
        const v = (n) => m.$(`[name=${n}]`).value.trim();
        if (!v('account_id')) { App.toast('Select the expense type', 'error'); return; }
        if (!(Number(v('amount')) > 0)) { App.toast('Enter the amount', 'error'); return; }
        const data = { source: 'drawer', account_id: Number(v('account_id')), amount: Number(v('amount')), payee: v('payee'), description: v('description'), or_no: v('or_no') };
        if (await run(() => Api.addPayout(data), btn)) {
          App.toast(`Payout of ${peso(data.amount)} recorded`);
          ['amount', 'payee', 'description', 'or_no'].forEach((n) => { m.$(`[name=${n}]`).value = ''; });
          load();
        }
      },
    });
    async function load() {
      try {
        const list = await Api.payouts();
        const total = list.filter((p) => p.status === 'posted').reduce((s, p) => s + Number(p.amount), 0);
        m.$('.payout-list').innerHTML = list.length ? list.map((p) => `<div class="pick-row${p.status !== 'posted' ? ' muted-row' : ''}">
            <div><b>${esc(p.description || p.account_name)}</b> <span class="muted small">${esc(p.doc_no)} · ${esc(p.account_name || '')}${p.payee ? ' · ' + esc(p.payee) : ''} · ${esc(fmtTime(p.created_at))}</span></div>
            <div class="row gap-sm"><b>${peso(p.amount)}</b>
              ${p.status === 'posted' ? `<button type="button" class="btn" data-act="voidPayout" data-id="${p.id}" data-no="${esc(p.doc_no)}">Void</button>` : '<span class="badge badge-red">void</span>'}</div>
          </div>`).join('') + `<div class="row between mt bold"><span>Total</span><span>${peso(total)}</span></div>` : '<div class="muted small">None yet.</div>';
      } catch (e) { showError(e); }
    }
    load();
  }

  // ------------------------------------------------------------------ X-reading (mid-day)
  async function xReadDialog() {
    const r = await run(() => Api.xreading(), $('[data-act="xread"]'));
    if (!r) return;
    modal({
      title: 'X-Reading (current day so far)',
      cls: 'dlg-reading',
      body: `<div class="receipt-preview">${previewReading(r, false)}</div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Close</button><button type="button" class="btn btn-primary btn-lg" data-act="print">⎙ Print</button>',
      actions: { print: () => Print.reading(r, false) },
    });
  }

  // ------------------------------------------------------------------ cash counting (open day / end of day)
  /** Denomination rows (tap a row, type the count on the keypad; "Next" moves down). */
  function denomCounter() {
    return `<div class="cash-count">
        <div>
          <div class="denoms">${B.denominations.map((d) => `<div class="row denom">
            <span class="denom-label">${denomLabel(d)}</span><span class="muted">×</span>
            ${numField('d' + String(d).replace('.', '_'), '', 'int', `data-denom="${d}" placeholder="0"`)}
            <span class="denom-amt mono">0.00</span></div>`).join('')}</div>
          <div class="row between mt count-total"><span>Total counted</span><span class="count-sum">₱0.00</span></div>
        </div>
        ${keypad(INT_KEYS, 'count-keys', '<button type="button" class="btn btn-dark key-next" data-key="next">Next ↓</button>')}
      </div>`;
  }
  /** Read the denomination fields: {counts: {"1000": 2, ...}, total}. */
  function readCounts(root) {
    const counts = {};
    let total = 0;
    root.querySelectorAll('[data-denom]').forEach((el) => {
      const n = Number(el.value) || 0;
      const d = Number(el.dataset.denom);
      el.closest('.denom').querySelector('.denom-amt').textContent = money(d * n);
      if (n > 0) { counts[el.dataset.denom] = n; total += d * n; }
    });
    total = r2(total);
    const sum = root.querySelector('.count-sum');
    if (sum) sum.textContent = peso(total);
    return { counts, total };
  }

  function openDayDialog() {
    let mode = 'count';
    const m = modal({
      title: 'Open business day — beginning cash',
      cls: 'wide dlg-cash',
      body: `<label class="field"><span class="field-label">Business date</span><input class="input" type="date" name="business_date" value="${esc(B.today)}">
          <span class="field-hint">Sales after midnight still count to this date until the day is closed.</span></label>
        <div class="tabs mt"><button type="button" class="tab active" data-act="mode" data-mode="count">Count by denomination</button>
          <button type="button" class="tab" data-act="mode" data-mode="amount">Enter total</button></div>
        <div class="mode-count">${denomCounter()}</div>
        <div class="mode-amount hidden"><div class="field-label mb-sm">Beginning cash / change fund (₱)</div>
          <div class="cash-count"><div>${numField('opening_cash', '', 'money', 'placeholder="0.00"')}</div>${keypad(MONEY_KEYS, 'count-keys')}</div></div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-success btn-lg open-btn">Open day</button>',
      actions: {
        mode: (b) => {
          mode = b.dataset.mode;
          m.el.querySelectorAll('[data-act=mode]').forEach((x) => x.classList.toggle('active', x === b));
          m.$('.mode-count').classList.toggle('hidden', mode !== 'count');
          m.$('.mode-amount').classList.toggle('hidden', mode !== 'amount');
          pad.select(mode === 'count' ? m.$('[data-denom]') : m.$('[name=opening_cash]'));
          paint();
        },
      },
      onSubmit: async (btn) => {
        const c = readCounts(m.el);
        const data = { business_date: m.$('[name=business_date]').value, opening_cash: mode === 'count' ? c.total : r2(m.$('[name=opening_cash]').value),
          denominations: mode === 'count' ? c.counts : null };
        const s = await run(() => Api.openDay(data), btn);
        if (!s) return;
        m.close();
        S.session = s;
        App.toast('Business day opened');
        render();
      },
    });
    const paint = () => {
      const total = mode === 'count' ? readCounts(m.el).total : r2(m.$('[name=opening_cash]').value);
      m.$('.open-btn').textContent = `Open day with ${peso(total)}`;
    };
    const pad = numpad(m.el, paint);
    paint();
  }

  /** End of day: blind cash count -> Z-reading with expected / counted / short-over. */
  function endOfDayDialog() {
    const open = S.orders.length;
    const m = modal({
      title: 'End of day — cash count',
      cls: 'wide dlg-cash',
      body: `${open ? `<div class="alert alert-warn">There are still ${open} open order(s). Settle or cancel them before closing the day.</div>` : ''}
        <div class="alert alert-info small">Count all the cash in the drawer, including the beginning cash. The expected amount is shown only after you submit
          (blind count). Any shortage or overage is posted to the books automatically.</div>
        ${denomCounter()}
        <label class="field mt"><span class="field-label">Remarks</span><input class="input" name="notes" autocomplete="off"></label>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Cancel</button><button type="submit" class="btn btn-danger btn-lg">Close day</button>',
      onSubmit: async (btn) => {
        const c = readCounts(m.el);
        const ok = await App.ask({ title: 'Close the business day?', okText: 'Close day', danger: true,
          message: `Counted cash: ${peso(c.total)}. After closing, no more sales can be recorded for this day and the Z-reading is generated.` });
        if (!ok) return;
        const z = await run(() => Api.closeDay({ denominations: c.counts, notes: m.$('[name=notes]').value.trim() }), btn);
        if (!z) return;
        m.close();
        S.session = null;
        S.order = null;
        S.view = 'board';
        render();
        loadOrders();
        zResultDialog(z);
      },
    });
    numpad(m.el, () => readCounts(m.el));
  }

  function zResultDialog(z) {
    const v = z.cash.variance;
    modal({
      title: 'Z-Reading — day closed',
      cls: 'dlg-reading',
      body: `<div class="z-summary ${v === 0 ? 'ok' : v < 0 ? 'short' : 'over'}">
          <div><span>Expected</span><b>${peso(z.cash.expected)}</b></div>
          <div><span>Counted</span><b>${peso(z.cash.counted)}</b></div>
          <div><span>${v === 0 ? 'Balanced' : v < 0 ? 'SHORT' : 'OVER'}</span><b>${peso(Math.abs(v))}</b></div>
        </div>
        <div class="receipt-preview mt">${previewReading(z, true)}</div>`,
      foot: '<button type="button" class="btn btn-lg" data-x>Done</button><button type="button" class="btn btn-primary btn-lg" data-act="print">⎙ Print Z-Reading</button>',
      actions: { print: () => Print.reading(z, true) },
    });
  }

  // ------------------------------------------------------------------ printer: status, reconnect, quick settings
  function printerDialog() {
    const m = modal({
      title: 'Printer',
      cls: 'dlg-printer',
      body: '<div class="printer-body"></div>',
      foot: `<a class="btn btn-lg" href="${esc(B.links.printerSetup)}" target="_blank" rel="noopener">Open printer setup ↗</a><span class="grow"></span>
        <button type="button" class="btn btn-lg" data-x>Close</button>`,
      actions: {
        reconnect: (b) => run(() => Printer.reconnect(), b),
        connect: (b) => run(() => Printer.connect(), b),
        retry: (b) => run(() => Printer.retryQueue(), b),
        clear: () => Printer.clearQueue(),
        test: () => printSafe('Test print', (o) => Printer.testPrint({ business: B.business, ...o })),
        toggle: (b) => { Printer.saveConfig({ [b.dataset.key]: b.checked }); },
      },
    });
    function paint(s) {
      if (!m.el.isConnected) return;
      const c = Printer.config();
      const p = printerState(s);
      const linkable = c.method === 'bluetooth' || c.method === 'serial';
      m.$('.printer-body').innerHTML = `
        <div class="printer-status ${p.cls}"><b>${esc(p.long)}</b>
          <div class="small">${c.paper} mm paper${s.lastError && !s.connected ? ' · ' + esc(s.lastError) : ''}</div></div>
        ${s.queue ? `<div class="alert alert-warn mt">${plural(s.queue, 'print job')} waiting for the printer.
            <div class="row gap-sm mt"><button type="button" class="btn" data-act="retry">Print now</button><button type="button" class="btn" data-act="clear">Discard</button></div></div>` : ''}
        <div class="printer-actions mt">
          ${linkable ? `<button type="button" class="btn btn-primary btn-xl" data-act="reconnect" ${s.connected || s.connecting ? 'disabled' : ''}>↻ Reconnect</button>
            <button type="button" class="btn btn-xl" data-act="connect">＋ Connect new printer</button>` : ''}
          <button type="button" class="btn btn-xl" data-act="test">⎙ Test print</button>
        </div>
        <div class="col gap-sm mt">
          <label class="checkbox"><input type="checkbox" data-act="toggle" data-key="autoPrint" ${c.autoPrint ? 'checked' : ''}> Print the receipt automatically after payment</label>
          <label class="checkbox"><input type="checkbox" data-act="toggle" data-key="kitchenSlip" ${c.kitchenSlip ? 'checked' : ''}> Print a kitchen slip when sending orders</label>
        </div>`;
    }
    Printer.onStatus(paint);
  }

  // =====================================================================================================
  // 7. Printing hooks (window.Printer from printer.js)
  // =====================================================================================================
  const printCtx = (extra) => ({ business: B.business, tax: B.tax, methods: B.payment_methods, ...extra });
  const Print = {
    receipt: (t, reprint) => printSafe(t.status === 'open' ? 'Bill' : 'Receipt', (o) => Printer.printReceipt(t, printCtx({ reprint: !!reprint, ...o }))),
    kitchen: (t, lines) => printSafe('Kitchen slip', (o) => Printer.printKitchen(t, lines, printCtx(o))),
    orderSlip: (t) => printSafe('Order slip', (o) => Printer.printOrderSlip(t, printCtx(o))),
    reading: (r, isZ) => printSafe(isZ ? 'Z-reading' : 'X-reading', (o) => Printer.printReading(r, printCtx(o), isZ)),
  };

  /**
   * Run a print job. Resolves true when printed.
   * - Printer asleep / out of range: printer.js keeps the job (err.queued) and prints it on reconnect.
   * - Other failures: offer Retry / Print with browser.
   */
  async function printSafe(what, job) {
    try {
      await job({});
      return true;
    } catch (err) {
      if (err.queued) return queuedDialog(what, err, job);
      App.toast(`${what}: ${err.message}`, 'error');
      return printFailedDialog(what, err, job);
    }
  }

  function queuedDialog(what, err, job) {
    return new Promise((resolve) => {
      let ok = false;
      const m = modal({
        title: `${what} saved`,
        body: `<div class="alert alert-warn">The printer is not connected. The ${esc(what.toLowerCase())} is saved and will print by itself when the printer reconnects.</div>
          <p class="muted small">Check that the printer is switched on, charged and near this tablet.</p>`,
        foot: '<button type="button" class="btn btn-lg" data-act="browser">Print with browser instead</button><span class="grow"></span><button type="submit" class="btn btn-primary btn-lg">OK</button>',
        onSubmit: () => m.close(),
        onClose: () => resolve(ok),
        actions: {
          browser: async (b) => {
            Printer.dropJob(err.jobId);
            if (await run(async () => { await job({ method: 'browser' }); return true; }, b)) { ok = true; m.close(); }
          },
        },
      });
    });
  }

  function printFailedDialog(what, err, job) {
    return new Promise((resolve) => {
      let ok = false;
      const linkable = ['bluetooth', 'serial'].includes(Printer.config().method);
      const m = modal({
        title: `${what} not printed`,
        body: `<div class="alert alert-error">${esc(err.message)}</div><p class="muted">Check that the printer is on, has paper and is near this device.</p>`,
        foot: `<button type="button" class="btn btn-lg" data-x>Close</button><span class="grow"></span>
          <button type="button" class="btn btn-lg" data-act="browser">Print with browser</button>
          <button type="button" class="btn btn-primary btn-lg" data-act="retry">${linkable && !Printer.isConnected() ? 'Connect & retry' : 'Retry'}</button>`,
        onClose: () => resolve(ok),
        actions: {
          retry: async (b) => {
            // This click lets the browser show its printer picker when the printer was never chosen
            const r = await run(async () => {
              if (linkable && !Printer.isConnected()) { try { await Printer.reconnect(); } catch (e) { await Printer.connect(); } }
              await job({});
              return true;
            }, b);
            if (r) { ok = true; m.close(); }
          },
          browser: async (b) => {
            const r = await run(async () => { await job({ method: 'browser' }); return true; }, b);
            if (r) { ok = true; m.close(); }
          },
        },
      });
    });
  }

  /** Printer chip text / colour for the top bar, from Printer.status(). */
  function printerState(s = Printer.status()) {
    const names = { browser: 'Browser print', rawbt: 'RawBT app', bluetooth: 'Bluetooth', serial: 'USB / serial' };
    const name = names[s.method] || s.method;
    if (s.method === 'browser' || s.method === 'rawbt') return { cls: 'ok', text: name, long: `${name} — ready`, queue: 0 };
    if (s.connecting) return { cls: 'busy', text: 'Connecting…', long: `Connecting to ${s.deviceName || 'the printer'}…`, queue: s.queue };
    if (s.connected) return { cls: 'ok', text: 'Connected', long: `Connected: ${s.deviceName || name}`, queue: s.queue };
    return { cls: 'warn', text: 'Not connected', long: `${name} printer not connected${s.deviceName ? ' (' + s.deviceName + ')' : ''}`, queue: s.queue };
  }

  /** On-screen previews use the same layout as the printed slip (printer.js document model). */
  function previewOf(doc) {
    const paper = Number(Printer.config().paper) === 80 ? 80 : 58;
    return `<div class="paper-${paper}">${Printer._internals.html(doc, paper)}</div>`;
  }
  const previewReceipt = (t) => previewOf(Printer._internals.receiptDoc(t, printCtx({})));
  const previewReading = (r, isZ) => previewOf(Printer._internals.readingDoc(r, printCtx({}), isZ));

  // =====================================================================================================
  // 8. Screen & keyboard (Android tablets) + install as an app
  // =====================================================================================================
  /**
   * Keep everything above the on-screen keyboard. The viewport meta has interactive-widget=resizes-content
   * (the page shrinks when the keyboard opens); for older Chrome we also follow window.visualViewport and
   * expose its size as CSS variables: --app-h (visible height) and --app-top (offset), used by .pos and dialogs.
   * html.kb-open is set while the keyboard is up (the visible height dropped well below the full height).
   */
  function trackViewport() {
    const vv = window.visualViewport;
    const html = document.documentElement;
    let fullH = 0;
    let lastW = 0;
    const update = () => {
      const h = vv ? vv.height : window.innerHeight;
      const w = vv ? vv.width : window.innerWidth;
      if (w !== lastW) { fullH = 0; lastW = w; }            // rotated: measure the full height again
      fullH = Math.max(fullH, h);
      html.style.setProperty('--app-h', h + 'px');
      html.style.setProperty('--app-top', (vv ? vv.offsetTop : 0) + 'px');
      const open = h < fullH * 0.8;
      if (html.classList.contains('kb-open') && !open) keyboardClosed();
      html.classList.toggle('kb-open', open);
      if (open) keepFocusedVisible();
    };
    if (vv) { vv.addEventListener('resize', update); vv.addEventListener('scroll', update); }
    window.addEventListener('resize', update);
    update();
  }
  /** Inputs that open the phone keyboard (not our keypad number fields). */
  const isTyping = (el) => el.matches('input:not([readonly]):not([type=checkbox]):not([type=radio]):not([type=date]), textarea');
  function keepFocusedVisible() {
    const el = document.activeElement;
    if (el && isTyping(el) && el.id !== 'pos-search') el.scrollIntoView({ block: 'nearest' });
  }
  /** The keyboard was closed with the Android back button: leave search mode too. */
  function keyboardClosed() {
    if (document.activeElement === search) search.blur();
  }

  /** Register the (pass-through) service worker so Chrome offers "Install app". Needs https or localhost. */
  function setupInstall() {
    if ('serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register(App.url('/sw.js')).catch(() => {});
    }
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      S.installPrompt = e;
      renderTop();
    });
    window.addEventListener('appinstalled', () => { S.installPrompt = null; renderTop(); App.toast('Installed — open “Mark5 POS” from the home screen'); });
  }
  async function installApp() {
    const p = S.installPrompt;
    if (!p) return;
    p.prompt();
    await p.userChoice;
    S.installPrompt = null;
    renderTop();
  }

  // =====================================================================================================
  // 9. Events & start-up
  // =====================================================================================================
  /** Top bar, board, order panel and phone bar buttons (data-act). */
  const ACTIONS = {
    board: () => { if (S.view === 'order') S.queue.then(closeOrder); else loadOrders(); },
    receipts: receiptsDialog,
    payout: payoutDialog,
    xread: xReadDialog,
    eod: async () => { await loadOrders(); endOfDayDialog(); },
    openday: openDayDialog,
    printer: printerDialog,
    install: installApp,
    logout,
    new: (b) => newOrder(b.dataset.type),
    refresh: () => { loadOrders(); loadMenu(); },
    open: (b) => openOrderById(Number(b.dataset.id)),
    cardMenu: (b) => cardMenu(Number(b.dataset.id)),
    details: detailsDialog,
    table: () => assignTable(false),
    line: (b) => S.queue.then(() => { const l = S.order && S.order.items.find((i) => i.id === Number(b.dataset.id)); if (l) lineDialog(l); }),
    send: sendToKitchen,
    discount: () => S.queue.then(discountDialog),
    split: splitDialog,
    merge: mergeDialog,
    bill: () => S.queue.then(() => { if (activeLines(S.order).length) Print.receipt(S.order, false); }),
    reprintOrder: (b) => S.queue.then(() => reprintOrder(S.order.id, b)),
    cancel: cancelOrder,
    done: () => S.queue.then(closeOrder),
    pay: startPay,
    pane: (b) => { S.pane = b.dataset.pane; render(); },
  };

  const pos = document.getElementById('pos');
  pos.addEventListener('click', (e) => {
    const tile = e.target.closest('[data-item]');
    if (tile) { const item = S.menu.items.find((i) => i.id === Number(tile.dataset.item)); if (item) addItem(item); return; }
    const cat = e.target.closest('[data-cat]');
    if (cat) { S.cat = cat.dataset.cat === 'all' ? 'all' : Number(cat.dataset.cat); search.value = ''; renderMenu(); return; }
    const b = e.target.closest('[data-act]');
    if (b && !b.disabled && ACTIONS[b.dataset.act]) ACTIONS[b.dataset.act](b, e);
  });
  // Order cards are not <button>s (they contain the ⋯ button): open them with Enter / Space too
  pos.addEventListener('keydown', (e) => {
    const card = e.target.closest('.order-card');
    if (card && e.target === card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openOrderById(Number(card.dataset.id)); }
  });

  // ---- Menu search / barcode.
  // While the search box has focus (keyboard up) the top bar and categories fold away so the results get the room.
  // Tapping a tile adds it and keeps the results and the keyboard; Enter adds the first match (barcode scanners).
  const search = $('#pos-search');
  search.addEventListener('focus', () => pos.classList.add('searching'));
  search.addEventListener('blur', () => pos.classList.remove('searching'));
  search.addEventListener('input', renderMenu);
  search.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const first = visibleItems()[0];
    if (first) { addItem(first); search.value = ''; renderMenu(); } else if (search.value.trim()) App.toast('No item matches ' + search.value.trim(), 'error');
  });
  $('#pos-search-clear').addEventListener('mousedown', (e) => e.preventDefault());   // keep the keyboard open
  $('#pos-search-clear').addEventListener('click', () => { search.value = ''; renderMenu(); });
  $('#pos-search-done').addEventListener('click', () => search.blur());
  // Tiles must not take the focus away from the search box (that would close the keyboard)
  $('#tiles').addEventListener('mousedown', (e) => { if (document.activeElement === search) e.preventDefault(); });

  // Typing on a physical keyboard anywhere on the order screen goes to the search box (scanner without tapping first)
  document.addEventListener('keydown', (e) => {
    if (S.view !== 'order' || document.querySelector('dialog[open]') || e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
    if (e.target.closest('input, textarea, select')) return;
    search.focus();
  });
  // A text field in a dialog that gets the keyboard: scroll it into view once the keyboard is up
  document.addEventListener('focusin', (e) => {
    if (isTyping(e.target) && e.target.closest('dialog')) setTimeout(() => e.target.scrollIntoView({ block: 'nearest' }), 350);
  });

  // Printer status in the top bar; keep the tablet awake while the POS is open (if chosen in Printer setup)
  Printer.onStatus(() => renderTop());
  if (Printer.config().keepAwake) Printer.keepAwake(true);

  // Multiple terminals: refresh the board every 15 s (and when the tab comes back into view)
  setInterval(() => { if (!document.hidden) loadOrders(); }, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadOrders(); });

  trackViewport();
  setupInstall();
  render();
  loadMenu();
  loadOrders();
})();
