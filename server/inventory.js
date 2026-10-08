'use strict';
// Stock ledger, unit conversions, recipe explosion and moving-average costing.
const db = require('./db');
const { r2, r4, bad, now, today } = require('./util');

const STOCKED = ['raw', 'retail'];

function getItem(id) {
  const it = db.get('SELECT * FROM items WHERE id = ?', id);
  if (!it) throw bad(`Item #${id} not found`);
  return it;
}

// Factor to convert 1 [uomId] into the item's base unit.
function factorToBase(item, uomId) {
  if (!uomId || Number(uomId) === Number(item.base_uom_id)) return 1;
  const iu = db.get('SELECT factor FROM item_uoms WHERE item_id = ? AND uom_id = ?', item.id, uomId);
  if (iu) return iu.factor;
  const conv = db.get('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', uomId, item.base_uom_id);
  if (conv) return conv.factor;
  const inv = db.get('SELECT factor FROM uom_conversions WHERE from_uom_id = ? AND to_uom_id = ?', item.base_uom_id, uomId);
  if (inv && inv.factor) return 1 / inv.factor;
  const u = db.get('SELECT abbr FROM uoms WHERE id = ?', uomId);
  const b = db.get('SELECT abbr FROM uoms WHERE id = ?', item.base_uom_id);
  throw bad(`No conversion from ${u ? u.abbr : uomId} to ${b ? b.abbr : 'base unit'} for "${item.name}". Add it under the item's units or UOM conversions.`);
}

function toBase(item, qty, uomId) {
  return r4(Number(qty) * factorToBase(item, uomId));
}

/**
 * Expand an item into the stocked items it consumes.
 * Composite items explode recursively into their components (sub-recipes supported).
 * Returns Map(item_id -> base qty)
 */
function explode(itemId, qty, acc = new Map(), depth = 0) {
  if (depth > 8) throw bad('Recipe nesting is too deep (possible circular recipe)');
  const item = getItem(itemId);
  if (STOCKED.includes(item.item_type)) {
    acc.set(item.id, r4((acc.get(item.id) || 0) + Number(qty)));
  } else if (item.item_type === 'composite') {
    const comps = db.all('SELECT * FROM item_components WHERE parent_id = ?', item.id);
    for (const c of comps) {
      const comp = getItem(c.component_id);
      explode(comp.id, toBase(comp, c.qty, c.uom_id) * Number(qty), acc, depth + 1);
    }
  }
  return acc;
}

// Theoretical unit cost (per base unit / per serving for composites).
function unitCost(itemId, depth = 0) {
  if (depth > 8) return 0;
  const item = getItem(itemId);
  if (item.item_type === 'composite') {
    let total = 0;
    for (const c of db.all('SELECT * FROM item_components WHERE parent_id = ?', item.id)) {
      const comp = getItem(c.component_id);
      let f;
      try { f = factorToBase(comp, c.uom_id); } catch { f = 0; }
      total += Number(c.qty) * f * unitCost(comp.id, depth + 1);
    }
    return r4(total);
  }
  return Number(item.avg_cost) || 0;
}

/**
 * Record a stock movement for a stocked item.
 * qty is signed, in base units. For inbound movements with unit_cost, the moving average is updated.
 * Returns the total cost value of the movement (signed).
 */
function moveStock({ item_id, qty, mtype, unit_cost = null, ref_type, ref_id, ref_no, bdate, notes, user_id }) {
  const item = getItem(item_id);
  if (!STOCKED.includes(item.item_type)) return 0;
  qty = r4(qty);
  if (qty === 0) return 0;
  let cost = Number(item.avg_cost) || 0;
  let newAvg = cost;
  if (qty > 0 && unit_cost !== null && unit_cost !== undefined) {
    cost = Number(unit_cost);
    const onHand = Math.max(Number(item.stock_qty), 0);
    newAvg = onHand + qty > 0 ? (onHand * Number(item.avg_cost) + qty * cost) / (onHand + qty) : cost;
  }
  const balance = r4(Number(item.stock_qty) + qty);
  const total = r2(qty * cost);
  db.insert('stock_movements', {
    item_id, ts: now(), bdate: bdate || today(), mtype, qty, unit_cost: r4(cost), total_cost: total,
    balance_after: balance, ref_type, ref_id, ref_no, notes, user_id,
  });
  const upd = { stock_qty: balance, avg_cost: r4(newAvg), updated_at: now() };
  if (qty > 0 && unit_cost !== null && unit_cost !== undefined) upd.last_cost = r4(cost);
  db.update('items', item.id, upd);
  return total;
}

// Deduct (or with sign=+1 restore) recipe ingredients for a list of sold lines. Returns total cost.
function consumeForSale(lines, { sign = -1, mtype = 'SALE', ref_type, ref_id, ref_no, bdate, user_id }) {
  const need = new Map();
  for (const l of lines) explode(l.item_id, l.qty, need);
  let totalCost = 0;
  for (const [itemId, q] of need) {
    totalCost += Math.abs(moveStock({ item_id: itemId, qty: sign * q, mtype, ref_type, ref_id, ref_no, bdate, user_id }));
  }
  return r2(totalCost);
}

// Restore stock exactly as consumed by a previous document (used on void, keeps original cost).
function reverseMovements(ref_type, ref_id, { mtype, bdate, user_id, notes }) {
  const moves = db.all('SELECT * FROM stock_movements WHERE ref_type = ? AND ref_id = ?', ref_type, ref_id);
  let total = 0;
  for (const m of moves) {
    if (m.mtype.endsWith('_VOID')) continue;
    const item = getItem(m.item_id);
    const qty = -m.qty;
    const balance = r4(Number(item.stock_qty) + qty);
    db.insert('stock_movements', {
      item_id: m.item_id, ts: now(), bdate: bdate || today(), mtype: mtype || m.mtype + '_VOID', qty,
      unit_cost: m.unit_cost, total_cost: r2(qty * m.unit_cost), balance_after: balance,
      ref_type, ref_id, ref_no: m.ref_no, notes, user_id,
    });
    const upd = { stock_qty: balance, updated_at: now() };
    if (m.qty > 0 && balance > 0) {
      // Backing out a receipt: remove its value from the moving average.
      const value = Math.max(Number(item.stock_qty), 0) * Number(item.avg_cost) - m.qty * m.unit_cost;
      upd.avg_cost = r4(Math.max(value / balance, 0));
    }
    db.update('items', item.id, upd);
    total += -m.total_cost;
  }
  return r2(total);
}

module.exports = { STOCKED, getItem, factorToBase, toBase, explode, unitCost, moveStock, consumeForSale, reverseMovements };
