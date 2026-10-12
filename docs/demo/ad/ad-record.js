/**
 * Records the scenes of the 1-minute ad with Playwright. Each scene has a target length (seconds); the assembly step
 * trims the clip to it, so the actions here are paced to fit. Writes rec/<scene>.webm + rec/scenes.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'rec');
const FROM = '2026-09-21', TO = '2026-10-11';
const ONLY = process.argv.slice(2);
const PREV = ONLY.length && fs.existsSync(path.join(OUT, 'scenes.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'scenes.json'))) : [];
if (!ONLY.length) fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const INIT = `
  (() => {
    const css = document.createElement('style');
    css.textContent = '::-webkit-scrollbar{width:0;height:0}' +
      '#__cur{position:fixed;left:0;top:0;width:22px;height:30px;z-index:2147483647;pointer-events:none;transform:translate(-3px,-2px);filter:drop-shadow(0 2px 3px rgba(0,0,0,.45))}' +
      '#__rip{position:fixed;width:44px;height:44px;border-radius:50%;border:3px solid #c2410c;z-index:2147483646;pointer-events:none;opacity:0;transform:translate(-50%,-50%) scale(.3)}' +
      '#__rip.go{animation:__r .45s ease-out}' +
      '@keyframes __r{0%{opacity:.9;transform:translate(-50%,-50%) scale(.3)}100%{opacity:0;transform:translate(-50%,-50%) scale(1.4)}}' +
      '#__slip{position:fixed;inset:0;z-index:2147483600;background:rgba(17,24,39,.72);display:flex;align-items:center;justify-content:center;gap:36px;opacity:0;transition:opacity .35s}' +
      '#__slip.on{opacity:1}' +
      '#__slip .paper{background:#fff;padding:18px 16px;border-radius:4px;box-shadow:0 30px 60px rgba(0,0,0,.5);transform:translateY(40px);transition:transform .5s ease-out}' +
      '#__slip.on .paper{transform:none}' +
      '#__slip .tag{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:#c2410c;color:#fff;font:700 13px/1 system-ui;padding:7px 12px;border-radius:999px;white-space:nowrap}';
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
  const W = opts.w, H = opts.h;
  const browser = await chromium.launch();
  const ctx = await browser.newContext({
    viewport: { width: W, height: H }, deviceScaleFactor: 1, hasTouch: !!opts.touch, isMobile: !!opts.mobile,
    recordVideo: { dir: OUT, size: { width: W, height: H } }, storageState: opts.state || undefined, locale: 'en-PH', timezoneId: 'Asia/Manila',
  });
  await ctx.addInitScript(INIT);
  const page = await ctx.newPage();
  const t0 = Date.now();
  const mouse = { x: 30, y: 30 };
  const api = {
    page, sleep,
    go: async (url) => { await page.goto(BASE + url, { waitUntil: 'networkidle' }); await sleep(200); },
    moveTo: async (x, y, steps = 18) => { await page.mouse.move(x, y, { steps }); mouse.x = x; mouse.y = y; },
    tap: async (sel, { pause = 500, nth = 0 } = {}) => {
      const loc = (typeof sel === 'string' ? page.locator(sel) : sel).nth(nth);
      await loc.waitFor({ state: 'visible', timeout: 10000 });
      await loc.scrollIntoViewIfNeeded();
      const b = await loc.boundingBox();
      const x = b.x + b.width / 2, y = b.y + b.height / 2;
      const dist = Math.hypot(x - mouse.x, y - mouse.y);
      await api.moveTo(x, y, Math.max(6, Math.min(22, Math.round(dist / 30))));
      await sleep(90);
      await page.mouse.down(); await sleep(60); await page.mouse.up();
      mouse.x = x; mouse.y = y;
      await sleep(pause);
    },
    scroll: async (dy, { sel = '.main', ms = 900 } = {}) => {
      await page.evaluate(({ sel, dy, ms }) => new Promise((res) => {
        const el = document.querySelector(sel) || document.scrollingElement;
        const start = el.scrollTop, t0 = performance.now();
        const step = (t) => { const k = Math.min(1, (t - t0) / ms); const e = k < .5 ? 2 * k * k : -1 + (4 - 2 * k) * k; el.scrollTop = start + dy * e; if (k < 1) requestAnimationFrame(step); else res(); };
        requestAnimationFrame(step);
      }), { sel, dy, ms });
      await sleep(150);
    },
    elapsed: () => (Date.now() - t0) / 1000,
    // wait until the scene has run for `s` seconds in total
    until: async (s) => { const left = s * 1000 - (Date.now() - t0); if (left > 0) await sleep(left); },
  };
  await api.moveTo(mouse.x, mouse.y, 1);
  let err = null;
  try { await fn(api); } catch (e) { err = e; console.error(`scene ${name} failed:`, e); }
  const dur = (Date.now() - t0) / 1000;
  const video = page.video();
  await ctx.close();
  const dest = path.join(OUT, `${name}.webm`);
  fs.renameSync(await video.path(), dest);
  await browser.close();
  console.log(`${name}: scripted ${dur.toFixed(1)}s (target ${opts.target}s)${err ? ' (ERROR)' : ''}`);
  return { name, file: dest, dur, target: opts.target, w: W, h: H, error: err ? String(err) : null };
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
  const waiter = path.join(OUT, 'waiter.json');
  await login('admin', 'admin123', admin);
  await login('maria', 'cashier123', waiter);
  const scenes = [];

  // B. Mobile order taker — phone
  scenes.push(await scene('B-mobile', { state: waiter, w: 390, h: 844, touch: true, mobile: true, target: 9.5 }, async (s) => {
    const p = s.page;
    await s.go('/pos');
    await p.waitForSelector('.order-card');
    await s.sleep(500);
    await s.tap('[data-act=new][data-type=dine_in]', { pause: 500 });
    await p.waitForSelector('.tile');
    await s.tap('.cat-btn:has-text("Ulam")', { pause: 350 });
    await s.tap('.tile:has-text("Sinigang")', { pause: 350 });
    await s.tap('.tile:has-text("Chicken Adobo")', { pause: 350 });
    await s.tap('.cat-btn:has-text("Rice Meals")', { pause: 300 });
    await s.tap('.tile:has-text("Garlic Rice")', { pause: 350 });
    await s.tap('[data-act=pane][data-pane=order]', { pause: 700 });
    await s.tap('.table-badge', { pause: 400 });
    await p.waitForSelector('.pos-dlg .table-chip');
    await s.tap('.pos-dlg .table-chip:has-text("9")', { nth: 0, pause: 700 });
    await s.tap('[data-act=send]', { pause: 300 });
    await s.until(9.8);
  }));

  // C. Slips — tablet: send the delivery order, show the kitchen slip, then the printer settings
  scenes.push(await scene('C-slips', { state: waiter, w: 1024, h: 768, touch: true, target: 10.5 }, async (s) => {
    const p = s.page;
    await s.go('/pos');
    await p.waitForSelector('.order-card');
    await s.tap('.order-card:has-text("Grab")', { pause: 700 });
    await p.waitForSelector('[data-act=send]');
    const id = await p.evaluate(() => { const b = document.querySelector('.order-card'); return null; });
    await s.tap('[data-act=send]', { pause: 400 });
    // show the kitchen slip the app just built, as the printer would print it (58 mm)
    await p.evaluate(async () => {
      const r = await fetch(document.querySelector('meta[name=base-url]').content.replace(/\/$/, '') + '/api/pos/orders', { headers: { Accept: 'application/json' } });
      const d = await r.json();
      const o = (d.orders || d).find((x) => x.order_type === 'delivery') || (d.orders || d)[0];
      const t = await (await fetch(document.querySelector('meta[name=base-url]').content.replace(/\/$/, '') + `/api/pos/orders/${o.id}`, { headers: { Accept: 'application/json' } })).json();
      const tk = t.ticket || t;
      const lines = tk.items.filter((l) => l.status === 'active');
      const I = window.Printer._internals;
      const slip = (title) => `<div class="paper" style="position:relative"><span class="tag">${title}</span><div class="receipt-preview"><div class="paper-58">${I.html(I.slipDoc(tk, lines, 'KITCHEN ORDER', false), 58)}</div></div></div>`;
      const box = document.createElement('div'); box.id = '__slip';
      box.innerHTML = slip('KITCHEN · copy 1') + slip('BAR · copy 2');
      document.body.appendChild(box);
      requestAnimationFrame(() => box.classList.add('on'));
    });
    await s.until(6.3);
    await s.go('/printer');
    await s.scroll(330, { sel: '.main', ms: 1200 });
    await s.until(10.8);
  }));

  // D. Dashboard
  scenes.push(await scene('D-dashboard', { state: admin, w: 1440, h: 900, target: 7.5 }, async (s) => {
    await s.go(`/?from=${FROM}&to=${TO}`);
    await s.sleep(1500);
    await s.scroll(520, { ms: 1800 });
    await s.sleep(900);
    await s.scroll(560, { ms: 1800 });
    await s.until(7.8);
  }));

  // E. Financials: income statement -> balance sheet -> payables
  scenes.push(await scene('E-finance', { state: admin, w: 1440, h: 900, target: 10.5 }, async (s) => {
    await s.go(`/reports/finance?report=is&from=2026-09-01&to=${TO}`);
    await s.sleep(900);
    await s.scroll(380, { ms: 1500 });
    await s.until(3.8);
    await s.go(`/reports/finance?report=bs&to=${TO}`);
    await s.sleep(800);
    await s.scroll(300, { ms: 1400 });
    await s.until(7.3);
    await s.go('/finance/payables');
    await s.until(10.8);
  }));

  // F. Payroll-side: cash advances with salary deductions
  scenes.push(await scene('F-advances', { state: admin, w: 1440, h: 900, target: 6.5 }, async (s) => {
    await s.go('/cash-advances');
    await s.sleep(1200);
    await s.tap('.table tbody tr:has-text("CA-000001")', { pause: 300 });
    await s.page.waitForLoadState('networkidle');
    await s.sleep(600);
    await s.scroll(300, { ms: 1200 });
    await s.until(6.8);
  }));

  fs.writeFileSync(path.join(OUT, 'scenes.json'), JSON.stringify(scenes, null, 2));
})();
