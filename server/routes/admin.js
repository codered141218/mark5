'use strict';
const express = require('express');
const bcrypt = require('bcryptjs');
const db = require('../db');
const { h, bad, notFound, now, audit, allSettings, setSetting, required, HttpError } = require('../util');
const { authenticate, requirePerm, createSession, loadUser } = require('../auth');
const { PERMISSION_GROUPS, ALL_PERMISSIONS } = require('../permissions');
const backup = require('../backup');

const router = express.Router();

// ------------------------------------------------------------------ auth (public)
router.post('/auth/login', h((req) => {
  const { username, password } = req.body || {};
  required(req.body || {}, 'username', 'password');
  const u = db.get('SELECT * FROM users WHERE username = ?', username);
  if (!u || !bcrypt.compareSync(String(password), u.password_hash)) throw new HttpError(401, 'Invalid username or password');
  if (!u.active) throw new HttpError(401, 'Account is disabled');
  const token = createSession(u.id);
  audit(u.id, 'login', 'user', u.id);
  return { token, user: loadUser(u.id) };
}));

router.get('/public/info', h(() => {
  const s = allSettings();
  return { business_name: s.business_name };
}));

router.use(authenticate);

router.post('/auth/logout', h((req) => {
  db.run('DELETE FROM sessions WHERE token = ?', req.token);
  return { ok: true };
}));

router.get('/auth/me', h((req) => ({ user: req.user })));

