import React, { useEffect, useState } from 'react';
import { useAuth } from '../auth';
import { api } from '../api';
import { Button, Field, Input } from '../components/ui';

export default function Login() {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [biz, setBiz] = useState('');

  useEffect(() => { api.get('/public/info').then((r) => setBiz(r.business_name)).catch(() => {}); }, []);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true); setError('');
    try { await login(username, password); } catch (err) { setError(err.message); } finally { setBusy(false); }
  };

  return (
    <div className="login-page">
      <form className="login-card" onSubmit={submit}>
        <div className="brand-logo">M5</div>
        <h1>{biz || 'Mark5 Restaurant Suite'}</h1>
        <p className="muted" style={{ marginTop: 4 }}>Sign in to continue</p>
        {error && <div className="alert alert-error">{error}</div>}
        <div className="stack mt">
          <Field label="Username"><Input autoFocus value={username} onChange={(e) => setUsername(e.target.value)} autoComplete="username" /></Field>
          <Field label="Password"><Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" /></Field>
          <Button variant="primary" size="lg" type="submit" loading={busy} style={{ width: '100%' }}>Sign in</Button>
        </div>
      </form>
    </div>
  );
}
