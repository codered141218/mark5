# Mark5 Restaurant Suite

An all-in-one, web-based restaurant management system built for Philippine restaurants:
**POS / cashiering, recipe-based inventory, and double-entry accounting in one place**, so
every sale, delivery, spoilage, petty cash payout and cash advance lands in the books
automatically.

## Highlights

### Front end — POS / Cashier (`/pos`)
- **Business day**: open the day with a beginning cash count (by denomination), take orders,
  then **End of Day** with a *blind* cash count → Z-reading with expected vs. actual cash,
  over/short (auto-posted to the GL), OR range, VAT breakdown and accumulated grand total.
  X-reading any time during the shift.
- **Tile-based item selection** by category, search / barcode scan, kitchen notes/modifiers.
- **Floor plan**: tables by area with running totals and time seated; take-out & delivery orders.
- **Send to kitchen** (prints a kitchen order slip). After sending, removing an item is a *void*.
- **Split tickets** (whole or partial quantities, to a new or an existing ticket), **merge
  tickets**, **move a ticket to another table**.
- **Split payments**: cash, card, GCash, Maya, bank transfer/InstaPay, GrabFood, foodpanda,
  and **charge to account** (creates an A/R invoice). Change computation, quick-cash buttons.
- **Philippine discounts**: Senior Citizen / PWD (VAT-exempt + 20% on the qualified share of the
  bill, records name & OSCA/PWD ID for the BIR sales book), promo % or fixed amount.
- **Voids**: item voids and receipt voids with reason; **manager PIN override** for cashiers who
  lack the permission. Voiding a paid receipt returns the ingredients to stock and reverses the
  journal entry.
- **Receipts**: reprint, search, print bill/pre-bill, 58/80 mm-friendly receipt layout.
- **Petty cash / drawer payouts** at the POS (reduces expected drawer cash).

### Back end — Admin
- **Dashboard** (date-filtered): net sales, average ticket, gross profit, food cost %, voids,
  wastage, expenses, estimated net income, daily/hourly sales, category & payment mix, top items,
  cash/bank position, AR/AP, low-stock alerts, pending cash-advance approvals.
- **Sales reports**: by date, item, category, receipt, payment method, hour, cashier, voids,
  SC/PWD discount book, End-of-day/Z-reading history (click to view & reprint).
- **Inventory**
  - Items with types: *raw/ingredient*, *composite* (menu item with a recipe — tag ingredients
    and sub-recipes), *retail* (e.g. bottled drinks), *non-inventory*.
  - **Units of measure & conversions**: global (1 kg = 1000 g) and per item (1 sack = 50 kg,
    1 case = 24 cans). Recipes can use any convertible unit.
  - Reorder point / reorder qty, low-stock flags, reorder suggestion report.
  - Moving-average costing, **menu/recipe costing with food cost %**.
  - **Delivery / Stock in** (cash, petty cash, bank, on credit → automatic payable, or opening
    balance), optional VAT-inclusive supplier price (input VAT).
  - **Stock issuance** (non-sales use: staff meals, commissary, other branch) charged to an
    expense account.
  - **Spoilage & wastage** (composites explode to ingredients).
  - **Inventory count sessions**: count sheet, compare actual vs. system, post adjustments
    with the variance booked to the GL.
  - Reports: stock on hand & valuation, stock card, movement summary, receiving, issuance,
    wastage, count variance, ingredient usage.
- **Petty cash**: fund vs. drawer payouts, replenishment, report with beginning/ending balance.
- **Finance**: chart of accounts (PH restaurant default), manual journal entries, trial balance,
  income statement, balance sheet, general ledger, general journal.
- **Accounts payable & receivable** with payments/collections and aging.
- **Banks**: record money in / money out / transfers per bank (not connected to the bank),
  card-settlement charges, bank register.
- **Employee cash advances**: request → approve/reject → release (posts Dr Advances to
  Employees / Cr cash, petty cash or bank) → repayments (salary deduction, cash, bank).
- **Users & roles** with a permission matrix (40+ permissions, grouped by function).
- **Audit trail**, **database backup & restore** (daily automatic backups, download, restore
  from a backup or an uploaded file — a safety backup is always taken first).
- **Every report and list exports to Excel**; every report is filtered by date.

## How the books stay in sync

| Event | Journal entry (automatic) |
|---|---|
| POS sale | Dr Cash / Card / E-wallet / A/R, Dr Sales Discounts · Cr Sales, Output VAT, Service Charge Payable · Dr COGS / Cr Inventory |
| Receipt void | Reversal of the sale entry, ingredients returned to stock |
| End of day over/short | Dr/Cr Cash on Hand vs. Cash Short/(Over) |
| Delivery / stock in | Dr Inventory (+ Input VAT) · Cr Cash / Bank / Petty Cash / Accounts Payable |
| Issuance / wastage | Dr chosen expense or Spoilage & Wastage · Cr Inventory |
| Count posting | Inventory vs. Inventory Variance |
| Petty cash expense | Dr Expense · Cr Petty Cash Fund (or Cash on Hand for drawer payouts) |
| Cash advance approved | Dr Advances to Employees · Cr Cash / Petty Cash / Bank |
| AP/AR payments, bank transactions | the corresponding cash/bank and AP/AR entries |

Prices on the menu are **VAT-inclusive**. Non-VAT businesses can turn VAT off in Settings.

## Running it

Requirements: **Node.js 22.13+** (uses the built-in `node:sqlite`, so there are no native
modules to compile — it runs fine on a Windows cashier PC).

```bash
npm install
npm run build        # builds the web app into client/dist
npm start            # http://localhost:3000
```

Default login: **admin / admin123** (manager PIN **1234**). Change both under *My account*.

Development (API on :3000 with auto-reload + Vite on :5173):

```bash
npm run dev
```

Tests (end-to-end business flows, checks that the books always balance):

```bash
npm test
```

### Configuration

| Env var | Default | |
|---|---|---|
| `PORT` | `3000` | HTTP port |
| `DATA_DIR` | `./data` | Database (`mark5.db`) and `backups/` folder |
| `TZ` | `Asia/Manila` | Business timezone |
| `SEED_SAMPLE` | `1` | Seed a sample Filipino menu with recipes on a brand-new database (`0` to start empty) |

Other terminals (tablets for waiters, a second cashier, the office PC) open
`http://<server-ip>:3000` on the same network.

### Backups
A backup is created automatically once a day (retention configurable). Copy the files in
`data/backups/` to a USB drive or cloud storage regularly. Restore from *Administration →
Backup & Restore*.

## Notes
- BIR: issuing official receipts/invoices from a POS requires BIR accreditation and a Permit to
  Use (PTU). The receipt title, prefix, and footer are configurable; the system already keeps
  the data BIR asks for (sequential receipt numbers, X/Z readings, accumulated grand total,
  SC/PWD sales book, void log, audit trail).
- Service charge is booked as a liability (*Service Charge Payable*) since it must be
  distributed to employees (RA 11360).

## Project layout

```
server/            Express API
  schema.sql       database schema
  gl.js            chart of accounts + journal posting
  inventory.js     stock ledger, UOM conversion, recipe explosion, costing
  routes/          pos, inventory, finance, reports, admin
client/src/        React app (Vite)
  pages/pos        cashier terminal
  pages/...        dashboard, reports, inventory, finance, admin
test/              end-to-end API tests
```
