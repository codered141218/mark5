/**
 * Recording toolkit for the M5 ad: per-scene browser contexts with a visible cursor, three device presets, logins
 * for the owner / cashier / waiter accounts, and a library of named "shots" (real screen actions) that scenes compose.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const DEVICES = {
  phone: { w: 390, h: 844, mobile: true, touch: true },
  tablet: { w: 1024, h: 768, touch: true },
  desktop: { w: 1440, h: 900 },
};
const USERS = { admin: ['admin', 'admin123'], maria: ['maria', 'cashier123'], ramon: ['ramon', 'waiter123'] };

const INIT = `
  (() => {
    const css = document.createElement('style');
    css.textContent = '::-webkit-scrollbar{width:0;height:0}' +
      '#__cur{position:fixed;left:0;top:0;width:22px;height:30px;z-index:2147483647;pointer-events:none;transform:translate(-3px,-2px);filter:drop-shadow(0 2px 3px rgba(0,0,0,.45))}' +
      '#__cur.touch{width:34px;height:34px;border-radius:50%;background:rgba(17,24,39,.28);border:2px solid rgba(255,255,255,.9);transform:translate(-50%,-50%)}' +
      '#__rip{position:fixed;width:44px;height:44px;border-radius:50%;border:3px solid #c2410c;z-index:2147483646;pointer-events:none;opacity:0;transform:translate(-50%,-50%) scale(.3)}' +
      '#__rip.go{animation:__r .45s ease-out}' +
      '@keyframes __r{0%{opacity:.9;transform:translate(-50%,-50%) scale(.3)}100%{opacity:0;transform:translate(-50%,-50%) scale(1.4)}}' +
      '#__slip{position:fixed;inset:0;z-index:2147483600;background:rgba(17,24,39,.72);display:flex;align-items:center;justify-content:center;gap:36px;opacity:0;transition:opacity .35s}' +
      '#__slip.on{opacity:1}' +
      '#__slip .paper{background:#fff;padding:18px 16px;border-radius:4px;max-width:92vw;box-shadow:0 30px 60px rgba(0,0,0,.5);transform:translateY(40px);transition:transform .5s ease-out;position:relative}' +
      '#__slip.on .paper{transform:none}' +
      '#__slip .tag{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:#c2410c;color:#fff;font:700 13px/1 system-ui;padding:7px 12px;border-radius:999px;white-space:nowrap}';
    document.addEventListener('DOMContentLoaded', () => {
      document.head.appendChild(css);
      const touch = matchMedia('(pointer: coarse)').matches;
      const c = document.createElement('div'); c.id = '__cur'; if (touch) c.className = 'touch';
      if (!touch) c.innerHTML = '<svg viewBox="0 0 22 30" width="22" height="30"><path d="M2 2 L2 23 L7.5 18 L11 27 L15 25.5 L11.5 17 L19 17 Z" fill="#fff" stroke="#111" stroke-width="1.6" stroke-linejoin="round"/></svg>';
      const r = document.createElement('div'); r.id = '__rip';
      document.body.appendChild(c); document.body.appendChild(r);
      c.style.display = 'none';
      window.addEventListener('mousemove', (e) => { c.style.display = ''; c.style.left = e.clientX + 'px'; c.style.top = e.clientY + 'px'; }, true);
      window.addEventListener('mousedown', (e) => { r.style.left = e.clientX + 'px'; r.style.top = e.clientY + 'px'; r.classList.remove('go'); void r.offsetWidth; r.classList.add('go'); }, true);
    });
  })();`;

async function login(user, dir) {
  const [u, pw] = USERS[user];
  const file = path.join(dir, `${user}.json`);
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  await p.goto(BASE + '/login');
  await p.fill('[name=username]', u); await p.fill('[name=password]', pw);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await ctx.storageState({ path: file });
  await browser.close();
  return file;
}

/** Record one scene. Returns {name, file, dur (scripted seconds), target, w, h, marks}. */
async function record(def, states, outDir) {
  const dev = DEVICES[def.device];
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: dev.w, height: dev.h }, deviceScaleFactor: 1, hasTouch: !!dev.touch, isMobile: !!dev.mobile,
    recordVideo: { dir: outDir, size: { width: dev.w, height: dev.h } }, storageState: states[def.who], locale: 'en-PH', timezoneId: 'Asia/Manila',
  });
  await ctx.addInitScript(INIT);
  const page = await ctx.newPage();
  const t0 = Date.now();
  const mouse = { x: 30, y: 30 };
  const marks = {};
  const s = {
    page, sleep, dev,
    mark: (k) => { marks[k] = (Date.now() - t0) / 1000; },
    elapsed: () => (Date.now() - t0) / 1000,
    until: async (sec) => { const left = sec * 1000 - (Date.now() - t0); if (left > 0) await sleep(left); },
    go: async (url) => { await page.goto(BASE + url, { waitUntil: 'networkidle' }); await sleep(200); },
    moveTo: async (x, y, steps = 18) => { await page.mouse.move(x, y, { steps }); mouse.x = x; mouse.y = y; },
    tap: async (sel, { pause = 500, nth = 0 } = {}) => {
      const loc = (typeof sel === 'string' ? page.locator(sel) : sel).nth(nth);
      await loc.waitFor({ state: 'visible', timeout: 10000 });
      await loc.scrollIntoViewIfNeeded();
      const b = await loc.boundingBox();
      const x = b.x + b.width / 2, y = b.y + b.height / 2;
      const dist = Math.hypot(x - mouse.x, y - mouse.y);
      await s.moveTo(x, y, Math.max(6, Math.min(22, Math.round(dist / 30))));
      await sleep(90);
      await page.mouse.down(); await sleep(60); await page.mouse.up();
      mouse.x = x; mouse.y = y;
      await sleep(pause);
    },
    type: async (sel, text, delay = 40) => { await s.tap(sel, { pause: 120 }); await page.keyboard.type(text, { delay }); await sleep(200); },
    scroll: async (dy, { sel = '.main', ms = 900 } = {}) => {
      await page.evaluate(({ sel, dy, ms }) => new Promise((res) => {
        const el = document.querySelector(sel) || document.scrollingElement;
        const start = el.scrollTop, t0 = performance.now();
        const step = (t) => { const k = Math.min(1, (t - t0) / ms); const e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k; el.scrollTop = start + dy * e; if (k < 1) requestAnimationFrame(step); else res(); };
        requestAnimationFrame(step);
      }), { sel, dy, ms });
      await sleep(150);
    },
    blur: () => page.evaluate(() => document.activeElement && document.activeElement.blur()),
  };
  await s.moveTo(mouse.x, mouse.y, 1);
  let err = null;
  try {
    for (const step of def.steps) {
      const [name, opts] = Array.isArray(step) ? step : [step, {}];
      if (!SHOTS[name]) throw new Error('unknown shot ' + name);
      await SHOTS[name](s, opts || {});
    }
    if (def.target) await s.until(def.target + 0.3);
  } catch (e) { err = e; console.error(`scene ${def.name} failed:`, e); }
  const dur = (Date.now() - t0) / 1000;
  const video = page.video();
  await ctx.close();
  const dest = path.join(outDir, `${def.name}.webm`);
  fs.renameSync(await video.path(), dest);
  await browser.close();
  console.log(`${def.name}: ${dur.toFixed(1)}s scripted (target ${def.target}s)${err ? ' ERROR' : ''}`);
  return { name: def.name, file: dest, dur, target: def.target, w: dev.w, h: dev.h, marks, error: err ? String(err) : null };
}

