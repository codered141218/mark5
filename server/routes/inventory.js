'use strict';
const express = require('express');
const db = require('../db');
const { h, bad, notFound, now, today, r2, r4, num, audit, nextNo, required, getSetting, addDays } = require('../util');
const { requirePerm, assertPerm } = require('../auth');
const inv = require('../inventory');
const gl = require('../gl');

const router = express.Router();
const VIEW = requirePerm('inventory.view', 'inventory.manage', 'pos.access');
const MANAGE = requirePerm('inventory.manage');

// ------------------------------------------------------------------ categories
router.get('/categories', VIEW, h(() => db.all(
  'SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) item_count FROM categories c ORDER BY sort_order, name'
)));
router.post('/categories', MANAGE, h((req) => {
  required(req.body, 'name');
  const { name, kind, color, sort_order } = req.body;
  return { id: db.insert('categories', { name, kind: kind || 'menu', color, sort_order: num(sort_order), active: 1 }) };
}));
router.put('/categories/:id', MANAGE, h((req) => {
  const { name, kind, color, sort_order, active } = req.body;
  db.update('categories', req.params.id, { name, kind, color, sort_order, active: active === undefined ? undefined : active ? 1 : 0 });
  return { ok: true };
}));
router.delete('/categories/:id', MANAGE, h((req) => {
  if (db.get('SELECT id FROM items WHERE category_id = ? LIMIT 1', req.params.id)) throw bad('Category still has items. Move or delete them first.');
  db.run('DELETE FROM categories WHERE id = ?', req.params.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ units of measure
router.get('/uoms', VIEW, h(() => db.all('SELECT * FROM uoms ORDER BY name')));
router.post('/uoms', MANAGE, h((req) => {
  required(req.body, 'name', 'abbr');
  return { id: db.insert('uoms', { name: req.body.name, abbr: req.body.abbr }) };
}));
router.put('/uoms/:id', MANAGE, h((req) => {
  db.update('uoms', req.params.id, { name: req.body.name, abbr: req.body.abbr });
  return { ok: true };
}));
router.delete('/uoms/:id', MANAGE, h((req) => {
  const id = req.params.id;
  const used = db.get(`SELECT 1 FROM items WHERE base_uom_id = ? UNION SELECT 1 FROM item_uoms WHERE uom_id = ?
    UNION SELECT 1 FROM item_components WHERE uom_id = ? UNION SELECT 1 FROM inv_doc_lines WHERE uom_id = ? LIMIT 1`, id, id, id, id);
  if (used) throw bad('Unit is in use and cannot be deleted');
  db.run('DELETE FROM uom_conversions WHERE from_uom_id = ? OR to_uom_id = ?', id, id);
  db.run('DELETE FROM uoms WHERE id = ?', id);
  return { ok: true };
}));
router.get('/uom-conversions', VIEW, h(() => db.all(
  `SELECT c.*, f.abbr from_abbr, t.abbr to_abbr FROM uom_conversions c
   JOIN uoms f ON f.id = c.from_uom_id JOIN uoms t ON t.id = c.to_uom_id ORDER BY f.abbr`
)));
router.post('/uom-conversions', MANAGE, h((req) => {
  required(req.body, 'from_uom_id', 'to_uom_id', 'factor');
  if (num(req.body.factor) <= 0) throw bad('Factor must be greater than zero');
  if (Number(req.body.from_uom_id) === Number(req.body.to_uom_id)) throw bad('Choose two different units');
  db.run(`INSERT INTO uom_conversions (from_uom_id, to_uom_id, factor) VALUES (?,?,?)
    ON CONFLICT(from_uom_id, to_uom_id) DO UPDATE SET factor = excluded.factor`, req.body.from_uom_id, req.body.to_uom_id, num(req.body.factor));
  return { ok: true };
}));
router.delete('/uom-conversions/:id', MANAGE, h((req) => {
  db.run('DELETE FROM uom_conversions WHERE id = ?', req.params.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ items
function itemRow(i) {
  const cost = i.item_type === 'composite' ? inv.unitCost(i.id) : i.avg_cost;
  return {
    ...i,
    unit_cost: r4(cost),
    stock_value: inv.STOCKED.includes(i.item_type) ? r2(i.stock_qty * i.avg_cost) : 0,
    food_cost_pct: i.price > 0 ? r2((cost / (i.price / vatDivisor())) * 100) : null,
    low_stock: inv.STOCKED.includes(i.item_type) && i.reorder_point > 0 && i.stock_qty <= i.reorder_point,
  };
}
function vatDivisor() {
  return getSetting('vat_registered', '1') === '1' ? 1 + num(getSetting('vat_rate', '12')) / 100 : 1;
}

router.get('/items', VIEW, h((req) => {
  const { type, category_id, q, active, stocked } = req.query;
  const where = [];
  const p = [];
  if (type) { where.push(`i.item_type IN (${type.split(',').map(() => '?').join(',')})`); p.push(...type.split(',')); }
  if (stocked === '1') where.push("i.item_type IN ('raw','retail')");
  if (category_id) { where.push('i.category_id = ?'); p.push(category_id); }
  if (active !== 'all') where.push('i.active = 1');
  if (q) { where.push('(i.name LIKE ? OR i.sku LIKE ? OR i.barcode = ?)'); p.push(`%${q}%`, `%${q}%`, q); }
  const rows = db.all(
    `SELECT i.*, c.name category_name, u.abbr uom FROM items i
     LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
     ${where.length ? 'WHERE ' + where.join(' AND ') : ''} ORDER BY c.sort_order, c.name, i.sort_order, i.name`, ...p
  );
  return rows.map(itemRow);
}));

router.get('/items/:id', VIEW, h((req) => {
  const i = db.get(
    `SELECT i.*, c.name category_name, u.abbr uom FROM items i LEFT JOIN categories c ON c.id = i.category_id
     LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.id = ?`, req.params.id
  );
  if (!i) throw notFound('Item');
  const components = db.all(
    `SELECT ic.*, it.name component_name, it.item_type component_type, u.abbr uom, bu.abbr base_uom FROM item_components ic
     JOIN items it ON it.id = ic.component_id LEFT JOIN uoms u ON u.id = ic.uom_id LEFT JOIN uoms bu ON bu.id = it.base_uom_id
     WHERE ic.parent_id = ? ORDER BY it.name`, i.id
  ).map((c) => {
    let cost = 0;
    try { cost = r4(inv.toBase(inv.getItem(c.component_id), c.qty, c.uom_id) * inv.unitCost(c.component_id)); } catch { cost = 0; }
    return { ...c, cost };
  });
  const uoms = db.all('SELECT iu.*, u.abbr, u.name FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.item_id = ?', i.id);
  const used_in = db.all('SELECT p.id, p.name FROM item_components ic JOIN items p ON p.id = ic.parent_id WHERE ic.component_id = ?', i.id);
  return { ...itemRow(i), components, uoms, used_in };
}));

// Units an item can be transacted in (base, item-specific and global conversions).
router.get('/items/:id/units', VIEW, h((req) => {
  const item = inv.getItem(req.params.id);
  const out = [];
  const base = db.get('SELECT id, abbr FROM uoms WHERE id = ?', item.base_uom_id);
  if (base) out.push({ uom_id: base.id, abbr: base.abbr, factor: 1 });
  for (const iu of db.all('SELECT iu.uom_id, u.abbr, iu.factor FROM item_uoms iu JOIN uoms u ON u.id = iu.uom_id WHERE iu.item_id = ?', item.id)) out.push(iu);
  for (const c of db.all('SELECT c.from_uom_id uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.from_uom_id WHERE c.to_uom_id = ?', item.base_uom_id)) {
    if (!out.some((o) => o.uom_id === c.uom_id)) out.push(c);
  }
  for (const c of db.all('SELECT c.to_uom_id uom_id, u.abbr, c.factor FROM uom_conversions c JOIN uoms u ON u.id = c.to_uom_id WHERE c.from_uom_id = ?', item.base_uom_id)) {
    if (!out.some((o) => o.uom_id === c.uom_id)) out.push({ ...c, factor: 1 / c.factor });
  }
  return out;
}));

function saveItemChildren(itemId, body) {
  if (Array.isArray(body.components)) {
    db.run('DELETE FROM item_components WHERE parent_id = ?', itemId);
    for (const c of body.components) {
      if (!c.component_id || !(num(c.qty) > 0)) continue;
      if (Number(c.component_id) === Number(itemId)) throw bad('An item cannot be a component of itself');
      db.insert('item_components', { parent_id: itemId, component_id: c.component_id, qty: num(c.qty), uom_id: c.uom_id || null });
    }
    // validate conversions and circularity
    inv.explode(itemId, 1);
  }
  if (Array.isArray(body.uoms)) {
    db.run('DELETE FROM item_uoms WHERE item_id = ?', itemId);
    for (const u of body.uoms) {
      if (!u.uom_id || !(num(u.factor) > 0)) continue;
      db.insert('item_uoms', { item_id: itemId, uom_id: u.uom_id, factor: num(u.factor) });
    }
  }
}

const ITEM_FIELDS = ['sku', 'name', 'category_id', 'item_type', 'base_uom_id', 'price', 'reorder_point', 'reorder_qty', 'sellable', 'active', 'color', 'barcode', 'description', 'sort_order'];
function pickItem(body) {
  const o = {};
  for (const f of ITEM_FIELDS) if (body[f] !== undefined) o[f] = body[f] === '' ? null : body[f];
  for (const f of ['price', 'reorder_point', 'reorder_qty', 'sort_order']) if (o[f] !== undefined) o[f] = num(o[f]);
  for (const f of ['sellable', 'active']) if (o[f] !== undefined) o[f] = o[f] ? 1 : 0;
  return o;
}

router.post('/items', MANAGE, h((req) => {
  required(req.body, 'name', 'item_type', 'base_uom_id');
  const data = pickItem(req.body);
  if (!data.sku) data.sku = nextNo('SKU', 'SKU', 5);
  if (db.get('SELECT id FROM items WHERE sku = ?', data.sku)) throw bad('SKU already exists');
  const id = db.tx(() => {
    const newId = db.insert('items', { ...data, active: data.active ?? 1, sellable: data.sellable ?? 1, avg_cost: num(req.body.avg_cost), last_cost: num(req.body.avg_cost), created_at: now(), updated_at: now() });
    saveItemChildren(newId, req.body);
    return newId;
  });
  audit(req.user.id, 'create', 'item', id, data.name);
  return { id };
}));

router.put('/items/:id', MANAGE, h((req) => {
  const item = inv.getItem(req.params.id);
  const data = pickItem(req.body);
  if (data.sku && db.get('SELECT id FROM items WHERE sku = ? AND id <> ?', data.sku, item.id)) throw bad('SKU already exists');
  if (data.item_type && data.item_type !== item.item_type && Math.abs(item.stock_qty) > 0.0001) {
    throw bad('Cannot change item type while it has stock on hand. Adjust stock to zero first.');
  }
  if (data.base_uom_id && Number(data.base_uom_id) !== Number(item.base_uom_id) && db.get('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', item.id)) {
    throw bad('Cannot change the base unit of an item that already has stock movements');
  }
  // Allow setting a standard cost directly for items with no stock history (e.g. initial setup).
  if (req.body.avg_cost !== undefined && !db.get('SELECT id FROM stock_movements WHERE item_id = ? LIMIT 1', item.id)) {
    data.avg_cost = num(req.body.avg_cost);
  }
  db.tx(() => {
    db.update('items', item.id, { ...data, updated_at: now() });
    saveItemChildren(item.id, req.body);
  });
  audit(req.user.id, 'update', 'item', item.id, data);
  return { ok: true };
}));

router.delete('/items/:id', MANAGE, h((req) => {
  const item = inv.getItem(req.params.id);
  const used = db.get(`SELECT 1 FROM stock_movements WHERE item_id = ? UNION SELECT 1 FROM ticket_items WHERE item_id = ?
    UNION SELECT 1 FROM inv_doc_lines WHERE item_id = ? UNION SELECT 1 FROM item_components WHERE component_id = ? LIMIT 1`, item.id, item.id, item.id, item.id);
  if (used) {
    db.run('UPDATE items SET active = 0, sellable = 0 WHERE id = ?', item.id);
    audit(req.user.id, 'deactivate', 'item', item.id, item.name);
    return { ok: true, deactivated: true, message: 'Item has history, so it was deactivated instead of deleted.' };
  }
  db.run('DELETE FROM items WHERE id = ?', item.id);
  audit(req.user.id, 'delete', 'item', item.id, item.name);
  return { ok: true };
}));

// ------------------------------------------------------------------ inventory documents (receive / issue / waste)
const DOC_PERM = { RECEIVE: 'inventory.receive', ISSUE: 'inventory.issue', WASTE: 'inventory.waste' };
const DOC_PREFIX = { RECEIVE: 'RR', ISSUE: 'IS', WASTE: 'WS' };
const docType = (t) => {
  const T = String(t || '').toUpperCase();
  if (!DOC_PERM[T]) throw bad('Unknown document type');
  return T;
};

function loadDoc(id) {
  const d = db.get(
    `SELECT d.*, s.name supplier_name, a.name expense_account_name, b.bank_name, u.full_name created_by_name, pu.full_name posted_by_name
     FROM inv_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN accounts a ON a.id = d.expense_account_id
     LEFT JOIN bank_accounts b ON b.id = d.bank_account_id LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users pu ON pu.id = d.posted_by
     WHERE d.id = ?`, id
  );
  if (!d) throw notFound('Document');
  d.lines = db.all(
    `SELECT l.*, i.name item_name, i.sku, i.item_type, u.abbr uom, bu.abbr base_uom FROM inv_doc_lines l JOIN items i ON i.id = l.item_id
     LEFT JOIN uoms u ON u.id = l.uom_id LEFT JOIN uoms bu ON bu.id = i.base_uom_id WHERE l.doc_id = ? ORDER BY l.id`, id
  );
  return d;
}

router.get('/docs', h((req) => {
  const T = docType(req.query.type);
  assertPerm(req, DOC_PERM[T]);
  const { from, to, status } = req.query;
  return db.all(
    `SELECT d.*, s.name supplier_name, u.full_name created_by_name,
            (SELECT COUNT(*) FROM inv_doc_lines l WHERE l.doc_id = d.id) line_count
     FROM inv_docs d LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN users u ON u.id = d.created_by
     WHERE d.doc_type = ? AND d.doc_date BETWEEN ? AND ? ${status ? 'AND d.status = ?' : ''}
     ORDER BY d.doc_date DESC, d.id DESC`, T, from || '0000-00-00', to || '9999-99-99', ...(status ? [status] : [])
  );
}));

router.get('/docs/:id', h((req) => {
  const d = loadDoc(req.params.id);
  assertPerm(req, DOC_PERM[d.doc_type]);
  return d;
}));

function saveDocLines(doc, lines) {
  if (!Array.isArray(lines) || !lines.length) throw bad('Add at least one item');
  db.run('DELETE FROM inv_doc_lines WHERE doc_id = ?', doc.id);
  let total = 0;
  for (const l of lines) {
    if (!l.item_id) continue;
    const item = inv.getItem(l.item_id);
    const qty = num(l.qty);
    if (!(qty > 0)) throw bad(`Quantity for "${item.name}" must be greater than zero`);
    if (doc.doc_type === 'RECEIVE' && !inv.STOCKED.includes(item.item_type)) throw bad(`"${item.name}" is not a stocked item and cannot be received`);
    if (item.item_type === 'non_inventory') throw bad(`"${item.name}" is a non-inventory item`);
    const base_qty = inv.toBase(item, qty, l.uom_id || item.base_uom_id);
    let unit_cost; let line_total;
    if (doc.doc_type === 'RECEIVE') {
      line_total = l.line_total !== undefined && l.line_total !== '' ? r2(l.line_total) : r2(qty * num(l.unit_cost));
      unit_cost = qty ? r4(line_total / qty) : 0;
    } else {
      // valued at current average / recipe cost
      const perBase = item.item_type === 'composite' ? inv.unitCost(item.id) : item.avg_cost;
      line_total = r2(base_qty * perBase);
      unit_cost = qty ? r4(line_total / qty) : 0;
    }
    total += line_total;
    db.insert('inv_doc_lines', { doc_id: doc.id, item_id: item.id, qty, uom_id: l.uom_id || item.base_uom_id, base_qty, unit_cost, line_total, reason: l.reason || null, notes: l.notes || null });
  }
  db.update('inv_docs', doc.id, { total_cost: r2(total) });
}

function docFields(T, b) {
  const o = {
    doc_date: b.doc_date || today(), notes: b.notes || null,
  };
  if (T === 'RECEIVE') {
    Object.assign(o, {
      supplier_id: b.supplier_id || null, invoice_no: b.invoice_no || null, payment_mode: b.payment_mode || 'credit',
      bank_account_id: b.payment_mode === 'bank' ? b.bank_account_id || null : null, vat_inclusive: b.vat_inclusive ? 1 : 0,
    });
    if (o.payment_mode === 'credit' && !o.supplier_id) throw bad('Supplier is required for deliveries on credit (accounts payable)');
  } else if (T === 'ISSUE') {
    Object.assign(o, { issued_to: b.issued_to || null, reason: b.reason || null, expense_account_id: b.expense_account_id || gl.acct('supplies') });
  } else {
    Object.assign(o, { reason: b.reason || 'spoilage' });
  }
  return o;
}

router.post('/docs', h((req) => {
  const T = docType(req.body.doc_type);
  assertPerm(req, DOC_PERM[T]);
  const id = db.tx(() => {
    const newId = db.insert('inv_docs', { doc_type: T, doc_no: nextNo(T, DOC_PREFIX[T]), status: 'draft', ...docFields(T, req.body), created_by: req.user.id, created_at: now() });
    saveDocLines({ id: newId, doc_type: T }, req.body.lines);
    if (req.body.post) postDoc(newId, req);
    return newId;
  });
  audit(req.user.id, 'create', `inv_doc:${T}`, id);
  return loadDoc(id);
}));

router.put('/docs/:id', h((req) => {
  const d = loadDoc(req.params.id);
  assertPerm(req, DOC_PERM[d.doc_type]);
  if (d.status !== 'draft') throw bad('Only draft documents can be edited');
  db.tx(() => {
    db.update('inv_docs', d.id, docFields(d.doc_type, req.body));
    saveDocLines(d, req.body.lines);
    if (req.body.post) postDoc(d.id, req);
  });
  return loadDoc(d.id);
}));

router.delete('/docs/:id', h((req) => {
  const d = loadDoc(req.params.id);
  assertPerm(req, DOC_PERM[d.doc_type]);
  if (d.status !== 'draft') throw bad('Posted documents must be voided, not deleted');
  db.run('DELETE FROM inv_docs WHERE id = ?', d.id);
  return { ok: true };
}));

function postDoc(id, req) {
  assertPerm(req, 'inventory.post');
  const d = loadDoc(id);
  if (d.status !== 'draft') throw bad('Document is already posted');
  if (!d.lines.length) throw bad('Document has no lines');
  const ref = { ref_type: 'inv_doc', ref_id: d.id, ref_no: d.doc_no, bdate: d.doc_date, user_id: req.user.id };
  const vatRate = getSetting('vat_registered', '1') === '1' ? num(getSetting('vat_rate', '12')) / 100 : 0;

  if (d.doc_type === 'RECEIVE') {
    let gross = 0; let net = 0;
    for (const l of d.lines) {
      const lineNet = d.vat_inclusive && vatRate ? r2(l.line_total / (1 + vatRate)) : l.line_total;
      gross += l.line_total; net += lineNet;
      inv.moveStock({ ...ref, item_id: l.item_id, qty: l.base_qty, mtype: 'RECEIVE', unit_cost: l.base_qty ? lineNet / l.base_qty : 0, notes: d.supplier_name || null });
    }
    gross = r2(gross); net = r2(net);
    const supplierLabel = d.supplier_name ? ` - ${d.supplier_name}` : '';
    const jeId = gl.postJE({
      date: d.doc_date, memo: `Delivery ${d.doc_no}${supplierLabel}${d.invoice_no ? ' Inv#' + d.invoice_no : ''}`,
      source_type: 'inv_receive', source_id: d.id, ref_no: d.doc_no, user_id: req.user.id,
      lines: [
        { key: 'inventory', debit: net },
        { key: 'input_vat', debit: r2(gross - net) },
        { account_id: gl.sourceAccount(d.payment_mode, d.bank_account_id), credit: gross, bank_account_id: d.payment_mode === 'bank' ? d.bank_account_id : null, party_type: 'supplier', party_id: d.supplier_id },
      ],
    });
    let apId = null;
    if (d.payment_mode === 'credit') {
      const sup = db.get('SELECT * FROM suppliers WHERE id = ?', d.supplier_id);
      apId = db.insert('ap_bills', {
        bill_no: nextNo('AP', 'AP'), supplier_id: d.supplier_id, bill_date: d.doc_date, due_date: addDays(d.doc_date, sup ? sup.terms_days : 0),
        ref_no: d.invoice_no || d.doc_no, description: `Delivery ${d.doc_no}`, amount: gross, paid_amount: 0, status: 'open',
        expense_account_id: gl.acct('inventory'), source_type: 'inv_receive', source_id: d.id, journal_entry_id: jeId, created_by: req.user.id, created_at: now(),
      });
    }
    db.update('inv_docs', d.id, { status: 'posted', posted_by: req.user.id, posted_at: now(), journal_entry_id: jeId, ap_bill_id: apId, total_cost: gross });
  } else {
    let total = 0;
    const mtype = d.doc_type === 'ISSUE' ? 'ISSUE' : 'WASTE';
    for (const l of d.lines) {
      const need = inv.explode(l.item_id, l.base_qty);
      for (const [itemId, q] of need) total += Math.abs(inv.moveStock({ ...ref, item_id: itemId, qty: -q, mtype, notes: d.reason || d.issued_to || null }));
    }
    total = r2(total);
    const debitAcct = d.doc_type === 'ISSUE' ? d.expense_account_id : gl.acct('wastage');
    const jeId = gl.postJE({
      date: d.doc_date,
      memo: d.doc_type === 'ISSUE' ? `Stock issuance ${d.doc_no}${d.issued_to ? ' to ' + d.issued_to : ''}` : `Spoilage/wastage ${d.doc_no} (${d.reason})`,
      source_type: d.doc_type === 'ISSUE' ? 'inv_issue' : 'inv_waste', source_id: d.id, ref_no: d.doc_no, user_id: req.user.id,
      lines: [{ account_id: debitAcct, debit: total }, { key: 'inventory', credit: total }],
    });
    db.update('inv_docs', d.id, { status: 'posted', posted_by: req.user.id, posted_at: now(), journal_entry_id: jeId, total_cost: total });
  }
  audit(req.user.id, 'post', `inv_doc:${d.doc_type}`, d.id, d.doc_no);
}

router.post('/docs/:id/post', h((req) => {
  const d = loadDoc(req.params.id);
  assertPerm(req, DOC_PERM[d.doc_type]);
  db.tx(() => postDoc(d.id, req));
  return loadDoc(d.id);
}));

router.post('/docs/:id/void', requirePerm('inventory.post'), h((req) => {
  const d = loadDoc(req.params.id);
  if (d.status !== 'posted') throw bad('Only posted documents can be voided');
  db.tx(() => {
    if (d.ap_bill_id) {
      const bill = db.get('SELECT * FROM ap_bills WHERE id = ?', d.ap_bill_id);
      if (bill && bill.paid_amount > 0) throw bad(`Payable ${bill.bill_no} already has payments. Void the payments first.`);
      if (bill) db.run("UPDATE ap_bills SET status = 'void' WHERE id = ?", bill.id);
    }
    inv.reverseMovements('inv_doc', d.id, { bdate: today(), user_id: req.user.id, notes: `Void ${d.doc_no}` });
    gl.reverseJE(d.journal_entry_id, { date: today(), user_id: req.user.id, memo: `Void ${d.doc_no}: ${req.body.reason || ''}` });
    db.update('inv_docs', d.id, { status: 'cancelled', notes: [d.notes, `VOID: ${req.body.reason || ''}`].filter(Boolean).join(' | ') });
  });
  audit(req.user.id, 'void', `inv_doc:${d.doc_type}`, d.id, req.body.reason);
  return loadDoc(d.id);
}));

// ------------------------------------------------------------------ inventory counts
const COUNT = requirePerm('inventory.count');

function loadCount(id) {
  const s = db.get(
    `SELECT s.*, c.name category_name, u.full_name created_by_name, pu.full_name posted_by_name FROM count_sessions s
     LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN users u ON u.id = s.created_by LEFT JOIN users pu ON pu.id = s.posted_by WHERE s.id = ?`, id
  );
  if (!s) throw notFound('Count session');
  s.lines = db.all(
    `SELECT l.*, i.name item_name, i.sku, i.stock_qty current_qty, i.avg_cost current_cost, u.abbr uom, c.name category_name
     FROM count_lines l JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = i.base_uom_id
     LEFT JOIN categories c ON c.id = i.category_id WHERE l.session_id = ? ORDER BY c.name, i.name`, id
  );
  return s;
}

router.get('/counts', COUNT, h((req) => db.all(
  `SELECT s.*, c.name category_name, u.full_name created_by_name,
          (SELECT COUNT(*) FROM count_lines l WHERE l.session_id = s.id) line_count,
          (SELECT COUNT(*) FROM count_lines l WHERE l.session_id = s.id AND l.counted_qty IS NOT NULL) counted_count
   FROM count_sessions s LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN users u ON u.id = s.created_by
   WHERE s.count_date BETWEEN ? AND ? ORDER BY s.count_date DESC, s.id DESC`, req.query.from || '0000-00-00', req.query.to || '9999-99-99'
)));
router.get('/counts/:id', COUNT, h((req) => loadCount(req.params.id)));

router.post('/counts', COUNT, h((req) => {
  const { count_date, category_id, notes } = req.body;
  const id = db.tx(() => {
    const sid = db.insert('count_sessions', { doc_no: nextNo('CNT', 'CNT'), count_date: count_date || today(), status: 'open', category_id: category_id || null, notes, created_by: req.user.id, created_at: now() });
    const items = db.all(`SELECT * FROM items WHERE active = 1 AND item_type IN ('raw','retail') ${category_id ? 'AND category_id = ?' : ''}`, ...(category_id ? [category_id] : []));
    if (!items.length) throw bad('No stocked items to count');
    for (const it of items) db.insert('count_lines', { session_id: sid, item_id: it.id, system_qty: it.stock_qty, unit_cost: it.avg_cost });
    return sid;
  });
  audit(req.user.id, 'create', 'count', id);
  return loadCount(id);
}));

router.put('/counts/:id', COUNT, h((req) => {
  const s = loadCount(req.params.id);
  if (s.status !== 'open') throw bad('Count session is already closed');
  db.tx(() => {
    if (req.body.notes !== undefined) db.update('count_sessions', s.id, { notes: req.body.notes });
    for (const l of req.body.lines || []) {
      const v = l.counted_qty === '' || l.counted_qty === null || l.counted_qty === undefined ? null : num(l.counted_qty);
      db.run('UPDATE count_lines SET counted_qty = ? WHERE id = ? AND session_id = ?', v, l.id, s.id);
    }
  });
  return loadCount(s.id);
}));

router.post('/counts/:id/post', requirePerm('inventory.post'), h((req) => {
  const s = loadCount(req.params.id);
  if (s.status !== 'open') throw bad('Count session is already closed');
  const counted = s.lines.filter((l) => l.counted_qty !== null);
  if (!counted.length) throw bad('Enter at least one counted quantity before posting');
  db.tx(() => {
    let gain = 0; let loss = 0;
    const date = s.count_date;
    for (const l of counted) {
      const item = inv.getItem(l.item_id);
      const variance = r4(l.counted_qty - item.stock_qty);
      const value = r2(variance * item.avg_cost);
      db.run('UPDATE count_lines SET system_qty = ?, variance = ?, unit_cost = ?, variance_value = ? WHERE id = ?', item.stock_qty, variance, item.avg_cost, value, l.id);
      if (variance !== 0) {
        inv.moveStock({ item_id: item.id, qty: variance, mtype: 'COUNT', unit_cost: item.avg_cost, ref_type: 'count', ref_id: s.id, ref_no: s.doc_no, bdate: date, user_id: req.user.id });
        if (value > 0) gain += value; else loss += -value;
      }
    }
    gain = r2(gain); loss = r2(loss);
    const jeId = gl.postJE({
      date, memo: `Inventory count ${s.doc_no} variance`, source_type: 'inv_count', source_id: s.id, ref_no: s.doc_no, user_id: req.user.id,
      lines: [
        { key: 'inventory', debit: gain, credit: loss },
        { key: 'inv_variance', debit: loss, credit: gain },
      ],
    });
    db.update('count_sessions', s.id, { status: 'posted', posted_by: req.user.id, posted_at: now(), journal_entry_id: jeId, total_variance_value: r2(gain - loss) });
  });
  audit(req.user.id, 'post', 'count', s.id, s.doc_no);
  return loadCount(s.id);
}));

router.post('/counts/:id/cancel', COUNT, h((req) => {
  const s = loadCount(req.params.id);
  if (s.status !== 'open') throw bad('Only open count sessions can be cancelled');
  db.update('count_sessions', s.id, { status: 'cancelled' });
  return { ok: true };
}));

module.exports = router;
