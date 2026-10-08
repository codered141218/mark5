# Mark5 Restaurant Suite (PHP + MySQL)

All-in-one, browser-based restaurant system for Philippine restaurants:
**POS / cashiering, recipe-based inventory and double-entry accounting in one place.**
Every sale, delivery, spoilage, petty cash payout and cash advance is posted to the books automatically.

- **Plain PHP 8.1+** — no framework, no Composer packages. Copy the folder to your server and run the installer.
- **MySQL 5.7+ / MariaDB 10.4+** (XAMPP, Laragon, cPanel hosting, VPS).
- Server-rendered pages + a little plain JavaScript (no build step).
- Works on PCs, tablets and phones (Chrome / Edge / Safari).

---

## Installation

### Option A — XAMPP / Laragon on Windows (recommended for a single restaurant)
1. Install [XAMPP](https://www.apachefriends.org) (PHP 8.1 or newer) and start **Apache** and **MySQL**.
2. Copy this project folder to `C:\xampp\htdocs\mark5`.
3. (Recommended) In `C:\xampp\php\php.ini` make sure `extension=zip` is enabled (for .xlsx exports), then restart Apache.
4. Open **http://localhost/mark5/** — the installer appears. Enter the MySQL details
   (XAMPP default: user `root`, empty password), choose the admin password and manager PIN, and click **Install**.
5. Log in as **admin**.

### Option B — any PHP host / VPS
Point the web server's document root to the `public/` folder (Apache uses `public/.htaccess`; for nginx route all
requests to `public/index.php`). Then open the site and follow the installer.

### Option C — command line (developers)
```bash
cp config/config.example.php config/config.php     # edit the MySQL settings
php database/install.php                           # creates the database, tables, admin/admin123, PIN 1234 + sample menu
php -S 0.0.0.0:8000 -t public public/index.php     # http://localhost:8000
```
`php database/install.php --empty` installs without the sample menu.

### Using tablets and phones
Other devices on the same Wi-Fi open `http://<server-ip>/mark5/` (find the IP with `ipconfig`).
Allow Apache through the Windows firewall when asked.

---

## Bluetooth receipt printers

Open **POS → Printer** (or *Administration → Printer Setup*) on **each device** — printer settings are saved per device.

| Method | Use it when | Notes |
|---|---|---|
| **Bluetooth (BLE)** | Chrome on Android, Windows or Mac with a BLE thermal printer | Web Bluetooth only works on **https://** or **localhost**. |
| **Serial / COM port** | Chrome/Edge on a Windows PC with a USB printer or a classic Bluetooth printer paired in Windows (it appears as a COM port) | Also needs https or localhost. |
| **RawBT app** | Android phone/tablet with **any** Bluetooth thermal printer (58 mm / 80 mm) | Install *RawBT* from Google Play and pair the printer there. Works over plain http on the LAN — the easiest option for Android tablets. |
| **Browser print** | The printer is installed in Windows/macOS, or as a fallback | Uses the normal print dialog. |

Options: paper width 58 mm (32 characters) or 80 mm (48 characters), auto-print receipt after payment, kitchen order slip
when pressing *Send*, number of copies, open the cash drawer (printer with drawer port).

