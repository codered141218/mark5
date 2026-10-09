-- Mark5 Restaurant Suite — MySQL / MariaDB schema
-- Money uses DECIMAL(14,2); quantities use DECIMAL(14,4) (e.g. 0.125 kg).
-- Run through the installer (/install or `php database/install.php`), which also loads seed data.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(64) PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document number series, e.g. OR-00000001, RR-000001
CREATE TABLE IF NOT EXISTS sequences (
  name VARCHAR(32) PRIMARY KEY,
  prefix VARCHAR(16) NOT NULL,
  next_no INT UNSIGNED NOT NULL DEFAULT 1,
  pad TINYINT UNSIGNED NOT NULL DEFAULT 6
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================== security
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  permissions TEXT NOT NULL,            -- JSON array of permission keys, ["*"] = everything
  is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  emp_no VARCHAR(32) NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  position VARCHAR(80) NULL,
  department VARCHAR(80) NULL,
  phone VARCHAR(40) NULL,
  date_hired DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,           -- manager PIN for POS overrides
  role_id INT UNSIGNED NULL,
  employee_id INT UNSIGNED NULL,        -- link to employee record (for cash advance requests)
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_users_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attempt_key VARCHAR(190) NOT NULL,
  attempted_at DATETIME NOT NULL,
  KEY ix_attempt (attempt_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  details TEXT NULL,
  KEY ix_audit_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================== finance
CREATE TABLE IF NOT EXISTS accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('asset','liability','equity','income','expense') NOT NULL,
  subtype VARCHAR(30) NULL,             -- cash, bank, current, inventory, fixed, contra, cogs, opex ...
  system_key VARCHAR(40) NULL UNIQUE,   -- used by automatic postings, e.g. 'cash_on_hand'
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(32) NOT NULL UNIQUE,
  entry_date DATE NOT NULL,
  memo VARCHAR(255) NULL,
  source_type VARCHAR(30) NOT NULL DEFAULT 'manual',   -- manual, pos_sale, inv_receive, petty_cash ...
  source_id INT UNSIGNED NULL,
  ref_no VARCHAR(60) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  reversal_of INT UNSIGNED NULL,
  reversed_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  KEY ix_je_date (entry_date),
  KEY ix_je_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  memo VARCHAR(255) NULL,
  bank_account_id INT UNSIGNED NULL,
  party_type VARCHAR(20) NULL,          -- supplier, customer, employee
  party_id INT UNSIGNED NULL,
  KEY ix_jl_account (account_id),
  CONSTRAINT fk_jl_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_name VARCHAR(80) NOT NULL,
  account_name VARCHAR(120) NULL,
  account_no VARCHAR(60) NULL,
  account_type VARCHAR(30) NULL,
  gl_account_id INT UNSIGNED NOT NULL,  -- each bank has its own "Cash in Bank - X" account
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_bank_gl FOREIGN KEY (gl_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_txns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  bank_account_id INT UNSIGNED NOT NULL,
  txn_date DATE NOT NULL,
  txn_type ENUM('deposit','withdrawal','transfer') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  bank_charges DECIMAL(14,2) NOT NULL DEFAULT 0,
  counter_account_id INT UNSIGNED NULL,
  transfer_bank_id INT UNSIGNED NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  KEY ix_bt_date (txn_date),
  CONSTRAINT fk_bt_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  tin VARCHAR(30) NULL,
  terms_days INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  tin VARCHAR(30) NULL,
  credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
  terms_days INT NOT NULL DEFAULT 30,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bills (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_no VARCHAR(32) NULL,
  supplier_id INT UNSIGNED NOT NULL,
  bill_date DATE NOT NULL,
  due_date DATE NULL,
  ref_no VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('open','partial','paid','void') NOT NULL DEFAULT 'open',
  expense_account_id INT UNSIGNED NULL,
  source_type VARCHAR(30) NULL,         -- manual | inv_receive
  source_id INT UNSIGNED NULL,
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_ap_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  bill_id INT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,          -- cash | petty_cash | bank
  bank_account_id INT UNSIGNED NULL,
  reference VARCHAR(60) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_app_bill FOREIGN KEY (bill_id) REFERENCES ap_bills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ar_invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(32) NULL,
  customer_id INT UNSIGNED NOT NULL,
  inv_date DATE NOT NULL,
  due_date DATE NULL,
  ref_no VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('open','partial','paid','void') NOT NULL DEFAULT 'open',
  income_account_id INT UNSIGNED NULL,
  source_type VARCHAR(30) NULL,         -- manual | pos_sale
  source_id INT UNSIGNED NULL,
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_ar_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ar_receipts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  invoice_id INT UNSIGNED NOT NULL,
  rcpt_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,
  bank_account_id INT UNSIGNED NULL,
  reference VARCHAR(60) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_arr_invoice FOREIGN KEY (invoice_id) REFERENCES ar_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_advances (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  employee_id INT UNSIGNED NOT NULL,
  request_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NULL,
  repayment_terms VARCHAR(255) NULL,
  status ENUM('pending','approved','rejected','settled','cancelled') NOT NULL DEFAULT 'pending',
  requested_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  release_method VARCHAR(20) NULL,      -- cash | petty_cash | bank
  bank_account_id INT UNSIGNED NULL,
  release_date DATE NULL,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  journal_entry_id INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_ca_emp FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ca_repayments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  advance_id INT UNSIGNED NOT NULL,
  pay_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL,          -- payroll | cash | bank
  bank_account_id INT UNSIGNED NULL,
  reference VARCHAR(60) NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  CONSTRAINT fk_car_ca FOREIGN KEY (advance_id) REFERENCES cash_advances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================== inventory
CREATE TABLE IF NOT EXISTS uoms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  abbr VARCHAR(12) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global conversions: 1 [from] = factor [to]   e.g. 1 kg = 1000 g
CREATE TABLE IF NOT EXISTS uom_conversions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_uom_id INT UNSIGNED NOT NULL,
  to_uom_id INT UNSIGNED NOT NULL,
  factor DECIMAL(18,6) NOT NULL,
  UNIQUE KEY uq_conv (from_uom_id, to_uom_id),
  CONSTRAINT fk_conv_from FOREIGN KEY (from_uom_id) REFERENCES uoms(id),
  CONSTRAINT fk_conv_to FOREIGN KEY (to_uom_id) REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where order slips go: Kitchen, Grill, Bar ... (items / categories are tagged with a station)
CREATE TABLE IF NOT EXISTS prep_stations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  kind ENUM('menu','inventory','both') NOT NULL DEFAULT 'menu',
  color VARCHAR(9) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  station_id INT UNSIGNED NULL,               -- default prep station of the category's menu items
  sales_account_id INT UNSIGNED NULL,         -- GL account overrides (NULL = the default in GL account setup)
  cogs_account_id INT UNSIGNED NULL,
  inventory_account_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- item_type: raw (ingredient, stocked) | composite (menu item with a recipe, not stocked itself)
--            retail (stocked and sold as-is, e.g. bottled drinks) | non_inventory (sold, not tracked)
CREATE TABLE IF NOT EXISTS items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  category_id INT UNSIGNED NULL,
  item_type ENUM('raw','composite','retail','non_inventory') NOT NULL,
  base_uom_id INT UNSIGNED NULL,
  price DECIMAL(14,2) NOT NULL DEFAULT 0,          -- selling price (VAT-inclusive unless the VAT-exclusive pricing setting is on)
  avg_cost DECIMAL(14,4) NOT NULL DEFAULT 0,       -- moving average cost per base unit
  last_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  stock_qty DECIMAL(14,4) NOT NULL DEFAULT 0,      -- in base unit
  reorder_point DECIMAL(14,4) NOT NULL DEFAULT 0,
  reorder_qty DECIMAL(14,4) NOT NULL DEFAULT 0,
  sellable TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  color VARCHAR(9) NULL,
  barcode VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  station_id INT UNSIGNED NULL,                    -- prep station for order slips (NULL = the category's)
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  KEY ix_items_cat (category_id),
  CONSTRAINT fk_items_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_items_uom FOREIGN KEY (base_uom_id) REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item-specific units: 1 [uom] = factor [base unit]   e.g. 1 sack = 50 kg
CREATE TABLE IF NOT EXISTS item_uoms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  uom_id INT UNSIGNED NOT NULL,
  factor DECIMAL(18,6) NOT NULL,
  UNIQUE KEY uq_item_uom (item_id, uom_id),
  CONSTRAINT fk_iu_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_iu_uom FOREIGN KEY (uom_id) REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recipe lines: parent (composite) uses qty [uom] of component
CREATE TABLE IF NOT EXISTS item_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NOT NULL,
  component_id INT UNSIGNED NOT NULL,
  qty DECIMAL(14,4) NOT NULL,
  uom_id INT UNSIGNED NULL,
  UNIQUE KEY uq_component (parent_id, component_id),
  CONSTRAINT fk_ic_parent FOREIGN KEY (parent_id) REFERENCES items(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_component FOREIGN KEY (component_id) REFERENCES items(id),
  CONSTRAINT fk_ic_uom FOREIGN KEY (uom_id) REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock ledger: every change to stock_qty is recorded here (signed qty in base unit)
CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  bdate DATE NOT NULL,                  -- business date
  mtype VARCHAR(20) NOT NULL,           -- RECEIVE, SALE, ISSUE, WASTE, COUNT, *_VOID
  qty DECIMAL(14,4) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance_after DECIMAL(14,4) NULL,
  ref_type VARCHAR(20) NULL,
  ref_id INT UNSIGNED NULL,
  ref_no VARCHAR(40) NULL,
  notes VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  KEY ix_sm_item (item_id, bdate),
  KEY ix_sm_date (bdate, mtype),
  KEY ix_sm_ref (ref_type, ref_id),
  CONSTRAINT fk_sm_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RECEIVE = delivery / stock in, ISSUE = stock issuance, WASTE = spoilage & wastage
CREATE TABLE IF NOT EXISTS inv_docs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type ENUM('RECEIVE','ISSUE','WASTE') NOT NULL,
  doc_no VARCHAR(32) NULL,
  doc_date DATE NOT NULL,
  status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  supplier_id INT UNSIGNED NULL,
  invoice_no VARCHAR(60) NULL,
  payment_mode VARCHAR(20) NULL,        -- credit | cash | petty_cash | bank | opening
  bank_account_id INT UNSIGNED NULL,
  vat_inclusive TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(120) NULL,
  issued_to VARCHAR(120) NULL,
  expense_account_id INT UNSIGNED NULL,
  notes TEXT NULL,
  total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  journal_entry_id INT UNSIGNED NULL,
  ap_bill_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NULL,
  KEY ix_inv_docs (doc_type, doc_date),
  CONSTRAINT fk_doc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_doc_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  qty DECIMAL(14,4) NOT NULL,
  uom_id INT UNSIGNED NULL,
  base_qty DECIMAL(14,4) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,     -- per unit entered
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason VARCHAR(120) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_dl_doc FOREIGN KEY (doc_id) REFERENCES inv_docs(id) ON DELETE CASCADE,
  CONSTRAINT fk_dl_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS count_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  count_date DATE NOT NULL,
  status ENUM('open','posted','cancelled') NOT NULL DEFAULT 'open',
  category_id INT UNSIGNED NULL,
  notes TEXT NULL,
  total_variance_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  posted_by INT UNSIGNED NULL,
  posted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS count_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  system_qty DECIMAL(14,4) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(14,4) NULL,       -- NULL = not counted (not adjusted)
  variance DECIMAL(14,4) NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  variance_value DECIMAL(14,2) NULL,
  UNIQUE KEY uq_count_item (session_id, item_id),
  CONSTRAINT fk_cl_session FOREIGN KEY (session_id) REFERENCES count_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cl_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================== POS
-- Discount presets picked at the POS (value NULL = cashier enters the value)
CREATE TABLE IF NOT EXISTS discounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL UNIQUE,
  kind ENUM('sc','pwd','percent','amount') NOT NULL,
  value DECIMAL(14,2) NULL,
  scope ENUM('both','order','item') NOT NULL DEFAULT 'both',
  requires_approval TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One business day = one cash session (beginning cash -> end of day count)
CREATE TABLE IF NOT EXISTS cash_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_date DATE NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opened_by INT UNSIGNED NULL,
  opened_at DATETIME NULL,
  opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  expected_cash DECIMAL(14,2) NULL,
  counted_cash DECIMAL(14,2) NULL,
  variance DECIMAL(14,2) NULL,
  denominations TEXT NULL,              -- JSON {"opening": {...}, "closing": {...}}
  notes VARCHAR(255) NULL,
  z_data MEDIUMTEXT NULL,               -- JSON snapshot of the Z-reading
  journal_entry_id INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A ticket is an order. table_label is free text assigned by the cashier (no fixed table setup).
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no VARCHAR(32) NULL,
  receipt_no VARCHAR(32) NULL,          -- assigned when paid
  cash_session_id INT UNSIGNED NULL,
  business_date DATE NOT NULL,
  table_label VARCHAR(30) NULL,
  order_type ENUM('dine_in','takeout','delivery') NOT NULL DEFAULT 'dine_in',
  customer_name VARCHAR(120) NULL,
  customer_id INT UNSIGNED NULL,
  pax INT NOT NULL DEFAULT 1,
  status ENUM('open','paid','void') NOT NULL DEFAULT 'open',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_type ENUM('none','sc','pwd','percent','amount') NOT NULL DEFAULT 'none',   -- whole-receipt discount
  discount_id INT UNSIGNED NULL,        -- preset used (discounts table)
  discount_name VARCHAR(60) NULL,
  discount_rate DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,   -- all discounts on the receipt (order + items)
  sc_discount DECIMAL(14,2) NOT NULL DEFAULT 0,       -- part that is SC/PWD (computed on net of VAT)
  promo_discount DECIMAL(14,2) NOT NULL DEFAULT 0,    -- part that is promo / employee / fixed (VAT-inclusive)
  sc_count INT NOT NULL DEFAULT 0,
  sc_details TEXT NULL,                 -- JSON [{name, id_no}]
  vatable_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_exempt_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  service_charge DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  change_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  cogs DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat_inclusive TINYINT(1) NOT NULL DEFAULT 1,   -- prices on this order include VAT (else VAT is added on top)
  notes VARCHAR(255) NULL,
  split_from_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  paid_by INT UNSIGNED NULL,
  paid_at DATETIME NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  void_reason VARCHAR(255) NULL,
  void_session_id INT UNSIGNED NULL,
  journal_entry_id INT UNSIGNED NULL,
  KEY ix_tickets_bdate (business_date, status),
  KEY ix_tickets_session (cash_session_id),
  KEY ix_tickets_receipt (receipt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  qty DECIMAL(14,4) NOT NULL,
  price DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  discount_id INT UNSIGNED NULL,        -- per-item discount (preset)
  discount_name VARCHAR(60) NULL,
  discount_kind ENUM('sc','pwd','percent','amount') NULL,
  discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  status ENUM('active','void') NOT NULL DEFAULT 'active',
  void_reason VARCHAR(255) NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  kitchen_sent TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  KEY ix_ti_ticket (ticket_id),
  CONSTRAINT fk_ti_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_item FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  cash_session_id INT UNSIGNED NULL,
  method VARCHAR(20) NOT NULL,          -- cash, card, gcash, maya, bank_transfer, grabfood, foodpanda, charge
  amount DECIMAL(14,2) NOT NULL,        -- amount applied to the bill (cash = tendered - change)
  tendered DECIMAL(14,2) NULL,
  change_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  reference VARCHAR(60) NULL,
  customer_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NULL,
  KEY ix_pay_ticket (ticket_id),
  CONSTRAINT fk_pay_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================== petty cash
-- expense: money paid out; replenish: money put into the petty cash fund.
-- source: fund = petty cash box, drawer = POS cash drawer (reduces expected cash at end of day)
CREATE TABLE IF NOT EXISTS petty_cash_txns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(32) NULL,
  txn_date DATE NOT NULL,
  txn_type ENUM('expense','replenish') NOT NULL,
  source ENUM('fund','drawer') NOT NULL DEFAULT 'fund',
  amount DECIMAL(14,2) NOT NULL,
  account_id INT UNSIGNED NULL,
  bank_account_id INT UNSIGNED NULL,
  payee VARCHAR(120) NULL,
  description VARCHAR(255) NULL,
  or_no VARCHAR(60) NULL,
  cash_session_id INT UNSIGNED NULL,
  status ENUM('posted','void') NOT NULL DEFAULT 'posted',
  journal_entry_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NULL,
  KEY ix_pc_date (txn_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
