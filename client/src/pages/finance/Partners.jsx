import React, { useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { money } from '../../format';
import {
  Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Modal, NumberInput, PageHeader, Tabs, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import { useForm, voidRow } from './shared';

// Adding is allowed to more roles than editing (see server/routes/finance.js).
const ADD_PERMS = ['partners.manage', 'inventory.receive', 'finance.ap', 'finance.ar'];
const KINDS = {
  suppliers: { one: 'Supplier', url: '/finance/suppliers', defTerms: 0 },
  customers: { one: 'Customer', url: '/finance/customers', defTerms: 30 },
};

export default function Partners() {
  const { can } = useAuth();
  const [tab, setTab] = useState('suppliers');
  return (
    <div className="stack">
      <PageHeader title="Suppliers & Customers"
        subtitle="Suppliers you buy from (deliveries and payables) and charge-account customers (receivables). Balances show what is still unpaid." />
      <Tabs tabs={[{ value: 'suppliers', label: 'Suppliers' }, { value: 'customers', label: 'Customers' }]} value={tab} onChange={setTab} />
      <PartnerList key={tab} kind={tab} canEdit={can('partners.manage')} canAdd={can(...ADD_PERMS)} />
    </div>
  );
}

function PartnerList({ kind, canEdit, canAdd }) {
  const toast = useToast();
  const dialog = useDialog();
  const k = KINDS[kind];
  const [showAll, setShowAll] = useState(false);
  const [edit, setEdit] = useState(null);
  const { data, loading, error, reload } = useApi(() => api.get(k.url, showAll ? { all: 1 } : undefined), [kind, showAll]);

  const deactivate = async (r) => {
    if (!(await dialog({ title: `Deactivate ${k.one.toLowerCase()}`, message: `Deactivate ${r.name}? It will be hidden from selections but its history is kept.`, danger: true, okText: 'Deactivate' }))) return;
    try { await api.del(`${k.url}/${r.id}`); toast(`${k.one} deactivated`); reload(); } catch (e) { toast(e.message, 'error'); }
  };
  const reactivate = async (r) => {
    try { await api.put(`${k.url}/${r.id}`, { active: true }); toast(`${k.one} reactivated`); reload(); } catch (e) { toast(e.message, 'error'); }
  };

  const columns = [
    { key: 'name', label: 'Name', render: (r) => <span className="bold">{r.name}</span> },
    { key: 'contact_person', label: 'Contact person' },
    { key: 'phone', label: 'Phone' },
    { key: 'email', label: 'Email' },
    { key: 'address', label: 'Address' },
    { key: 'tin', label: 'TIN' },
    { key: 'terms_days', label: 'Terms (days)', type: 'number' },
    ...(kind === 'customers' ? [{ key: 'credit_limit', label: 'Credit limit', type: 'money' }] : []),
    { key: 'notes', label: 'Notes' },
    { key: 'balance', label: 'Balance outstanding', type: 'money', total: true,
      render: (r) => <span className={r.balance > 0 ? 'bold' : 'muted'}>{money(r.balance || 0)}</span> },
    ...(showAll ? [{ key: 'active', label: 'Status', render: (r) => <Badge color={r.active ? 'green' : 'gray'}>{r.active ? 'active' : 'inactive'}</Badge>, exportValue: (r) => (r.active ? 'Active' : 'Inactive') }] : []),
    ...(canEdit ? [{
      key: '_act', label: '', noExport: true, sortable: false, render: (r) => (
        <div className="row gap-sm" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
          <Button size="sm" onClick={() => setEdit(r)}>Edit</Button>
          {r.active ? <Button size="sm" variant="ghost" onClick={() => deactivate(r)}>Deactivate</Button>
            : <Button size="sm" variant="ghost" onClick={() => reactivate(r)}>Reactivate</Button>}
        </div>
      ),
    }] : []),
  ];

  return (
    <>
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} rowClass={voidRow} onRowClick={canEdit ? (r) => setEdit(r) : undefined}
          exportName={kind} exportTitle={kind === 'suppliers' ? 'Suppliers' : 'Customers'}
          toolbar={<>
            <Checkbox checked={showAll} onChange={setShowAll} label="Show inactive" />
            {canAdd && <Button size="sm" variant="primary" onClick={() => setEdit({})}>+ New {k.one.toLowerCase()}</Button>}
          </>}
          emptyText={`No ${kind} yet.`} />
      </Card>
      {edit && <PartnerModal kind={kind} row={edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); reload(); }} />}
    </>
  );
}

function PartnerModal({ kind, row, onClose, onSaved }) {
  const toast = useToast();
  const k = KINDS[kind];
  const isNew = !row.id;
  const [f, set] = useForm({
    name: row.name || '', contact_person: row.contact_person || '', phone: row.phone || '', email: row.email || '', address: row.address || '',
    tin: row.tin || '', terms_days: row.terms_days ?? k.defTerms, credit_limit: row.credit_limit ?? 0, notes: row.notes || '',
  });
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!f.name.trim()) return toast('Name is required', 'error');
    setBusy(true);
    const body = { ...f };
    if (kind === 'suppliers') delete body.credit_limit;
    try {
      if (isNew) await api.post(k.url, body);
      else await api.put(`${k.url}/${row.id}`, body);
      toast(`${k.one} saved`);
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title={isNew ? `New ${k.one.toLowerCase()}` : `Edit ${row.name}`} onClose={onClose} width={640}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
      <div className="form-grid">
        <Field label="Name" span={2}><Input value={f.name} onChange={set('name')} autoFocus /></Field>
        <Field label="Contact person"><Input value={f.contact_person} onChange={set('contact_person')} /></Field>
        <Field label="Phone"><Input value={f.phone} onChange={set('phone')} placeholder="09xx-xxx-xxxx" /></Field>
        <Field label="Email"><Input type="email" value={f.email} onChange={set('email')} /></Field>
        <Field label="TIN"><Input value={f.tin} onChange={set('tin')} placeholder="000-000-000-000" /></Field>
        <Field label="Address" span={2}><Input value={f.address} onChange={set('address')} /></Field>
        <Field label="Payment terms (days)" hint={kind === 'suppliers' ? '0 = cash / COD' : 'Days before an invoice is due'}>
          <NumberInput value={f.terms_days} onChange={set('terms_days')} min="0" step="1" />
        </Field>
        {kind === 'customers' && <Field label="Credit limit (₱)" hint="0 = no limit"><NumberInput value={f.credit_limit} onChange={set('credit_limit')} min="0" /></Field>}
        <Field label="Notes" span={2}><Textarea value={f.notes} onChange={set('notes')} rows={2} /></Field>
      </div>
    </Modal>
  );
}
