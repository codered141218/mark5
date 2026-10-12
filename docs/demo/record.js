/**
 * Records the showcase scenes with Playwright. Each scene is its own browser context (one .webm each) and a list
 * of captions with the time they appeared, written to scenes.json for the ffmpeg assembly step.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'rec');
const W = 1440, H = 900;
const FROM = '2026-09-21', TO = '2026-10-11';
const ONLY = process.argv.slice(2);
const PREV = ONLY.length && fs.existsSync(path.join(OUT, 'scenes.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'scenes.json'))) : [];
if (!ONLY.length) fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Fake cursor + click ripple + no scrollbars, injected into every page.
const INIT = `
  (() => {
    const css = document.createElement('style');
    css.textContent = '::-webkit-scrollbar{width:0;height:0}' +
      '#__cur{position:fixed;left:0;top:0;width:22px;height:30px;z-index:2147483647;pointer-events:none;transform:translate(-3px,-2px);filter:drop-shadow(0 2px 3px rgba(0,0,0,.45))}' +
      '#__rip{position:fixed;width:40px;height:40px;border-radius:50%;border:3px solid #c2410c;z-index:2147483646;pointer-events:none;opacity:0;transform:translate(-50%,-50%) scale(.3)}' +
      '#__rip.go{animation:__r .45s ease-out}' +
      '@keyframes __r{0%{opacity:.9;transform:translate(-50%,-50%) scale(.3)}100%{opacity:0;transform:translate(-50%,-50%) scale(1.4)}}';
    document.addEventListener('DOMContentLoaded', () => {
      document.head.appendChild(css);
      const c = document.createElement('div'); c.id = '__cur';
      c.innerHTML = '<svg viewBox="0 0 22 30" width="22" height="30"><path d="M2 2 L2 23 L7.5 18 L11 27 L15 25.5 L11.5 17 L19 17 Z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>';
      const r = document.createElement('div'); r.id = '__rip';
      document.body.appendChild(c); document.body.appendChild(r);
      c.style.display = 'none';
      window.addEventListener('mousemove', (e) => { c.style.display = ''; c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; }, true);
      window.addEventListener('mousedown', (e) => { r.style.left = e.clientX + 'px'; r.style.top = e.clientY + 'px'; r.classList.remove('go'); void r.offsetWidth; r.classList.add('go'); }, true);
    });
  })();`;

async function scene(name, opts, fn) {
  if (ONLY.length && !ONLY.includes(name)) { const old = PREV.find((x) => x.name === name); if (old) { console.log(name + ': kept'); return old; } }
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: opts.w || W, height: opts.h || H },
    deviceScaleFactor: 1,
    hasTouch: !!opts.touch,
    isMobile: !!opts.mobile,
    recordVideo: { dir: OUT, size: { width: opts.w || W, height: opts.h || H } },
    storageState: opts.state || undefined,
    locale: 'en-PH',
    timezoneId: 'Asia/Manila',
  });
  await ctx.addInitScript(INIT);
  const page = await ctx.newPage();
  const t0 = Date.now();
  const caps = [];
  const mark = (text) => caps.push({ t: (Date.now() - t0) / 1000, text });
  const mouse = { x: 40, y: 40 };
  const api = {
    page, mark, sleep,
    go: async (url) => { await page.goto(BASE + url, { waitUntil: 'networkidle' }); await sleep(250); },
    moveTo: async (x, y, steps = 22) => { await page.mouse.move(x, y, { steps }); mouse.x = x; mouse.y = y; },
    tap: async (sel, { pause = 700, nth = 0, exact } = {}) => {
      const loc = (typeof sel === 'string' ? page.locator(sel) : sel).nth(nth);
      await loc.waitFor({ state: 'visible', timeout: 10000 });
      await loc.scrollIntoViewIfNeeded();
      const b = await loc.boundingBox();
      const x = b.x + b.width / 2, y = b.y + b.height / 2;
      const dist = Math.hypot(x - mouse.x, y - mouse.y);
      await api.moveTo(x, y, Math.max(8, Math.min(30, Math.round(dist / 25))));
      await sleep(120);
      await page.mouse.down(); await sleep(70); await page.mouse.up();
      mouse.x = x; mouse.y = y;
      await sleep(pause);
    },
    type: async (sel, text, delay = 55) => { await api.tap(sel, { pause: 150 }); await page.keyboard.type(text, { delay }); await sleep(250); },
    // Smooth scroll of the back-office content pane (or any element)
    scroll: async (dy, { sel = '.main', ms = 900 } = {}) => {
      await page.evaluate(({ sel, dy, ms }) => new Promise((res) => {
        const el = document.querySelector(sel) || document.scrollingElement;
        const start = el.scrollTop, t0 = performance.now();
        const step = (t) => { const k = Math.min(1, (t - t0) / ms); const e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k; el.scrollTop = start + dy * e; if (k < 1) requestAnimationFrame(step); else res(); };
        requestAnimationFrame(step);
      }), { sel, dy, ms });
      await sleep(200);
    },
    hover: async (sel) => { const b = await page.locator(sel).first().boundingBox(); if (b) await api.moveTo(b.x + b.width / 2, b.y + b.height / 2); },
  };
  await api.moveTo(mouse.x, mouse.y, 1);
  let err = null;
  try { await fn(api); } catch (e) { err = e; console.error(`scene ${name} failed:`, e); }
  const dur = (Date.now() - t0) / 1000;
  const video = page.video();
  await ctx.close();
  const file = await video.path();
  const dest = path.join(OUT, `${name}.webm`);
  fs.renameSync(file, dest);
  await browser.close();
  console.log(`${name}: ${dur.toFixed(1)}s, ${caps.length} captions${err ? ' (ERROR)' : ''}`);
  return { name, file: dest, dur, caps, w: opts.w || W, h: opts.h || H, error: err ? String(err) : null };
}

async function login(user, pass, stateFile) {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  await p.goto(BASE + '/login');
  await p.fill('[name=username]', user); await p.fill('[name=password]', pass);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await ctx.storageState({ path: stateFile });
  await browser.close();
}

(async () => {
  const admin = path.join(OUT, 'admin.json');
  const cashier = path.join(OUT, 'cashier.json');
  await login('admin', 'admin123', admin);
  await login('maria', 'cashier123', cashier);
  const scenes = [];

  // ---------------------------------------------------------------- 1. login + dashboard
  scenes.push(await scene('01-login', {}, async (s) => {
    await s.go('/login');
    s.mark('Sign in — plain PHP 8 + MySQL, nothing to install on the client');
    await s.sleep(600);
    await s.type('[name=username]', 'admin', 70);
    await s.type('[name=password]', 'admin123', 60);
    await s.tap('button[type=submit]', { pause: 300 });
    await s.page.waitForURL(BASE + '/');
    await s.page.waitForLoadState('networkidle');
    s.mark('Dashboard — net sales, gross profit, food cost % and cash position for any date range');
    await s.sleep(900);
    await s.go(`/?from=${FROM}&to=${TO}`);
    await s.sleep(1600);
    await s.scroll(520, { ms: 1400 });
    await s.sleep(1400);
    s.mark('Top items, payment mix, busiest hours, AP / AR and bank balances — all from the same database');
    await s.scroll(700, { ms: 1500 });
    await s.sleep(1800);
  }));

  // ---------------------------------------------------------------- 2. POS as the cashier
  scenes.push(await scene('02-pos', { state: cashier }, async (s) => {
    const p = s.page;
    await s.go('/pos');
    await p.waitForSelector('.order-card');
    s.mark('POS — the orders board: open tables, take-outs and deliveries with elapsed time');
    await s.sleep(2200);
    await s.tap('[data-act=new][data-type=dine_in]');
    s.mark('Take the order first: tap items by category, search or scan a barcode');
    await p.waitForSelector('.tile');
    await p.evaluate(() => document.activeElement && document.activeElement.blur());
    await s.sleep(400);
    await s.tap('.cat-btn:has-text("Ulam")', { pause: 500 });
    await s.tap('.tile:has-text("Sizzling Pork Sisig")');
    await s.tap('.tile:has-text("Chicken Adobo")');
    await s.tap('.cat-btn:has-text("Rice Meals")', { pause: 500 });
    await s.tap('.tile:has-text("Garlic Rice")', { pause: 350 });
    await s.tap('.tile:has-text("Garlic Rice")');
    await s.tap('.cat-btn:has-text("Beverages")', { pause: 500 });
    await s.tap('.tile:has-text("House Iced Tea")', { pause: 350 });
    await s.tap('.tile:has-text("House Iced Tea")');
    await s.sleep(500);
    s.mark('…then assign a table: tap a chip or type any label (“12A”, “Patio 2”)');
    await s.tap('.table-badge');
    await p.waitForSelector('.pos-dlg .table-chip');
    await s.sleep(500);
    await s.tap('.pos-dlg .table-chip:has-text("12")', { pause: 900 });
    s.mark('Send prints the kitchen slip; sent items are marked on the ticket');
    await s.tap('[data-act=send]', { pause: 1500 });
    s.mark('Senior Citizen / PWD: VAT-exempt + 20%, names & ID numbers kept for the BIR sales book');
    await s.tap('[data-act=discount]');
    await p.waitForSelector('.pos-dlg .preset');
    await s.tap('.pos-dlg .preset:has-text("Senior Citizen")', { pause: 700 });
    await s.type('.pos-dlg input[data-k=name]', 'Nena Dela Cruz', 45);
    await s.type('.pos-dlg input[data-k=id_no]', 'OSCA-10234', 45);
    await s.tap('.pos-dlg [data-act=scApply]', { pause: 600 });
    s.mark('The cashier cannot discount on her own — a manager approves with a PIN');
    await p.waitForSelector('.dlg-pin');
    await s.sleep(500);
    for (const k of ['2', '4', '6', '8']) await s.tap(`.dlg-pin [data-key="${k}"]`, { pause: 220 });
    await s.tap('.dlg-pin button[type=submit]', { pause: 1600 });
    s.mark('Split payments: cash, card, GCash, Maya, GrabFood, foodpanda or charge to a customer account');
    await s.tap('[data-act=pay]');
    await p.waitForSelector('.dlg-pay');
    await s.sleep(700);
    await s.tap('.dlg-pay [data-act=method][data-m=gcash]', { pause: 500 });
    await s.type('.dlg-pay [name=reference]', 'GC-77120', 40);
    for (const k of ['2', '0', '0']) await s.tap(`.dlg-pay [data-key="${k}"]`, { pause: 160 });
    await s.tap('.dlg-pay [data-act=add]', { pause: 700 });
    await s.tap('.dlg-pay [data-act=method][data-m=cash]', { pause: 500 });
    await s.tap('.dlg-pay [data-act=quick]', { nth: 1, pause: 800 });
    await s.tap('.dlg-pay .pay-complete', { pause: 400 });
    await p.waitForSelector('.dlg-paid');
    s.mark('Receipt, change and the journal entry (sales, VAT, COGS, stock) are all posted at once');
    await s.sleep(2200);
    await s.tap('.dlg-paid [data-act=board]', { pause: 800 });
    s.mark('Receipts: search, preview, reprint — or void with a reason and a manager PIN');
    await s.tap('[data-act=receipts]');
    await p.waitForSelector('.rcpt-row');
    await s.sleep(400);
    await s.tap('.rcpt-row', { nth: 0, pause: 500 });
    await p.waitForSelector('.rcpt-right .receipt-preview');
    await s.scroll(260, { sel: '.rcpt-right', ms: 1200 });
    await s.sleep(1400);
    await s.tap('.dlg-receipts [data-x]', { pause: 500 });
    s.mark('X-reading any time; End of Day = blind cash count by denomination → Z-reading, over/short posted to the books');
    await s.tap('[data-act=xread]');
    await p.waitForSelector('.dlg-reading');
    await s.scroll(300, { sel: '.dlg-reading .modal-body', ms: 1400 });
    await s.sleep(900);
    await s.tap('.dlg-reading [data-x]', { pause: 500 });
    await s.tap('[data-act=eod]');
    await p.waitForSelector('.dlg-cash');
    await s.sleep(400);
    await s.tap('.dlg-cash [data-denom="1000"]', { pause: 250 });
    await s.tap('.dlg-cash [data-key="3"]', { pause: 250 });
    await s.tap('.dlg-cash [data-key="next"]', { pause: 250 });
    await s.tap('.dlg-cash [data-key="1"]', { pause: 200 });
    await s.tap('.dlg-cash [data-key="2"]', { pause: 250 });
    await s.tap('.dlg-cash [data-key="next"]', { pause: 250 });
    await s.tap('.dlg-cash [data-key="8"]', { pause: 1200 });
    await s.tap('.dlg-cash [data-x]', { pause: 600 });
  }));

  // ---------------------------------------------------------------- 3. inventory & recipes
  scenes.push(await scene('03-inventory', { state: admin }, async (s) => {
    await s.go('/inventory/items');
    s.mark('Inventory — ingredients, menu items with recipes, retail goods; moving-average cost and reorder points');
    await s.sleep(1500);
    await s.scroll(380, { ms: 1300 });
    await s.sleep(900);
    await s.tap('.table a:has-text("Sizzling Pork Sisig"), .table tr:has-text("Sizzling Pork Sisig") td', { pause: 400 });
    await s.page.waitForLoadState('networkidle');
    s.mark('Recipes in grams / ml with unit conversions; sub-recipes allowed (meal = viand + rice)');
    await s.sleep(800);
    await s.scroll(560, { ms: 1500 });
    s.mark('Menu costing: recipe cost vs. selling price → food cost % with a target band');
    await s.sleep(2200);
    await s.go('/inventory/receiving');
    s.mark('Delivery / stock-in: cash, bank, petty cash or on credit (→ accounts payable), VAT-inclusive costs');
    await s.sleep(1500);
    await s.tap('.table tbody tr', { nth: 0, pause: 400 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(1800);
    await s.scroll(300, { ms: 1000 });
    await s.sleep(800);
    await s.go('/inventory/counts');
    s.mark('Inventory count sessions: actual vs. system, variance posted automatically');
    await s.sleep(1200);
    await s.tap('.table tbody tr', { nth: 0, pause: 400 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(1600);
    await s.scroll(250, { ms: 900 });
    await s.sleep(800);
  }));

  // ---------------------------------------------------------------- 4. reports
  scenes.push(await scene('04-reports', { state: admin }, async (s) => {
    await s.go(`/reports/sales?report=daily&from=${FROM}&to=${TO}`);
    s.mark('Sales reports by date, item, category, receipt, payment method, hour, cashier, voids, SC/PWD book');
    await s.sleep(1500);
    await s.scroll(300, { ms: 1100 });
    await s.sleep(600);
    await s.go(`/reports/sales?report=hourly&from=${FROM}&to=${TO}`);
    await s.sleep(1600);
    await s.go(`/reports/sales?report=eod&from=${FROM}&to=${TO}`);
    s.mark('Z-readings: OR range, VAT breakdown, expected vs. counted cash, accumulated grand total');
    await s.sleep(1300);
    await s.tap('.table tbody tr', { nth: 0, pause: 400 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(1600);
    await s.scroll(400, { ms: 1300 });
    await s.sleep(900);
    await s.go('/reports/inventory?report=recipe');
    s.mark('Inventory reports: stock on hand & valuation, reorder suggestion, movements, stock card, usage, menu costing');
    await s.sleep(2000);
    await s.go('/reports/inventory?report=onhand');
    await s.sleep(1600);
    await s.go(`/reports/finance?report=is&from=2026-09-01&to=${TO}`);
    s.mark('Double-entry books: income statement, balance sheet, trial balance, general ledger, journal, aging');
    await s.sleep(1600);
    await s.scroll(420, { ms: 1300 });
    await s.sleep(900);
    await s.go(`/reports/finance?report=bs&to=${TO}`);
    await s.sleep(1800);
    await s.scroll(300, { ms: 1000 });
    await s.sleep(700);
    await s.go(`/reports/finance?report=ap&to=${TO}`);
    await s.sleep(1700);
    s.mark('Every list and report exports to Excel');
    await s.hover('a:has-text("Excel")');
    await s.sleep(1300);
  }));

  // ---------------------------------------------------------------- 5. cash & finance
  scenes.push(await scene('05-finance', { state: admin }, async (s) => {
    await s.go('/finance/banks');
    s.mark('Banks & e-wallets: money in / out / transfers, card settlement charges — each one posted to the ledger');
    await s.sleep(2000);
    await s.go('/petty-cash');
    s.mark('Petty cash fund and drawer payouts (ice, LPG, fare…) with replenishment');
    await s.sleep(1900);
    await s.go('/finance/payables');
    s.mark('Accounts payable from deliveries on credit; receivables from charge accounts; aging for both');
    await s.sleep(1900);
    await s.go('/cash-advances');
    s.mark('Cash advances: request → approve (posts to the GL) → repay by salary deduction, cash or bank');
    await s.sleep(1900);
    await s.go('/finance/journals');
    s.mark('Journal entries — the automatic ones from POS, stock and cash, plus manual entries');
    await s.sleep(1300);
    await s.tap('.table tbody tr:has-text("POS sale")', { nth: 0, pause: 400 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(2400);
  }));

  // ---------------------------------------------------------------- 6. administration
  scenes.push(await scene('06-admin', { state: admin }, async (s) => {
    await s.go('/admin/roles');
    s.mark('Users, roles & 40+ permissions — cashier, waiter, inventory clerk, accountant, manager');
    await s.sleep(800);
    await s.tap('a:has-text("Cashier")', { pause: 400 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(1000);
    await s.scroll(380, { ms: 1200 });
    await s.sleep(900);
    await s.go('/admin/discounts');
    s.mark('Configurable discounts: SC / PWD, employee, promo %, fixed ₱, complimentary, open amount; optional approval');
    await s.sleep(2200);
    await s.go('/printer');
    s.mark('Printer setup per device: Bluetooth (BLE), RawBT, serial / USB or browser print; 58 / 80 mm paper');
    await s.sleep(1500);
    await s.scroll(380, { ms: 1300 });
    await s.sleep(1000);
    await s.go('/admin/backup');
    s.mark('Backup & restore in pure PHP (no mysqldump); automatic daily backups with retention');
    await s.sleep(2000);
    await s.go('/admin/audit');
    s.mark('Audit trail of every sensitive action: voids, discounts, logins, settings, day open / close');
    await s.sleep(2200);
  }));

  // ---------------------------------------------------------------- 7. tablet + phone
  scenes.push(await scene('07-tablet', { state: cashier, w: 1024, h: 768, touch: true }, async (s) => {
    const p = s.page;
    await s.go('/pos');
    await p.waitForSelector('.order-card');
    s.mark('Made for Android tablets: big tiles, on-screen keypads, installable full-screen app');
    await s.sleep(800);
    await s.tap('.order-card', { nth: 0, pause: 900 });
    await p.waitForSelector('.tile');
    await s.tap('.cat-btn:has-text("Desserts")', { pause: 500 });
    await s.tap('.tile:has-text("Halo-halo")', { pause: 700 });
    await s.tap('[data-act=pay]');
    await p.waitForSelector('.dlg-pay');
    await s.sleep(500);
    for (const k of ['1', '5', '0', '0']) await s.tap(`.dlg-pay [data-key="${k}"]`, { pause: 200 });
    await s.sleep(1200);
    await s.tap('.dlg-pay [data-x]', { pause: 400 });
    await s.tap('[data-act=done]', { pause: 600 });
  }));

  scenes.push(await scene('08-phone', { state: cashier, w: 390, h: 844, touch: true, mobile: true }, async (s) => {
    const p = s.page;
    await s.go('/pos');
    await p.waitForSelector('.order-card');
    s.mark('…and on phones: menu and order panes with a switcher');
    await s.sleep(900);
    await s.tap('.order-card', { nth: 1, pause: 900 });
    await s.tap('[data-act=pane][data-pane=menu]', { pause: 700 }).catch(() => {});
    await p.waitForSelector('.tile');
    await s.tap('.tile:has-text("Bottled Water")', { pause: 600 });
    await s.tap('[data-act=pane][data-pane=order]', { pause: 1400 });
  }));

  fs.writeFileSync(path.join(OUT, 'scenes.json'), JSON.stringify(scenes, null, 2));
  console.log('total', scenes.reduce((a, s) => a + s.dur, 0).toFixed(1), 's');
})();
