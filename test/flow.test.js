'use strict';
// End-to-end business flow test: POS -> inventory -> GL -> reports -> backup.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'mark5-test-'));
process.env.DATA_DIR = dir;
process.env.TZ = 'Asia/Manila';
const app = require('../server/index.js');

let server; let base; let token;
async function api(method, url, body, tok = token) {
  const res = await fetch(base + '/api' + url, {
    method, headers: { 'Content-Type': 'application/json', ...(tok ? { Authorization: 'Bearer ' + tok } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const ct = res.headers.get('content-type') || '';
  const data = ct.includes('json') ? await res.json() : await res.arrayBuffer();
  if (!res.ok) { const e = new Error(data.error || res.status); e.status = res.status; throw e; }
  return data;
}
const item = async (name) => {
  const rows = await api('GET', '/inventory/items?q=' + encodeURIComponent(name));
  return rows.find((r) => r.name.startsWith(name)) || rows[0];
};
const tb = async () => {
  const rows = await api('GET', '/reports/finance/trial-balance?from=2000-01-01&to=2100-01-01');
  const d = rows.reduce((s, r) => s + r.ending_debit, 0);
  const c = rows.reduce((s, r) => s + r.ending_credit, 0);
  return { d: Math.round(d * 100) / 100, c: Math.round(c * 100) / 100, rows };
};

test.before(async () => {
  await new Promise((r) => { server = app.listen(0, r); });
  base = `http://127.0.0.1:${server.address().port}`;
  token = (await api('POST', '/auth/login', { username: 'admin', password: 'admin123' })).token;
});
test.after(() => { server.close(); fs.rmSync(dir, { recursive: true, force: true }); });

test('rejects bad login and unauthenticated access', async () => {
  await assert.rejects(api('POST', '/auth/login', { username: 'admin', password: 'x' }), /Invalid/);
  await assert.rejects(api('GET', '/inventory/items', null, null), (e) => e.status === 401);
});

test('stock in on credit creates inventory, AP and balanced GL', async () => {
  const chicken = await item('Chicken (whole');
  const rice = await item('Rice');
  const coke = await item('Coke');
  const kg = chicken.base_uom_id;
  const units = await api('GET', `/inventory/items/${rice.id}/units`);
  const sack = units.find((u) => u.abbr === 'sack');
  assert.ok(sack, 'rice has sack unit');
  const suppliers = await api('GET', '/finance/suppliers');
  const doc = await api('POST', '/inventory/docs', {
    doc_type: 'RECEIVE', supplier_id: suppliers[0].id, invoice_no: 'SI-1001', payment_mode: 'credit', post: true,
    lines: [
      { item_id: chicken.id, qty: 20, uom_id: kg, line_total: 4000 },
      { item_id: rice.id, qty: 2, uom_id: sack.uom_id, line_total: 5500 },
      { item_id: coke.id, qty: 48, line_total: 1824 },
    ],
  });
  assert.equal(doc.status, 'posted');
  const rice2 = await item('Rice');
  assert.equal(rice2.stock_qty, 100); // 2 sacks x 50kg
  assert.equal(rice2.avg_cost, 55);
  const bills = await api('GET', '/finance/ap/bills?status=unpaid');
  assert.equal(bills[0].amount, 11324);
  // due date = delivery date + supplier terms (15 days), computed in local time
  const d0 = new Date(doc.doc_date + 'T00:00:00'); d0.setDate(d0.getDate() + suppliers[0].terms_days);
  const pad = (n) => String(n).padStart(2, '0');
  assert.equal(bills[0].due_date, `${d0.getFullYear()}-${pad(d0.getMonth() + 1)}-${pad(d0.getDate())}`);
  const { d, c } = await tb();
  assert.equal(d, c);
});

let sessionId;
test('open day, sell with SC discount, split payment, recipe deduction', async () => {
  const s = await api('POST', '/pos/sessions/open', { opening_cash: 2000 });
  sessionId = s.id;
  await assert.rejects(api('POST', '/pos/sessions/open', { opening_cash: 1 }), /already open/);
  const tables = await api('GET', '/pos/tables');
  const t = await api('POST', '/pos/tickets', { table_id: tables.tables[0].id, pax: 2 });
  const adoboMeal = await item('Adobo Rice Meal');
  const coke = await item('Coke');
  await api('POST', `/pos/tickets/${t.id}/items`, { item_id: adoboMeal.id, qty: 2 });
  let tk = await api('POST', `/pos/tickets/${t.id}/items`, { item_id: coke.id, qty: 2 });
  assert.equal(tk.subtotal, 2 * 199 + 2 * 65);
  await assert.rejects(api('POST', `/pos/tickets/${t.id}/discount`, { discount_type: 'sc', sc_count: 1, sc_details: [] }), /ID number/);
  tk = await api('POST', `/pos/tickets/${t.id}/discount`, { discount_type: 'sc', sc_count: 1, sc_details: [{ name: 'Lola Nena', id_no: 'SC-123' }] });
  // gross 528, SC share = 264 -> net of VAT 235.71 -> 20% = 47.14; vatable 264 -> 235.71 + 28.29 VAT
  assert.equal(tk.vat_exempt_sales, 235.71);
  assert.equal(tk.discount_amount, 47.14);
  assert.equal(tk.vat_amount, 28.29);
  assert.equal(tk.total, 452.57);
  await assert.rejects(api('POST', `/pos/tickets/${t.id}/pay`, { payments: [{ method: 'cash', amount: 100 }] }), /short/);
  const paid = await api('POST', `/pos/tickets/${t.id}/pay`, { payments: [{ method: 'card', amount: 200, reference: 'APP123' }, { method: 'cash', amount: 300 }] });
  assert.equal(paid.status, 'paid');
  assert.equal(paid.change_amount, 47.43);
  assert.ok(paid.receipt_no.startsWith('OR-'));
  const chicken = await item('Chicken (whole');
  assert.equal(chicken.stock_qty, 20 - 0.5); // 250g per adobo x2
  const coke2 = await item('Coke');
  assert.equal(coke2.stock_qty, 46);
  const { d, c } = await tb();
  assert.equal(d, c);
});

test('split ticket, move table, void item with manager PIN, void receipt', async () => {
  const tables = await api('GET', '/pos/tables');
  const sisig = await item('Sizzling Pork Sisig');
  const water = await item('Bottled Water');
  const t = await api('POST', '/pos/tickets', { table_id: tables.tables[1].id, pax: 2 });
  await api('POST', `/pos/tickets/${t.id}/items`, { item_id: sisig.id, qty: 2 });
  let tk = await api('POST', `/pos/tickets/${t.id}/items`, { item_id: water.id, qty: 2 });
  await api('POST', `/pos/tickets/${t.id}/send`);
  const sisigLine = tk.items.find((i) => i.item_id === sisig.id);
  const split = await api('POST', `/pos/tickets/${t.id}/split`, { lines: [{ id: sisigLine.id, qty: 1 }] });
  assert.equal(split.source.subtotal, 225 + 60);
  assert.equal(split.target.subtotal, 225);
  const moved = await api('POST', `/pos/tickets/${split.target.id}/move`, { table_id: tables.tables[2].id });
  assert.equal(moved.table_id, tables.tables[2].id);

  // cashier without void permission needs a manager PIN
  const roles = await api('GET', '/roles');
  const cashierRole = roles.find((r) => r.name === 'Cashier');
  await api('POST', '/users', { username: 'cashier1', full_name: 'Cashier One', password: 'secret1', role_id: cashierRole.id });
  const ctok = (await api('POST', '/auth/login', { username: 'cashier1', password: 'secret1' })).token;
  const waterLine = split.source.items.find((i) => i.item_id === water.id);
  await assert.rejects(api('POST', `/pos/tickets/${t.id}/items/${waterLine.id}/void`, { reason: 'wrong order' }, ctok), /authorization/i);
  tk = await api('POST', `/pos/tickets/${t.id}/items/${waterLine.id}/void`, { reason: 'wrong order', pin: '1234' }, ctok);
  assert.equal(tk.subtotal, 225);
  await assert.rejects(api('GET', '/finance/journals', null, ctok), (e) => e.status === 403);

  const paid = await api('POST', `/pos/tickets/${split.target.id}/pay`, { payments: [{ method: 'gcash', amount: 225, reference: 'G1' }] }, ctok);
  const before = (await item('Maskara')).stock_qty;
  await assert.rejects(api('POST', `/pos/tickets/${paid.id}/void`, { reason: 'test' }, ctok), /authorization/i);
  const v = await api('POST', `/pos/tickets/${paid.id}/void`, { reason: 'customer complaint', pin: '1234' }, ctok);
  assert.equal(v.status, 'void');
  assert.equal((await item('Maskara')).stock_qty, before + 0.2);
  await api('POST', `/pos/tickets/${t.id}/pay`, { payments: [{ method: 'cash', amount: 1000 }] }, ctok);
  const { d, c } = await tb();
  assert.equal(d, c);
});

test('petty cash from drawer affects expected cash; close day computes variance', async () => {
  const accts = await api('GET', '/finance/accounts');
  const lpg = accts.find((a) => a.name.includes('LPG'));
  await api('POST', '/finance/petty-cash', { txn_type: 'expense', source: 'drawer', amount: 150, account_id: lpg.id, description: 'LPG refill', payee: 'Petron' });
  const x = await api('GET', '/pos/sessions/current/xreading');
  // opening 2000 + cash (452.57-200=252.57) + (225 sisig) - 150 payout
  assert.equal(x.cash.cash_sales, 477.57);
  assert.equal(x.cash.expected, 2327.57);
  const z = await api('POST', '/pos/sessions/current/close', { denominations: { 1000: 2, 200: 1, 100: 1, 20: 1, 5: 1, 1: 2 } });
  assert.equal(z.cash.counted, 2327);
  assert.equal(z.cash.variance, -0.57);
  assert.equal(z.session.status, 'closed');
  assert.equal(z.voided.cnt, 1);
  const { d, c, rows } = await tb();
  assert.equal(d, c);
  const short = rows.find((r) => r.account.startsWith('Cash Short'));
  assert.equal(short.ending_debit, 0.57);
});

test('wastage, issuance and count session post to GL', async () => {
  const egg = await item('Egg');
  const chicken = await item('Chicken (whole');
  await api('POST', '/inventory/docs', { doc_type: 'WASTE', reason: 'spoilage', post: true, lines: [{ item_id: chicken.id, qty: 1 }] });
  const accts = await api('GET', '/finance/accounts');
  await api('POST', '/inventory/docs', { doc_type: 'ISSUE', issued_to: 'Staff meal', expense_account_id: accts.find((a) => a.name === 'Staff Meals').id, post: true, lines: [{ item_id: chicken.id, qty: 0.5 }] });
  assert.equal((await item('Chicken (whole')).stock_qty, 20 - 0.5 - 1 - 0.5);
  const cnt = await api('POST', '/inventory/counts', {});
  const line = cnt.lines.find((l) => l.item_id === chicken.id);
  await api('PUT', `/inventory/counts/${cnt.id}`, { lines: [{ id: line.id, counted_qty: 17.5 }] });
  const posted = await api('POST', `/inventory/counts/${cnt.id}/post`);
  assert.equal(posted.status, 'posted');
  assert.equal((await item('Chicken (whole')).stock_qty, 17.5);
  assert.ok(egg);
  const wastage = await api('GET', '/reports/inventory/wastage?from=2000-01-01&to=2100-01-01');
  assert.equal(wastage.length, 1);
  const { d, c } = await tb();
  assert.equal(d, c);
});

test('AP payment, AR invoice & collection, bank, cash advance approval hits GL', async () => {
  const bank = await api('POST', '/finance/banks', { bank_name: 'BDO', account_no: '001234567890', opening_balance: 50000 });
  const bill = (await api('GET', '/finance/ap/bills?status=unpaid'))[0];
  await api('POST', `/finance/ap/bills/${bill.id}/pay`, { amount: 5000, method: 'bank', bank_account_id: bank.id });
  assert.equal((await api('GET', `/finance/ap/bills/${bill.id}`)).status, 'partial');
  const cust = (await api('GET', '/finance/customers'))[0];
  const inv = await api('POST', '/finance/ar/invoices', { customer_id: cust.id, inv_date: '2026-01-05', amount: 12000, description: 'Catering' });
  await api('POST', `/finance/ar/invoices/${inv.id}/collect`, { amount: 12000, method: 'bank', bank_account_id: bank.id });
  const accts = await api('GET', '/finance/accounts');
  const coh = accts.find((a) => a.system_key === 'cash_on_hand');
  await api('POST', '/finance/bank-txns', { bank_account_id: bank.id, txn_type: 'deposit', amount: 1000, counter_account_id: coh.id, txn_date: '2026-01-06' });
  const emp = (await api('GET', '/finance/employees'))[0];
  const ca = await api('POST', '/finance/cash-advances', { employee_id: emp.id, amount: 3000, reason: 'Tuition' });
  assert.equal(ca.status, 'pending');
  const appr = await api('POST', `/finance/cash-advances/${ca.id}/approve`, { release_method: 'bank', bank_account_id: bank.id });
  assert.equal(appr.status, 'approved');
  assert.ok(appr.journal_entry_id);
  await api('POST', `/finance/cash-advances/${ca.id}/repay`, { amount: 1000, method: 'payroll' });
  const banks = await api('GET', '/finance/banks');
  assert.equal(banks[0].balance, 50000 - 5000 + 12000 + 1000 - 3000);
  const { d, c, rows } = await tb();
  assert.equal(d, c);
  assert.equal(rows.find((r) => r.account === 'Advances to Employees').ending_debit, 2000);
  const bs = await api('GET', '/reports/finance/balance-sheet?to=2100-01-01');
  assert.equal(bs.check, 0);
  const is = await api('GET', '/reports/finance/income-statement?from=2000-01-01&to=2100-01-01');
  assert.ok(is.revenue > 0);
});

test('reports, dashboard and excel export', async () => {
  const q = '?from=2000-01-01&to=2100-01-01';
  const dash = await api('GET', '/reports/dashboard' + q);
  assert.ok(dash.kpi.receipts >= 2);
  for (const r of ['sales/daily', 'sales/items', 'sales/categories', 'sales/receipts', 'sales/payments', 'sales/hourly', 'sales/cashiers', 'sales/voids', 'sales/discounts', 'sales/eod',
    'inventory/onhand', 'inventory/reorder', 'inventory/movement', 'inventory/receiving', 'inventory/issuance', 'inventory/counts', 'inventory/usage', 'inventory/recipe-costing',
    'petty-cash', 'finance/journal', 'finance/ap-aging', 'finance/ar-aging', 'finance/cash-advances']) {
    await api('GET', `/reports/${r}${q}`);
  }
  const disc = await api('GET', '/reports/sales/discounts' + q);
  assert.equal(disc[0].id_numbers, 'SC-123');
  const xlsx = await api('POST', '/reports/export/xlsx', { filename: 'test', title: 'Test', columns: [{ key: 'a', label: 'A', type: 'money' }], rows: [{ a: 1.5 }] });
  assert.equal(Buffer.from(xlsx).slice(0, 2).toString(), 'PK');
});

test('backup and restore', async () => {
  const b = await api('POST', '/backups');
  assert.ok(b.name.endsWith('.db'));
  await api('POST', '/inventory/categories', { name: 'Temp Category' });
  const r = await api('POST', `/backups/${b.name}/restore`);
  assert.ok(r.safety_backup);
  token = (await api('POST', '/auth/login', { username: 'admin', password: 'admin123' })).token;
  const cats = await api('GET', '/inventory/categories');
  assert.ok(!cats.some((c) => c.name === 'Temp Category'));
});
