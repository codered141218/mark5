import React, { useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import DataTable from '../../components/DataTable';
import { Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Modal, PageHeader, Select, useApi, useDialog, useToast } from '../../components/ui';

const BLANK = { username: '', full_name: '', password: '', pin: '', role_id: '', employee_id: '', active: true };

export default function Users() {
  const toast = useToast();
  const dialog = useDialog();
  const { user } = useAuth();
  const [editing, setEditing] = useState(null);
  const { data, loading, error, reload } = useApi(() => api.get('/users'), []);
  const roles = useApi(() => api.get('/roles'), []);
  const emps = useApi(() => api.get('/finance/employees'), []);

  const disable = async (u) => {
    if (!(await dialog({ title: 'Disable user', message: `Disable "${u.username}"? They will be signed out and can no longer log in. Their past transactions stay in the records.`, danger: true, okText: 'Disable' }))) return;
    try {
      await api.del(`/users/${u.id}`);
      toast('User disabled');
      reload();
    } catch (e) { toast(e.message, 'error'); }
  };

  const columns = [
    { key: 'username', label: 'Username', render: (r) => <span className="bold">{r.username}{r.id === user.id && <span className="muted small"> (you)</span>}</span> },
    { key: 'full_name', label: 'Full name' },
    { key: 'role_name', label: 'Role', render: (r) => r.role_name ? <Badge color="blue">{r.role_name}</Badge> : <span className="muted">—</span> },
    { key: 'employee_name', label: 'Linked employee', render: (r) => r.employee_name || <span className="muted">—</span> },
    { key: 'has_pin', label: 'POS PIN', exportValue: (r) => (r.has_pin ? 'Yes' : 'No'), render: (r) => (r.has_pin ? '✓' : <span className="muted">—</span>), align: 'center' },
    { key: 'active', label: 'Status', exportValue: (r) => (r.active ? 'Active' : 'Disabled'), render: (r) => <Badge color={r.active ? 'green' : 'red'}>{r.active ? 'active' : 'disabled'}</Badge> },
    { key: 'last_login', label: 'Last login', type: 'datetime' },
    {
      key: '_act', label: '', noExport: true, sortable: false, align: 'right',
      render: (r) => (
        <span className="row gap-sm" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
          <Button size="sm" onClick={() => setEditing({ ...BLANK, ...r, role_id: String(r.role_id || ''), employee_id: String(r.employee_id || ''), active: !!r.active, password: '', pin: '' })}>Edit</Button>
          {r.active && r.id !== user.id ? <Button size="sm" variant="ghost" style={{ color: "var(--red)" }} onClick={() => disable(r)}>Disable</Button> : null}
        </span>
      ),
    },
  ];

  return (
    <div>
      <PageHeader title="Users" subtitle="Who can sign in, and what role (set of permissions) each person has."
        actions={<Button variant="primary" onClick={() => setEditing({ ...BLANK })}>+ Add user</Button>} />
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} exportName="users" exportTitle="System users"
          rowClass={(r) => (r.active ? '' : 'muted-row')} emptyText="No users." />
      </Card>
      {editing && (
        <UserForm initial={editing} roles={roles.data || []} employees={emps.data || []} self={editing.id === user.id}
          onClose={() => setEditing(null)} onSaved={(msg) => { toast(msg); setEditing(null); reload(); }} />
      )}
    </div>
  );
}

function UserForm({ initial, roles, employees, self, onClose, onSaved }) {
  const toast = useToast();
  const [f, setF] = useState(initial);
  const [busy, setBusy] = useState(false);
  const isNew = !f.id;
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e && e.target ? e.target.value : e }));

  const save = async () => {
    if (isNew && !f.username.trim()) return toast('Username is required', 'error');
    if (!f.full_name.trim()) return toast('Full name is required', 'error');
    if (!f.role_id) return toast('Choose a role', 'error');
    if ((isNew || f.password) && f.password.length < 6) return toast('Password must be at least 6 characters', 'error');
    if (f.pin && !/^\d{4,8}$/.test(f.pin)) return toast('PIN must be 4–8 digits', 'error');
    setBusy(true);
    try {
      const body = { full_name: f.full_name.trim(), role_id: Number(f.role_id), employee_id: f.employee_id ? Number(f.employee_id) : null };
      if (f.password) body.password = f.password;
      if (f.pin) body.pin = f.pin;
      if (isNew) await api.post('/users', { ...body, username: f.username.trim() });
      else await api.put(`/users/${f.id}`, { ...body, active: f.active });
      onSaved(isNew ? 'User created' : 'User updated');
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const roleOpts = roles.map((r) => ({ value: String(r.id), label: r.name }));
  const role = roles.find((r) => String(r.id) === f.role_id);
  const empOpts = employees.map((e) => ({ value: String(e.id), label: `${e.full_name}${e.emp_no ? ` (${e.emp_no})` : ''}` }));
  // Keep the current link visible even if that employee is now inactive.
  if (f.employee_id && !empOpts.some((o) => o.value === f.employee_id)) empOpts.push({ value: f.employee_id, label: initial.employee_name || `Employee #${f.employee_id}` });

  return (
    <Modal title={isNew ? 'Add user' : `Edit user — ${initial.username}`} onClose={onClose} width={640}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>{isNew ? 'Create user' : 'Save'}</Button></>}>
      <div className="form-grid">
        {isNew && <Field label="Username *"><Input autoFocus value={f.username} onChange={set('username')} autoComplete="off" /></Field>}
        <Field label="Full name *" span={isNew ? 1 : 2}><Input autoFocus={!isNew} value={f.full_name} onChange={set('full_name')} /></Field>
        <Field label={isNew ? 'Password *' : 'New password'} hint={isNew ? 'At least 6 characters' : 'Leave blank to keep the current password'}>
          <Input type="password" value={f.password} onChange={set('password')} autoComplete="new-password" />
        </Field>
        <Field label={isNew ? 'POS PIN (optional)' : 'New POS PIN'} hint={isNew ? '4–8 digits' : (initial.has_pin ? 'Has a PIN. Leave blank to keep it' : 'No PIN set yet')}>
          <Input type="password" inputMode="numeric" maxLength={8} value={f.pin} onChange={(e) => set('pin')(e.target.value.replace(/\D/g, ''))} autoComplete="new-password" />
        </Field>
        <Field label="Role *" hint={role ? role.description : undefined}><Select options={roleOpts} value={f.role_id} onChange={set('role_id')} placeholder="— Choose role —" /></Field>
        <Field label="Linked employee"><Select options={empOpts} value={f.employee_id} onChange={set('employee_id')} placeholder="— None —" /></Field>
      </div>
      <div className="alert alert-info mt small" style={{ marginBottom: 0 }}>
        <b>POS PIN</b> is a short number code used for quick manager override approvals on the POS — e.g. authorizing a void or a discount for a cashier without signing in.<br />
        <b>Linked employee</b> lets this user file their own cash advance requests.
      </div>
      {!isNew && (
        <div className="mt">
          {self ? <span className="muted small">You cannot disable your own account.</span>
            : <Checkbox checked={f.active} onChange={set('active')} label="Active (can sign in)" />}
        </div>
      )}
    </Modal>
  );
}
