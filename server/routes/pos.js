'use strict';
const express = require('express');
const db = require('../db');
const { h, bad, notFound, now, today, r2, num, audit, nextNo, getSetting, isDate, addDays } = require('../util');
const { requirePerm, authorize, assertPerm, can } = require('../auth');
const inv = require('../inventory');
const gl = require('../gl');

const router = express.Router();
const POS = requirePerm('pos.access');

const PAYMENT_METHODS = {
  cash: { label: 'Cash', account: 'cash_on_hand' },
  card: { label: 'Credit/Debit Card', account: 'card_clearing' },
  gcash: { label: 'GCash', account: 'ewallet_clearing' },
  maya: { label: 'Maya', account: 'ewallet_clearing' },
  bank_transfer: { label: 'Bank Transfer / InstaPay', account: 'ewallet_clearing' },
  grabfood: { label: 'GrabFood', account: 'ewallet_clearing' },
  foodpanda: { label: 'foodpanda', account: 'ewallet_clearing' },
  charge: { label: 'Charge to Account', account: 'ar' },
};
const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1, 0.25, 0.1, 0.05];

function taxConfig() {
  const vatRegistered = getSetting('vat_registered', '1') === '1';
  return {
    vatRegistered,
    vatRate: vatRegistered ? num(getSetting('vat_rate', '12')) / 100 : 0,
    scRate: num(getSetting('sc_discount_rate', '20')) / 100,
    svcRate: num(getSetting('service_charge_rate', '0')) / 100,
    svcDineInOnly: getSetting('service_charge_dine_in_only', '1') === '1',
  };
}

/**
 * Philippine POS computation (prices are VAT-inclusive):
 *  - SC/PWD: the qualified share (sc_count / pax) is VAT-exempt and gets a 20% discount on the net-of-VAT amount.
 *  - Regular % / fixed discounts reduce the VATable amount.
 *  - Service charge is computed on net sales (excluding VAT).
 */
function computeTotals(t, lines) {
  const cfg = taxConfig();
  const div = 1 + cfg.vatRate;
  const gross = r2(lines.filter((l) => l.status === 'active').reduce((s, l) => s + l.line_total, 0));
  let eligible = 0; let eligibleNet = 0; let scDisc = 0; let regDisc = 0;
  if (t.discount_type === 'sc' || t.discount_type === 'pwd') {
    const pax = Math.max(Number(t.pax) || 1, 1);
    const cnt = Math.min(Math.max(Number(t.sc_count) || 1, 1), pax);
    eligible = r2((gross * cnt) / pax);
    eligibleNet = r2(eligible / div);
    scDisc = r2(eligibleNet * cfg.scRate);
  } else if (t.discount_type === 'percent') {
    regDisc = r2((gross * Math.min(num(t.discount_rate), 100)) / 100);
  } else if (t.discount_type === 'amount') {
    regDisc = r2(Math.min(num(t.discount_rate), gross));
  }
  const vatableIncl = r2(gross - eligible - regDisc);
  const vatableSales = r2(vatableIncl / div);
  const vat = r2(vatableIncl - vatableSales);
  const svcApplies = cfg.svcRate > 0 && (!cfg.svcDineInOnly || t.order_type === 'dine_in');
  const svc = svcApplies ? r2(cfg.svcRate * (vatableSales + eligibleNet - scDisc)) : 0;
  const total = r2(vatableIncl + eligibleNet - scDisc + svc);
  return {
    subtotal: gross, discount_amount: r2(scDisc + regDisc), vatable_sales: vatableSales, vat_amount: vat,
    vat_exempt_sales: eligibleNet, service_charge: svc, total,
    _regDisc: regDisc, _scDisc: scDisc,
  };
}

function recalc(ticketId) {
  const t = db.get('SELECT * FROM tickets WHERE id = ?', ticketId);
  const lines = db.all('SELECT * FROM ticket_items WHERE ticket_id = ?', ticketId);
  const c = computeTotals(t, lines);
  db.update('tickets', ticketId, {
    subtotal: c.subtotal, discount_amount: c.discount_amount, vatable_sales: c.vatable_sales, vat_amount: c.vat_amount,
    vat_exempt_sales: c.vat_exempt_sales, service_charge: c.service_charge, total: c.total,
  });
  return c;
}

