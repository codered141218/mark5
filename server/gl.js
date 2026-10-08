'use strict';
// General ledger posting. Every module that moves money or inventory value posts here.
const db = require('./db');
const { r2, bad, now, nextNo } = require('./util');

// Default Philippine restaurant chart of accounts. system_key = used by automatic postings.
const DEFAULT_ACCOUNTS = [
  ['1000', 'Cash on Hand', 'asset', 'cash', 'cash_on_hand'],
  ['1020', 'Petty Cash Fund', 'asset', 'cash', 'petty_cash'],
  ['1030', 'Cash in Bank', 'asset', 'bank', 'cash_in_bank'],
  ['1040', 'Credit/Debit Card Receivable', 'asset', 'current', 'card_clearing'],
  ['1045', 'E-Wallet & Delivery App Receivable', 'asset', 'current', 'ewallet_clearing'],
  ['1100', 'Accounts Receivable', 'asset', 'current', 'ar'],
  ['1150', 'Advances to Employees', 'asset', 'current', 'emp_advances'],
  ['1200', 'Inventory', 'asset', 'inventory', 'inventory'],
  ['1300', 'Input VAT', 'asset', 'current', 'input_vat'],
  ['1400', 'Prepaid Expenses', 'asset', 'current', null],
  ['1500', 'Kitchen Equipment', 'asset', 'fixed', null],
  ['1510', 'Furniture & Fixtures', 'asset', 'fixed', null],
  ['1590', 'Accumulated Depreciation', 'asset', 'contra', null],
  ['2000', 'Accounts Payable', 'liability', 'current', 'ap'],
  ['2100', 'Output VAT Payable', 'liability', 'current', 'output_vat'],
  ['2150', 'Service Charge Payable', 'liability', 'current', 'service_charge'],
  ['2200', 'Salaries Payable', 'liability', 'current', 'salaries_payable'],
  ['2210', 'SSS/PhilHealth/Pag-IBIG Payable', 'liability', 'current', null],
  ['2220', 'Withholding Tax Payable', 'liability', 'current', null],
  ['2500', 'Loans Payable', 'liability', 'long_term', null],
  ['3000', "Owner's Capital", 'equity', 'capital', 'capital'],
  ['3100', "Owner's Drawings", 'equity', 'drawings', null],
  ['3200', 'Retained Earnings', 'equity', 'retained', 'retained_earnings'],
  ['3900', 'Opening Balance Equity', 'equity', 'capital', 'opening_equity'],
  ['4000', 'Food & Beverage Sales', 'income', 'sales', 'sales'],
  ['4100', 'Sales Discounts (SC/PWD/Promo)', 'income', 'contra', 'sales_discounts'],
  ['4800', 'Other Income', 'income', 'other', 'other_income'],
  ['5000', 'Cost of Goods Sold', 'expense', 'cogs', 'cogs'],
  ['5100', 'Spoilage & Wastage', 'expense', 'cogs', 'wastage'],
  ['5110', 'Inventory Variance (Over/Short)', 'expense', 'cogs', 'inv_variance'],
  ['5120', 'Staff Meals', 'expense', 'cogs', 'staff_meals'],
  ['6000', 'Salaries & Wages', 'expense', 'opex', 'salaries'],
  ['6010', 'SSS/PhilHealth/Pag-IBIG Contributions', 'expense', 'opex', null],
  ['6100', 'Rent Expense', 'expense', 'opex', null],
  ['6200', 'Electricity & Water', 'expense', 'opex', null],
  ['6210', 'LPG / Gas', 'expense', 'opex', null],
  ['6220', 'Internet & Telephone', 'expense', 'opex', null],
  ['6300', 'Repairs & Maintenance', 'expense', 'opex', null],
  ['6400', 'Transportation & Delivery', 'expense', 'opex', null],
  ['6500', 'Kitchen & Store Supplies', 'expense', 'opex', 'supplies'],
  ['6510', 'Packaging (Take-out)', 'expense', 'opex', null],
  ['6600', 'Marketing & Advertising', 'expense', 'opex', null],
  ['6700', 'Taxes & Licenses', 'expense', 'opex', null],
  ['6800', 'Bank & Card Charges', 'expense', 'opex', 'bank_charges'],
  ['6850', 'Cash Short / (Over)', 'expense', 'opex', 'cash_short_over'],
  ['6900', 'Miscellaneous Expense', 'expense', 'opex', 'misc_expense'],
];

function seedAccounts() {
  for (const [code, name, type, subtype, key] of DEFAULT_ACCOUNTS) {
    const exists = db.get('SELECT id FROM accounts WHERE code = ? OR (system_key IS NOT NULL AND system_key = ?)', code, key);
    if (!exists) db.insert('accounts', { code, name, type, subtype, system_key: key, is_system: key ? 1 : 0, active: 1 });
  }
}

