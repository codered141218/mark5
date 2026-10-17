/**
 * Records every POV scene of the story ad, in the order the story happens (the database state flows from one scene to
 * the next: the waiter's Table 12 is what the cashier settles; the payroll the owner posts is what Ramon sees on his phone).
 * node plan.js [sceneName...]  -> rec2/<scene>.webm + rec2/scenes.json (re-records only the named scenes when given)
 */
const { record, login } = require('./lib');
const fs = require('fs'); const path = require('path');
const OUT = path.join(__dirname, 'rec2');
const ONLY = process.argv.slice(2);
const PREV = ONLY.length && fs.existsSync(path.join(OUT, 'scenes.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'scenes.json'))) : [];
if (!ONLY.length) fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });
const ATTLOG = path.join(__dirname, '..', '..', 'zkteco-1_attlog.dat');
const d = new Date(); const pad = (n) => String(n).padStart(2, '0');
const today = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const daysAgo = (n) => { const x = new Date(d); x.setDate(x.getDate() - n); return `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`; };
const FROM = daysAgo(21), TO = daysAgo(1);

const SCENES = [
  // ---- OPENING: the orders board on the counter, Table 7 waiting
  { name: 'x0-board', who: 'maria', device: 'tablet', target: 11, steps: [
    'pos_board', ['wait', { ms: 1500 }], ['hover', { sel: '.order-card:has-text("Table 7")' }], ['wait', { ms: 2500 }], ['hover', { sel: '.order-card:has-text("Patio 2")' }], ['wait', { ms: 2000 }] ] },

  // ---- WAITER (Ramon, phone)
  { name: 'w1-order', who: 'ramon', device: 'phone', target: 16, steps: [
    'pos_board', ['wait', { ms: 600 }], ['pos_new_order'], ['pos_category', { name: 'Ulam' }], ['pos_items', { items: ['Sinigang', 'Sizzling Pork Sisig'], pause: 320 }],
    ['pos_category', { name: 'Rice Meals' }], ['pos_items', { items: ['Garlic Rice', 'Garlic Rice'], pause: 320 }],
    ['pos_pane', { pane: 'order' }], ['mark', { key: 'order' }], ['pos_line_note', { item: 'Sizzling Pork Sisig', chip: 'Extra spicy' }],
    ['pos_table', { label: '12' }], ['mark', { key: 'table' }], ['pos_send', { pause: 900 }], ['mark', { key: 'sent' }], ['pos_kitchen_slip_overlay', { find: '12', tags: ['KITCHEN SLIP'] }], ['wait', { ms: 2600 }] ] },
  { name: 'w2-split', who: 'ramon', device: 'phone', target: 11, steps: [
    ['wait', { ms: 300 }], 'pos_board', ['pos_open', { text: 'Table 12' }], ['pos_pane', { pane: 'order' }], ['pos_split', { item: 'Sinigang', table: '8' }], ['wait', { ms: 600 }], 'pos_done', ['wait', { ms: 1200 }] ] },

  // ---- CASHIER (Maria, tablet)
  { name: 'c1-discount', who: 'maria', device: 'tablet', target: 15, steps: [
    'pos_board', ['wait', { ms: 700 }], ['pos_open', { text: 'Table 12' }], ['wait', { ms: 500 }], ['pos_discount_sc', { name: 'Lola Nena', idNo: 'OSCA-10234' }], ['wait', { ms: 900 }] ] },
  { name: 'c2-pay', who: 'maria', device: 'tablet', target: 15, steps: [
    'pos_board', ['pos_open', { text: 'Table 12' }], ['pos_pay', { pays: [{ method: 'gcash', ref: 'GC-77120', amount: '100' }, { method: 'card', ref: 'A8831', amount: '100' }], quick: 1, holdPaid: 1800 }],
    'pos_paid_board', ['wait', { ms: 500 }] ] },
  { name: 'c3-eod', who: 'maria', device: 'tablet', target: 17, steps: [
    'pos_board', ['pos_receipts', { pick: 0, scroll: 200, hold: 800 }], ['pos_eod', { counts: [['1000', '4'], ['500', '9'], ['100', '12']], hold: 900 }],
    ['mark', { key: 'counted' }], ['zreading_yesterday'], ['scroll', { dy: 520, sel: 'html', ms: 1400 }], ['wait', { ms: 2200 }] ] },

  // ---- OWNER (Nena, desktop)
  { name: 'o1-dashboard', who: 'admin', device: 'desktop', target: 14, steps: [
    ['go', { url: `/?from=${FROM}&to=${TO}`, settle: 1400 }], ['scroll', { dy: 520, ms: 1600 }], ['wait', { ms: 1200 }], ['scroll', { dy: 560, ms: 1500 }], ['wait', { ms: 900 }],
    ['go', { url: `/reports/sales?report=hourly&from=${FROM}&to=${TO}`, settle: 2000 }], ['go', { url: `/reports/sales?report=payments&from=${FROM}&to=${TO}`, settle: 1800 }] ] },
  { name: 'o3a-foodcost', who: 'admin', device: 'desktop', target: 13, steps: [
    ['go', { url: '/inventory/items/2', settle: 1600 }], ['go', { url: '/inventory/items/28', settle: 800 }], ['scroll', { dy: 560, ms: 1500 }], ['wait', { ms: 1800 }],
    ['go', { url: '/reports/inventory?report=recipe', settle: 1200 }], ['hover', { sel: '.table tbody tr:has-text("Sisig Rice Meal")' }], ['wait', { ms: 1800 }] ] },
  { name: 'o3b-inventory', who: 'admin', device: 'desktop', target: 15, steps: [
    ['go', { url: '/inventory/receiving', settle: 900 }], ['open_row', { text: 'On credit' }], ['wait', { ms: 1300 }],
    ['go', { url: '/inventory/issuance', settle: 1500 }], ['go', { url: '/inventory/wastage', settle: 1400 }],
    ['go', { url: '/reports/inventory?report=onhand', settle: 900 }], ['scroll', { dy: 240, ms: 1100 }], ['wait', { ms: 1100 }] ] },
  { name: 'o4-finance', who: 'admin', device: 'desktop', target: 13, steps: [
    ['go', { url: `/reports/finance?report=is&from=${daysAgo(46)}&to=${TO}`, settle: 1000 }], ['scroll', { dy: 420, ms: 1500 }], ['wait', { ms: 900 }],
    ['go', { url: `/reports/finance?report=bs&to=${TO}`, settle: 1800 }], ['go', { url: `/reports/finance?report=ap&to=${TO}`, settle: 1600 }],
    ['go', { url: '/finance/journals', settle: 800 }], ['open_row', { text: 'POS sale' }], ['wait', { ms: 1800 }] ] },

  // ---- PAYROLL (Nena, desktop)
  { name: 'p1-import', who: 'admin', device: 'desktop', target: 11, steps: [
    ['go', { url: '/payroll/attendance', settle: 500 }], ['payroll_import', { file: ATTLOG }], ['wait', { ms: 1200 }], ['scroll', { dy: 300, ms: 1200 }], ['wait', { ms: 1500 }] ] },
  { name: 'p2-run', who: 'admin', device: 'desktop', target: 14, steps: [
    ['go', { url: '/payroll', settle: 500 }], ['payroll_run', { post: true, settle: 1400 }], ['wait', { ms: 1500 }], 'payroll_payslips', ['wait', { ms: 2500 }] ] },

  // ---- EMPLOYEE (Ramon, phone)
  { name: 'e1-mypay', who: 'ramon', device: 'phone', target: 15, steps: [
    ['mypay', {}], ['wait', { ms: 1500 }], 'mypay_payslip', ['wait', { ms: 1800 }], ['scroll', { dy: 200, ms: 900, sel: 'html' }], ['wait', { ms: 600 }],
    ['ca_request', { amount: '1500', reason: 'Medicine for Nanay' }], ['wait', { ms: 1800 }] ] },
];

const { execSync } = require('child_process');
const SNAPSHOT = process.env.M5_SNAPSHOT || path.join(__dirname, 'snapshot.sql');   // mysqldump taken right after the seed scripts
(async () => {
  if (!ONLY.length) { execSync(`mysql mark5 < ${SNAPSHOT} && mysql mark5 < ${path.join(__dirname, 'prep.sql')}`, { stdio: 'inherit' }); console.log('database restored + prepped'); }
  const states = {}; for (const u of ['admin', 'maria', 'ramon']) states[u] = await login(u, OUT);
  const out = [];
  for (const sc of SCENES) {
    if (ONLY.length && !ONLY.includes(sc.name)) { const old = PREV.find((x) => x.name === sc.name); if (old) { console.log(sc.name + ': kept'); out.push(old); continue; } }
    out.push(await record(sc, states, OUT));
  }
  fs.writeFileSync(path.join(OUT, 'scenes.json'), JSON.stringify(out, null, 2));
  console.log('total', out.reduce((a, s) => a + s.dur, 0).toFixed(1), 's');
})();