**Why https matters:** browsers only allow Bluetooth and serial access from secure pages. On a LAN, either use RawBT on
Android, run the POS on the same PC as the server (`http://localhost/...` counts as secure), or install an SSL
certificate (e.g. host the system online with Let's Encrypt, or a local certificate).

Printers known to speak ESC/POS over BLE include most generic 58 mm "Bluetooth Printer" models (Goojprt, Xprinter,
MTP-II, PeriPage). If yours is not found by *Bluetooth (BLE)*, it is probably a classic-Bluetooth-only model: use RawBT
(Android) or Serial (Windows).

---

## Features

**Front of house — POS (`/pos`)**
- Open the day with a beginning cash count; **End of Day** with a blind cash count by denomination → Z-reading
  (expected vs. actual cash, over/short posted to the books, OR range, VAT breakdown, accumulated grand total). X-reading anytime.
- Take the order first, **then assign a table** (free text: "5", "12A", "Patio 2" — no fixed table setup). Change table any time.
- Item tiles by category, search / barcode, kitchen notes, *Send* prints a kitchen slip.
- Split orders (whole or partial quantities), merge orders, take-out & delivery.
- Split payments: cash, card, GCash, Maya, bank transfer, GrabFood, foodpanda, charge to a customer account (A/R).
- Senior Citizen / PWD discount (VAT-exempt + 20% on the qualified share, with names & ID numbers), promo % / fixed.
- Voids with reasons and **manager PIN override**; voiding a paid receipt returns ingredients to stock and reverses the sale.
- Receipts search / reprint, drawer payouts (petty cash).

**Back office**
- Date-filtered **dashboard**; sales reports (by date, item, category, receipt, payment, hour, cashier, voids, SC/PWD book, Z-readings).
- **Inventory**: raw ingredients, composite menu items with recipes (sub-recipes allowed), retail items; units & conversions
  (1 sack = 50 kg, 1 kg = 1000 g); reorder points; moving-average costing; menu costing with food cost %;
  delivery / stock-in (cash, bank, petty cash or on credit → payable), stock issuance, spoilage & wastage,
  inventory count sessions (actual vs. system, variance posted); stock card and inventory reports.
- **Finance**: chart of accounts, journal entries, trial balance, income statement, balance sheet, general ledger,
  accounts payable & receivable with aging, banks (money in/out/transfers, card settlement charges), petty cash.
- **Cash advances**: request → approve (posts to the GL) → repayments (salary deduction, cash, bank).
- **Users, roles & permissions** (40+ permissions), audit trail, **backup & restore** (pure PHP, no mysqldump needed).
- Every list and report exports to **Excel**.

### How the books stay in sync

| Event | Automatic journal entry |
|---|---|
| POS sale | Dr Cash / Card / E-wallet / A/R, Dr Sales Discounts · Cr Sales, Output VAT, Service Charge Payable · Dr COGS / Cr Inventory |
| Receipt void | Reversal of the sale; ingredients returned to stock |
| End of day over/short | Cash on Hand vs. Cash Short/(Over) |
| Delivery / stock in | Dr Inventory (+ Input VAT) · Cr Cash / Bank / Petty Cash / Accounts Payable |
| Issuance / wastage / count | Expense or Spoilage / Inventory Variance vs. Inventory |
| Petty cash, cash advances, AP/AR, banks | the corresponding cash/bank and AP/AR entries |

---

## Project structure (for developers)

```
public/            web root: index.php (front controller), assets/css, assets/js (app.js, pos.js, printer.js)
app/
  bootstrap.php    config, autoloader, helpers
  helpers.php      view(), redirect(), e(), money(), date_range() ...
  nav.php          sidebar menu
  Core/            DB (PDO wrapper), Router, Request/Response, View, Auth, Csrf, Table (HTML + Excel), Excel
  Services/        business logic: Ledger (GL), Inventory, Pos/*, Finance/*, Reports/*, Backup ...
  Controllers/     thin controllers per module
  routes/          one route file per module: $router->get('/path', [Controller::class, 'method'], 'permission')
views/             PHP templates (layouts/app.php, layouts/blank.php, one folder per module)
database/          schema.sql, install.php
tests/             php tests/run.php — uses a separate <db>_test database
storage/           backups/, logs/
```

Conventions: controllers stay thin; rules and postings live in `app/Services`, which the tests call directly.
Every money movement goes through `App\Services\Ledger::post()`, every stock change through `App\Services\Inventory::move()`.

### Tests
```bash
php tests/run.php          # all tests (creates and drops the <database>_test database)
php tests/run.php Pos      # only tests/PosTest.php
```

---

## Notes
- **BIR**: issuing official receipts/invoices from a POS requires BIR accreditation and a Permit to Use (PTU). The system
  keeps what BIR asks for (sequential receipt numbers, X/Z readings, accumulated grand total, SC/PWD sales book,
  void log, audit trail); receipt title, prefix and footer are configurable.
- Service charge is booked as a liability (*Service Charge Payable*), since it must be distributed to employees (RA 11360).
- Default login after the CLI installer: **admin / admin123**, manager PIN **1234** — change them under *My account*.