const keyCache = new Map();
function acct(key) {
  if (keyCache.has(key)) return keyCache.get(key);
  const row = db.get('SELECT id FROM accounts WHERE system_key = ?', key);
  if (!row) throw new Error(`System account "${key}" is missing from the chart of accounts`);
  keyCache.set(key, row.id);
  return row.id;
}
function clearCache() {
  keyCache.clear();
}

// GL account for a payment/funding source: cash | bank | petty_cash | credit (AP) | opening
function sourceAccount(method, bankAccountId) {
  switch (method) {
    case 'cash':
    case 'drawer':
      return acct('cash_on_hand');
    case 'petty_cash':
    case 'fund':
      return acct('petty_cash');
    case 'bank': {
      const bank = db.get('SELECT gl_account_id FROM bank_accounts WHERE id = ?', bankAccountId);
      if (!bank) throw bad('Please select the bank account');
      return bank.gl_account_id;
    }
    case 'credit':
      return acct('ap');
    case 'opening':
      return acct('opening_equity');
    case 'payroll':
      return acct('salaries_payable');
    default:
      throw bad(`Unknown payment method "${method}"`);
  }
}

/**
 * Post a balanced journal entry.
 * lines: [{ account_id | key, debit, credit, memo, bank_account_id, party_type, party_id }]
 */
function postJE({ date, memo, source_type = 'manual', source_id = null, ref_no = null, lines, user_id = null }) {
  if (!date) throw bad('Journal date is required');
  const clean = [];
  for (const l of lines) {
    const account_id = l.account_id || (l.key ? acct(l.key) : null);
    if (!account_id) throw bad('Journal line is missing an account');
    let debit = r2(l.debit);
    let credit = r2(l.credit);
    if (debit < 0) { credit = r2(credit - debit); debit = 0; }
    if (credit < 0) { debit = r2(debit - credit); credit = 0; }
    if (debit === 0 && credit === 0) continue;
    clean.push({ ...l, account_id, debit, credit });
  }
  const dr = r2(clean.reduce((s, l) => s + l.debit, 0));
  const cr = r2(clean.reduce((s, l) => s + l.credit, 0));
  if (Math.abs(dr - cr) > 0.009) throw bad(`Journal entry is not balanced (debit ${dr} vs credit ${cr})`);
  if (!clean.length) return null;
  return db.tx(() => {
    const id = db.insert('journal_entries', {
      entry_no: nextNo('JE', 'JE'), entry_date: date, memo, source_type, source_id, ref_no,
      status: 'posted', created_by: user_id, created_at: now(),
    });
    for (const l of clean) {
      db.insert('journal_lines', {
        entry_id: id, account_id: l.account_id, debit: l.debit, credit: l.credit, memo: l.memo || null,
        bank_account_id: l.bank_account_id || null, party_type: l.party_type || null, party_id: l.party_id || null,
      });
    }
    return id;
  });
}

// Reverse a journal entry (used by every "void").
function reverseJE(entryId, { date, memo, user_id } = {}) {
  if (!entryId) return null;
  const je = db.get('SELECT * FROM journal_entries WHERE id = ?', entryId);
  if (!je) return null;
  if (je.reversed_by) throw bad(`Journal ${je.entry_no} is already reversed`);
  const lines = db.all('SELECT * FROM journal_lines WHERE entry_id = ?', entryId);
  return db.tx(() => {
    const revId = postJE({
      date: date || je.entry_date,
      memo: memo || `Reversal of ${je.entry_no}${je.memo ? ' - ' + je.memo : ''}`,
      source_type: je.source_type,
      source_id: je.source_id,
      ref_no: je.ref_no,
      user_id,
      lines: lines.map((l) => ({ ...l, debit: l.credit, credit: l.debit })),
    });
    db.run('UPDATE journal_entries SET reversal_of = ? WHERE id = ?', entryId, revId);
    db.run("UPDATE journal_entries SET reversed_by = ?, status = 'void' WHERE id = ?", revId, entryId);
    return revId;
  });
}

function accountBalance(accountId, asOf) {
  const row = db.get(
    `SELECT COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c FROM journal_lines l
     JOIN journal_entries e ON e.id = l.entry_id WHERE l.account_id = ? ${asOf ? 'AND e.entry_date <= ?' : ''}`,
    ...(asOf ? [accountId, asOf] : [accountId])
  );
  return r2(row.d - row.c);
}

module.exports = { DEFAULT_ACCOUNTS, seedAccounts, acct, clearCache, sourceAccount, postJE, reverseJE, accountBalance };
