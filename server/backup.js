'use strict';
const fs = require('fs');
const path = require('path');
const { DatabaseSync } = require('node:sqlite');
const db = require('./db');
const { bad, notFound, getSetting, today } = require('./util');

const BACKUP_DIR = path.join(db.dataDir, 'backups');
fs.mkdirSync(BACKUP_DIR, { recursive: true });

function stamp() {
  const d = new Date();
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}

function list() {
  return fs.readdirSync(BACKUP_DIR)
    .filter((f) => f.endsWith('.db'))
    .map((f) => {
      const st = fs.statSync(path.join(BACKUP_DIR, f));
      return { name: f, size: st.size, created_at: st.mtime.toISOString(), kind: f.split('_')[1] || 'manual' };
    })
    .sort((a, b) => b.created_at.localeCompare(a.created_at));
}

function pathOf(name) {
  if (!/^[\w.-]+\.db$/.test(name)) throw bad('Invalid backup name');
  const p = path.join(BACKUP_DIR, name);
  if (!fs.existsSync(p)) throw notFound('Backup');
  return p;
}

function create(kind = 'manual') {
  const name = `mark5_${kind}_${stamp()}.db`;
  db.backupTo(path.join(BACKUP_DIR, name));
  prune();
  return list().find((b) => b.name === name);
}

function prune() {
  const keep = Number(getSetting('backup_retention', '30')) || 30;
  const autos = list().filter((b) => b.kind === 'auto');
  for (const b of autos.slice(keep)) fs.unlinkSync(path.join(BACKUP_DIR, b.name));
}

function remove(name) {
  fs.unlinkSync(pathOf(name));
}

function validate(file) {
  const fd = fs.openSync(file, 'r');
  const buf = Buffer.alloc(16);
  fs.readSync(fd, buf, 0, 16, 0);
  fs.closeSync(fd);
  if (buf.toString('latin1') !== 'SQLite format 3\u0000') throw bad('File is not a valid database backup');
  const test = new DatabaseSync(file, { readOnly: true });
  try {
    const t = test.prepare("SELECT COUNT(*) c FROM sqlite_master WHERE type='table' AND name IN ('users','settings','items','journal_entries')").get();
    if (Number(t.c) < 4) throw bad('Backup does not look like a Mark5 database');
  } finally {
    test.close();
  }
}

function restoreFromFile(file) {
  validate(file);
  const safety = create('pre-restore');
  db.close();
  try {
    for (const ext of ['-wal', '-shm']) {
      if (fs.existsSync(db.file + ext)) fs.unlinkSync(db.file + ext);
    }
    fs.copyFileSync(file, db.file);
  } finally {
    db.open();
    require('./gl').clearCache();
  }
  return { ok: true, safety_backup: safety.name };
}

function restoreFromBuffer(buf) {
  const tmp = path.join(BACKUP_DIR, `upload_${stamp()}.tmp`);
  fs.writeFileSync(tmp, buf);
  try {
    return restoreFromFile(tmp);
  } finally {
    if (fs.existsSync(tmp)) fs.unlinkSync(tmp);
  }
}

// One automatic backup per day.
function autoBackup() {
  if (getSetting('auto_backup', '1') !== '1') return;
  const tag = today().replace(/-/g, '');
  if (list().some((b) => b.kind === 'auto' && b.name.includes(`_${tag}-`))) return;
  create('auto');
}

module.exports = { list, create, remove, pathOf, restoreFromFile, restoreFromBuffer, autoBackup, BACKUP_DIR };
