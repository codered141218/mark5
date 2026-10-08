import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api, getToken, setToken, setUnauthorizedHandler } from './api';

const AuthCtx = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [settings, setSettings] = useState({});
  const [loading, setLoading] = useState(true);

  const loadSettings = useCallback(async () => {
    try { setSettings(await api.get('/settings')); } catch { /* ignore */ }
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => setUser(null));
    if (!getToken()) { setLoading(false); return; }
    api.get('/auth/me')
      .then((r) => { setUser(r.user); return loadSettings(); })
      .catch(() => setToken(null))
      .finally(() => setLoading(false));
  }, [loadSettings]);

  const login = async (username, password) => {
    const r = await api.post('/auth/login', { username, password });
    setToken(r.token);
    setUser(r.user);
    await loadSettings();
    return r.user;
  };
  const logout = async () => {
    try { await api.post('/auth/logout'); } catch { /* ignore */ }
    setToken(null);
    setUser(null);
  };
  const can = useCallback((...perms) => {
    if (!user) return false;
    const p = user.permissions || [];
    return p.includes('*') || perms.some((x) => p.includes(x));
  }, [user]);

  return (
    <AuthCtx.Provider value={{ user, settings, loading, login, logout, can, reloadSettings: loadSettings }}>
      {children}
    </AuthCtx.Provider>
  );
}

export const useAuth = () => useContext(AuthCtx);
