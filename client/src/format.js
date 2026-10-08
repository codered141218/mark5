const pesoFmt = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qtyFmt = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 4 });
const intFmt = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 });

export const money = (n) => (n === null || n === undefined || n === '' ? '' : pesoFmt.format(Number(n) || 0));
export const peso = (n) => (n === null || n === undefined || n === '' ? '' : `₱${pesoFmt.format(Number(n) || 0)}`);
export const qty = (n) => (n === null || n === undefined || n === '' ? '' : qtyFmt.format(Number(n) || 0));
export const int = (n) => (n === null || n === undefined || n === '' ? '' : intFmt.format(Number(n) || 0));
export const pct = (n) => (n === null || n === undefined || n === '' ? '' : `${pesoFmt.format(Number(n) || 0)}%`);

const pad = (n) => String(n).padStart(2, '0');
export const toISO = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const today = () => toISO(new Date());

export function fmtDate(s) {
  if (!s) return '';
  const d = new Date(String(s).length <= 10 ? s + 'T00:00:00' : String(s).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}
export function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}
export function fmtTime(s) {
  if (!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
}

export function presetRange(key) {
  const now = new Date();
  const d = (y, m, day) => toISO(new Date(y, m, day));
  const y = now.getFullYear(); const m = now.getMonth(); const day = now.getDate();
  switch (key) {
    case 'today': return { from: toISO(now), to: toISO(now) };
    case 'yesterday': return { from: d(y, m, day - 1), to: d(y, m, day - 1) };
    case 'week': { const dow = (now.getDay() + 6) % 7; return { from: d(y, m, day - dow), to: toISO(now) }; }
    case 'last7': return { from: d(y, m, day - 6), to: toISO(now) };
    case 'month': return { from: d(y, m, 1), to: toISO(now) };
    case 'lastmonth': return { from: d(y, m - 1, 1), to: d(y, m, 0) };
    case 'quarter': { const q = Math.floor(m / 3) * 3; return { from: d(y, q, 1), to: toISO(now) }; }
    case 'year': return { from: d(y, 0, 1), to: toISO(now) };
    case 'lastyear': return { from: d(y - 1, 0, 1), to: d(y - 1, 11, 31) };
    default: return { from: d(y, m, 1), to: toISO(now) };
  }
}

export const ITEM_TYPES = {
  raw: 'Raw material / Ingredient',
  composite: 'Composite / Menu item (recipe)',
  retail: 'Retail (stocked & sold as-is)',
  non_inventory: 'Non-inventory / Service',
};
export const ITEM_TYPE_SHORT = { raw: 'Raw', composite: 'Composite', retail: 'Retail', non_inventory: 'Non-inv' };
