'use strict';
const express = require('express');
const ExcelJS = require('exceljs');
const db = require('../db');
const { h, r2, r4, dateRange, getSetting, today, bad, addDays } = require('../util');
const { requirePerm, can } = require('../auth');
const inv = require('../inventory');
const gl = require('../gl');

const router = express.Router();
const SALES = requirePerm('reports.sales');
const INV = requirePerm('reports.inventory');
const FIN = requirePerm('reports.finance');

const PAID = "t.status = 'paid'";
const METHOD_LABELS = {
  cash: 'Cash', card: 'Card', gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank Transfer', grabfood: 'GrabFood', foodpanda: 'foodpanda', charge: 'Charge (A/R)',
};
function deepRound(v) {
  if (typeof v === 'number') return Number.isInteger(v) ? v : r4(v);
  if (Array.isArray(v)) return v.map(deepRound);
  if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, deepRound(x)]));
  return v;
}
const round = deepRound;

// ------------------------------------------------------------------ dashboard
router.get('/dashboard', requirePerm('dashboard.view'), h((req) => {
  const { from, to } = dateRange(req.query);
  const sales = db.get(
    `SELECT COUNT(*) receipts, COALESCE(SUM(total),0) net, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_amount),0) discounts,
            COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(service_charge),0) svc, COALESCE(SUM(cogs),0) cogs, COALESCE(SUM(pax),0) pax
     FROM tickets t WHERE ${PAID} AND business_date BETWEEN ? AND ?`, from, to
  );
  const netOfVat = r2(sales.net - sales.vat - sales.svc);
  const voids = db.get("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amt FROM tickets WHERE status = 'void' AND receipt_no IS NOT NULL AND business_date BETWEEN ? AND ?", from, to);
  const daily = db.all(
    `SELECT business_date date, COUNT(*) receipts, SUM(total) net, SUM(cogs) cogs FROM tickets t WHERE ${PAID} AND business_date BETWEEN ? AND ?
     GROUP BY business_date ORDER BY business_date`, from, to
  );
  const hourly = db.all(
    `SELECT CAST(substr(paid_at, 12, 2) AS INTEGER) hour, COUNT(*) receipts, SUM(total) net FROM tickets t
     WHERE ${PAID} AND business_date BETWEEN ? AND ? GROUP BY hour ORDER BY hour`, from, to
  );
  const topItems = db.all(
    `SELECT i.item_id, i.name, SUM(i.qty) qty, SUM(i.line_total) amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
     WHERE ${PAID} AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY i.item_id ORDER BY amount DESC LIMIT 10`, from, to
  );
  const categories = db.all(
    `SELECT COALESCE(c.name, 'Uncategorized') name, SUM(i.qty) qty, SUM(i.line_total) amount FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id
     JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
     WHERE ${PAID} AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY c.name ORDER BY amount DESC`, from, to
  );
  const payments = db.all(
    `SELECT p.method, SUM(p.amount) amount, COUNT(*) cnt FROM payments p JOIN tickets t ON t.id = p.ticket_id
     WHERE ${PAID} AND t.business_date BETWEEN ? AND ? GROUP BY p.method ORDER BY amount DESC`, from, to
  ).map((p) => ({ ...p, label: METHOD_LABELS[p.method] || p.method }));
  const orderTypes = db.all(
    `SELECT order_type, COUNT(*) cnt, SUM(total) amount FROM tickets t WHERE ${PAID} AND business_date BETWEEN ? AND ? GROUP BY order_type`, from, to
  );
  const wastage = db.value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'WASTE' AND status = 'posted' AND doc_date BETWEEN ? AND ?", from, to);
  const pettyCash = db.value("SELECT COALESCE(SUM(amount),0) FROM petty_cash_txns WHERE txn_type = 'expense' AND status = 'posted' AND txn_date BETWEEN ? AND ?", from, to);
  const purchases = db.value("SELECT COALESCE(SUM(total_cost),0) FROM inv_docs WHERE doc_type = 'RECEIVE' AND status = 'posted' AND doc_date BETWEEN ? AND ?", from, to);
  const expenses = db.value(
    `SELECT COALESCE(SUM(l.debit - l.credit),0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
     WHERE a.type = 'expense' AND COALESCE(a.subtype,'') <> 'cogs' AND e.entry_date BETWEEN ? AND ?`, from, to
  );
  const lowStock = db.all(
    `SELECT i.id, i.name, i.stock_qty, i.reorder_point, i.reorder_qty, u.abbr uom FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id
     WHERE i.active = 1 AND i.item_type IN ('raw','retail') AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point ORDER BY (i.stock_qty / i.reorder_point) LIMIT 15`
  );
  const apDue = db.get(
    "SELECT COUNT(*) cnt, COALESCE(SUM(amount - paid_amount),0) amt FROM ap_bills WHERE status IN ('open','partial') AND due_date <= ?", addDays(today(), 7)
  );
  const apTotal = db.value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ap_bills WHERE status IN ('open','partial')");
  const arTotal = db.value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial')");
  const arOverdue = db.value("SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices WHERE status IN ('open','partial') AND due_date < ?", today());
  const pendingCA = db.get("SELECT COUNT(*) cnt, COALESCE(SUM(amount),0) amt FROM cash_advances WHERE status = 'pending'");
  const caOutstanding = db.value("SELECT COALESCE(SUM(balance),0) FROM cash_advances WHERE status = 'approved'");
  const banks = db.all('SELECT b.id, b.bank_name, b.account_no, b.gl_account_id FROM bank_accounts b WHERE b.active = 1')
    .map((b) => ({ ...b, balance: gl.accountBalance(b.gl_account_id, to) }));
  const cashOnHand = gl.accountBalance(gl.acct('cash_on_hand'), to);
  const pettyFund = gl.accountBalance(gl.acct('petty_cash'), to);
  const inventoryValue = db.value("SELECT COALESCE(SUM(CASE WHEN stock_qty > 0 THEN stock_qty * avg_cost ELSE 0 END),0) FROM items WHERE item_type IN ('raw','retail')");
  const session = db.get("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
  const openTickets = db.get("SELECT COUNT(*) cnt, COALESCE(SUM(total),0) amt FROM tickets WHERE status = 'open'");

  return round({
    from, to,
    kpi: {
      net_sales: sales.net, net_of_vat: netOfVat, gross_sales: sales.gross, discounts: sales.discounts, vat: sales.vat, service_charge: sales.svc,
      receipts: sales.receipts, pax: sales.pax, avg_ticket: sales.receipts ? sales.net / sales.receipts : 0,
      cogs: sales.cogs, gross_profit: netOfVat - sales.cogs, food_cost_pct: netOfVat ? (sales.cogs / netOfVat) * 100 : 0,
      voids: voids.cnt, void_amount: voids.amt, wastage, petty_cash: pettyCash, purchases, operating_expenses: expenses,
      net_income_est: netOfVat - sales.cogs - wastage - expenses,
    },
    daily: round(daily), hourly: round(hourly), top_items: round(topItems), categories: round(categories), payments: round(payments), order_types: round(orderTypes),
    low_stock: lowStock,
    position: {
      cash_on_hand: cashOnHand, petty_cash: pettyFund, banks: round(banks), inventory_value: inventoryValue,
      ap_total: apTotal, ap_due_7d: apDue.amt, ap_due_count: apDue.cnt, ar_total: arTotal, ar_overdue: arOverdue,
      ca_pending: pendingCA.cnt, ca_pending_amount: pendingCA.amt, ca_outstanding: caOutstanding,
    },
    session, open_tickets: openTickets,
  });
}));

// ------------------------------------------------------------------ sales reports
router.get('/sales/daily', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT business_date date, COUNT(*) receipts, SUM(pax) pax, SUM(subtotal) gross, SUM(discount_amount) discounts, SUM(vatable_sales) vatable_sales,
            SUM(vat_exempt_sales) vat_exempt, SUM(vat_amount) vat, SUM(service_charge) service_charge, SUM(total) net_sales, SUM(cogs) cogs,
            SUM(total) - SUM(vat_amount) - SUM(service_charge) - SUM(cogs) gross_profit
     FROM tickets t WHERE ${PAID} AND business_date BETWEEN ? AND ? GROUP BY business_date ORDER BY business_date`, from, to
  ));
}));

router.get('/sales/items', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = db.all(
    `SELECT it.sku, i.name item, COALESCE(c.name,'Uncategorized') category, SUM(i.qty) qty, SUM(i.line_total) gross, i.item_id
     FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
     WHERE ${PAID} AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY i.item_id, i.name ORDER BY gross DESC`, from, to
  );
  const total = rows.reduce((s, r) => s + r.gross, 0) || 1;
  return round(rows.map((r) => {
    const cost = inv.unitCost(r.item_id);
    return { ...r, avg_price: r.gross / r.qty, unit_cost: cost, total_cost: cost * r.qty, share_pct: (r.gross / total) * 100 };
  }));
}));

router.get('/sales/categories', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = db.all(
    `SELECT COALESCE(c.name,'Uncategorized') category, COUNT(DISTINCT t.id) receipts, SUM(i.qty) qty, SUM(i.line_total) gross
     FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id JOIN items it ON it.id = i.item_id LEFT JOIN categories c ON c.id = it.category_id
     WHERE ${PAID} AND i.status = 'active' AND t.business_date BETWEEN ? AND ? GROUP BY c.name ORDER BY gross DESC`, from, to
  );
  const total = rows.reduce((s, r) => s + r.gross, 0) || 1;
  return round(rows.map((r) => ({ ...r, share_pct: (r.gross / total) * 100 })));
}));

router.get('/sales/receipts', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  const status = req.query.status;
  return round(db.all(
    `SELECT t.id, t.business_date date, t.receipt_no, t.ticket_no, t.status, t.order_type, dt.name table_name, t.customer_name, t.pax, t.subtotal gross,
            t.discount_type, t.discount_amount discount, t.vat_amount vat, t.service_charge, t.total, t.paid_at, u.full_name cashier,
            (SELECT GROUP_CONCAT(method, ', ') FROM payments p WHERE p.ticket_id = t.id) payment, t.void_reason
     FROM tickets t LEFT JOIN dining_tables dt ON dt.id = t.table_id LEFT JOIN users u ON u.id = t.paid_by
     WHERE t.receipt_no IS NOT NULL AND t.business_date BETWEEN ? AND ? ${status ? 'AND t.status = ?' : ''} ORDER BY t.receipt_no`,
    from, to, ...(status ? [status] : [])
  ));
}));

router.get('/sales/payments', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT p.method, COUNT(*) transactions, SUM(p.amount) amount FROM payments p JOIN tickets t ON t.id = p.ticket_id
     WHERE ${PAID} AND t.business_date BETWEEN ? AND ? GROUP BY p.method ORDER BY amount DESC`, from, to
  ).map((r) => ({ ...r, method: METHOD_LABELS[r.method] || r.method })));
}));

