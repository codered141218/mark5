import React, { useEffect, useMemo, useState } from 'react';
import { api } from '../../api';
import { Badge, Button, Card, Checkbox, Empty, ErrorBox, Field, Input, Loading, PageHeader, useApi, useDialog, useToast } from '../../components/ui';

const isFull = (r) => r && (r.is_system || (r.permissions || []).includes('*'));

export default function Roles() {
  const toast = useToast();
  const dialog = useDialog();
  const roles = useApi(() => api.get('/roles'), []);
  const groups = useApi(() => api.get('/permissions'), []);
  const [sel, setSel] = useState(null); // role being edited (copy); id undefined = new
  const [busy, setBusy] = useState(false);

  const total = useMemo(() => (groups.data || []).reduce((s, g) => s + g.perms.length, 0), [groups.data]);

  // Select the first role once loaded.
  useEffect(() => {
    if (!sel && roles.data && roles.data.length) pick(roles.data.find((r) => !r.is_system) || roles.data[0]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roles.data]);

  const pick = (r) => setSel({ id: r.id, name: r.name, description: r.description || '', permissions: [...(r.permissions || [])], is_system: r.is_system, user_count: r.user_count });
  const startNew = () => setSel({ name: '', description: '', permissions: [] });
  const duplicate = (r) => setSel({
    name: `${r.name} (copy)`, description: r.description || '',
    permissions: isFull(r) ? (groups.data || []).flatMap((g) => g.perms.map((p) => p[0])) : [...r.permissions],
  });

  const save = async () => {
    if (!sel.name.trim()) return toast('Role name is required', 'error');
    setBusy(true);
    try {
      const body = { name: sel.name.trim(), description: sel.description, permissions: sel.permissions };
      let id = sel.id;
      if (id) await api.put(`/roles/${id}`, body);
      else id = (await api.post('/roles', body)).id;
      toast(sel.id ? 'Role saved' : 'Role created');
      const list = await roles.reload();
      const r = (list || []).find((x) => x.id === id);
      if (r) pick(r);
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!(await dialog({ title: 'Delete role', message: `Delete the role "${sel.name}"? This cannot be undone. Roles still assigned to users cannot be deleted.`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/roles/${sel.id}`);
      toast('Role deleted');
      setSel(null);
      roles.reload();
    } catch (e) { toast(e.message, 'error'); }
  };

  const has = (k) => sel.permissions.includes(k);
  const toggle = (k, on) => setSel((s) => ({ ...s, permissions: on ? [...new Set([...s.permissions, k])] : s.permissions.filter((p) => p !== k) }));
  const setGroup = (g, on) => setSel((s) => {
    const keys = g.perms.map((p) => p[0]);
    return { ...s, permissions: on ? [...new Set([...s.permissions, ...keys])] : s.permissions.filter((p) => !keys.includes(p)) };
  });

  const full = isFull(sel);

  return (
    <div>
      <PageHeader title="Roles & Permissions"
        subtitle="A role is a job function (Cashier, Cook, Manager…). Tick what that function is allowed to do, then assign the role to users."
        actions={<Button variant="primary" onClick={startNew}>+ New role</Button>} />
      <ErrorBox error={roles.error || groups.error} />
      <div className="row gap-lg" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <Card title="Roles" className="roles-list" pad={false}>
          <div style={{ width: 280, maxWidth: '100%' }}>
            {roles.loading && !roles.data ? <Loading /> : (roles.data || []).map((r) => (
              <button key={r.id} type="button" onClick={() => pick(r)}
                style={{
                  display: 'block', width: '100%', textAlign: 'left', border: 'none', borderBottom: '1px solid var(--border)', font: 'inherit', cursor: 'pointer', padding: '10px 14px',
                  background: sel && sel.id === r.id ? 'var(--brand-soft)' : 'transparent', borderLeft: `3px solid ${sel && sel.id === r.id ? 'var(--brand)' : 'transparent'}`,
                }}>
                <div className="row between gap-sm">
                  <span className="bold">{r.name}</span>
                  <span className="muted small nowrap">{r.user_count} user{r.user_count === 1 ? '' : 's'}</span>
                </div>
                <div className="muted small" style={{ marginTop: 2 }}>{r.description || '—'}</div>
                <div className="small" style={{ marginTop: 4 }}>
                  {isFull(r) ? <Badge color="green">Full access</Badge> : <span className="muted">{r.permissions.length} of {total} permissions</span>}
                </div>
              </button>
            ))}
          </div>
        </Card>

        <div className="grow" style={{ minWidth: 320 }}>
          {!sel ? <Card><Empty>Select a role on the left, or create a new one.</Empty></Card> : (
            <Card title={sel.id ? `Edit role — ${sel.name || 'untitled'}` : 'New role'}
              actions={<>
                {sel.id && <Button size="sm" onClick={() => duplicate(sel)}>⧉ Duplicate</Button>}
                {sel.id && !sel.is_system && <Button size="sm" variant="ghost" style={{ color: "var(--red)" }} onClick={remove}>Delete</Button>}
                {!full && <Button size="sm" variant="primary" loading={busy} onClick={save}>{sel.id ? 'Save changes' : 'Create role'}</Button>}
              </>}>
              <div className="form-grid">
                <Field label="Role name *"><Input value={sel.name} disabled={!!sel.is_system} onChange={(e) => setSel({ ...sel, name: e.target.value })} placeholder="e.g. Head Cook" /></Field>
                <Field label="Description" span={2}><Input value={sel.description} disabled={!!sel.is_system} onChange={(e) => setSel({ ...sel, description: e.target.value })} placeholder="What this job function does" /></Field>
              </div>
              {full ? (
                <div className="alert alert-success mt" style={{ marginBottom: 0 }}>
                  <b>Full access.</b> The {sel.name} role can do everything in the system, including managing users, roles and backups. It cannot be edited or deleted.
                  Use <b>Duplicate</b> to create a restricted role from it.
                </div>
              ) : (
                <>
                  <div className="row between wrap gap mt mb">
                    <span className="muted small">{sel.permissions.length} of {total} permissions selected. Users with this role only see the menus and buttons they're allowed to use.</span>
                    <span className="row gap-sm">
                      <Button size="sm" variant="ghost" onClick={() => setSel({ ...sel, permissions: (groups.data || []).flatMap((g) => g.perms.map((p) => p[0])) })}>Select all</Button>
                      <Button size="sm" variant="ghost" onClick={() => setSel({ ...sel, permissions: [] })}>Clear all</Button>
                    </span>
                  </div>
                  {(groups.data || []).map((g) => {
                    const n = g.perms.filter((p) => has(p[0])).length;
                    return (
                      <div key={g.group} className="perm-group">
                        <div className="perm-group-head">
                          <span>{g.group} <span className="muted small" style={{ fontWeight: 400 }}>({n}/{g.perms.length})</span></span>
                          <span className="row gap-sm small">
                            <Button size="sm" variant="link" onClick={() => setGroup(g, true)} disabled={n === g.perms.length}>Select all</Button>
                            <span className="muted">/</span>
                            <Button size="sm" variant="link" onClick={() => setGroup(g, false)} disabled={n === 0}>None</Button>
                          </span>
                        </div>
                        <div className="perm-list">
                          {g.perms.map(([k, label]) => <Checkbox key={k} checked={has(k)} onChange={(on) => toggle(k, on)} label={label} />)}
                        </div>
                      </div>
                    );
                  })}
                  <div className="row gap-sm" style={{ justifyContent: 'flex-end' }}>
                    <Button variant="primary" loading={busy} onClick={save}>{sel.id ? 'Save changes' : 'Create role'}</Button>
                  </div>
                </>
              )}
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
