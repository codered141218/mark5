import React, { useState } from 'react';
import { api } from '../../api';
import { fmtDate, peso } from '../../format';
import DataTable from '../../components/DataTable';
import { Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Modal, PageHeader, Textarea, useApi, useToast } from '../../components/ui';

const BLANK = { emp_no: '', full_name: '', position: '', department: '', phone: '', date_hired: '', notes: '', active: true };

export default function Employees() {
  const toast = useToast();
  const [showAll, setShowAll] = useState(false);
  const [editing, setEditing] = useState(null);
  const { data, loading, error, reload } = useApi(() => api.get('/finance/employees', showAll ? { all: 1 } : undefined), [showAll]);

  const columns = [
    { key: 'emp_no', label: 'Emp No', width: 90 },
    { key: 'full_name', label: 'Full name', render: (r) => <span className="bold">{r.full_name}</span> },
    { key: 'position', label: 'Position' },
    { key: 'department', label: 'Department' },
    { key: 'phone', label: 'Phone' },
    { key: 'date_hired', label: 'Date hired', type: 'date' },
    { key: 'ca_balance', label: 'Cash advance bal.', type: 'money', total: true },
    {
      key: 'active', label: 'Status', exportValue: (r) => (r.active ? 'Active' : 'Inactive'),
      render: (r) => <Badge color={r.active ? 'green' : 'gray'}>{r.active ? 'active' : 'inactive'}</Badge>,
    },
  ];

  return (
    <div>
      <PageHeader title="Employees" subtitle="Staff records used for cash advances and linking user accounts."
        actions={<Button variant="primary" onClick={() => setEditing({ ...BLANK })}>+ Add employee</Button>} />
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} exportName="employees" exportTitle="Employees"
          onRowClick={(r) => setEditing({ ...BLANK, ...r, active: !!r.active })}
          toolbar={<Checkbox checked={showAll} onChange={setShowAll} label="Show inactive" />}
          emptyText="No employees yet." />
      </Card>
      {editing && (
        <EmployeeForm initial={editing} onClose={() => setEditing(null)}
          onSaved={(msg) => { toast(msg); setEditing(null); reload(); }} />
      )}
    </div>
  );
}

function EmployeeForm({ initial, onClose, onSaved }) {
  const toast = useToast();
  const [f, setF] = useState(initial);
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF((x) => ({ ...x, [k]: e && e.target ? e.target.value : e }));

  const save = async () => {
    if (!f.full_name.trim()) return toast('Full name is required', 'error');
    setBusy(true);
    try {
      const body = { emp_no: f.emp_no, full_name: f.full_name, position: f.position, department: f.department, phone: f.phone, date_hired: f.date_hired, notes: f.notes };
      if (f.id) await api.put(`/finance/employees/${f.id}`, { ...body, active: f.active });
      else await api.post('/finance/employees', body);
      onSaved(f.id ? 'Employee updated' : 'Employee added');
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal title={f.id ? `Edit employee — ${initial.full_name}` : 'Add employee'} onClose={onClose} width={640}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
      <div className="form-grid">
        <Field label="Employee no." hint={f.id ? undefined : 'Leave blank to auto-number'}><Input value={f.emp_no} onChange={set('emp_no')} placeholder="Auto" /></Field>
        <Field label="Full name *" span={2}><Input autoFocus value={f.full_name} onChange={set('full_name')} /></Field>
        <Field label="Position"><Input value={f.position} onChange={set('position')} placeholder="e.g. Cook, Server" /></Field>
        <Field label="Department"><Input value={f.department} onChange={set('department')} placeholder="e.g. Kitchen, Dining" /></Field>
        <Field label="Phone"><Input value={f.phone} onChange={set('phone')} placeholder="09xx xxx xxxx" /></Field>
        <Field label="Date hired"><Input type="date" value={f.date_hired} onChange={set('date_hired')} /></Field>
        <Field label="Notes" span={3}><Textarea value={f.notes} onChange={set('notes')} /></Field>
      </div>
      {f.id && (
        <div className="mt row between wrap gap">
          <Checkbox checked={f.active} onChange={set('active')} label="Active employee" />
          {Number(f.ca_balance) > 0 && <span className="small text-amber">Outstanding cash advance: {peso(f.ca_balance)}</span>}
          {f.date_hired && <span className="muted small">Hired {fmtDate(f.date_hired)}</span>}
        </div>
      )}
    </Modal>
  );
}
