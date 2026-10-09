# Free online test server with automatic updates

Every time new code is pushed to GitHub, the **Test and deploy** workflow (`.github/workflows/deploy.yml`):
1. runs all automated tests on PHP 8.1 + MySQL 8, and
2. if they pass, uploads only the changed files to your web host by FTP.

Your settings (`config/config.php`), data, backups and logs on the server are never overwritten.
Because the free host gives you an **https://** address, the tablet can open it over any internet connection and the
Bluetooth printer works directly in Chrome (no `chrome://flags` step).

> A free host is for **testing**. For the real restaurant use the shop PC (XAMPP) or a paid host, so the POS keeps
> working when the internet is down and you are not limited by free-plan quotas.

---

## One-time setup with InfinityFree (≈ 10 minutes)

### 1. Create the free hosting account
1. Sign up at **https://www.infinityfree.com** and click **Create Account**.
2. Choose a free subdomain, e.g. `mark5pos.infinityfreeapp.com`, and finish. Wait until the account is **Active**.

### 2. Create the database
1. In the InfinityFree client area open your account → **Control Panel** → **MySQL Databases**.
2. Create a database named `mark5`. Its full name will look like **`if0_37123456_mark5`**.
3. Write down from the **MySQL Databases** page / account details:
   - **MySQL hostname** — like `sql123.infinityfree.com`
   - **MySQL username** — like `if0_37123456`
   - **MySQL password** — the account password shown under *Account details* (click *Show*)

### 3. Get the FTP details
In the client area → your account → **FTP Details**:
- **FTP hostname** — `ftpupload.net`
- **FTP username** — like `if0_37123456`
- **FTP password** — same account password as above

### 4. Give GitHub the FTP details (stored encrypted, never shown in logs)
1. Open the repository on GitHub → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**.
2. Add three secrets:

   | Name | Value |
   |---|---|
   | `FTP_SERVER` | `ftpupload.net` |
   | `FTP_USERNAME` | your FTP username, e.g. `if0_37123456` |
   | `FTP_PASSWORD` | your FTP / account password |

   (Other hosts: also add `FTP_SERVER_DIR` if your web folder is not `/htdocs/`, e.g. `/public_html/` on cPanel hosts.)

### 5. First upload
GitHub → **Actions** → **Test and deploy** → **Run workflow** (pick the branch `claude/stoic-babbage-9bom4p`).
The first upload sends every file and takes a few minutes; later updates only send what changed.

### 6. Install
1. Open your site, e.g. **https://mark5pos.infinityfreeapp.com** — the installer appears.
2. Enter the MySQL hostname, the full database name (`if0_..._mark5`), the MySQL username and password from step 2,
   choose the admin password and manager PIN, and click **Install**.
3. Log in as **admin**.

If `https://` does not open yet, use `http://` for now and issue the free certificate in the client area
(**SSL Certificates**); it can take a few minutes after the account is created.

### 7. Use it on the tablet
Open the same https address in Chrome on the tablet → **Open POS** → **Printer** → *Bluetooth* → **Connect printer**.
Tip: Chrome menu → **Add to Home screen** installs the POS full-screen.

---

## After that
Nothing to do: each push updates the site within a few minutes. To see progress or errors open **GitHub → Actions**.
A red ❌ on the *test* step means the new code failed its tests and was **not** uploaded — the site keeps the last good version.
If a deploy ever fails half-way, use **Run workflow** again.

## Troubleshooting
- **“Could not create or open the database”** — use the exact full database name (`if0_..._mark5`) and the MySQL
  hostname from the control panel (not `localhost`).
- **Login works but some pages show errors** — open `storage/logs/php-error.log` with the host's File Manager.
- **First page shows a short “loading/checking your browser”** — that is InfinityFree's free-plan protection; it only
  happens once per browser.
- **Start over** — delete the tables in phpMyAdmin (or create a new database) and delete `config/config.php` with the
  File Manager; the installer appears again.
