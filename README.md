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

### Option C — free online test server that updates itself
See **[DEPLOY.md](DEPLOY.md)**: a free PHP + MySQL host (InfinityFree) plus a GitHub Actions workflow that tests every push
and uploads it automatically. Gives you an https address you can open from any tablet.

### Option D — command line (developers)
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

## Bluetooth receipt printers (portable 58 mm printers on Android tablets)

Open **POS → Printer** (or *Administration → Printer Setup*) on **each tablet** — printer settings are saved per device.

| Method | Use it when | Notes |
|---|---|---|
| **Bluetooth (BLE)** | Chrome on an Android tablet (or Windows/Mac) with a BLE thermal printer | Prints directly from the POS. Needs a secure address — see below. |
| **RawBT app** | Android tablet with **any** Bluetooth thermal printer (also classic-Bluetooth-only models) | Install *RawBT* from Google Play, pair the printer in Android Bluetooth settings, choose RawBT in Printer setup. Works over plain http. |
| **Serial / COM port** | Windows PC with a USB printer or a Bluetooth printer paired as a COM port | Chrome / Edge, secure address needed. |
| **Browser print** | Printer installed in Windows/macOS, or as a fallback | Normal print dialog. |

**Making Bluetooth work over the LAN (http://192.168.x.x)** — browsers only allow Bluetooth on secure pages. On the tablet:
1. Open Chrome and go to `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
2. Type the server address, e.g. `http://192.168.1.10` (include the port if you use one), set it to **Enabled**, tap **Relaunch**.
3. Open the POS → Printer → Bluetooth → **Connect printer**. (Alternatively host the system with https, or use RawBT.)

Portable-printer features: remembers the printer and **reconnects automatically** when it wakes up; receipts printed
while it was asleep are **queued and printed on reconnect**; adjustable Bluetooth packet size / delay (choose a smaller
packet size if long receipts come out cut or garbled); paper feed after printing (portable printers have no cutter);
keeps the tablet screen awake while the POS is open; self-test page and a diagnostic log.

Other options: paper width 58 mm (32 characters) / 80 mm (48 characters), auto-print receipt after payment, kitchen
order slip on *Send* (with copies), reprint order slip, open the cash drawer, print ₱ as "P" or "PHP".

---

## Features

**Front of house — POS (`/pos`)**
- Open the day with a beginning cash count; **End of Day** with a blind cash count by denomination → Z-reading
  (expected vs. actual cash, over/short posted to the books, OR range, VAT breakdown, accumulated grand total). X-reading anytime.
- Take the order first, **then assign a table** (free text: "5", "12A", "Patio 2" — no fixed table setup). Change table any time.
- Item tiles by category, search / barcode, kitchen notes, *Send* prints a kitchen slip.
- Split orders (whole or partial quantities), merge orders, take-out & delivery.
- Split payments: cash, card, GCash, Maya, bank transfer, GrabFood, foodpanda, charge to a customer account (A/R).
- **Configurable discounts** (*Administration → Discounts*): per item or on the whole receipt — Senior Citizen / PWD
  (VAT-exempt + 20%, tag the senior's own items or the qualified share of the bill, with names & ID numbers), employee,
  promo %, fixed ₱, complimentary, or "open" discounts where the cashier types the value; optional manager approval.
- Voids with reasons and **manager PIN override**; voiding a paid receipt returns ingredients to stock and reverses the sale.
- Receipts search / reprint, **reprint order slip**, drawer payouts (petty cash).
- Made for Android tablets: on-screen keypads for numbers (no keyboard popping up), layout that stays usable while
  the keyboard is open, installable full-screen ("Add to Home screen").

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

### Updating an existing installation
Copy the new files over the old ones (keep `config/config.php` and `storage/`). Database changes are applied
automatically on the first page load (or run `php database/migrate.php`). Take a backup first (*Administration → Backup*).

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