router.get('/sales/hourly', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT printf('%02d:00 - %02d:59', CAST(substr(paid_at,12,2) AS INTEGER), CAST(substr(paid_at,12,2) AS INTEGER)) hour,
            COUNT(*) receipts, SUM(pax) pax, SUM(total) net_sales, AVG(total) avg_ticket
     FROM tickets t WHERE ${PAID} AND business_date BETWEEN ? AND ? GROUP BY substr(paid_at,12,2) ORDER BY substr(paid_at,12,2)`, from, to
  ));
}));

router.get('/sales/cashiers', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT u.full_name cashier, COUNT(*) receipts, SUM(t.total) net_sales, SUM(t.discount_amount) discounts,
            (SELECT COUNT(*) FROM tickets v WHERE v.voided_by = u.id AND v.status = 'void' AND v.receipt_no IS NOT NULL AND v.business_date BETWEEN ? AND ?) voids_authorized
     FROM tickets t JOIN users u ON u.id = t.paid_by WHERE ${PAID} AND t.business_date BETWEEN ? AND ? GROUP BY u.id ORDER BY net_sales DESC`, from, to, from, to
  ));
}));

router.get('/sales/voids', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  const receipts = db.all(
    `SELECT t.business_date date, 'Receipt' kind, t.receipt_no ref, '' item, NULL qty, t.total amount, t.void_reason reason, t.voided_at, u.full_name authorized_by
     FROM tickets t LEFT JOIN users u ON u.id = t.voided_by WHERE t.status = 'void' AND t.receipt_no IS NOT NULL AND t.business_date BETWEEN ? AND ?`, from, to
  );
  const items = db.all(
    `SELECT t.business_date date, 'Item' kind, COALESCE(t.receipt_no, t.ticket_no) ref, i.name item, i.qty, i.line_total amount, i.void_reason reason, i.voided_at, u.full_name authorized_by
     FROM ticket_items i JOIN tickets t ON t.id = i.ticket_id LEFT JOIN users u ON u.id = i.voided_by
     WHERE i.status = 'void' AND t.business_date BETWEEN ? AND ?`, from, to
  );
  return round([...receipts, ...items].sort((a, b) => String(a.voided_at).localeCompare(String(b.voided_at))));
}));

