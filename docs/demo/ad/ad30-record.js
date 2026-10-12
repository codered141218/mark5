/**
 * Records the scenes of the 1-minute ad with Playwright. Each scene has a target length (seconds); the assembly step
 * trims the clip to it, so the actions here are paced to fit. Writes rec/<scene>.webm + rec/scenes.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, 'rec30');
const ATTLOG = path.join(__dirname, '..', 'zkteco-1_attlog.dat');
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

  // B. Attendance import from the ZKTeco log
  scenes.push(await scene('B-attendance', { state: admin, w: 1440, h: 900, target: 5.5 }, async (s) => {
    const p = s.page;
    await s.go('/payroll/attendance?from=2026-10-01&to=2026-10-15');
    await s.sleep(300);
    await s.tap('[data-open=dlg-import]', { pause: 500 });
    await p.setInputFiles('#dlg-import input[type=file]', ATTLOG);
    await s.sleep(300);
    await s.tap('#dlg-import [name=device]', { pause: 150 });
    await p.keyboard.type('ZKTeco K40 · kitchen door', { delay: 28 });
    await s.sleep(200);
    await s.tap('#dlg-import button[type=submit]', { pause: 200 });
    await p.waitForLoadState('networkidle');
    await s.until(3.9);
    await s.scroll(260, { ms: 900 });
    await s.until(5.8);
  }));

  // C. Payroll run: compute, post, payslips
  scenes.push(await scene('C-payroll', { state: admin, w: 1440, h: 900, target: 7.0 }, async (s) => {
    const p = s.page;
    await s.go('/payroll');
    await s.tap('[data-open=dlg-run]', { pause: 500 });
    await s.tap('#dlg-run button[type=submit]', { pause: 150 });
    await p.waitForLoadState('networkidle');
    await s.sleep(600);
    await s.tap('form[action$="/post"] button[type=submit]', { pause: 400 });
    await p.waitForSelector('dialog.modal button[value=ok]');
    await s.tap('dialog.modal button[value=ok]', { pause: 150 });
    await p.waitForLoadState('networkidle');
    await s.until(4.6);
    const id = p.url().match(/payroll\/(\d+)/)[1];
    await s.go(`/payroll/${id}/payslips`);
    await s.until(7.3);
  }));

  // D. Composite item with its recipe and live food cost
  scenes.push(await scene('D-recipe', { state: admin, w: 1440, h: 900, target: 5.2 }, async (s) => {
    await s.go('/inventory/items/28');
    await s.sleep(900);
    await s.scroll(560, { ms: 1500 });
    await s.until(5.5);
  }));

  // E. Menu costing report
  scenes.push(await scene('E-costing', { state: admin, w: 1440, h: 900, target: 4.0 }, async (s) => {
    await s.go('/reports/inventory?report=recipe');
    await s.sleep(1200);
    await s.scroll(120, { ms: 900 });
    await s.until(4.3);
  }));

  fs.writeFileSync(path.join(OUT, 'scenes.json'), JSON.stringify(scenes, null, 2));
})();
