/**
 * Renders the title / POV / outro cards as 1440x900 PNGs with Chromium (HTML + Inter), so the cards share the app's
 * look instead of ffmpeg drawtext. Usage: node cards.js cards.json out-dir
 *   cards.json: [{ name, kind: 'title'|'pov'|'outro'|'quote', ...fields }]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const LOGO = `<svg viewBox="0 0 32 32" width="{S}" height="{S}"><rect width="32" height="32" rx="8" fill="#c2410c"/><text x="16" y="22" font-size="15" text-anchor="middle" fill="#fff" font-family="Inter, Arial" font-weight="800">M5</text></svg>`;
const CSS = `
  @font-face { font-family: Inter; src: local('Inter'); }
  * { box-sizing: border-box; margin: 0; }
  html, body { width: 1440px; height: 900px; overflow: hidden; }
  body { font-family: Inter, 'Inter Display', system-ui, sans-serif; color: #fff; background: #0f172a;
    background-image: radial-gradient(1200px 700px at 20% 110%, rgba(194,65,12,.55), transparent 60%), radial-gradient(900px 600px at 95% -10%, rgba(29,78,216,.35), transparent 60%); }
  .wrap { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 60px; }
  .title { font-size: 92px; font-weight: 800; letter-spacing: -2px; line-height: 1.05; }
  .sub { font-size: 36px; font-weight: 600; color: #fdba74; margin-top: 18px; }
  .small { font-size: 26px; color: #cbd5e1; margin-top: 14px; font-weight: 500; }
  .pov { align-items: flex-start; text-align: left; padding: 100px 120px; }
  .pov .kicker { font-size: 26px; font-weight: 700; letter-spacing: 4px; color: #fdba74; text-transform: uppercase; }
  .pov .name { font-size: 110px; font-weight: 800; letter-spacing: -3px; line-height: 1; margin-top: 18px; }
  .pov .role { font-size: 40px; font-weight: 600; color: #e2e8f0; margin-top: 10px; }
  .pov .when { font-size: 30px; color: #94a3b8; margin-top: 30px; font-weight: 500; }
  .pov .problem { font-size: 34px; color: #fff; margin-top: 44px; max-width: 1000px; line-height: 1.3; font-weight: 500; padding-left: 26px; border-left: 6px solid #c2410c; }
  .quote .q { font-size: 56px; font-weight: 700; line-height: 1.2; max-width: 1150px; letter-spacing: -1px; }
  .quote .who { font-size: 28px; color: #fdba74; margin-top: 30px; font-weight: 600; }
  .logo { position: absolute; top: 56px; right: 64px; display: flex; align-items: center; gap: 14px; font-weight: 700; font-size: 24px; color: #e2e8f0; }
  .tags { display: flex; gap: 14px; margin-top: 36px; flex-wrap: wrap; justify-content: center; }
  .tag { border: 2px solid rgba(255,255,255,.35); border-radius: 999px; padding: 10px 22px; font-size: 24px; font-weight: 600; color: #e2e8f0; }
  .url { font-size: 30px; color: #fdba74; margin-top: 36px; font-weight: 600; }
`;

function html(c) {
  const logo = (S) => LOGO.replace(/\{S\}/g, S);
  let body = '';
  if (c.kind === 'title') body = `<div class="wrap">${logo(150)}<div class="title" style="margin-top:34px">${esc(c.title)}</div><div class="sub">${esc(c.sub)}</div><div class="small">${esc(c.small || '')}</div></div>`;
  if (c.kind === 'pov') body = `<div class="logo">${logo(44)} M5 Restaurant Suite</div><div class="wrap pov"><div class="kicker">${esc(c.kicker)}</div><div class="name">${esc(c.name)}</div><div class="role">${esc(c.role)}</div><div class="when">${esc(c.when || '')}</div>${c.problem ? `<div class="problem">${esc(c.problem)}</div>` : ''}</div>`;
  if (c.kind === 'quote') body = `<div class="logo">${logo(44)} M5 Restaurant Suite</div><div class="wrap quote"><div class="q">“${esc(c.quote)}”</div><div class="who">— ${esc(c.who)}</div></div>`;
  if (c.kind === 'frame') body = `<div class="logo">${logo(44)} M5 Restaurant Suite</div><div class="wrap"><div style="width:${c.w + 28}px;height:${c.h + 28}px;border-radius:${c.r || 44}px;background:#111827;box-shadow:0 40px 90px rgba(0,0,0,.6), inset 0 0 0 2px rgba(255,255,255,.08)"></div></div>`;
  if (c.kind === 'logo') body = `<div style="position:absolute;left:0;top:0;display:flex;align-items:center;gap:12px;padding:10px 16px 10px 10px;border-radius:14px;background:rgba(15,23,42,.78);font-weight:700;font-size:22px;color:#fff">${logo(40)} M5 Restaurant Suite</div>`;
  if (c.kind === 'outro') body = `<div class="wrap">${logo(120)}<div class="title" style="margin-top:28px;font-size:84px">${esc(c.title)}</div><div class="sub">${esc(c.sub)}</div><div class="tags">${(c.tags || []).map((t) => `<span class="tag">${esc(t)}</span>`).join('')}</div><div class="url">${esc(c.url || '')}</div></div>`;
  return `<!doctype html><html><head><meta charset="utf-8"><style>${CSS}</style></head><body>${body}</body></html>`;
}

(async () => {
  const [cardsFile, outDir] = process.argv.slice(2);
  const cards = JSON.parse(fs.readFileSync(cardsFile, 'utf8'));
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
  for (const c of cards) {
    await page.setContent(html(c), { waitUntil: 'load' });
    await page.waitForTimeout(150);
    const out = path.join(outDir, `${c.file || c.name}.png`);
    if (c.kind === 'logo') {
      await page.addStyleTag({ content: 'html,body{background:transparent !important;background-image:none !important}' });
      const el = await page.$('body > div');
      await el.screenshot({ path: out, omitBackground: true });
    } else await page.screenshot({ path: out });
    console.log('card', out);
  }
  await browser.close();
})();