// SC/PWD sales book (BIR requirement) and other discounts
router.get('/sales/discounts', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = db.all(
    `SELECT t.business_date date, t.receipt_no, t.discount_type, t.discount_rate, t.sc_count, t.pax, t.sc_details, t.subtotal gross,
            t.vat_exempt_sales, t.discount_amount discount, t.total net
     FROM tickets t WHERE ${PAID} AND t.discount_type <> 'none' AND t.business_date BETWEEN ? AND ? ORDER BY t.receipt_no`, from, to
  );
  return round(rows.map((r) => {
    let d = [];
    try { d = JSON.parse(r.sc_details || '[]'); } catch { d = []; }
    return {
      ...r, sc_details: undefined,
      discount_type: { sc: 'Senior Citizen', pwd: 'PWD', percent: `${r.discount_rate}%`, amount: 'Fixed amount' }[r.discount_type] || r.discount_type,
      names: d.map((x) => x.name).join('; '), id_numbers: d.map((x) => x.id_no).join('; '),
    };
  }));
}));

router.get('/sales/eod', SALES, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT s.id, s.business_date, s.status, s.opened_at, ou.full_name opened_by, s.closed_at, cu.full_name closed_by, s.opening_cash,
            (SELECT COUNT(*) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') receipts,
            (SELECT COALESCE(SUM(total),0) FROM tickets t WHERE t.cash_session_id = s.id AND t.status = 'paid') net_sales,
            s.expected_cash, s.counted_cash, s.variance
     FROM cash_sessions s LEFT JOIN users ou ON ou.id = s.opened_by LEFT JOIN users cu ON cu.id = s.closed_by
     WHERE s.business_date BETWEEN ? AND ? ORDER BY s.business_date, s.id`, from, to
  ));
}));

// ------------------------------------------------------------------ inventory reports
router.get('/inventory/onhand', INV, h((req) => {
  const cat = req.query.category_id;
  return round(db.all(
    `SELECT i.id, i.sku, i.name item, COALESCE(c.name,'') category, i.item_type type, u.abbr uom, i.stock_qty on_hand, i.avg_cost, i.last_cost,
            i.stock_qty * i.avg_cost value, i.reorder_point, i.reorder_qty,
            CASE WHEN i.stock_qty < 0 THEN 'NEGATIVE' WHEN i.reorder_point > 0 AND i.stock_qty <= i.reorder_point THEN 'REORDER' ELSE 'OK' END status
     FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
     WHERE i.active = 1 AND i.item_type IN ('raw','retail') ${cat ? 'AND i.category_id = ?' : ''} ORDER BY c.name, i.name`, ...(cat ? [cat] : [])
  ));
}));

router.get('/inventory/reorder', INV, h(() => round(db.all(
  `SELECT i.sku, i.name item, COALESCE(c.name,'') category, u.abbr uom, i.stock_qty on_hand, i.reorder_point, i.reorder_qty,
          MAX(i.reorder_qty, i.reorder_point - i.stock_qty) suggested_order, i.last_cost, MAX(i.reorder_qty, i.reorder_point - i.stock_qty) * i.last_cost est_cost
   FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN uoms u ON u.id = i.base_uom_id
   WHERE i.active = 1 AND i.item_type IN ('raw','retail') AND i.reorder_point > 0 AND i.stock_qty <= i.reorder_point ORDER BY c.name, i.name`
))));

// Movement summary per item over a period: beginning, in, out by type, ending
router.get('/inventory/movement', INV, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = db.all(
    `SELECT i.id, i.sku, i.name item, u.abbr uom, i.avg_cost,
      COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate < ?),0) beginning,
      COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate BETWEEN ? AND ? AND m.mtype IN ('RECEIVE','RECEIVE_VOID')),0) received,
      COALESCE((SELECT -SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate BETWEEN ? AND ? AND m.mtype IN ('SALE','SALE_VOID')),0) sold,
      COALESCE((SELECT -SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate BETWEEN ? AND ? AND m.mtype IN ('ISSUE','ISSUE_VOID')),0) issued,
      COALESCE((SELECT -SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate BETWEEN ? AND ? AND m.mtype IN ('WASTE','WASTE_VOID')),0) wasted,
      COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate BETWEEN ? AND ? AND m.mtype IN ('COUNT')),0) count_adj,
      COALESCE((SELECT SUM(qty) FROM stock_movements m WHERE m.item_id = i.id AND m.bdate <= ?),0) ending
     FROM items i LEFT JOIN uoms u ON u.id = i.base_uom_id WHERE i.item_type IN ('raw','retail') AND i.active = 1 ORDER BY i.name`,
    from, from, to, from, to, from, to, from, to, from, to, to
  );
  return round(rows.map((r) => ({ ...r, ending_value: r.ending * r.avg_cost })));
}));

router.get('/inventory/stockcard', INV, h((req) => {
  const { from, to } = dateRange(req.query);
  const itemId = req.query.item_id;
  if (!itemId) return [];
  const beginning = db.value('SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE item_id = ? AND bdate < ?', itemId, from);
  const moves = db.all(
    `SELECT m.bdate date, m.ts, m.mtype type, m.ref_no, m.notes, m.qty, m.unit_cost, m.total_cost, u.full_name user FROM stock_movements m
     LEFT JOIN users u ON u.id = m.user_id WHERE m.item_id = ? AND m.bdate BETWEEN ? AND ? ORDER BY m.bdate, m.id`, itemId, from, to
  );
  let bal = beginning;
  const rows = [{ date: from, type: 'BEGINNING', ref_no: '', qty_in: null, qty_out: null, balance: r4(beginning) }];
  for (const m of moves) {
    bal += m.qty;
    rows.push({ ...m, qty_in: m.qty > 0 ? m.qty : null, qty_out: m.qty < 0 ? -m.qty : null, balance: r4(bal) });
  }
  return round(rows);
}));

function docReport(type) {
  return h((req) => {
    const { from, to } = dateRange(req.query);
    return round(db.all(
      `SELECT d.doc_date date, d.doc_no, ${type === 'RECEIVE' ? "s.name supplier, d.invoice_no, d.payment_mode," : type === 'ISSUE' ? 'd.issued_to, a.name expense_account,' : ''}
              d.reason, i.sku, i.name item, l.qty, u.abbr uom, l.base_qty, bu.abbr base_uom, l.unit_cost, l.line_total amount, d.status, cu.full_name prepared_by
       FROM inv_doc_lines l JOIN inv_docs d ON d.id = l.doc_id JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = l.uom_id
       LEFT JOIN uoms bu ON bu.id = i.base_uom_id LEFT JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN accounts a ON a.id = d.expense_account_id
       LEFT JOIN users cu ON cu.id = d.created_by
       WHERE d.doc_type = ? AND d.status = 'posted' AND d.doc_date BETWEEN ? AND ? ORDER BY d.doc_date, d.doc_no`, type, from, to
    ));
  });
}
router.get('/inventory/receiving', INV, docReport('RECEIVE'));
router.get('/inventory/issuance', INV, docReport('ISSUE'));
router.get('/inventory/wastage', INV, docReport('WASTE'));

router.get('/inventory/counts', INV, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT s.count_date date, s.doc_no, i.sku, i.name item, u.abbr uom, l.system_qty, l.counted_qty, l.variance, l.unit_cost, l.variance_value
     FROM count_lines l JOIN count_sessions s ON s.id = l.session_id JOIN items i ON i.id = l.item_id LEFT JOIN uoms u ON u.id = i.base_uom_id
     WHERE s.status = 'posted' AND l.counted_qty IS NOT NULL AND s.count_date BETWEEN ? AND ? ORDER BY s.count_date, s.doc_no, i.name`, from, to
  ));
}));

