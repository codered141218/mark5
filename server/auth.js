'use strict';
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const db = require('./db');
const { HttpError, now, audit } = require('./util');
const { hasPerm } = require('./permissions');

const SESSION_HOURS = 16;

function loadUser(userId) {
  const u = db.get(
    `SELECT u.id, u.username, u.full_name, u.role_id, u.employee_id, u.active, r.name role_name, r.permissions
     FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?`, userId
  );
  if (!u) return null;
  let perms = [];
  try { perms = JSON.parse(u.permissions || '[]'); } catch { perms = []; }
  return { ...u, permissions: perms };
}

function createSession(userId) {
  const token = crypto.randomBytes(32).toString('hex');
  const exp = new Date(Date.now() + SESSION_HOURS * 3600 * 1000);
  db.run('INSERT INTO sessions (token, user_id, created_at, expires_at) VALUES (?,?,?,?)', token, userId, now(), exp.toISOString());
  db.run('UPDATE users SET last_login = ? WHERE id = ?', now(), userId);
  return token;
}

function authenticate(req, res, next) {
  const hdr = req.headers.authorization || '';
  const token = hdr.startsWith('Bearer ') ? hdr.slice(7) : null;
  if (!token) return next(new HttpError(401, 'Please log in'));
  const s = db.get('SELECT * FROM sessions WHERE token = ?', token);
  if (!s || new Date(s.expires_at) < new Date()) {
    if (s) db.run('DELETE FROM sessions WHERE token = ?', token);
    return next(new HttpError(401, 'Session expired, please log in again'));
  }
  const user = loadUser(s.user_id);
  if (!user || !user.active) return next(new HttpError(401, 'Account is disabled'));
  req.user = user;
  req.token = token;
  next();
}

const can = (user, perm) => hasPerm(user.permissions, perm);

// requirePerm('a', 'b') => user needs ANY of the listed permissions
function requirePerm(...perms) {
  return (req, res, next) => {
    if (perms.some((p) => can(req.user, p))) return next();
    next(new HttpError(403, 'You do not have permission for this function'));
  };
}

function assertPerm(req, perm) {
  if (!can(req.user, perm)) throw new HttpError(403, 'You do not have permission for this function');
}

/**
 * Manager override: if the logged-in user lacks `perm`, accept a PIN from any active user who has it.
 * Returns the id of the authorizing user.
 */
function authorize(req, perm, pin) {
  if (can(req.user, perm)) return req.user.id;
  if (!pin) throw new HttpError(403, 'Manager authorization required');
  const candidates = db.all('SELECT id, pin_hash FROM users WHERE active = 1 AND pin_hash IS NOT NULL');
  for (const c of candidates) {
    if (bcrypt.compareSync(String(pin), c.pin_hash)) {
      const u = loadUser(c.id);
      if (u && can(u, perm)) {
        audit(req.user.id, 'override', perm, null, { authorized_by: u.username });
        return u.id;
      }
    }
  }
  throw new HttpError(403, 'Invalid manager PIN or insufficient authority');
}

module.exports = { loadUser, createSession, authenticate, requirePerm, assertPerm, authorize, can };
