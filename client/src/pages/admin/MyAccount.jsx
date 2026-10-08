import React, { useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { Badge, Button, Card, ErrorBox, Field, Input, Loading, PageHeader, useApi, useToast } from '../../components/ui';

const BLANK = { current_password: '', new_password: '', confirm: '', new_pin: '' };

export default function MyAccount() {
  const { user } = useAuth();
  const toast = useToast();
  const groups = useApi(() => api.get('/permissions'), []);
  const [f, setF] = useState(BLANK);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e.target.value }));

  const perms = user.permissions || [];
  const full = perms.includes('*');

  const submit = async (e) => {
    e.preventDefault();
    setErr('');
    if (!f.new_password && !f.new_pin) return setErr('Enter a new password and/or a new PIN.');
    if (!f.current_password) return setErr('Enter your current password to confirm the change.');
    if (f.new_password && f.new_password.length < 6) return setErr('New password must be at least 6 characters.');
    if (f.new_password !== f.confirm) return setErr('New password and confirmation do not match.');
    if (f.new_pin && !/^\d{4,8}$/.test(f.new_pin)) return setErr('PIN must be 4–8 digits.');
    setBusy(true);
    try {
      await api.post('/auth/change-password', { current_password: f.current_password, new_password: f.new_password || undefined, new_pin: f.new_pin || undefined });
      toast([f.new_password && 'Password', f.new_pin && 'PIN'].filter(Boolean).join(' and ') + ' updated');
      setF(BLANK);
    } catch (ex) {
      setErr(ex.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <PageHeader title="My Account" subtitle="Your sign-in details and what you're allowed to do." />
      <div className="grid-2" style={{ alignItems: 'start' }}>
        <div className="stack">
          <Card title="Profile">
            <div className="form-grid">
              <Field label="Full name"><div className="bold">{user.full_name}</div></Field>
              <Field label="Username"><div>{user.username}</div></Field>
              <Field label="Role"><div><Badge color="blue">{user.role_name || 'No role'}</Badge></div></Field>
            </div>
          </Card>
          <Card title="Change password / POS PIN">
            <form onSubmit={submit} className="stack">
              {err && <div className="alert alert-error" style={{ marginBottom: 0 }}>{err}</div>}
              <Field label="Current password *"><Input type="password" value={f.current_password} onChange={set('current_password')} autoComplete="current-password" /></Field>
              <div className="form-grid">
                <Field label="New password" hint="At least 6 characters. Leave blank to keep."><Input type="password" value={f.new_password} onChange={set('new_password')} autoComplete="new-password" /></Field>
                <Field label="Confirm new password"><Input type="password" value={f.confirm} onChange={set('confirm')} autoComplete="new-password" disabled={!f.new_password} /></Field>
              </div>
              <Field label="New POS PIN" hint="4–8 digits, used for quick manager approvals on the POS (voids, discounts). Leave blank to keep.">
                <Input type="password" inputMode="numeric" maxLength={8} value={f.new_pin} onChange={(e) => setF((x) => ({ ...x, new_pin: e.target.value.replace(/\D/g, '') }))} autoComplete="new-password" style={{ maxWidth: 200 }} />
              </Field>
              <div><Button variant="primary" type="submit" loading={busy}>Update</Button></div>
            </form>
          </Card>
        </div>
        <Card title="My permissions">
          {full ? (
            <div className="alert alert-success" style={{ marginBottom: 0 }}><b>Full access.</b> Your role can use every feature of the system.</div>
          ) : groups.loading && !groups.data ? <Loading /> : (
            <>
              <ErrorBox error={groups.error} />
              {(groups.data || []).map((g) => {
                const mine = g.perms.filter(([k]) => perms.includes(k));
                return (
                  <div key={g.group} className="perm-group">
                    <div className="perm-group-head"><span>{g.group}</span><span className="muted small">{mine.length}/{g.perms.length}</span></div>
                    <div className="perm-list">
                      {mine.length ? mine.map(([k, label]) => <span key={k} className="small">✓ {label}</span>) : <span className="muted small">No access</span>}
                    </div>
                  </div>
                );
              })}
              <p className="muted small" style={{ marginBottom: 0 }}>Need more access? Ask your administrator to update your role.</p>
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