// Theoretical ingredient usage from sales vs. actual usage per counts
router.get('/inventory/usage', INV, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT i.sku, i.name item, u.abbr uom, -SUM(CASE WHEN m.mtype IN ('SALE','SALE_VOID') THEN m.qty ELSE 0 END) sold_usage,
            -SUM(CASE WHEN m.mtype IN ('SALE','SALE_VOID') THEN m.total_cost ELSE 0 END) sold_cost,
            -SUM(CASE WHEN m.mtype IN ('WASTE','WASTE_VOID') THEN m.qty ELSE 0 END) wasted,
            -SUM(CASE WHEN m.mtype = 'COUNT' THEN m.qty ELSE 0 END) count_shortage,
            -SUM(CASE WHEN m.mtype = 'COUNT' THEN m.total_cost ELSE 0 END) shortage_cost
     FROM stock_movements m JOIN items i ON i.id = m.item_id LEFT JOIN uoms u ON u.id = i.base_uom_id
     WHERE m.bdate BETWEEN ? AND ? GROUP BY i.id ORDER BY sold_cost DESC`, from, to
  ));
}));

// Menu / recipe costing — food cost % per sellable item
router.get('/inventory/recipe-costing', INV, h(() => {
  const div = getSetting('vat_registered', '1') === '1' ? 1 + Number(getSetting('vat_rate', '12')) / 100 : 1;
  return round(db.all(
    `SELECT i.id, i.sku, i.name item, COALESCE(c.name,'') category, i.item_type type, i.price FROM items i LEFT JOIN categories c ON c.id = i.category_id
     WHERE i.active = 1 AND i.sellable = 1 AND i.item_type <> 'non_inventory' ORDER BY c.name, i.name`
  ).map((r) => {
    const cost = inv.unitCost(r.id);
    const net = r.price / div;
    return { ...r, price_net_of_vat: net, cost, margin: net - cost, food_cost_pct: net ? (cost / net) * 100 : null };
  }));
}));

// ------------------------------------------------------------------ petty cash
router.get('/petty-cash', requirePerm('pettycash.view', 'reports.finance'), h((req) => {
  const { from, to } = dateRange(req.query);
  const pc = gl.acct('petty_cash');
  const beginning = gl.accountBalance(pc, addDays(from, -1));
  const rows = db.all(
    `SELECT p.txn_date date, p.doc_no, p.txn_type type, p.source, p.payee, p.description, a.name account, p.or_no, p.amount, p.status, u.full_name recorded_by
     FROM petty_cash_txns p LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN users u ON u.id = p.created_by
     WHERE p.txn_date BETWEEN ? AND ? ORDER BY p.txn_date, p.id`, from, to
  );
  const byAccount = db.all(
    `SELECT a.name account, COUNT(*) cnt, SUM(p.amount) amount FROM petty_cash_txns p JOIN accounts a ON a.id = p.account_id
     WHERE p.status = 'posted' AND p.txn_type = 'expense' AND p.txn_date BETWEEN ? AND ? GROUP BY a.name ORDER BY amount DESC`, from, to
  );
  return { beginning, ending: gl.accountBalance(pc, to), rows: round(rows), by_account: round(byAccount) };
}));

// ------------------------------------------------------------------ financial statements
function balances(from, to) {
  return db.all(
    `SELECT a.id, a.code, a.name, a.type, a.subtype,
            COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit END),0) opening,
            COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.debit END),0) debit,
            COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.credit END),0) credit
     FROM accounts a LEFT JOIN journal_lines l ON l.account_id = a.id LEFT JOIN journal_entries e ON e.id = l.entry_id AND e.entry_date <= ?
     GROUP BY a.id ORDER BY a.code`, from || '0000-00-00', from || '0000-00-00', to, from || '0000-00-00', to, to
  ).map((a) => ({ ...a, closing: r2(a.opening + a.debit - a.credit) }));
}

router.get('/finance/trial-balance', FIN, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = balances(from, to).filter((a) => a.opening || a.debit || a.credit);
  return round(rows.map((a) => ({
    code: a.code, account: a.name, type: a.type, opening: a.opening, period_debit: a.debit, period_credit: a.credit,
    ending_debit: a.closing > 0 ? a.closing : 0, ending_credit: a.closing < 0 ? -a.closing : 0,
  })));
}));

router.get('/finance/income-statement', FIN, h((req) => {
  const { from, to } = dateRange(req.query);
  const rows = balances(from, to).map((a) => ({ ...a, period: r2(a.debit - a.credit) }));
  const inc = rows.filter((a) => a.type === 'income' && a.period).map((a) => ({ code: a.code, name: a.name, amount: -a.period, subtype: a.subtype }));
  const cogs = rows.filter((a) => a.type === 'expense' && a.subtype === 'cogs' && a.period).map((a) => ({ code: a.code, name: a.name, amount: a.period }));
  const opex = rows.filter((a) => a.type === 'expense' && a.subtype !== 'cogs' && a.period).map((a) => ({ code: a.code, name: a.name, amount: a.period }));
  const sum = (l) => r2(l.reduce((s, x) => s + x.amount, 0));
  const revenue = sum(inc); const totalCogs = sum(cogs); const totalOpex = sum(opex);
  return { from, to, income: inc, cogs, opex, revenue, total_cogs: totalCogs, gross_profit: r2(revenue - totalCogs), total_opex: totalOpex, net_income: r2(revenue - totalCogs - totalOpex) };
}));

router.get('/finance/balance-sheet', FIN, h((req) => {
  const to = req.query.to || today();
  const rows = balances(null, to);
  const pick = (type, sign) => rows.filter((a) => a.type === type && a.closing).map((a) => ({ code: a.code, name: a.name, amount: r2(sign * a.closing) }));
  const assets = pick('asset', 1); const liabilities = pick('liability', -1); const equity = pick('equity', -1);
  const netIncome = r2(-rows.filter((a) => a.type === 'income' || a.type === 'expense').reduce((s, a) => s + a.closing, 0));
  equity.push({ code: '', name: 'Net Income (cumulative, unclosed)', amount: netIncome });
  const sum = (l) => r2(l.reduce((s, x) => s + x.amount, 0));
  return { as_of: to, assets, liabilities, equity, total_assets: sum(assets), total_liabilities: sum(liabilities), total_equity: sum(equity), check: r2(sum(assets) - sum(liabilities) - sum(equity)) };
}));

router.get('/finance/general-ledger', FIN, h((req) => {
  const { from, to } = dateRange(req.query);
  const accountId = req.query.account_id;
  if (!accountId) throw bad('Select an account');
  const beginning = gl.accountBalance(accountId, addDays(from, -1));
  const lines = db.all(
    `SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, COALESCE(l.memo, e.memo) description, l.debit, l.credit
     FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id`,
    accountId, from, to
  );
  let bal = beginning;
  const rows = [{ date: from, entry_no: '', description: 'Beginning balance', debit: null, credit: null, balance: beginning }];
  for (const l of lines) { bal += l.debit - l.credit; rows.push({ ...l, balance: r2(bal) }); }
  return round(rows);
}));

router.get('/finance/journal', FIN, h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, e.memo, a.code, a.name account, l.debit, l.credit, l.memo line_memo
     FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN accounts a ON a.id = l.account_id
     WHERE e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id, l.debit DESC`, from, to
  ));
}));

