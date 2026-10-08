'use strict';
const express = require('express');
const db = require('../db');
const { h, bad, notFound, now, today, r2, num, audit, nextNo, required, isDate, addDays } = require('../util');
const { requirePerm, assertPerm, can, authorize } = require('../auth');
const gl = require('../gl');

const router = express.Router();

// ------------------------------------------------------------------ chart of accounts
router.get('/accounts', requirePerm('finance.view', 'finance.accounts', 'pettycash.manage', 'pos.petty_cash', 'inventory.issue', 'finance.ap', 'finance.ar', 'finance.banks', 'finance.journal'), h((req) => {
  const rows = db.all(
    `SELECT a.*, COALESCE(SUM(l.debit),0) total_debit, COALESCE(SUM(l.credit),0) total_credit
     FROM accounts a LEFT JOIN journal_lines l ON l.account_id = a.id GROUP BY a.id ORDER BY a.code`
  );
  return rows.map((a) => ({ ...a, balance: r2(a.total_debit - a.total_credit) }));
}));
router.post('/accounts', requirePerm('finance.accounts'), h((req) => {
  required(req.body, 'code', 'name', 'type');
  if (db.get('SELECT id FROM accounts WHERE code = ?', req.body.code)) throw bad('Account code already exists');
  const id = db.insert('accounts', { code: req.body.code, name: req.body.name, type: req.body.type, subtype: req.body.subtype || null, description: req.body.description || null, active: 1, is_system: 0 });
  audit(req.user.id, 'create', 'account', id, req.body);
  return { id };
}));
router.put('/accounts/:id', requirePerm('finance.accounts'), h((req) => {
  const a = db.get('SELECT * FROM accounts WHERE id = ?', req.params.id);
  if (!a) throw notFound('Account');
  const hasTx = db.get('SELECT id FROM journal_lines WHERE account_id = ? LIMIT 1', a.id);
  if (req.body.type && req.body.type !== a.type && (a.is_system || hasTx)) throw bad('Cannot change the type of a system account or an account with transactions');
  if (req.body.code && req.body.code !== a.code && db.get('SELECT id FROM accounts WHERE code = ? AND id <> ?', req.body.code, a.id)) throw bad('Account code already exists');
  db.update('accounts', a.id, {
    code: req.body.code, name: req.body.name, type: req.body.type, subtype: req.body.subtype, description: req.body.description,
    active: req.body.active === undefined ? undefined : req.body.active ? 1 : 0,
  });
  audit(req.user.id, 'update', 'account', a.id, req.body);
  return { ok: true };
}));
router.delete('/accounts/:id', requirePerm('finance.accounts'), h((req) => {
  const a = db.get('SELECT * FROM accounts WHERE id = ?', req.params.id);
  if (!a) throw notFound('Account');
  if (a.is_system) throw bad('System accounts are used by automatic postings and cannot be deleted');
  if (db.get('SELECT id FROM journal_lines WHERE account_id = ? LIMIT 1', a.id)) throw bad('Account has transactions; deactivate it instead');
  if (db.get('SELECT id FROM bank_accounts WHERE gl_account_id = ?', a.id)) throw bad('Account is linked to a bank');
  db.run('DELETE FROM accounts WHERE id = ?', a.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ journal entries
function loadJE(id) {
  const je = db.get('SELECT e.*, u.full_name created_by_name FROM journal_entries e LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?', id);
  if (!je) throw notFound('Journal entry');
  je.lines = db.all(
    `SELECT l.*, a.code, a.name account_name FROM journal_lines l JOIN accounts a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.debit DESC, l.id`, id
  );
  return je;
}
router.get('/journals', requirePerm('finance.view', 'finance.journal'), h((req) => {
  const { from, to, source_type, q } = req.query;
  const where = ['e.entry_date BETWEEN ? AND ?'];
  const p = [from || '0000-00-00', to || '9999-99-99'];
  if (source_type) { where.push('e.source_type = ?'); p.push(source_type); }
  if (q) { where.push('(e.memo LIKE ? OR e.entry_no LIKE ? OR e.ref_no LIKE ?)'); p.push(`%${q}%`, `%${q}%`, `%${q}%`); }
  return db.all(
    `SELECT e.*, u.full_name created_by_name, (SELECT SUM(debit) FROM journal_lines l WHERE l.entry_id = e.id) amount
     FROM journal_entries e LEFT JOIN users u ON u.id = e.created_by WHERE ${where.join(' AND ')} ORDER BY e.entry_date DESC, e.id DESC LIMIT 3000`, ...p
  );
}));
router.get('/journals/:id', requirePerm('finance.view', 'finance.journal'), h((req) => loadJE(req.params.id)));
router.post('/journals', requirePerm('finance.journal'), h((req) => {
  required(req.body, 'entry_date');
  const id = gl.postJE({
    date: req.body.entry_date, memo: req.body.memo, ref_no: req.body.ref_no, source_type: 'manual', user_id: req.user.id,
    lines: (req.body.lines || []).map((l) => ({ account_id: l.account_id, debit: num(l.debit), credit: num(l.credit), memo: l.memo })),
  });
  if (!id) throw bad('Journal entry has no amounts');
  audit(req.user.id, 'create', 'journal', id);
  return loadJE(id);
}));
router.post('/journals/:id/void', requirePerm('finance.journal'), h((req) => {
  const je = loadJE(req.params.id);
  if (je.source_type !== 'manual') throw bad('System-generated entries must be voided from their source document (receipt, delivery, etc.)');
  if (je.reversal_of) throw bad('This entry is itself a reversal');
  const rev = gl.reverseJE(je.id, { date: isDate(req.body.date) ? req.body.date : today(), user_id: req.user.id, memo: req.body.reason ? `Void ${je.entry_no}: ${req.body.reason}` : undefined });
  audit(req.user.id, 'void', 'journal', je.id, req.body.reason);
  return loadJE(rev);
}));

// ------------------------------------------------------------------ banks
router.get('/banks', requirePerm('finance.banks', 'finance.view', 'finance.ap', 'finance.ar', 'pettycash.manage', 'inventory.receive', 'ca.approve', 'ca.manage'), h(() =>
  db.all('SELECT b.*, a.code gl_code, a.name gl_name FROM bank_accounts b JOIN accounts a ON a.id = b.gl_account_id ORDER BY b.bank_name')
    .map((b) => ({ ...b, balance: gl.accountBalance(b.gl_account_id) }))
));
router.post('/banks', requirePerm('finance.banks'), h((req) => {
  required(req.body, 'bank_name');
  const { bank_name, account_name, account_no, account_type, notes, opening_balance, opening_date } = req.body;
  const id = db.tx(() => {
    let n = 1;
    while (db.get('SELECT id FROM accounts WHERE code = ?', `103${n}`)) n++;
    const glId = db.insert('accounts', {
      code: `103${n}`, name: `Cash in Bank - ${bank_name}${account_no ? ' ' + String(account_no).slice(-4) : ''}`, type: 'asset', subtype: 'bank', is_system: 0, active: 1,
    });
    const bid = db.insert('bank_accounts', { bank_name, account_name, account_no, account_type, gl_account_id: glId, active: 1, notes });
    if (num(opening_balance) !== 0) {
      gl.postJE({
        date: isDate(opening_date) ? opening_date : today(), memo: `Opening balance - ${bank_name}`, source_type: 'bank_opening', source_id: bid, user_id: req.user.id,
        lines: [{ account_id: glId, debit: num(opening_balance), bank_account_id: bid }, { key: 'opening_equity', credit: num(opening_balance) }],
      });
    }
    return bid;
  });
  audit(req.user.id, 'create', 'bank', id, req.body);
  return { id };
}));
router.put('/banks/:id', requirePerm('finance.banks'), h((req) => {
  const b = db.get('SELECT * FROM bank_accounts WHERE id = ?', req.params.id);
  if (!b) throw notFound('Bank');
  const { bank_name, account_name, account_no, account_type, notes, active } = req.body;
  db.update('bank_accounts', b.id, { bank_name, account_name, account_no, account_type, notes, active: active === undefined ? undefined : active ? 1 : 0 });
  if (bank_name) db.update('accounts', b.gl_account_id, { name: `Cash in Bank - ${bank_name}${account_no || b.account_no ? ' ' + String(account_no || b.account_no).slice(-4) : ''}` });
  return { ok: true };
}));

router.get('/bank-txns', requirePerm('finance.banks', 'finance.view'), h((req) => {
  const { from, to, bank_account_id } = req.query;
  return db.all(
    `SELECT t.*, b.bank_name, b.account_no, a.name counter_account_name, tb.bank_name transfer_bank_name, u.full_name created_by_name
     FROM bank_txns t JOIN bank_accounts b ON b.id = t.bank_account_id LEFT JOIN accounts a ON a.id = t.counter_account_id
     LEFT JOIN bank_accounts tb ON tb.id = t.transfer_bank_id LEFT JOIN users u ON u.id = t.created_by
     WHERE t.txn_date BETWEEN ? AND ? ${bank_account_id ? 'AND (t.bank_account_id = ? OR t.transfer_bank_id = ?)' : ''}
     ORDER BY t.txn_date DESC, t.id DESC`, from || '0000-00-00', to || '9999-99-99', ...(bank_account_id ? [bank_account_id, bank_account_id] : [])
  );
}));
router.post('/bank-txns', requirePerm('finance.banks'), h((req) => {
  required(req.body, 'bank_account_id', 'txn_type', 'amount', 'txn_date');
  const { bank_account_id, txn_type, counter_account_id, transfer_bank_id, reference, description, txn_date } = req.body;
  const amount = r2(req.body.amount);
  if (!(amount > 0)) throw bad('Amount must be greater than zero');
  const bank = db.get('SELECT * FROM bank_accounts WHERE id = ?', bank_account_id);
  if (!bank) throw notFound('Bank');
  let lines;
  let memo;
  if (txn_type === 'transfer') {
    const to = db.get('SELECT * FROM bank_accounts WHERE id = ?', transfer_bank_id);
    if (!to) throw bad('Select the destination bank');
    if (to.id === bank.id) throw bad('Source and destination bank must differ');
    memo = `Transfer ${bank.bank_name} → ${to.bank_name}`;
    lines = [{ account_id: to.gl_account_id, debit: amount, bank_account_id: to.id }, { account_id: bank.gl_account_id, credit: amount, bank_account_id: bank.id }];
  } else {
    if (!counter_account_id) throw bad(txn_type === 'deposit' ? 'Select where the money came from' : 'Select what the money was used for');
    const charges = r2(req.body.bank_charges);
    if (txn_type === 'deposit') {
      memo = `Deposit to ${bank.bank_name}`;
      // Optional bank/card charges deducted from a deposit (e.g. card settlement less MDR).
      lines = [
        { account_id: bank.gl_account_id, debit: r2(amount - charges), bank_account_id: bank.id },
        { key: 'bank_charges', debit: charges },
        { account_id: counter_account_id, credit: amount },
      ];
    } else {
      memo = `Withdrawal from ${bank.bank_name}`;
      lines = [
        { account_id: counter_account_id, debit: amount },
        { key: 'bank_charges', debit: charges },
        { account_id: bank.gl_account_id, credit: r2(amount + charges), bank_account_id: bank.id },
      ];
    }
  }
  const id = db.tx(() => {
    const tid = db.insert('bank_txns', {
      doc_no: nextNo('BT', 'BT'), bank_account_id: bank.id, txn_date, txn_type, amount, counter_account_id: txn_type === 'transfer' ? null : counter_account_id,
      transfer_bank_id: txn_type === 'transfer' ? transfer_bank_id : null, bank_charges: txn_type === 'transfer' ? 0 : r2(req.body.bank_charges),
      reference, description, status: 'posted', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({ date: txn_date, memo: description ? `${memo} - ${description}` : memo, source_type: 'bank', source_id: tid, ref_no: reference, user_id: req.user.id, lines });
    db.update('bank_txns', tid, { journal_entry_id: jeId });
    return tid;
  });
  audit(req.user.id, 'create', 'bank_txn', id, req.body);
  return { id };
}));
router.post('/bank-txns/:id/void', requirePerm('finance.banks'), h((req) => {
  const t = db.get('SELECT * FROM bank_txns WHERE id = ?', req.params.id);
  if (!t) throw notFound('Bank transaction');
  if (t.status !== 'posted') throw bad('Already void');
  db.tx(() => {
    gl.reverseJE(t.journal_entry_id, { date: today(), user_id: req.user.id });
    db.update('bank_txns', t.id, { status: 'void' });
  });
  audit(req.user.id, 'void', 'bank_txn', t.id, req.body.reason);
  return { ok: true };
}));

// ------------------------------------------------------------------ suppliers & customers
for (const [path, table] of [['suppliers', 'suppliers'], ['customers', 'customers']]) {
  const fields = table === 'suppliers'
    ? ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'terms_days', 'notes']
    : ['name', 'contact_person', 'phone', 'email', 'address', 'tin', 'terms_days', 'credit_limit', 'notes'];
  const pick = (b) => Object.fromEntries(fields.filter((f) => b[f] !== undefined).map((f) => [f, ['terms_days', 'credit_limit'].includes(f) ? num(b[f]) : b[f]]));
  router.get(`/${path}`, h((req) => {
    const balField = table === 'suppliers'
      ? "(SELECT COALESCE(SUM(amount - paid_amount),0) FROM ap_bills b WHERE b.supplier_id = x.id AND b.status IN ('open','partial'))"
      : "(SELECT COALESCE(SUM(amount - paid_amount),0) FROM ar_invoices b WHERE b.customer_id = x.id AND b.status IN ('open','partial'))";
    return db.all(`SELECT x.*, ${balField} balance FROM ${table} x ${req.query.all ? '' : 'WHERE x.active = 1'} ORDER BY x.name`);
  }));
  router.post(`/${path}`, requirePerm('partners.manage', 'inventory.receive', 'finance.ap', 'finance.ar'), h((req) => {
    required(req.body, 'name');
    return { id: db.insert(table, { ...pick(req.body), active: 1 }) };
  }));
  router.put(`/${path}/:id`, requirePerm('partners.manage'), h((req) => {
    db.update(table, req.params.id, { ...pick(req.body), active: req.body.active === undefined ? undefined : req.body.active ? 1 : 0 });
    return { ok: true };
  }));
  router.delete(`/${path}/:id`, requirePerm('partners.manage'), h((req) => {
    db.run(`UPDATE ${table} SET active = 0 WHERE id = ?`, req.params.id);
    return { ok: true };
  }));
}

// ------------------------------------------------------------------ accounts payable
function billStatus(amount, paid) {
  if (paid <= 0.001) return 'open';
  if (paid + 0.001 >= amount) return 'paid';
  return 'partial';
}
router.get('/ap/bills', requirePerm('finance.ap'), h((req) => {
  const { from, to, status, supplier_id } = req.query;
  const where = ['b.bill_date BETWEEN ? AND ?']; const p = [from || '0000-00-00', to || '9999-99-99'];
  if (status === 'unpaid') where.push("b.status IN ('open','partial')");
  else if (status) { where.push('b.status = ?'); p.push(status); }
  if (supplier_id) { where.push('b.supplier_id = ?'); p.push(supplier_id); }
  return db.all(
    `SELECT b.*, s.name supplier_name, a.name expense_account_name, ROUND(b.amount - b.paid_amount, 2) balance
     FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id LEFT JOIN accounts a ON a.id = b.expense_account_id
     WHERE ${where.join(' AND ')} ORDER BY b.bill_date DESC, b.id DESC`, ...p
  );
}));
router.get('/ap/bills/:id', requirePerm('finance.ap'), h((req) => {
  const b = db.get('SELECT b.*, s.name supplier_name FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id WHERE b.id = ?', req.params.id);
  if (!b) throw notFound('Bill');
  b.payments = db.all('SELECT p.*, bk.bank_name FROM ap_payments p LEFT JOIN bank_accounts bk ON bk.id = p.bank_account_id WHERE p.bill_id = ? ORDER BY p.pay_date', b.id);
  return b;
}));
router.post('/ap/bills', requirePerm('finance.ap'), h((req) => {
  required(req.body, 'supplier_id', 'bill_date', 'amount', 'expense_account_id');
  const amount = r2(req.body.amount);
  if (!(amount > 0)) throw bad('Amount must be greater than zero');
  const sup = db.get('SELECT * FROM suppliers WHERE id = ?', req.body.supplier_id);
  if (!sup) throw notFound('Supplier');
  const id = db.tx(() => {
    const bid = db.insert('ap_bills', {
      bill_no: nextNo('AP', 'AP'), supplier_id: sup.id, bill_date: req.body.bill_date, due_date: req.body.due_date || addDays(req.body.bill_date, sup.terms_days),
      ref_no: req.body.ref_no, description: req.body.description, amount, paid_amount: 0, status: 'open', expense_account_id: req.body.expense_account_id,
      source_type: 'manual', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({
      date: req.body.bill_date, memo: `Bill ${sup.name}${req.body.description ? ' - ' + req.body.description : ''}`, source_type: 'ap_bill', source_id: bid,
      ref_no: req.body.ref_no, user_id: req.user.id,
      lines: [{ account_id: req.body.expense_account_id, debit: amount }, { key: 'ap', credit: amount, party_type: 'supplier', party_id: sup.id }],
    });
    db.update('ap_bills', bid, { journal_entry_id: jeId });
    return bid;
  });
  audit(req.user.id, 'create', 'ap_bill', id, req.body);
  return { id };
}));
router.post('/ap/bills/:id/void', requirePerm('finance.ap'), h((req) => {
  const b = db.get('SELECT * FROM ap_bills WHERE id = ?', req.params.id);
  if (!b) throw notFound('Bill');
  if (b.source_type === 'inv_receive') throw bad('This payable came from a delivery. Void the delivery receipt instead.');
  if (b.paid_amount > 0) throw bad('Void the payments first');
  if (b.status === 'void') throw bad('Already void');
  db.tx(() => { gl.reverseJE(b.journal_entry_id, { date: today(), user_id: req.user.id }); db.update('ap_bills', b.id, { status: 'void' }); });
  audit(req.user.id, 'void', 'ap_bill', b.id, req.body.reason);
  return { ok: true };
}));
router.post('/ap/bills/:id/pay', requirePerm('finance.ap'), h((req) => {
  const b = db.get('SELECT b.*, s.name supplier_name FROM ap_bills b JOIN suppliers s ON s.id = b.supplier_id WHERE b.id = ?', req.params.id);
  if (!b) throw notFound('Bill');
  if (!['open', 'partial'].includes(b.status)) throw bad('Bill is not open');
  const amount = r2(req.body.amount);
  const balance = r2(b.amount - b.paid_amount);
  if (!(amount > 0) || amount > balance + 0.001) throw bad(`Amount must be between 0 and the balance of ${balance.toFixed(2)}`);
  const method = req.body.method || 'cash';
  const date = isDate(req.body.pay_date) ? req.body.pay_date : today();
  db.tx(() => {
    const pid = db.insert('ap_payments', {
      doc_no: nextNo('APV', 'PV'), bill_id: b.id, pay_date: date, amount, method, bank_account_id: method === 'bank' ? req.body.bank_account_id : null,
      reference: req.body.reference, status: 'posted', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({
      date, memo: `Payment to ${b.supplier_name} (${b.bill_no})`, source_type: 'ap_payment', source_id: pid, ref_no: req.body.reference, user_id: req.user.id,
      lines: [
        { key: 'ap', debit: amount, party_type: 'supplier', party_id: b.supplier_id },
        { account_id: gl.sourceAccount(method, req.body.bank_account_id), credit: amount, bank_account_id: method === 'bank' ? req.body.bank_account_id : null },
      ],
    });
    db.update('ap_payments', pid, { journal_entry_id: jeId });
    const paid = r2(b.paid_amount + amount);
    db.update('ap_bills', b.id, { paid_amount: paid, status: billStatus(b.amount, paid) });
  });
  audit(req.user.id, 'pay', 'ap_bill', b.id, { amount, method });
  return { ok: true };
}));
router.post('/ap/payments/:id/void', requirePerm('finance.ap'), h((req) => {
  const p = db.get('SELECT * FROM ap_payments WHERE id = ?', req.params.id);
  if (!p || p.status !== 'posted') throw bad('Payment not found or already void');
  db.tx(() => {
    gl.reverseJE(p.journal_entry_id, { date: today(), user_id: req.user.id });
    db.update('ap_payments', p.id, { status: 'void' });
    const b = db.get('SELECT * FROM ap_bills WHERE id = ?', p.bill_id);
    const paid = r2(b.paid_amount - p.amount);
    db.update('ap_bills', b.id, { paid_amount: paid, status: billStatus(b.amount, paid) });
  });
  audit(req.user.id, 'void', 'ap_payment', p.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ accounts receivable
router.get('/ar/invoices', requirePerm('finance.ar'), h((req) => {
  const { from, to, status, customer_id } = req.query;
  const where = ['i.inv_date BETWEEN ? AND ?']; const p = [from || '0000-00-00', to || '9999-99-99'];
  if (status === 'unpaid') where.push("i.status IN ('open','partial')");
  else if (status) { where.push('i.status = ?'); p.push(status); }
  if (customer_id) { where.push('i.customer_id = ?'); p.push(customer_id); }
  return db.all(
    `SELECT i.*, c.name customer_name, ROUND(i.amount - i.paid_amount, 2) balance FROM ar_invoices i JOIN customers c ON c.id = i.customer_id
     WHERE ${where.join(' AND ')} ORDER BY i.inv_date DESC, i.id DESC`, ...p
  );
}));
router.get('/ar/invoices/:id', requirePerm('finance.ar'), h((req) => {
  const i = db.get('SELECT i.*, c.name customer_name FROM ar_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?', req.params.id);
  if (!i) throw notFound('Invoice');
  i.receipts = db.all('SELECT r.*, b.bank_name FROM ar_receipts r LEFT JOIN bank_accounts b ON b.id = r.bank_account_id WHERE r.invoice_id = ? ORDER BY r.rcpt_date', i.id);
  return i;
}));
router.post('/ar/invoices', requirePerm('finance.ar'), h((req) => {
  required(req.body, 'customer_id', 'inv_date', 'amount');
  const amount = r2(req.body.amount);
  if (!(amount > 0)) throw bad('Amount must be greater than zero');
  const cust = db.get('SELECT * FROM customers WHERE id = ?', req.body.customer_id);
  if (!cust) throw notFound('Customer');
  const incomeId = req.body.income_account_id || gl.acct('sales');
  const id = db.tx(() => {
    const iid = db.insert('ar_invoices', {
      invoice_no: nextNo('AR', 'AR'), customer_id: cust.id, inv_date: req.body.inv_date, due_date: req.body.due_date || addDays(req.body.inv_date, cust.terms_days),
      ref_no: req.body.ref_no, description: req.body.description, amount, paid_amount: 0, status: 'open', income_account_id: incomeId,
      source_type: 'manual', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({
      date: req.body.inv_date, memo: `Invoice ${cust.name}${req.body.description ? ' - ' + req.body.description : ''}`, source_type: 'ar_invoice', source_id: iid,
      ref_no: req.body.ref_no, user_id: req.user.id,
      lines: [{ key: 'ar', debit: amount, party_type: 'customer', party_id: cust.id }, { account_id: incomeId, credit: amount }],
    });
    db.update('ar_invoices', iid, { journal_entry_id: jeId });
    return iid;
  });
  audit(req.user.id, 'create', 'ar_invoice', id, req.body);
  return { id };
}));
router.post('/ar/invoices/:id/void', requirePerm('finance.ar'), h((req) => {
  const i = db.get('SELECT * FROM ar_invoices WHERE id = ?', req.params.id);
  if (!i) throw notFound('Invoice');
  if (i.source_type === 'pos_sale') throw bad('This receivable came from a POS charge. Void the POS receipt instead.');
  if (i.paid_amount > 0) throw bad('Void the collections first');
  if (i.status === 'void') throw bad('Already void');
  db.tx(() => { gl.reverseJE(i.journal_entry_id, { date: today(), user_id: req.user.id }); db.update('ar_invoices', i.id, { status: 'void' }); });
  audit(req.user.id, 'void', 'ar_invoice', i.id, req.body.reason);
  return { ok: true };
}));
router.post('/ar/invoices/:id/collect', requirePerm('finance.ar'), h((req) => {
  const i = db.get('SELECT i.*, c.name customer_name FROM ar_invoices i JOIN customers c ON c.id = i.customer_id WHERE i.id = ?', req.params.id);
  if (!i) throw notFound('Invoice');
  if (!['open', 'partial'].includes(i.status)) throw bad('Invoice is not open');
  const amount = r2(req.body.amount);
  const balance = r2(i.amount - i.paid_amount);
  if (!(amount > 0) || amount > balance + 0.001) throw bad(`Amount must be between 0 and the balance of ${balance.toFixed(2)}`);
  const method = req.body.method || 'cash';
  const date = isDate(req.body.rcpt_date) ? req.body.rcpt_date : today();
  db.tx(() => {
    const rid = db.insert('ar_receipts', {
      doc_no: nextNo('CR', 'CR'), invoice_id: i.id, rcpt_date: date, amount, method, bank_account_id: method === 'bank' ? req.body.bank_account_id : null,
      reference: req.body.reference, status: 'posted', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({
      date, memo: `Collection from ${i.customer_name} (${i.invoice_no})`, source_type: 'ar_receipt', source_id: rid, ref_no: req.body.reference, user_id: req.user.id,
      lines: [
        { account_id: gl.sourceAccount(method, req.body.bank_account_id), debit: amount, bank_account_id: method === 'bank' ? req.body.bank_account_id : null },
        { key: 'ar', credit: amount, party_type: 'customer', party_id: i.customer_id },
      ],
    });
    db.update('ar_receipts', rid, { journal_entry_id: jeId });
    const paid = r2(i.paid_amount + amount);
    db.update('ar_invoices', i.id, { paid_amount: paid, status: billStatus(i.amount, paid) });
  });
  audit(req.user.id, 'collect', 'ar_invoice', i.id, { amount, method });
  return { ok: true };
}));
router.post('/ar/receipts/:id/void', requirePerm('finance.ar'), h((req) => {
  const r = db.get('SELECT * FROM ar_receipts WHERE id = ?', req.params.id);
  if (!r || r.status !== 'posted') throw bad('Collection not found or already void');
  db.tx(() => {
    gl.reverseJE(r.journal_entry_id, { date: today(), user_id: req.user.id });
    db.update('ar_receipts', r.id, { status: 'void' });
    const i = db.get('SELECT * FROM ar_invoices WHERE id = ?', r.invoice_id);
    const paid = r2(i.paid_amount - r.amount);
    db.update('ar_invoices', i.id, { paid_amount: paid, status: billStatus(i.amount, paid) });
  });
  audit(req.user.id, 'void', 'ar_receipt', r.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ employees
router.get('/employees', requirePerm('employees.manage', 'ca.request', 'ca.approve', 'ca.manage', 'admin.users'), h((req) => {
  const rows = db.all(
    `SELECT e.*, (SELECT COALESCE(SUM(balance),0) FROM cash_advances c WHERE c.employee_id = e.id AND c.status = 'approved') ca_balance
     FROM employees e ${req.query.all ? '' : 'WHERE e.active = 1'} ORDER BY e.full_name`
  );
  // Users who may only file for themselves only see their own employee record.
  if (!can(req.user, 'employees.manage') && !can(req.user, 'ca.approve') && !can(req.user, 'ca.manage') && !can(req.user, 'admin.users')) {
    return rows.filter((r) => r.id === req.user.employee_id);
  }
  return rows;
}));
const EMP_FIELDS = ['emp_no', 'full_name', 'position', 'department', 'phone', 'date_hired', 'notes'];
router.post('/employees', requirePerm('employees.manage'), h((req) => {
  required(req.body, 'full_name');
  const data = Object.fromEntries(EMP_FIELDS.map((f) => [f, req.body[f] || null]));
  if (!data.emp_no) data.emp_no = nextNo('EMP', 'EMP', 3);
  return { id: db.insert('employees', { ...data, active: 1 }) };
}));
router.put('/employees/:id', requirePerm('employees.manage'), h((req) => {
  const data = Object.fromEntries(EMP_FIELDS.filter((f) => req.body[f] !== undefined).map((f) => [f, req.body[f] || null]));
  if (req.body.active !== undefined) data.active = req.body.active ? 1 : 0;
  db.update('employees', req.params.id, data);
  return { ok: true };
}));

// ------------------------------------------------------------------ cash advances
function loadCA(id) {
  const c = db.get(
    `SELECT c.*, e.full_name employee_name, e.emp_no, ru.full_name requested_by_name, au.full_name approved_by_name, b.bank_name
     FROM cash_advances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users ru ON ru.id = c.requested_by
     LEFT JOIN users au ON au.id = c.approved_by LEFT JOIN bank_accounts b ON b.id = c.bank_account_id WHERE c.id = ?`, id
  );
  if (!c) throw notFound('Cash advance');
  c.repayments = db.all('SELECT r.*, u.full_name created_by_name FROM ca_repayments r LEFT JOIN users u ON u.id = r.created_by WHERE r.advance_id = ? ORDER BY r.pay_date, r.id', id);
  return c;
}
router.get('/cash-advances', requirePerm('ca.request', 'ca.approve', 'ca.manage'), h((req) => {
  const { from, to, status, employee_id } = req.query;
  const where = ['c.request_date BETWEEN ? AND ?']; const p = [from || '0000-00-00', to || '9999-99-99'];
  if (status) { where.push('c.status = ?'); p.push(status); }
  if (employee_id) { where.push('c.employee_id = ?'); p.push(employee_id); }
  if (!can(req.user, 'ca.approve') && !can(req.user, 'ca.manage')) {
    where.push('(c.requested_by = ? OR c.employee_id = ?)'); p.push(req.user.id, req.user.employee_id || -1);
  }
  return db.all(
    `SELECT c.*, e.full_name employee_name, e.emp_no, ru.full_name requested_by_name, au.full_name approved_by_name
     FROM cash_advances c JOIN employees e ON e.id = c.employee_id LEFT JOIN users ru ON ru.id = c.requested_by LEFT JOIN users au ON au.id = c.approved_by
     WHERE ${where.join(' AND ')} ORDER BY c.request_date DESC, c.id DESC`, ...p
  );
}));
router.get('/cash-advances/:id', requirePerm('ca.request', 'ca.approve', 'ca.manage'), h((req) => loadCA(req.params.id)));
router.post('/cash-advances', requirePerm('ca.request'), h((req) => {
  required(req.body, 'employee_id', 'amount');
  const amount = r2(req.body.amount);
  if (!(amount > 0)) throw bad('Amount must be greater than zero');
  const empId = Number(req.body.employee_id);
  if (!can(req.user, 'ca.approve') && !can(req.user, 'ca.manage') && !can(req.user, 'employees.manage') && empId !== req.user.employee_id) {
    throw bad('You can only request a cash advance for yourself. Ask the admin to link your user to your employee record.');
  }
  const id = db.insert('cash_advances', {
    doc_no: nextNo('CA', 'CA'), employee_id: empId, request_date: isDate(req.body.request_date) ? req.body.request_date : today(), amount,
    reason: req.body.reason, repayment_terms: req.body.repayment_terms, status: 'pending', requested_by: req.user.id, balance: 0, created_at: now(),
  });
  audit(req.user.id, 'request', 'cash_advance', id, { amount });
  return loadCA(id);
}));
router.post('/cash-advances/:id/approve', requirePerm('ca.approve'), h((req) => {
  const c = loadCA(req.params.id);
  if (c.status !== 'pending') throw bad('Only pending requests can be approved');
  const method = req.body.release_method || 'cash';
  if (!['cash', 'petty_cash', 'bank'].includes(method)) throw bad('Choose how the advance is released');
  const amount = req.body.amount !== undefined ? r2(req.body.amount) : c.amount;
  if (!(amount > 0)) throw bad('Approved amount must be greater than zero');
  const date = isDate(req.body.release_date) ? req.body.release_date : today();
  db.tx(() => {
    const jeId = gl.postJE({
      date, memo: `Cash advance ${c.doc_no} - ${c.employee_name}`, source_type: 'cash_advance', source_id: c.id, ref_no: c.doc_no, user_id: req.user.id,
      lines: [
        { key: 'emp_advances', debit: amount, party_type: 'employee', party_id: c.employee_id },
        { account_id: gl.sourceAccount(method, req.body.bank_account_id), credit: amount, bank_account_id: method === 'bank' ? req.body.bank_account_id : null },
      ],
    });
    db.update('cash_advances', c.id, {
      status: 'approved', amount, balance: amount, approved_by: req.user.id, approved_at: now(), release_method: method,
      bank_account_id: method === 'bank' ? req.body.bank_account_id : null, release_date: date, remarks: req.body.remarks || c.remarks, journal_entry_id: jeId,
    });
  });
  audit(req.user.id, 'approve', 'cash_advance', c.id, { amount, method });
  return loadCA(c.id);
}));
router.post('/cash-advances/:id/reject', requirePerm('ca.approve'), h((req) => {
  const c = loadCA(req.params.id);
  if (c.status !== 'pending') throw bad('Only pending requests can be rejected');
  db.update('cash_advances', c.id, { status: 'rejected', approved_by: req.user.id, approved_at: now(), remarks: req.body.remarks || null });
  audit(req.user.id, 'reject', 'cash_advance', c.id, req.body.remarks);
  return loadCA(c.id);
}));
router.post('/cash-advances/:id/cancel', requirePerm('ca.request'), h((req) => {
  const c = loadCA(req.params.id);
  if (c.status !== 'pending') throw bad('Only pending requests can be cancelled');
  if (c.requested_by !== req.user.id && !can(req.user, 'ca.approve')) throw bad('You can only cancel your own request');
  db.update('cash_advances', c.id, { status: 'cancelled' });
  return loadCA(c.id);
}));
router.post('/cash-advances/:id/repay', requirePerm('ca.manage'), h((req) => {
  const c = loadCA(req.params.id);
  if (c.status !== 'approved') throw bad('Advance is not outstanding');
  const amount = r2(req.body.amount);
  if (!(amount > 0) || amount > c.balance + 0.001) throw bad(`Amount must be between 0 and the balance of ${c.balance.toFixed(2)}`);
  const method = req.body.method || 'payroll';
  const date = isDate(req.body.pay_date) ? req.body.pay_date : today();
  db.tx(() => {
    const rid = db.insert('ca_repayments', {
      doc_no: nextNo('CAR', 'CAR'), advance_id: c.id, pay_date: date, amount, method, bank_account_id: method === 'bank' ? req.body.bank_account_id : null,
      reference: req.body.reference, status: 'posted', created_by: req.user.id, created_at: now(),
    });
    const jeId = gl.postJE({
      date, memo: `CA repayment ${c.doc_no} - ${c.employee_name} (${method === 'payroll' ? 'salary deduction' : method})`, source_type: 'ca_repayment', source_id: rid,
      ref_no: c.doc_no, user_id: req.user.id,
      lines: [
        { account_id: gl.sourceAccount(method, req.body.bank_account_id), debit: amount, bank_account_id: method === 'bank' ? req.body.bank_account_id : null },
        { key: 'emp_advances', credit: amount, party_type: 'employee', party_id: c.employee_id },
      ],
    });
    db.update('ca_repayments', rid, { journal_entry_id: jeId });
    const bal = r2(c.balance - amount);
    db.update('cash_advances', c.id, { balance: bal, status: bal <= 0.001 ? 'settled' : 'approved' });
  });
  audit(req.user.id, 'repay', 'cash_advance', c.id, { amount, method });
  return loadCA(c.id);
}));
router.post('/ca-repayments/:id/void', requirePerm('ca.manage'), h((req) => {
  const r = db.get('SELECT * FROM ca_repayments WHERE id = ?', req.params.id);
  if (!r || r.status !== 'posted') throw bad('Repayment not found or already void');
  db.tx(() => {
    gl.reverseJE(r.journal_entry_id, { date: today(), user_id: req.user.id });
    db.update('ca_repayments', r.id, { status: 'void' });
    const c = db.get('SELECT * FROM cash_advances WHERE id = ?', r.advance_id);
    db.update('cash_advances', c.id, { balance: r2(c.balance + r.amount), status: 'approved' });
  });
  return { ok: true };
}));

// ------------------------------------------------------------------ petty cash
router.get('/petty-cash', requirePerm('pettycash.view', 'pettycash.manage', 'pos.petty_cash'), h((req) => {
  const { from, to } = req.query;
  const onlyMine = !can(req.user, 'pettycash.view') && !can(req.user, 'pettycash.manage');
  const rows = db.all(
    `SELECT p.*, a.code account_code, a.name account_name, b.bank_name, u.full_name created_by_name FROM petty_cash_txns p
     LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN bank_accounts b ON b.id = p.bank_account_id LEFT JOIN users u ON u.id = p.created_by
     WHERE p.txn_date BETWEEN ? AND ? ${onlyMine ? 'AND p.created_by = ?' : ''} ORDER BY p.txn_date DESC, p.id DESC`,
    from || '0000-00-00', to || '9999-99-99', ...(onlyMine ? [req.user.id] : [])
  );
  return { rows, fund_balance: gl.accountBalance(gl.acct('petty_cash')) };
}));
router.post('/petty-cash', requirePerm('pettycash.manage', 'pos.petty_cash'), h((req) => {
  const type = req.body.txn_type || 'expense';
  const source = req.body.source || 'fund';
  const amount = r2(req.body.amount);
  if (!(amount > 0)) throw bad('Amount must be greater than zero');
  if (!['expense', 'replenish'].includes(type)) throw bad('Invalid type');
  if (!['fund', 'drawer'].includes(source)) throw bad('Invalid source');
  if (type === 'replenish') assertPerm(req, 'pettycash.manage');
  const date = isDate(req.body.txn_date) ? req.body.txn_date : today();
  let sessionId = null;
  if (source === 'drawer') {
    const s = db.get("SELECT * FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    if (!s) throw bad('Open the business day before paying out from the cash drawer');
    sessionId = s.id;
  }
  let lines; let memo;
  if (type === 'expense') {
    required(req.body, 'account_id');
    memo = `Petty cash: ${req.body.description || 'expense'}${req.body.payee ? ' - ' + req.body.payee : ''}`;
    lines = [{ account_id: req.body.account_id, debit: amount, memo: req.body.description }, { key: source === 'drawer' ? 'cash_on_hand' : 'petty_cash', credit: amount }];
  } else {
    // Replenish the petty cash fund from cash on hand / the drawer / a bank
    const fromMethod = req.body.from_method || (source === 'drawer' ? 'cash' : 'bank');
    memo = 'Petty cash fund replenishment';
    lines = [
      { key: 'petty_cash', debit: amount },
      { account_id: gl.sourceAccount(fromMethod, req.body.bank_account_id), credit: amount, bank_account_id: fromMethod === 'bank' ? req.body.bank_account_id : null },
    ];
  }
  const id = db.tx(() => {
    const pid = db.insert('petty_cash_txns', {
      doc_no: nextNo('PCV', 'PCV'), txn_date: sessionId ? db.value('SELECT business_date FROM cash_sessions WHERE id = ?', sessionId) : date,
      txn_type: type, source, amount, account_id: type === 'expense' ? req.body.account_id : null,
      bank_account_id: type === 'replenish' ? req.body.bank_account_id || null : null, payee: req.body.payee, description: req.body.description,
      or_no: req.body.or_no, cash_session_id: sessionId, status: 'posted', created_by: req.user.id, created_at: now(),
    });
    const txnDate = db.value('SELECT txn_date FROM petty_cash_txns WHERE id = ?', pid);
    const jeId = gl.postJE({ date: txnDate, memo, source_type: 'petty_cash', source_id: pid, ref_no: req.body.or_no, user_id: req.user.id, lines });
    db.update('petty_cash_txns', pid, { journal_entry_id: jeId });
    return pid;
  });
  audit(req.user.id, 'create', 'petty_cash', id, req.body);
  return { id };
}));
router.post('/petty-cash/:id/void', requirePerm('pettycash.manage', 'pos.petty_cash'), h((req) => {
  const p = db.get('SELECT * FROM petty_cash_txns WHERE id = ?', req.params.id);
  if (!p || p.status !== 'posted') throw bad('Transaction not found or already void');
  if (!can(req.user, 'pettycash.manage')) {
    // Cashiers may only void their own drawer payout while the day is still open.
    const s = db.get("SELECT id FROM cash_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    if (p.created_by !== req.user.id || !s || p.cash_session_id !== s.id) authorize(req, 'pettycash.manage', req.body.pin);
  }
  if (p.cash_session_id && db.value('SELECT status FROM cash_sessions WHERE id = ?', p.cash_session_id) === 'closed') {
    throw bad('This drawer payout belongs to a closed business day and can no longer be voided');
  }
  db.tx(() => {
    gl.reverseJE(p.journal_entry_id, { date: p.cash_session_id ? p.txn_date : today(), user_id: req.user.id });
    db.update('petty_cash_txns', p.id, { status: 'void' });
  });
  audit(req.user.id, 'void', 'petty_cash', p.id, req.body.reason);
  return { ok: true };
}));

module.exports = router;
