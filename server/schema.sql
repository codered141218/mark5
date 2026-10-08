-- Mark5 Restaurant Suite schema (SQLite)
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT
);

CREATE TABLE IF NOT EXISTS sequences (
  name TEXT PRIMARY KEY,
  prefix TEXT NOT NULL,
  next INTEGER NOT NULL DEFAULT 1,
  pad INTEGER NOT NULL DEFAULT 6
);

-- ---------------------------------------------------------------- security
CREATE TABLE IF NOT EXISTS roles (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  description TEXT,
  permissions TEXT NOT NULL DEFAULT '[]',
  is_system INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS employees (
  id INTEGER PRIMARY KEY,
  emp_no TEXT UNIQUE,
  full_name TEXT NOT NULL,
  position TEXT,
  department TEXT,
  phone TEXT,
  date_hired TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  full_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  pin_hash TEXT,
  role_id INTEGER REFERENCES roles(id),
  employee_id INTEGER REFERENCES employees(id),
  active INTEGER NOT NULL DEFAULT 1,
  last_login TEXT,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT,
  expires_at TEXT
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  ts TEXT,
  user_id INTEGER,
  action TEXT,
  entity TEXT,
  entity_id INTEGER,
  details TEXT
);
CREATE INDEX IF NOT EXISTS ix_audit_ts ON audit_log(ts);

-- ---------------------------------------------------------------- finance
CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('asset','liability','equity','income','expense')),
  subtype TEXT,
  system_key TEXT UNIQUE,
  is_system INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  description TEXT
);