function aging(table, partyTable, partyField, dateField, asOf) {
  const rows = db.all(
    `SELECT x.*, p.name party FROM ${table} x JOIN ${partyTable} p ON p.id = x.${partyField}
     WHERE x.status IN ('open','partial') AND x.${dateField} <= ?`, asOf
  );
  const byParty = new Map();
  for (const r of rows) {
    const bal = r2(r.amount - r.paid_amount);
    const days = Math.floor((new Date(asOf) - new Date(r.due_date || r[dateField])) / 86400000);
    const bucket = days <= 0 ? 'current' : days <= 30 ? 'd1_30' : days <= 60 ? 'd31_60' : days <= 90 ? 'd61_90' : 'over_90';
    const p = byParty.get(r.party) || { party: r.party, current: 0, d1_30: 0, d31_60: 0, d61_90: 0, over_90: 0, total: 0 };
    p[bucket] += bal; p.total += bal;
    byParty.set(r.party, p);
  }
  return round([...byParty.values()].sort((a, b) => b.total - a.total));
}
router.get('/finance/ap-aging', requirePerm('reports.finance', 'finance.ap'), h((req) => aging('ap_bills', 'suppliers', 'supplier_id', 'bill_date', req.query.to || today())));
router.get('/finance/ar-aging', requirePerm('reports.finance', 'finance.ar'), h((req) => aging('ar_invoices', 'customers', 'customer_id', 'inv_date', req.query.to || today())));

