'use strict';
// Thin synchronous wrapper around Node's built-in SQLite driver.
const fs = require('fs');
const path = require('path');
const { DatabaseSync } = require('node:sqlite');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, '..', 'data');
const DB_FILE = process.env.DB_FILE || path.join(DATA_DIR, 'mark5.db');
fs.mkdirSync(DATA_DIR, { recursive: true });

let conn = null;
let txDepth = 0;

function normalize(params) {
  return params.map((p) => {
    if (p === undefined) return null;
    if (typeof p === 'boolean') return p ? 1 : 0;
    return p;
  });
}

function plain(row) {
  return row ? { ...row } : row;
}

// Columns added after the first release: [table, column, definition]
const ADDED_COLUMNS = [
  ['bank_txns', 'bank_charges', 'REAL NOT NULL DEFAULT 0'],
];
function migrate() {
  for (const [table, col, def] of ADDED_COLUMNS) {
    const cols = conn.prepare(`PRAGMA table_info(${table})`).all().map((c) => c.name);
    if (!cols.includes(col)) conn.exec(`ALTER TABLE ${table} ADD COLUMN ${col} ${def}`);
  }
}

const db = {
  file: DB_FILE,
  dataDir: DATA_DIR,

  open() {
    conn = new DatabaseSync(DB_FILE);
    conn.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    conn.exec(fs.readFileSync(path.join(__dirname, 'schema.sql'), 'utf8'));
    migrate();
    return db;
  },

  close() {
    if (conn) conn.close();
    conn = null;
  },

  all(sql, ...params) {
    return conn.prepare(sql).all(...normalize(params)).map(plain);
  },
  get(sql, ...params) {
    return plain(conn.prepare(sql).get(...normalize(params)));
  },
  run(sql, ...params) {
    const r = conn.prepare(sql).run(...normalize(params));
    return { id: Number(r.lastInsertRowid), changes: Number(r.changes) };
  },
  exec(sql) {
    conn.exec(sql);
  },
  value(sql, ...params) {
    const row = conn.prepare(sql).get(...normalize(params));
    if (!row) return null;
    return Object.values(row)[0];
  },

  // Insert an object into a table; returns new id.
  insert(table, obj) {
    const keys = Object.keys(obj).filter((k) => obj[k] !== undefined);
    const sql = `INSERT INTO ${table} (${keys.join(',')}) VALUES (${keys.map(() => '?').join(',')})`;
    return db.run(sql, ...keys.map((k) => obj[k])).id;
  },
  update(table, id, obj) {
    const keys = Object.keys(obj).filter((k) => obj[k] !== undefined);
    if (!keys.length) return;
    db.run(`UPDATE ${table} SET ${keys.map((k) => `${k} = ?`).join(', ')} WHERE id = ?`, ...keys.map((k) => obj[k]), id);
  },

  // Nested-safe transaction using savepoints.
  tx(fn) {
    const sp = `sp${txDepth}`;
    if (txDepth === 0) conn.exec('BEGIN IMMEDIATE');
    else conn.exec(`SAVEPOINT ${sp}`);
    txDepth++;
    try {
      const result = fn();
      txDepth--;
      if (txDepth === 0) conn.exec('COMMIT');
      else conn.exec(`RELEASE ${sp}`);
      return result;
    } catch (e) {
      txDepth--;
      if (txDepth === 0) conn.exec('ROLLBACK');
      else conn.exec(`ROLLBACK TO ${sp}; RELEASE ${sp}`);
      throw e;
    }
  },

  backupTo(file) {
    if (fs.existsSync(file)) fs.unlinkSync(file);
    conn.exec(`VACUUM INTO '${file.replace(/'/g, "''")}'`);
  },
};

module.exports = db;