CREATE TABLE IF NOT EXISTS journal_entries (
  id INTEGER PRIMARY KEY,
  entry_no TEXT UNIQUE,
  entry_date TEXT NOT NULL,
  memo TEXT,
  source_type TEXT NOT NULL DEFAULT 'manual',
  source_id INTEGER,
  ref_no TEXT,
  status TEXT NOT NULL DEFAULT 'posted',
  reversal_of INTEGER REFERENCES journal_entries(id),
  reversed_by INTEGER REFERENCES journal_entries(id),
  created_by INTEGER,
  created_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_je_date ON journal_entries(entry_date);
CREATE INDEX IF NOT EXISTS ix_je_source ON journal_entries(source_type, source_id);

CREATE TABLE IF NOT EXISTS journal_lines (
  id INTEGER PRIMARY KEY,
  entry_id INTEGER NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
  account_id INTEGER NOT NULL REFERENCES accounts(id),
  debit REAL NOT NULL DEFAULT 0,
  credit REAL NOT NULL DEFAULT 0,
  memo TEXT,
  bank_account_id INTEGER,
  party_type TEXT,
  party_id INTEGER
);
CREATE INDEX IF NOT EXISTS ix_jl_entry ON journal_lines(entry_id);
CREATE INDEX IF NOT EXISTS ix_jl_account ON journal_lines(account_id);

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INTEGER PRIMARY KEY,
  bank_name TEXT NOT NULL,
  account_name TEXT,
  account_no TEXT,
  account_type TEXT,
  gl_account_id INTEGER NOT NULL REFERENCES accounts(id),
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS bank_txns (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  bank_account_id INTEGER NOT NULL REFERENCES bank_accounts(id),
  txn_date TEXT NOT NULL,
  txn_type TEXT NOT NULL CHECK (txn_type IN ('deposit','withdrawal','transfer')),
  amount REAL NOT NULL,
  bank_charges REAL NOT NULL DEFAULT 0,
  counter_account_id INTEGER REFERENCES accounts(id),
  transfer_bank_id INTEGER REFERENCES bank_accounts(id),
  reference TEXT,
  description TEXT,
  status TEXT NOT NULL DEFAULT 'posted',
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS suppliers (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  contact_person TEXT,
  phone TEXT,
  email TEXT,
  address TEXT,
  tin TEXT,
  terms_days INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  contact_person TEXT,
  phone TEXT,
  email TEXT,
  address TEXT,
  tin TEXT,
  credit_limit REAL NOT NULL DEFAULT 0,
  terms_days INTEGER NOT NULL DEFAULT 30,
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS ap_bills (
  id INTEGER PRIMARY KEY,
  bill_no TEXT,
  supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
  bill_date TEXT NOT NULL,
  due_date TEXT,
  ref_no TEXT,
  description TEXT,
  amount REAL NOT NULL,
  paid_amount REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'open',
  expense_account_id INTEGER REFERENCES accounts(id),
  source_type TEXT,
  source_id INTEGER,
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS ap_payments (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  bill_id INTEGER NOT NULL REFERENCES ap_bills(id),
  pay_date TEXT NOT NULL,
  amount REAL NOT NULL,
  method TEXT NOT NULL,
  bank_account_id INTEGER,
  reference TEXT,
  status TEXT NOT NULL DEFAULT 'posted',
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS ar_invoices (
  id INTEGER PRIMARY KEY,
  invoice_no TEXT,
  customer_id INTEGER NOT NULL REFERENCES customers(id),
  inv_date TEXT NOT NULL,
  due_date TEXT,
  ref_no TEXT,
  description TEXT,
  amount REAL NOT NULL,
  paid_amount REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'open',
  income_account_id INTEGER REFERENCES accounts(id),
  source_type TEXT,
  source_id INTEGER,
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS ar_receipts (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  invoice_id INTEGER NOT NULL REFERENCES ar_invoices(id),
  rcpt_date TEXT NOT NULL,
  amount REAL NOT NULL,
  method TEXT NOT NULL,
  bank_account_id INTEGER,
  reference TEXT,
  status TEXT NOT NULL DEFAULT 'posted',
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS cash_advances (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  employee_id INTEGER NOT NULL REFERENCES employees(id),
  request_date TEXT NOT NULL,
  amount REAL NOT NULL,
  reason TEXT,
  repayment_terms TEXT,
  status TEXT NOT NULL DEFAULT 'pending',
  requested_by INTEGER,
  approved_by INTEGER,
  approved_at TEXT,
  release_method TEXT,
  bank_account_id INTEGER,
  release_date TEXT,
  balance REAL NOT NULL DEFAULT 0,
  remarks TEXT,
  journal_entry_id INTEGER,
  created_at TEXT
);

CREATE TABLE IF NOT EXISTS ca_repayments (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  advance_id INTEGER NOT NULL REFERENCES cash_advances(id),
  pay_date TEXT NOT NULL,
  amount REAL NOT NULL,
  method TEXT NOT NULL,
  bank_account_id INTEGER,
  reference TEXT,
  status TEXT NOT NULL DEFAULT 'posted',
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);

-- ---------------------------------------------------------------- inventory
CREATE TABLE IF NOT EXISTS uoms (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  abbr TEXT NOT NULL UNIQUE
);

-- 1 [from] = factor [to]
CREATE TABLE IF NOT EXISTS uom_conversions (
  id INTEGER PRIMARY KEY,
  from_uom_id INTEGER NOT NULL REFERENCES uoms(id),
  to_uom_id INTEGER NOT NULL REFERENCES uoms(id),
  factor REAL NOT NULL,
  UNIQUE (from_uom_id, to_uom_id)
);

CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  kind TEXT NOT NULL DEFAULT 'menu',
  color TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1
);

-- item_type: raw (ingredient, stocked), composite (menu item made of components, not stocked),
--            retail (stocked and sold as-is, e.g. bottled drinks), non_inventory (sold, not tracked)
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY,
  sku TEXT UNIQUE,
  name TEXT NOT NULL,
  category_id INTEGER REFERENCES categories(id),
  item_type TEXT NOT NULL CHECK (item_type IN ('raw','composite','retail','non_inventory')),
  base_uom_id INTEGER REFERENCES uoms(id),
  price REAL NOT NULL DEFAULT 0,
  avg_cost REAL NOT NULL DEFAULT 0,
  last_cost REAL NOT NULL DEFAULT 0,
  stock_qty REAL NOT NULL DEFAULT 0,
  reorder_point REAL NOT NULL DEFAULT 0,
  reorder_qty REAL NOT NULL DEFAULT 0,
  sellable INTEGER NOT NULL DEFAULT 1,
  active INTEGER NOT NULL DEFAULT 1,
  color TEXT,
  barcode TEXT,
  description TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT,
  updated_at TEXT
);

CREATE TABLE IF NOT EXISTS item_uoms (
  id INTEGER PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  uom_id INTEGER NOT NULL REFERENCES uoms(id),
  factor REAL NOT NULL,
  UNIQUE (item_id, uom_id)
);

CREATE TABLE IF NOT EXISTS item_components (
  id INTEGER PRIMARY KEY,
  parent_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  component_id INTEGER NOT NULL REFERENCES items(id),
  qty REAL NOT NULL,
  uom_id INTEGER REFERENCES uoms(id),
  UNIQUE (parent_id, component_id)
);

CREATE TABLE IF NOT EXISTS stock_movements (
  id INTEGER PRIMARY KEY,
  item_id INTEGER NOT NULL REFERENCES items(id),
  ts TEXT NOT NULL,
  bdate TEXT NOT NULL,
  mtype TEXT NOT NULL,
  qty REAL NOT NULL,
  unit_cost REAL NOT NULL DEFAULT 0,
  total_cost REAL NOT NULL DEFAULT 0,
  balance_after REAL,
  ref_type TEXT,
  ref_id INTEGER,
  ref_no TEXT,
  notes TEXT,
  user_id INTEGER
);
CREATE INDEX IF NOT EXISTS ix_sm_item ON stock_movements(item_id, bdate);
CREATE INDEX IF NOT EXISTS ix_sm_date ON stock_movements(bdate, mtype);

-- RECEIVE = delivery / stock in, ISSUE = stock issuance, WASTE = spoilage & wastage
CREATE TABLE IF NOT EXISTS inv_docs (
  id INTEGER PRIMARY KEY,
  doc_type TEXT NOT NULL CHECK (doc_type IN ('RECEIVE','ISSUE','WASTE')),
  doc_no TEXT,
  doc_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft',
  supplier_id INTEGER REFERENCES suppliers(id),
  invoice_no TEXT,
  payment_mode TEXT,
  bank_account_id INTEGER,
  vat_inclusive INTEGER NOT NULL DEFAULT 0,
  reason TEXT,
  issued_to TEXT,
  expense_account_id INTEGER REFERENCES accounts(id),
  notes TEXT,
  total_cost REAL NOT NULL DEFAULT 0,
  journal_entry_id INTEGER,
  ap_bill_id INTEGER,
  created_by INTEGER,
  created_at TEXT,
  posted_by INTEGER,
  posted_at TEXT
);

CREATE TABLE IF NOT EXISTS inv_doc_lines (
  id INTEGER PRIMARY KEY,
  doc_id INTEGER NOT NULL REFERENCES inv_docs(id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES items(id),
  qty REAL NOT NULL,
  uom_id INTEGER REFERENCES uoms(id),
  base_qty REAL NOT NULL DEFAULT 0,
  unit_cost REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  reason TEXT,
  notes TEXT
);

CREATE TABLE IF NOT EXISTS count_sessions (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  count_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  category_id INTEGER,
  notes TEXT,
  total_variance_value REAL NOT NULL DEFAULT 0,
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT,
  posted_by INTEGER,
  posted_at TEXT
);

CREATE TABLE IF NOT EXISTS count_lines (
  id INTEGER PRIMARY KEY,
  session_id INTEGER NOT NULL REFERENCES count_sessions(id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES items(id),
  system_qty REAL NOT NULL DEFAULT 0,
  counted_qty REAL,
  variance REAL,
  unit_cost REAL NOT NULL DEFAULT 0,
  variance_value REAL,
  UNIQUE (session_id, item_id)
);

-- ---------------------------------------------------------------- POS
CREATE TABLE IF NOT EXISTS dining_tables (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  area TEXT,
  seats INTEGER NOT NULL DEFAULT 4,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS cash_sessions (
  id INTEGER PRIMARY KEY,
  business_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  opened_by INTEGER,
  opened_at TEXT,
  opening_cash REAL NOT NULL DEFAULT 0,
  closed_by INTEGER,
  closed_at TEXT,
  expected_cash REAL,
  counted_cash REAL,
  variance REAL,
  denominations TEXT,
  notes TEXT,
  z_data TEXT,
  journal_entry_id INTEGER
);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY,
  ticket_no TEXT,
  receipt_no TEXT,
  cash_session_id INTEGER REFERENCES cash_sessions(id),
  business_date TEXT NOT NULL,
  table_id INTEGER REFERENCES dining_tables(id),
  order_type TEXT NOT NULL DEFAULT 'dine_in',
  customer_name TEXT,
  customer_id INTEGER REFERENCES customers(id),
  pax INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'open',
  subtotal REAL NOT NULL DEFAULT 0,
  discount_type TEXT NOT NULL DEFAULT 'none',
  discount_rate REAL NOT NULL DEFAULT 0,
  discount_amount REAL NOT NULL DEFAULT 0,
  sc_count INTEGER NOT NULL DEFAULT 0,
  sc_details TEXT,
  vatable_sales REAL NOT NULL DEFAULT 0,
  vat_amount REAL NOT NULL DEFAULT 0,
  vat_exempt_sales REAL NOT NULL DEFAULT 0,
  service_charge REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL DEFAULT 0,
  paid_total REAL NOT NULL DEFAULT 0,
  change_amount REAL NOT NULL DEFAULT 0,
  cogs REAL NOT NULL DEFAULT 0,
  notes TEXT,
  split_from_id INTEGER,
  created_by INTEGER,
  created_at TEXT,
  paid_by INTEGER,
  paid_at TEXT,
  voided_by INTEGER,
  voided_at TEXT,
  void_reason TEXT,
  void_session_id INTEGER,
  journal_entry_id INTEGER
);
CREATE INDEX IF NOT EXISTS ix_tickets_bdate ON tickets(business_date, status);
CREATE INDEX IF NOT EXISTS ix_tickets_session ON tickets(cash_session_id);

CREATE TABLE IF NOT EXISTS ticket_items (
  id INTEGER PRIMARY KEY,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES items(id),
  name TEXT NOT NULL,
  qty REAL NOT NULL,
  price REAL NOT NULL,
  line_total REAL NOT NULL,
  notes TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  void_reason TEXT,
  voided_by INTEGER,
  voided_at TEXT,
  kitchen_sent INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER,
  created_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_ti_ticket ON ticket_items(ticket_id);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  cash_session_id INTEGER,
  method TEXT NOT NULL,
  amount REAL NOT NULL,
  tendered REAL,
  change_amount REAL NOT NULL DEFAULT 0,
  reference TEXT,
  customer_id INTEGER,
  user_id INTEGER,
  created_at TEXT
);
CREATE INDEX IF NOT EXISTS ix_pay_ticket ON payments(ticket_id);

-- ---------------------------------------------------------------- petty cash
-- expense: money paid out; replenish: money put into the petty cash fund
-- source: fund = petty cash box, drawer = POS cash drawer (affects expected cash)
CREATE TABLE IF NOT EXISTS petty_cash_txns (
  id INTEGER PRIMARY KEY,
  doc_no TEXT,
  txn_date TEXT NOT NULL,
  txn_type TEXT NOT NULL CHECK (txn_type IN ('expense','replenish')),
  source TEXT NOT NULL DEFAULT 'fund',
  amount REAL NOT NULL,
  account_id INTEGER REFERENCES accounts(id),
  bank_account_id INTEGER,
  payee TEXT,
  description TEXT,
  or_no TEXT,
  cash_session_id INTEGER,
  status TEXT NOT NULL DEFAULT 'posted',
  journal_entry_id INTEGER,
  created_by INTEGER,
  created_at TEXT
);