router.post('/auth/change-password', h((req) => {
  const { current_password, new_password, new_pin } = req.body;
  const u = db.get('SELECT * FROM users WHERE id = ?', req.user.id);
  if (!bcrypt.compareSync(String(current_password || ''), u.password_hash)) throw bad('Current password is incorrect');
  if (new_password) {
    if (String(new_password).length < 6) throw bad('Password must be at least 6 characters');
    db.run('UPDATE users SET password_hash = ? WHERE id = ?', bcrypt.hashSync(String(new_password), 10), u.id);
  }
  if (new_pin) {
    if (!/^\d{4,8}$/.test(String(new_pin))) throw bad('PIN must be 4-8 digits');
    db.run('UPDATE users SET pin_hash = ? WHERE id = ?', bcrypt.hashSync(String(new_pin), 10), u.id);
  }
  audit(u.id, 'change_password', 'user', u.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ settings
router.get('/settings', h(() => allSettings()));
router.put('/settings', requirePerm('admin.settings'), h((req) => {
  db.tx(() => {
    for (const [k, v] of Object.entries(req.body || {})) {
      if (/^[a-z0-9_]+$/.test(k)) setSetting(k, v);
    }
  });
  audit(req.user.id, 'update', 'settings', null, req.body);
  return allSettings();
}));

// ------------------------------------------------------------------ tables
router.get('/tables', h(() => db.all('SELECT * FROM dining_tables ORDER BY area, sort_order, name')));
router.post('/tables', requirePerm('admin.settings'), h((req) => {
  required(req.body, 'name');
  const { name, area, seats, sort_order } = req.body;
  return { id: db.insert('dining_tables', { name, area, seats: Number(seats) || 4, sort_order: Number(sort_order) || 0, active: 1 }) };
}));
router.put('/tables/:id', requirePerm('admin.settings'), h((req) => {
  const { name, area, seats, sort_order, active } = req.body;
  db.update('dining_tables', req.params.id, { name, area, seats, sort_order, active: active === undefined ? undefined : active ? 1 : 0 });
  return { ok: true };
}));
router.delete('/tables/:id', requirePerm('admin.settings'), h((req) => {
  if (db.get('SELECT id FROM tickets WHERE table_id = ? LIMIT 1', req.params.id)) {
    db.run('UPDATE dining_tables SET active = 0 WHERE id = ?', req.params.id);
    return { ok: true, deactivated: true };
  }
  db.run('DELETE FROM dining_tables WHERE id = ?', req.params.id);
  return { ok: true };
}));

// ------------------------------------------------------------------ roles & permissions
router.get('/permissions', h(() => PERMISSION_GROUPS));
router.get('/roles', requirePerm('admin.roles', 'admin.users'), h(() =>
  db.all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) user_count FROM roles r ORDER BY r.name')
    .map((r) => ({ ...r, permissions: JSON.parse(r.permissions || '[]') }))
));
function cleanPerms(perms) {
  if (!Array.isArray(perms)) return [];
  if (perms.includes('*')) return ['*'];
  return perms.filter((p) => ALL_PERMISSIONS.includes(p));
}
router.post('/roles', requirePerm('admin.roles'), h((req) => {
  required(req.body, 'name');
  const id = db.insert('roles', { name: req.body.name, description: req.body.description, permissions: JSON.stringify(cleanPerms(req.body.permissions)) });
  audit(req.user.id, 'create', 'role', id, req.body);
  return { id };
}));
router.put('/roles/:id', requirePerm('admin.roles'), h((req) => {
  const role = db.get('SELECT * FROM roles WHERE id = ?', req.params.id);
  if (!role) throw notFound('Role');
  const perms = role.is_system ? ['*'] : cleanPerms(req.body.permissions);
  db.update('roles', role.id, { name: role.is_system ? role.name : req.body.name, description: req.body.description, permissions: JSON.stringify(perms) });
  audit(req.user.id, 'update', 'role', role.id, req.body);
  return { ok: true };
}));
router.delete('/roles/:id', requirePerm('admin.roles'), h((req) => {
  const role = db.get('SELECT * FROM roles WHERE id = ?', req.params.id);
  if (!role) throw notFound('Role');
  if (role.is_system) throw bad('The Administrator role cannot be deleted');
  if (db.get('SELECT id FROM users WHERE role_id = ?', role.id)) throw bad('Role is still assigned to users');
  db.run('DELETE FROM roles WHERE id = ?', role.id);
  audit(req.user.id, 'delete', 'role', role.id, role.name);
  return { ok: true };
}));

// ------------------------------------------------------------------ users
router.get('/users', requirePerm('admin.users'), h(() => db.all(
  `SELECT u.id, u.username, u.full_name, u.role_id, u.employee_id, u.active, u.last_login, u.created_at,
          r.name role_name, e.full_name employee_name, (u.pin_hash IS NOT NULL) has_pin
   FROM users u LEFT JOIN roles r ON r.id = u.role_id LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.full_name`
)));
router.post('/users', requirePerm('admin.users'), h((req) => {
  required(req.body, 'username', 'full_name', 'password', 'role_id');
  const { username, full_name, password, pin, role_id, employee_id } = req.body;
  if (String(password).length < 6) throw bad('Password must be at least 6 characters');
  if (pin && !/^\d{4,8}$/.test(String(pin))) throw bad('PIN must be 4-8 digits');
  if (db.get('SELECT id FROM users WHERE username = ?', username)) throw bad('Username already exists');
  const id = db.insert('users', {
    username, full_name, password_hash: bcrypt.hashSync(String(password), 10),
    pin_hash: pin ? bcrypt.hashSync(String(pin), 10) : null, role_id, employee_id: employee_id || null, active: 1, created_at: now(),
  });
  audit(req.user.id, 'create', 'user', id, { username, role_id });
  return { id };
}));
router.put('/users/:id', requirePerm('admin.users'), h((req) => {
  const u = db.get('SELECT * FROM users WHERE id = ?', req.params.id);
  if (!u) throw notFound('User');
  const { full_name, role_id, employee_id, active, password, pin } = req.body;
  if (u.id === req.user.id && active === false) throw bad('You cannot disable your own account');
  const upd = { full_name, role_id, employee_id: employee_id === undefined ? undefined : employee_id || null, active: active === undefined ? undefined : active ? 1 : 0 };
  if (password) {
    if (String(password).length < 6) throw bad('Password must be at least 6 characters');
    upd.password_hash = bcrypt.hashSync(String(password), 10);
  }
  if (pin) {
    if (!/^\d{4,8}$/.test(String(pin))) throw bad('PIN must be 4-8 digits');
    upd.pin_hash = bcrypt.hashSync(String(pin), 10);
  }
  db.update('users', u.id, upd);
  if (active === false) db.run('DELETE FROM sessions WHERE user_id = ?', u.id);
  audit(req.user.id, 'update', 'user', u.id, { full_name, role_id, active, password_changed: !!password });
  return { ok: true };
}));
router.delete('/users/:id', requirePerm('admin.users'), h((req) => {
  if (Number(req.params.id) === req.user.id) throw bad('You cannot delete your own account');
  // Users referenced by transactions are disabled instead of deleted, to keep the audit trail intact.
  db.run('UPDATE users SET active = 0 WHERE id = ?', req.params.id);
  db.run('DELETE FROM sessions WHERE user_id = ?', req.params.id);
  audit(req.user.id, 'disable', 'user', Number(req.params.id));
  return { ok: true };
}));

// ------------------------------------------------------------------ audit trail
router.get('/audit', requirePerm('admin.audit'), h((req) => {
  const { from, to } = req.query;
  return db.all(
    `SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     WHERE substr(a.ts,1,10) BETWEEN ? AND ? ORDER BY a.id DESC LIMIT 2000`, from || '0000-00-00', to || '9999-99-99'
  );
}));

// ------------------------------------------------------------------ backup & restore
router.get('/backups', requirePerm('admin.backup'), h(() => backup.list()));
router.post('/backups', requirePerm('admin.backup'), h((req) => {
  const b = backup.create('manual');
  audit(req.user.id, 'backup', 'database', null, b.name);
  return b;
}));
router.get('/backups/:name/download', requirePerm('admin.backup'), (req, res, next) => {
  try {
    res.download(backup.pathOf(req.params.name), req.params.name);
  } catch (e) { next(e); }
});
router.delete('/backups/:name', requirePerm('admin.backup'), h((req) => {
  backup.remove(req.params.name);
  audit(req.user.id, 'delete_backup', 'database', null, req.params.name);
  return { ok: true };
}));
router.post('/backups/:name/restore', requirePerm('admin.backup'), h((req) => {
  const result = backup.restoreFromFile(backup.pathOf(req.params.name));
  audit(null, 'restore', 'database', null, { from: req.params.name, by: req.user.username });
  return result;
}));
router.post('/restore-upload', requirePerm('admin.backup'),
  express.raw({ type: '*/*', limit: '500mb' }),
  h((req) => {
    if (!req.body || !req.body.length) throw bad('No file uploaded');
    const result = backup.restoreFromBuffer(req.body);
    audit(null, 'restore', 'database', null, { from: 'upload', by: req.user.username });
    return result;
  }));

module.exports = router;