function loadTicket(id) {
  const t = db.get(
    `SELECT t.*, dt.name table_name, dt.area table_area, u.full_name created_by_name, pu.full_name paid_by_name,
            vu.full_name voided_by_name, c.name customer_account_name
     FROM tickets t LEFT JOIN dining_tables dt ON dt.id = t.table_id LEFT JOIN users u ON u.id = t.created_by
     LEFT JOIN users pu ON pu.id = t.paid_by LEFT JOIN users vu ON vu.id = t.voided_by LEFT JOIN customers c ON c.id = t.customer_id
     WHERE t.id = ?`, id
  );
  if (!t) throw notFound('Ticket');
  t.items = db.all('SELECT * FROM ticket_items WHERE ticket_id = ? ORDER BY id', id);
  t.payments = db.all('SELECT * FROM payments WHERE ticket_id = ? ORDER BY id', id);
  t.sc_details = t.sc_details ? JSON.parse(t.sc_details) : [];
  return t;
}

function openTicket(id) {
  const t = loadTicket(id);
  if (t.status !== 'open') throw bad(`Ticket ${t.ticket_no} is already ${t.status}`);
  return t;
}

function currentSession() {
  return db.get("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
}
function requireSession() {
  const s = currentSession();
  if (!s) throw bad('The business day is not open yet. Open the day with the beginning cash first.');
  return s;
}

// ------------------------------------------------------------------ bootstrap data
router.get('/state', POS, h(() => {
  const session = currentSession();
  const cfg = taxConfig();
  return {
    session,
    tax: cfg,
    payment_methods: Object.entries(PAYMENT_METHODS).map(([k, v]) => ({ key: k, label: v.label })),
    denominations: DENOMINATIONS,
    business: {
      name: getSetting('business_name'), address: getSetting('business_address'), tin: getSetting('business_tin'),
      phone: getSetting('business_phone'), receipt_title: getSetting('receipt_title'), receipt_footer: getSetting('receipt_footer'),
    },
  };
}));

router.get('/menu', POS, h(() => {
  const items = db.all(
    `SELECT i.id, i.name, i.price, i.category_id, i.color, i.item_type, i.stock_qty, i.sku, i.barcode FROM items i
     WHERE i.active = 1 AND i.sellable = 1 ORDER BY i.sort_order, i.name`
  );
  const catIds = new Set(items.map((i) => i.category_id));
  const categories = db.all('SELECT id, name, color FROM categories WHERE active = 1 ORDER BY sort_order, name').filter((c) => catIds.has(c.id));
  return { categories, items };
}));

router.get('/tables', POS, h(() => {
  const tables = db.all('SELECT * FROM dining_tables WHERE active = 1 ORDER BY area, sort_order, name');
  const open = db.all(
    `SELECT t.id, t.ticket_no, t.table_id, t.total, t.pax, t.created_at, t.customer_name, t.order_type,
            (SELECT COUNT(*) FROM ticket_items i WHERE i.ticket_id = t.id AND i.status = 'active') item_count
     FROM tickets t WHERE t.status = 'open' ORDER BY t.id`
  );
  return {
    tables: tables.map((tb) => ({ ...tb, tickets: open.filter((o) => o.table_id === tb.id) })),
    others: open.filter((o) => !o.table_id),
  };
}));

// ------------------------------------------------------------------ business day (cash session)
router.post('/sessions/open', requirePerm('pos.open_day'), h((req) => {
  if (currentSession()) throw bad('A business day is already open');
  const bdate = isDate(req.body.business_date) ? req.body.business_date : today();
  const opening = r2(req.body.opening_cash);
  if (opening < 0) throw bad('Beginning cash cannot be negative');
  const id = db.insert('cash_sessions', {
    business_date: bdate, status: 'open', opened_by: req.user.id, opened_at: now(), opening_cash: opening,
    denominations: req.body.denominations ? JSON.stringify({ opening: req.body.denominations }) : null, notes: req.body.notes || null,
  });
  audit(req.user.id, 'open_day', 'cash_session', id, { opening, bdate });
  return db.get('SELECT * FROM cash_sessions WHERE id = ?', id);
}));

function sessionReport(sessionId) {
  const s = db.get(
    `SELECT s.*, ou.full_name opened_by_name, cu.full_name closed_by_name FROM cash_sessions s
     LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by WHERE s.id = ?`, sessionId
  );
  if (!s) throw notFound('Business day');
  const paid = db.get(
    `SELECT COUNT(*) cnt, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_amount),0) discounts, COALESCE(SUM(service_charge),0) svc,
            COALESCE(SUM(vatable_sales),0) vatable, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(vat_exempt_sales),0) exempt,
            COALESCE(SUM(total),0) net, COALESCE(SUM(pax),0) pax, MIN(receipt_no) first_or, MAX(receipt_no) last_or
     FROM tickets WHERE cash_session_id = ? AND status = 'paid'`, sessionId
  );
  const voided = db.get(
    "SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amount FROM tickets WHERE cash_session_id = ? AND status = 'void' AND receipt_no IS NOT NULL", sessionId
  );
  const cancelled = db.get("SELECT COUNT(*) cnt FROM tickets WHERE cash_session_id = ? AND status = 'void' AND receipt_no IS NULL", sessionId);
  const itemVoids = db.get(
    `SELECT COUNT(*) cnt, COALESCE(SUM(i.line_total),0) amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
     WHERE t.cash_session_id = ? AND i.status = 'void'`, sessionId
  );
  const discounts = db.all(
    `SELECT discount_type, COUNT(*) cnt, COALESCE(SUM(discount_amount),0) amount FROM tickets
     WHERE cash_session_id = ? AND status = 'paid' AND discount_type <> 'none' GROUP BY discount_type`, sessionId
  );
  const payments = db.all(
    `SELECT p.method, COUNT(*) cnt, COALESCE(SUM(p.amount),0) amount FROM payments p JOIN tickets t ON t.id = p.ticket_id
     WHERE t.cash_session_id = ? AND t.status = 'paid' GROUP BY p.method ORDER BY amount DESC`, sessionId
  ).map((p) => ({ ...p, label: (PAYMENT_METHODS[p.method] || {}).label || p.method }));
  const cashSales = r2((payments.find((p) => p.method === 'cash') || { amount: 0 }).amount);
  const payouts = db.all(
    `SELECT p.*, a.name account_name FROM petty_cash_txns p LEFT JOIN accounts a ON a.id = p.account_id
     WHERE p.cash_session_id = ? AND p.source = 'drawer' AND p.status = 'posted' ORDER BY p.id`, sessionId
  );
  const payoutTotal = r2(payouts.reduce((a, p) => a + (p.txn_type === 'expense' ? p.amount : -p.amount), 0));
  const refunds = db.get(
    `SELECT COALESCE(SUM(p.amount),0) amt FROM payments p JOIN tickets t ON t.id = p.ticket_id
     WHERE t.void_session_id = ? AND t.cash_session_id <> ? AND p.method = 'cash'`, sessionId, sessionId
  ).amt;
  const categories = db.all(
    `SELECT COALESCE(c.name,'Uncategorized') category, SUM(i.qty) qty, SUM(i.line_total) amount FROM ticket_items i
     JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
     WHERE t.cash_session_id = ? AND t.status = 'paid' AND i.status = 'active' GROUP BY c.name ORDER BY amount DESC`, sessionId
  );
  const grandBefore = db.value(
    "SELECT COALESCE(SUM(total),0) FROM tickets WHERE status = 'paid' AND cash_session_id < ?", sessionId
  );
  const expected = r2(s.opening_cash + cashSales - payoutTotal - refunds);
  return {
    session: s,
    sales: Object.fromEntries(Object.entries(paid).map(([k, v]) => [k, typeof v === 'number' ? r2(v) : v])),
    voided, cancelled: cancelled.cnt, item_voids: itemVoids, discounts, payments, categories, payouts,
    cash: {
      opening: r2(s.opening_cash), cash_sales: cashSales, payouts: payoutTotal, refunds: r2(refunds),
      expected: s.status === 'closed' ? s.expected_cash : expected,
      counted: s.counted_cash, variance: s.variance,
    },
    grand_total: { beginning: r2(grandBefore), ending: r2(grandBefore + paid.net) },
  };
}

router.get('/sessions/current/xreading', requirePerm('pos.xreading', 'pos.close_day'), h(() => sessionReport(requireSession().id)));

router.post('/sessions/current/close', requirePerm('pos.close_day'), h((req) => {
  const s = requireSession();
  const openCount = db.value("SELECT COUNT(*) FROM tickets WHERE cash_session_id = ? AND status = 'open'", s.id);
  if (openCount > 0) throw bad(`There are still ${openCount} open ticket(s). Settle or void them before closing the day.`);
  const rep = sessionReport(s.id);
  const denoms = req.body.denominations || {};
  let counted = 0;
  for (const [d, q] of Object.entries(denoms)) counted += num(d) * num(q);
  counted = r2(req.body.counted_cash !== undefined && req.body.counted_cash !== '' && !Object.keys(denoms).length ? req.body.counted_cash : counted);
  const expected = rep.cash.expected;
  const variance = r2(counted - expected);
  db.tx(() => {
    let jeId = null;
    if (variance !== 0) {
      jeId = gl.postJE({
        date: s.business_date, memo: `Cash ${variance < 0 ? 'short' : 'over'} - EOD ${s.business_date}`,
        source_type: 'pos_eod', source_id: s.id, user_id: req.user.id,
        lines: [
          { key: 'cash_on_hand', debit: variance > 0 ? variance : 0, credit: variance < 0 ? -variance : 0 },
          { key: 'cash_short_over', debit: variance < 0 ? -variance : 0, credit: variance > 0 ? variance : 0 },
        ],
      });
    }
    let den = {};
    try { den = s.denominations ? JSON.parse(s.denominations) : {}; } catch { den = {}; }
    den.closing = denoms;
    db.update('cash_sessions', s.id, {
      status: 'closed', closed_by: req.user.id, closed_at: now(), expected_cash: expected, counted_cash: counted, variance,
      denominations: JSON.stringify(den), notes: [s.notes, req.body.notes].filter(Boolean).join(' | ') || null, journal_entry_id: jeId,
    });
    const final = sessionReport(s.id);
    db.update('cash_sessions', s.id, { z_data: JSON.stringify(final) });
  });
  audit(req.user.id, 'close_day', 'cash_session', s.id, { expected, counted, variance });
  return sessionReport(s.id);
}));

router.get('/sessions', requirePerm('pos.close_day', 'reports.sales'), h((req) => {
  const from = req.query.from || '0000-00-00'; const to = req.query.to || '9999-99-99';
  return db.all(
    `SELECT s.*, ou.full_name opened_by_name, cu.full_name closed_by_name,
            (SELECT COUNT(*) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') receipts,
            (SELECT COALESCE(SUM(total),0) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') net_sales
     FROM cash_sessions s LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by
     WHERE s.business_date BETWEEN ? AND ? ORDER BY s.id DESC`, from, to
  ).map(({ z_data, ...rest }) => rest);
}));
router.get('/sessions/:id', requirePerm('pos.close_day', 'reports.sales'), h((req) => sessionReport(req.params.id)));

// ------------------------------------------------------------------ tickets
router.get('/tickets', POS, h((req) => {
  const { status, date, session_id, q } = req.query;
  const where = []; const p = [];
  if (status) { where.push('t.status = ?'); p.push(status); }
  if (date) { where.push('t.business_date = ?'); p.push(date); }
  if (session_id) { where.push('t.cash_session_id = ?'); p.push(session_id); }
  if (q) { where.push('(t.receipt_no LIKE ? OR t.ticket_no LIKE ? OR t.customer_name LIKE ?)'); p.push(`%${q}%`, `%${q}%`, `%${q}%`); }
  if (!where.length) { const s = currentSession(); if (s) { where.push('t.cash_session_id = ?'); p.push(s.id); } }
  return db.all(
    `SELECT t.id, t.ticket_no, t.receipt_no, t.status, t.total, t.paid_at, t.created_at, t.order_type, t.customer_name, t.business_date,
            dt.name table_name, u.full_name cashier
     FROM tickets t LEFT JOIN dining_tables dt ON dt.id = t.table_id LEFT JOIN users u ON u.id = COALESCE(t.paid_by, t.created_by)
     ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY t.id DESC LIMIT 500`, ...p
  );
}));

router.get('/tickets/:id', POS, h((req) => loadTicket(req.params.id)));

router.post('/tickets', POS, h((req) => {
  const s = requireSession();
  const { table_id, order_type, customer_name, pax, notes } = req.body;
  const id = db.insert('tickets', {
    ticket_no: nextNo('TKT', 'T', 6), cash_session_id: s.id, business_date: s.business_date, table_id: table_id || null,
    order_type: order_type || (table_id ? 'dine_in' : 'takeout'), customer_name: customer_name || null, pax: Math.max(num(pax, 1), 1),
    status: 'open', notes: notes || null, created_by: req.user.id, created_at: now(),
  });
  return loadTicket(id);
}));

router.put('/tickets/:id', POS, h((req) => {
  const t = openTicket(req.params.id);
  const { order_type, customer_name, pax, notes } = req.body;
  db.update('tickets', t.id, { order_type, customer_name, pax: pax === undefined ? undefined : Math.max(num(pax, 1), 1), notes });
  recalc(t.id);
  return loadTicket(t.id);
}));

router.post('/tickets/:id/items', POS, h((req) => {
  const t = openTicket(req.params.id);
  const item = inv.getItem(req.body.item_id);
  if (!item.active || !item.sellable) throw bad(`${item.name} is not available for sale`);
  const qty = num(req.body.qty, 1);
  if (!(qty > 0)) throw bad('Quantity must be greater than zero');
  const notes = req.body.notes || null;
  let price = item.price;
  if (req.body.price !== undefined && Number(req.body.price) !== item.price) {
    authorize(req, 'pos.discount', req.body.pin); // open price / price override
    price = r2(req.body.price);
  }
  const existing = db.get(
    "SELECT * FROM ticket_items WHERE ticket_id = ? AND item_id = ? AND status = 'active' AND kitchen_sent = 0 AND price = ? AND COALESCE(notes,'') = COALESCE(?, '')",
    t.id, item.id, price, notes
  );
  if (existing) {
    const q = existing.qty + qty;
    db.update('ticket_items', existing.id, { qty: q, line_total: r2(q * price) });
  } else {
    db.insert('ticket_items', {
      ticket_id: t.id, item_id: item.id, name: item.name, qty, price, line_total: r2(qty * price), notes,
      status: 'active', kitchen_sent: 0, created_by: req.user.id, created_at: now(),
    });
  }
  recalc(t.id);
  return loadTicket(t.id);
}));

router.put('/tickets/:id/items/:lineId', POS, h((req) => {
  const t = openTicket(req.params.id);
  const line = db.get('SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ?', req.params.lineId, t.id);
  if (!line || line.status !== 'active') throw notFound('Ticket line');
  const qty = req.body.qty !== undefined ? num(req.body.qty) : line.qty;
  if (!(qty > 0)) throw bad('Quantity must be greater than zero');
  if (line.kitchen_sent && qty < line.qty) {
    // Reducing a line already sent to the kitchen is a void of the difference.
    const by = authorize(req, 'pos.void_item', req.body.pin);
    db.insert('ticket_items', {
      ticket_id: t.id, item_id: line.item_id, name: line.name, qty: r2(line.qty - qty), price: line.price,
      line_total: r2((line.qty - qty) * line.price), notes: line.notes, status: 'void', void_reason: req.body.reason || 'Reduced quantity',
      voided_by: by, voided_at: now(), kitchen_sent: 1, created_by: line.created_by, created_at: line.created_at,
    });
  }
  db.update('ticket_items', line.id, { qty, line_total: r2(qty * line.price), notes: req.body.notes !== undefined ? req.body.notes || null : undefined });
  recalc(t.id);
  return loadTicket(t.id);
}));

router.post('/tickets/:id/items/:lineId/void', POS, h((req) => {
  const t = openTicket(req.params.id);
  const line = db.get('SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ?', req.params.lineId, t.id);
  if (!line || line.status !== 'active') throw notFound('Ticket line');
  if (!line.kitchen_sent) {
    db.run('DELETE FROM ticket_items WHERE id = ?', line.id);
  } else {
    const by = authorize(req, 'pos.void_item', req.body.pin);
    if (!req.body.reason) throw bad('Void reason is required');
    db.update('ticket_items', line.id, { status: 'void', void_reason: req.body.reason, voided_by: by, voided_at: now() });
    audit(req.user.id, 'void_item', 'ticket', t.id, { line: line.name, qty: line.qty, reason: req.body.reason, authorized_by: by });
  }
  recalc(t.id);
  return loadTicket(t.id);
}));

// Send to kitchen: marks lines as ordered (after this, removing them is a void).
router.post('/tickets/:id/send', POS, h((req) => {
  const t = openTicket(req.params.id);
  const pending = t.items.filter((i) => i.status === 'active' && !i.kitchen_sent);
  db.run("UPDATE ticket_items SET kitchen_sent = 1 WHERE ticket_id = ? AND status = 'active' AND kitchen_sent = 0", t.id);
  return { ticket: loadTicket(t.id), sent: pending };
}));

router.post('/tickets/:id/discount', POS, h((req) => {
  const t = openTicket(req.params.id);
  const type = req.body.discount_type || 'none';
  if (!['none', 'sc', 'pwd', 'percent', 'amount'].includes(type)) throw bad('Invalid discount type');
  if (type !== 'none') authorize(req, 'pos.discount', req.body.pin);
  const upd = { discount_type: type, discount_rate: 0, sc_count: 0, sc_details: null };
  if (type === 'sc' || type === 'pwd') {
    const details = (req.body.sc_details || []).filter((d) => d && (d.name || d.id_no));
    const cnt = Math.max(num(req.body.sc_count, details.length || 1), 1);
    if (details.length < cnt) throw bad('Enter the name and ID number of each Senior Citizen / PWD');
    for (const d of details) if (!d.name || !d.id_no) throw bad('Name and ID number are required for each Senior Citizen / PWD');
    upd.sc_count = cnt;
    upd.sc_details = JSON.stringify(details.slice(0, cnt));
    if (num(req.body.pax) > 0) upd.pax = num(req.body.pax);
    if ((upd.pax || t.pax) < cnt) upd.pax = cnt;
  } else if (type === 'percent' || type === 'amount') {
    upd.discount_rate = num(req.body.discount_rate);
    if (!(upd.discount_rate > 0)) throw bad('Enter the discount value');
    if (type === 'percent' && upd.discount_rate > 100) throw bad('Discount cannot exceed 100%');
  }
  db.update('tickets', t.id, upd);
  recalc(t.id);
  audit(req.user.id, 'discount', 'ticket', t.id, { type, rate: upd.discount_rate, sc_count: upd.sc_count });
  return loadTicket(t.id);
}));

router.post('/tickets/:id/move', requirePerm('pos.split_move'), h((req) => {
  const t = openTicket(req.params.id);
  const tableId = req.body.table_id || null;
  if (tableId && !db.get('SELECT id FROM dining_tables WHERE id = ? AND active = 1', tableId)) throw notFound('Table');
  db.update('tickets', t.id, { table_id: tableId, order_type: tableId ? 'dine_in' : t.order_type === 'dine_in' ? 'takeout' : t.order_type });
  recalc(t.id);
  audit(req.user.id, 'move_table', 'ticket', t.id, { from: t.table_id, to: tableId });
  return loadTicket(t.id);
}));

// Split: move selected lines (optionally partial quantities) to a new or existing ticket.
router.post('/tickets/:id/split', requirePerm('pos.split_move'), h((req) => {
  const t = openTicket(req.params.id);
  const sel = (req.body.lines || []).filter((l) => num(l.qty) > 0);
  if (!sel.length) throw bad('Select the items to split');
  const result = db.tx(() => {
    let targetId = req.body.target_ticket_id;
    if (targetId) {
      openTicket(targetId);
    } else {
      targetId = db.insert('tickets', {
        ticket_no: nextNo('TKT', 'T', 6), cash_session_id: t.cash_session_id, business_date: t.business_date,
        table_id: req.body.table_id !== undefined ? req.body.table_id || null : t.table_id, order_type: t.order_type,
        customer_name: req.body.customer_name || t.customer_name, pax: 1, status: 'open', split_from_id: t.id,
        created_by: req.user.id, created_at: now(),
      });
    }
    for (const s of sel) {
      const line = db.get("SELECT * FROM ticket_items WHERE id = ? AND ticket_id = ? AND status = 'active'", s.id, t.id);
      if (!line) throw bad('Selected item is no longer on the ticket');
      const qty = Math.min(num(s.qty), line.qty);
      if (qty >= line.qty) {
        db.run('UPDATE ticket_items SET ticket_id = ? WHERE id = ?', targetId, line.id);
      } else {
        db.update('ticket_items', line.id, { qty: line.qty - qty, line_total: r2((line.qty - qty) * line.price) });
        db.insert('ticket_items', { ...line, id: undefined, ticket_id: targetId, qty, line_total: r2(qty * line.price) });
      }
    }
    if (t.pax > 1 && !req.body.target_ticket_id) {
      const movePax = Math.max(Math.min(num(req.body.pax, 1), t.pax - 1), 1);
      db.update('tickets', t.id, { pax: t.pax - movePax });
      db.update('tickets', targetId, { pax: movePax });
    }
    recalc(t.id); recalc(targetId);
    return targetId;
  });
  audit(req.user.id, 'split', 'ticket', t.id, { to: result });
  return { source: loadTicket(t.id), target: loadTicket(result) };
}));

router.post('/tickets/:id/merge', requirePerm('pos.split_move'), h((req) => {
  const t = openTicket(req.params.id);
  const src = openTicket(req.body.source_ticket_id);
  if (src.id === t.id) throw bad('Choose a different ticket to merge');
  db.tx(() => {
    db.run('UPDATE ticket_items SET ticket_id = ? WHERE ticket_id = ?', t.id, src.id);
    db.update('tickets', t.id, { pax: t.pax + src.pax });
    db.update('tickets', src.id, { status: 'void', void_reason: `Merged into ${t.ticket_no}`, voided_by: req.user.id, voided_at: now(), void_session_id: src.cash_session_id });
    recalc(t.id);
  });
  audit(req.user.id, 'merge', 'ticket', t.id, { from: src.id });
  return loadTicket(t.id);
}));

// ------------------------------------------------------------------ settle / payment
router.post('/tickets/:id/pay', requirePerm('pos.settle'), h((req) => {
  const t = openTicket(req.params.id);
  const session = requireSession();
  const active = t.items.filter((i) => i.status === 'active');
  if (!active.length) throw bad('Ticket has no items');
  const totals = recalc(t.id);
  const total = totals.total;
  const pays = (req.body.payments || []).map((p) => ({ ...p, method: p.method, amount: r2(p.amount) })).filter((p) => p.amount > 0);
  if (!pays.length) throw bad('Enter the payment');
  for (const p of pays) if (!PAYMENT_METHODS[p.method]) throw bad(`Unknown payment method ${p.method}`);
  const nonCash = r2(pays.filter((p) => p.method !== 'cash').reduce((s, p) => s + p.amount, 0));
  const cashTendered = r2(pays.filter((p) => p.method === 'cash').reduce((s, p) => s + p.amount, 0));
  if (nonCash > total + 0.001) throw bad('Non-cash payments cannot exceed the amount due');
  if (r2(nonCash + cashTendered) < total) throw bad(`Payment is short by ₱${r2(total - nonCash - cashTendered).toFixed(2)}`);
  const change = r2(nonCash + cashTendered - total);
  const charge = pays.find((p) => p.method === 'charge');
  if (charge && !charge.customer_id) throw bad('Select the customer account to charge');
  for (const p of pays) if (['card', 'gcash', 'maya', 'bank_transfer'].includes(p.method) && getSetting('require_payment_ref', '0') === '1' && !p.reference) {
    throw bad(`Reference / approval number is required for ${PAYMENT_METHODS[p.method].label}`);
  }

  db.tx(() => {
    const receiptNo = nextNo('OR', getSetting('receipt_prefix', 'OR') || 'OR', 8);
    let cashApplied = cashTendered - change;
    const applied = {};
    for (const p of pays) {
      const amt = p.method === 'cash' ? r2(Math.max(cashApplied, 0)) : p.amount;
      if (p.method === 'cash') cashApplied = 0;
      db.insert('payments', {
        ticket_id: t.id, cash_session_id: session.id, method: p.method, amount: amt, tendered: p.method === 'cash' ? p.amount : amt,
        change_amount: p.method === 'cash' ? change : 0, reference: p.reference || null, customer_id: p.customer_id || null,
        user_id: req.user.id, created_at: now(),
      });
      const key = PAYMENT_METHODS[p.method].account;
      applied[key] = r2((applied[key] || 0) + amt);
    }
    // Inventory: deduct recipe ingredients / retail stock
    const cogs = inv.consumeForSale(active, { ref_type: 'ticket', ref_id: t.id, ref_no: receiptNo, bdate: t.business_date, user_id: req.user.id });

    // GL
    const cfg = taxConfig();
    const discountNet = t.discount_type === 'sc' || t.discount_type === 'pwd' ? totals.discount_amount : r2(totals.discount_amount / (1 + cfg.vatRate));
    const sales = r2(total - totals.vat_amount - totals.service_charge + discountNet);
    const lines = Object.entries(applied).map(([key, amt]) => ({
      key, debit: amt, party_type: key === 'ar' ? 'customer' : null, party_id: key === 'ar' ? charge.customer_id : null,
    }));
    lines.push({ key: 'sales_discounts', debit: discountNet, memo: t.discount_type !== 'none' ? t.discount_type.toUpperCase() : null });
    lines.push({ key: 'sales', credit: sales });
    lines.push({ key: 'output_vat', credit: totals.vat_amount });
    lines.push({ key: 'service_charge', credit: totals.service_charge });
    lines.push({ key: 'cogs', debit: cogs });
    lines.push({ key: 'inventory', credit: cogs });
    const jeId = gl.postJE({
      date: t.business_date, memo: `POS sale ${receiptNo}${t.table_name ? ' (' + t.table_name + ')' : ''}`,
      source_type: 'pos_sale', source_id: t.id, ref_no: receiptNo, user_id: req.user.id, lines,
    });
    if (charge) {
      const cust = db.get('SELECT * FROM customers WHERE id = ?', charge.customer_id);
      if (!cust) throw notFound('Customer');
      db.insert('ar_invoices', {
        invoice_no: nextNo('AR', 'AR'), customer_id: cust.id, inv_date: t.business_date, due_date: addDays(t.business_date, cust.terms_days || 0),
        ref_no: receiptNo, description: `POS charge ${receiptNo}`, amount: applied.ar, paid_amount: 0, status: 'open',
        income_account_id: gl.acct('sales'), source_type: 'pos_sale', source_id: t.id, journal_entry_id: jeId, created_by: req.user.id, created_at: now(),
      });
    }
    db.update('tickets', t.id, {
      status: 'paid', receipt_no: receiptNo, paid_total: r2(nonCash + cashTendered), change_amount: change, cogs,
      paid_by: req.user.id, paid_at: now(), journal_entry_id: jeId, customer_id: charge ? charge.customer_id : t.customer_id,
    });
    db.run("UPDATE ticket_items SET kitchen_sent = 1 WHERE ticket_id = ? AND status = 'active'", t.id);
  });
  audit(req.user.id, 'settle', 'ticket', t.id, { total, change });
  return loadTicket(t.id);
}));

// Void an open ticket (cancel) or a paid receipt (reverses stock + GL).
router.post('/tickets/:id/void', POS, h((req) => {
  const t = loadTicket(req.params.id);
  if (t.status === 'void') throw bad('Ticket is already void');
  const reason = req.body.reason;
  if (!reason) throw bad('Void reason is required');
  if (t.status === 'open') {
    const sent = t.items.some((i) => i.kitchen_sent && i.status === 'active');
    const by = sent ? authorize(req, 'pos.void_item', req.body.pin) : req.user.id;
    db.update('tickets', t.id, { status: 'void', void_reason: reason, voided_by: by, voided_at: now(), void_session_id: t.cash_session_id });
    audit(req.user.id, 'cancel_ticket', 'ticket', t.id, { reason, authorized_by: by });
    return loadTicket(t.id);
  }
  const by = authorize(req, 'pos.void_receipt', req.body.pin);
  const session = currentSession();
  const cashPart = t.payments.filter((p) => p.method === 'cash').reduce((s, p) => s + p.amount, 0);
  if (cashPart > 0 && !session && t.cash_session_id) {
    throw bad('Open the business day first: the cash refund for this receipt must come out of the drawer.');
  }
  db.tx(() => {
    const ar = db.get("SELECT * FROM ar_invoices WHERE source_type = 'pos_sale' AND source_id = ? AND status <> 'void'", t.id);
    if (ar) {
      if (ar.paid_amount > 0) throw bad(`Receivable ${ar.invoice_no} already has collections. Void the collections first.`);
      db.run("UPDATE ar_invoices SET status = 'void' WHERE id = ?", ar.id);
    }
    const sameSession = session && session.id === t.cash_session_id;
    const vdate = sameSession ? t.business_date : session ? session.business_date : today();
    inv.reverseMovements('ticket', t.id, { bdate: vdate, user_id: req.user.id, notes: `Void ${t.receipt_no}` });
    gl.reverseJE(t.journal_entry_id, { date: vdate, user_id: req.user.id, memo: `Void receipt ${t.receipt_no}: ${reason}` });
    db.update('tickets', t.id, { status: 'void', void_reason: reason, voided_by: by, voided_at: now(), void_session_id: session ? session.id : null });
  });
  audit(req.user.id, 'void_receipt', 'ticket', t.id, { receipt: t.receipt_no, total: t.total, reason, authorized_by: by });
  return loadTicket(t.id);
}));

router.post('/tickets/:id/reprint', requirePerm('pos.reprint'), h((req) => {
  const t = loadTicket(req.params.id);
  audit(req.user.id, 'reprint', 'ticket', t.id, t.receipt_no);
  return t;
}));

// Expose for reports
router.PAYMENT_METHODS = PAYMENT_METHODS;
router.sessionReport = sessionReport;
module.exports = router;