router.get('/finance/bank-register', requirePerm('reports.finance', 'finance.banks'), h((req) => {
  const { from, to } = dateRange(req.query);
  const bank = db.get('SELECT * FROM bank_accounts WHERE id = ?', req.query.bank_account_id);
  if (!bank) throw bad('Select a bank account');
  const beginning = gl.accountBalance(bank.gl_account_id, addDays(from, -1));
  const lines = db.all(
    `SELECT e.entry_date date, e.entry_no, e.source_type source, e.ref_no, e.memo description, l.debit money_in, l.credit money_out
     FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? ORDER BY e.entry_date, e.id`,
    bank.gl_account_id, from, to
  );
  let bal = beginning;
  const rows = [{ date: from, description: 'Beginning balance', money_in: null, money_out: null, balance: beginning }];
  for (const l of lines) { bal += l.money_in - l.money_out; rows.push({ ...l, balance: r2(bal) }); }
  return round(rows);
}));

router.get('/finance/cash-advances', requirePerm('reports.finance', 'ca.manage'), h((req) => {
  const { from, to } = dateRange(req.query);
  return round(db.all(
    `SELECT c.doc_no, c.request_date, e.emp_no, e.full_name employee, c.amount, c.status, c.release_method, c.release_date, au.full_name approved_by,
            COALESCE((SELECT SUM(amount) FROM ca_repayments r WHERE r.advance_id = c.id AND r.status = 'posted'),0) repaid, c.balance, c.reason
     FROM cash_advances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users au ON au.id = c.approved_by
     WHERE c.request_date BETWEEN ? AND ? ORDER BY c.request_date, c.id`, from, to
  ));
}));

