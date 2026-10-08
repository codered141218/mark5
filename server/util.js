'use strict';
const db = require('./db');

class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}
const bad = (msg) => new HttpError(400, msg);
const notFound = (what = 'Record') => new HttpError(404, `${what} not found`);

const r2 = (n) => Math.round((Number(n) || 0) * 100 + Number.EPSILON * 100) / 100;
const r4 = (n) => Math.round((Number(n) || 0) * 10000) / 10000;
const num = (n, def = 0) => {
  const v = Number(n);
  return Number.isFinite(v) ? v : def;
};

const pad = (n) => String(n).padStart(2, '0');
function now() {
  const d = new Date();
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}
function today() {
  return now().slice(0, 10);
}
function isDate(s) {
  return typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s);
}
function addDays(dateStr, days) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + Number(days || 0));
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
function dateRange(q) {
  const t = today();
  const from = isDate(q.from) ? q.from : t.slice(0, 8) + '01';
  const to = isDate(q.to) ? q.to : t;
  return { from, to };
}

// Document numbering, e.g. nextNo('OR') -> "OR-00000001"
function nextNo(name, prefix, padLen = 6) {
  let seq = db.get('SELECT * FROM sequences WHERE name = ?', name);
  if (!seq) {
    db.run('INSERT INTO sequences (name, prefix, next, pad) VALUES (?,?,?,?)', name, prefix || name, 1, padLen);
    seq = db.get('SELECT * FROM sequences WHERE name = ?', name);
  } else if (prefix && prefix !== seq.prefix) {
    // e.g. receipt prefix changed in settings
    db.run('UPDATE sequences SET prefix = ? WHERE name = ?', prefix, name);
    seq.prefix = prefix;
  }
  db.run('UPDATE sequences SET next = next + 1 WHERE name = ?', name);
  return `${seq.prefix}-${String(seq.next).padStart(seq.pad, '0')}`;
}

function getSetting(key, def = null) {
  const row = db.get('SELECT value FROM settings WHERE key = ?', key);
  return row ? row.value : def;
}
function setSetting(key, value) {
  db.run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', key, value == null ? null : String(value));
}
function allSettings() {
  const out = {};
  for (const r of db.all('SELECT key, value FROM settings')) out[r.key] = r.value;
  return out;
}

function audit(userId, action, entity, entityId, details) {
  db.run(
    'INSERT INTO audit_log (ts, user_id, action, entity, entity_id, details) VALUES (?,?,?,?,?,?)',
    now(), userId || null, action, entity || null, entityId || null,
    details == null ? null : typeof details === 'string' ? details : JSON.stringify(details)
  );
}

// Wrap route handlers: sync or async, errors go to express error handler.
const h = (fn) => (req, res, next) => {
  try {
    const out = fn(req, res, next);
    if (out && typeof out.then === 'function') {
      out.then((v) => { if (v !== undefined && !res.headersSent) res.json(v); }).catch(next);
    } else if (out !== undefined && !res.headersSent) {
      res.json(out);
    }
  } catch (e) {
    next(e);
  }
};

function required(obj, ...fields) {
  for (const f of fields) {
    if (obj[f] === undefined || obj[f] === null || obj[f] === '') throw bad(`${f.replace(/_/g, ' ')} is required`);
  }
}

module.exports = {
  HttpError, bad, notFound, r2, r4, num, now, today, isDate, addDays, dateRange,
  nextNo, getSetting, setSetting, allSettings, audit, h, required,
};