// ===================================================================== shot library
const SHOTS = {
  // ---------- any
  go: (s, { url, settle = 300 }) => s.go(url).then(() => s.sleep(settle)),
  wait: (s, { ms = 500 }) => s.sleep(ms),
  until: (s, { sec }) => s.until(sec),
  mark: (s, { key }) => s.mark(key),
  scroll: (s, { dy = 400, ms = 1200, sel = '.main' }) => s.scroll(dy, { ms, sel }),

  // ---------- waiter / cashier POS
  pos_board: async (s) => { await s.go('/pos'); await s.page.waitForSelector('.order-card, .board-empty'); await s.sleep(300); },
  pos_new_order: async (s, { type = 'dine_in' }) => {
    await s.tap(`[data-act=new][data-type=${type}]`, { pause: 400 });
    await s.page.waitForSelector('.tile');
    await s.blur();
    await s.sleep(200);
  },
  pos_category: async (s, { name }) => { await s.tap(`.cat-btn:has-text("${name}")`, { pause: 350 }); },
  pos_items: async (s, { items, pause = 380 }) => { for (const it of items) await s.tap(`.tile:has-text("${it}")`, { pause }); },
  pos_pane: async (s, { pane }) => { await s.tap(`[data-act=pane][data-pane=${pane}]`, { pause: 500 }).catch(() => {}); },
  pos_line_note: async (s, { item, chip, text }) => {
    await s.tap(`.tline:has-text("${item}")`, { pause: 450 });
    await s.page.waitForSelector('.pos-dlg [name=notes]');
    if (chip) await s.tap(`.pos-dlg [data-act=note][data-note="${chip}"]`, { pause: 350 });
    if (text) await s.type('.pos-dlg [name=notes]', text, 45);
    await s.tap('.pos-dlg button[type=submit]', { pause: 500 });
  },
  pos_table: async (s, { label }) => {
    await s.tap('.table-badge', { pause: 400 });
    await s.page.waitForSelector('.pos-dlg .table-chip');
    const dlg = s.page.locator('.pos-dlg').last();
    const chip = dlg.locator('.table-chip').filter({ hasText: new RegExp(`^${label}(in use)?$`) });
    if (await chip.count()) await s.tap(chip, { pause: 500 });
    else { for (const ch of String(label)) await s.tap(dlg.locator(`.table-keys [data-key="${ch}"]`), { pause: 160 }); }
    // a chip of a table that is already in use only warns: confirm with "Assign table"
    if (await dlg.isVisible().catch(() => false)) await s.tap(dlg.locator('button[type=submit]'), { pause: 500 });
  },
  pos_send: async (s, { pause = 900 }) => { await s.tap('[data-act=send]', { pause }); },
  pos_open: async (s, { text }) => { await s.tap(`.order-card:has-text("${text}")`, { pause: 700 }); await s.page.waitForSelector('[data-act=send]'); },
  pos_done: async (s) => { await s.tap('[data-act=done]', { pause: 500 }); },
  pos_split: async (s, { item, table }) => {
    await s.tap('[data-act=split]', { pause: 500 });
    await s.page.waitForSelector('.pos-dlg .split-line');
    await s.tap(`.pos-dlg .split-line:has-text("${item}") [data-act=whole]`, { pause: 400 });
    await s.tap('.pos-dlg [data-act=table]', { pause: 400 });
    await s.page.waitForSelector('.dlg-table .table-chip');
    const tdlg = s.page.locator('.dlg-table').last();
    await s.tap(tdlg.locator('.table-chip').filter({ hasText: new RegExp(`^${table}(in use)?$`) }), { pause: 450 });
    if (await tdlg.isVisible().catch(() => false)) await s.tap(tdlg.locator('button[type=submit]'), { pause: 400 });
    await s.tap('.dlg-split button[type=submit]', { pause: 900 });
    s.mark('split');
  },
  pos_pay: async (s, { pays = [], quick = null, complete = true, holdPaid = 1500 }) => {
    await s.tap('[data-act=pay]', { pause: 300 });
    await s.page.waitForSelector('.dlg-pay');
    await s.sleep(400);
    for (const p of pays) {
      await s.tap(`.dlg-pay [data-act=method][data-m=${p.method}]`, { pause: 350 });
      if (p.ref) await s.type('.dlg-pay [name=reference]', p.ref, 35);
      if (p.amount) { for (const k of String(p.amount)) await s.tap(`.dlg-pay [data-key="${k}"]`, { pause: 140 }); await s.tap('.dlg-pay [data-act=add]', { pause: 500 }); }
    }
    if (quick !== null) { await s.tap('.dlg-pay [data-act=method][data-m=cash]', { pause: 350 }); await s.tap('.dlg-pay [data-act=quick]', { nth: quick, pause: 600 }); }
    if (complete) {
      await s.tap('.dlg-pay .pay-complete', { pause: 300 });
      await s.page.waitForSelector('.dlg-paid');
      s.mark('paid');
      await s.sleep(holdPaid);
    }
  },
  pos_paid_board: async (s) => { await s.tap('.dlg-paid [data-act=board]', { pause: 600 }); },
  pos_discount_sc: async (s, { name, idNo, pin = '2468' }) => {
    await s.tap('[data-act=discount]', { pause: 300 });
    await s.page.waitForSelector('.pos-dlg .preset');
    await s.tap('.pos-dlg .preset:has-text("Senior Citizen")', { pause: 500 });
    await s.type('.pos-dlg input[data-k=name]', name, 38);
    await s.type('.pos-dlg input[data-k=id_no]', idNo, 38);
    await s.tap('.pos-dlg [data-act=scApply]', { pause: 400 });
    await s.page.waitForSelector('.dlg-pin');
    s.mark('pin');
    await s.sleep(400);
    for (const k of pin) await s.tap(`.dlg-pin [data-key="${k}"]`, { pause: 180 });
    await s.tap('.dlg-pin button[type=submit]', { pause: 1200 });
  },
  pos_void_line: async (s, { item, reason, pin = '2468' }) => {
    await s.tap(`.tline:has-text("${item}")`, { pause: 450 });
    await s.page.waitForSelector('.pos-dlg [name=reason]');
    await s.type('.pos-dlg [name=reason]', reason, 40);
    await s.tap('.pos-dlg [data-act=void]', { pause: 400 });
    await s.page.waitForSelector('.dlg-pin');
    s.mark('pin');
    for (const k of pin) await s.tap(`.dlg-pin [data-key="${k}"]`, { pause: 180 });
    await s.tap('.dlg-pin button[type=submit]', { pause: 1000 });
  },
  pos_receipts: async (s, { pick = 0, scroll = 240, hold = 1200, close = true }) => {
    await s.tap('[data-act=receipts]', { pause: 300 });
    await s.page.waitForSelector('.rcpt-row');
    await s.sleep(300);
    await s.tap('.rcpt-row', { nth: pick, pause: 500 });
    await s.page.waitForSelector('.rcpt-right .receipt-preview');
    if (scroll) await s.scroll(scroll, { sel: '.rcpt-right', ms: 1000 });
    await s.sleep(hold);
    if (close) await s.tap('.dlg-receipts [data-x]', { pause: 400 });
  },
  pos_xread: async (s, { scroll = 300, hold = 900, close = true }) => {
    await s.tap('[data-act=xread]', { pause: 300 });
    await s.page.waitForSelector('.dlg-reading');
    await s.scroll(scroll, { sel: '.dlg-reading .modal-body', ms: 1200 });
    await s.sleep(hold);
    if (close) await s.tap('.dlg-reading [data-x]', { pause: 400 });
  },
  pos_eod: async (s, { counts = [['1000', '3'], ['500', '12'], ['100', '8']], close = true, hold = 900 }) => {
    await s.tap('[data-act=eod]', { pause: 300 });
    await s.page.waitForSelector('.dlg-cash');
    await s.sleep(300);
    for (const [d, n] of counts) {
      await s.tap(`.dlg-cash [data-denom="${d}"]`, { pause: 200 });
      for (const k of n) await s.tap(`.dlg-cash [data-key="${k}"]`, { pause: 170 });
    }
    await s.sleep(hold);
    if (close) await s.tap('.dlg-cash [data-x]', { pause: 400 });
  },
  zreading_yesterday: async (s) => {
    const id = await s.page.evaluate(async () => {
      const base = document.querySelector('meta[name=base-url]').content.replace(/\/$/, '');
      const r = await fetch(base + '/api/pos/orders', { headers: { Accept: 'application/json' } });
      const d = await r.json();
      return d.session ? d.session.id - 1 : null;   // the session before today's = yesterday's closed day
    });
    await s.go(`/reports/eod/${id}`);
    s.mark('zreading');
  },
  pos_kitchen_slip_overlay: async (s, { tags = ['KITCHEN · copy 1', 'BAR · copy 2'], find = 'delivery' }) => {
    await s.page.evaluate(async ({ tags, find }) => {
      const base = document.querySelector('meta[name=base-url]').content.replace(/\/$/, '');
      const d = await (await fetch(base + '/api/pos/orders', { headers: { Accept: 'application/json' } })).json();
      const list = d.orders || d;
      const o = list.find((x) => x.order_type === find || String(x.table_label) === find || (x.customer_name || '').includes(find)) || list[0];
      const t = await (await fetch(base + `/api/pos/orders/${o.id}`, { headers: { Accept: 'application/json' } })).json();
      const tk = t.ticket || t;
      const lines = tk.items.filter((l) => l.status === 'active');
      const I = window.Printer._internals;
      const slip = (title) => `<div class="paper"><span class="tag">${title}</span><div class="receipt-preview"><div class="paper-58">${I.html(I.slipDoc(tk, lines, 'KITCHEN ORDER', false), 58)}</div></div></div>`;
      const box = document.createElement('div'); box.id = '__slip';
      box.innerHTML = tags.map(slip).join('');
      document.body.appendChild(box);
      requestAnimationFrame(() => box.classList.add('on'));
    }, { tags, find });
  },

  // ---------- back office
  open_row: async (s, { text, nth = 0, pause = 400 }) => { await s.tap(`.table tbody tr:has-text("${text}")`, { nth, pause }); await s.page.waitForLoadState('networkidle'); },
  hover: async (s, { sel }) => { const b = await s.page.locator(sel).first().boundingBox(); if (b) await s.moveTo(b.x + b.width / 2, b.y + b.height / 2); },

  // ---------- payroll
  payroll_import: async (s, { file, device = 'ZKTeco K40 · kitchen door' }) => {
    await s.tap('[data-open=dlg-import]', { pause: 450 });
    await s.page.setInputFiles('#dlg-import input[type=file]', file);
    await s.sleep(250);
    await s.type('#dlg-import [name=device]', device, 26);
    await s.tap('#dlg-import button[type=submit]', { pause: 200 });
    await s.page.waitForLoadState('networkidle');
    s.mark('imported');
  },
  payroll_run: async (s, { post = true, settle = 600 }) => {
    await s.tap('[data-open=dlg-run]', { pause: 450 });
    await s.tap('#dlg-run button[type=submit]', { pause: 150 });
    await s.page.waitForLoadState('networkidle');
    s.mark('computed');
    await s.sleep(settle);
    if (post) {
      await s.tap('form[action$="/post"] button[type=submit]', { pause: 400 });
      await s.page.waitForSelector('dialog.modal button[value=ok]');
      await s.tap('dialog.modal button[value=ok]', { pause: 150 });
      await s.page.waitForLoadState('networkidle');
      s.mark('posted');
    }
  },
  payroll_payslips: async (s) => {
    const id = s.page.url().match(/payroll\/(\d+)/)[1];
    await s.go(`/payroll/${id}/payslips`);
  },

  // ---------- employee
  mypay: async (s, { scroll = 0 }) => { await s.go('/my-pay'); if (scroll) await s.scroll(scroll, { ms: 1000 }); },
  mypay_payslip: async (s) => { await s.tap('.slip-row', { nth: 0, pause: 300 }); await s.page.waitForLoadState('networkidle'); },
  ca_request: async (s, { amount = '1500', reason = 'Medicine for Nanay' }) => {
    await s.go('/cash-advances');
    await s.tap('[data-open=dlg-request]', { pause: 450 });
    await s.type('#dlg-request [name=amount]', amount, 60);
    await s.type('#dlg-request [name=reason]', reason, 35);
    await s.tap('#dlg-request button[type=submit]', { pause: 300 });
    await s.page.waitForLoadState('networkidle');
    s.mark('requested');
  },
};

module.exports = { record, login, SHOTS, DEVICES, BASE, sleep };