// ------------------------------------------------------------------ Excel export
// Body: { filename, title, subtitle, columns: [{key,label,type}], rows: [...], totals: {key: value} }
router.post('/export/xlsx', async (req, res, next) => {
  try {
    const { filename = 'report', title, subtitle, columns = [], rows = [], totals } = req.body || {};
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Mark5 Restaurant Suite';
    const ws = wb.addWorksheet(String(title || 'Report').slice(0, 31).replace(/[\\/?*[\]:]/g, '-'));
    const ncol = Math.max(columns.length, 1);
    const biz = getSetting('business_name', '');
    let rowIdx = 1;
    for (const [text, size] of [[biz, 14], [title, 12], [subtitle, 10]]) {
      if (!text) continue;
      ws.mergeCells(rowIdx, 1, rowIdx, ncol);
      const c = ws.getCell(rowIdx, 1);
      c.value = text; c.font = { bold: size > 10, size };
      rowIdx++;
    }
    rowIdx++;
    const header = ws.getRow(rowIdx);
    columns.forEach((col, i) => {
      const c = header.getCell(i + 1);
      c.value = col.label || col.key;
      c.font = { bold: true, color: { argb: 'FFFFFFFF' } };
      c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1F2937' } };
      c.alignment = { vertical: 'middle', horizontal: ['money', 'number', 'qty', 'percent'].includes(col.type) ? 'right' : 'left' };
    });
    const fmt = { money: '#,##0.00;[Red]-#,##0.00', number: '#,##0', qty: '#,##0.####', percent: '0.00"%"' };
    const writeRow = (r, bold) => {
      rowIdx++;
      const row = ws.getRow(rowIdx);
      columns.forEach((col, i) => {
        const c = row.getCell(i + 1);
        let v = r[col.key];
        if (['money', 'number', 'qty', 'percent'].includes(col.type) && v !== null && v !== undefined && v !== '') v = Number(v);
        c.value = v === undefined ? null : v;
        if (fmt[col.type]) c.numFmt = fmt[col.type];
        if (bold || r._bold) c.font = { bold: true };
      });
      if (r._indent) row.getCell(1).alignment = { indent: r._indent };
    };
    for (const r of rows) writeRow(r);
    if (totals) writeRow(totals, true);
    columns.forEach((col, i) => {
      let w = String(col.label || col.key).length;
      for (const r of rows.slice(0, 500)) w = Math.max(w, String(r[col.key] ?? '').length);
      ws.getColumn(i + 1).width = Math.min(Math.max(w + 2, 8), 60);
    });
    ws.views = [{ state: 'frozen', ySplit: rowIdx - rows.length - (totals ? 1 : 0) }];
    const safe = String(filename).replace(/[^\w.-]+/g, '_');
    res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    res.setHeader('Content-Disposition', `attachment; filename="${safe}.xlsx"`);
    await wb.xlsx.write(res);
    res.end();
  } catch (e) {
    next(e);
  }
});

module.exports = router;
