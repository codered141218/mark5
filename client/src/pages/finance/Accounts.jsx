import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { money } from '../../format';
import {
  Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Modal, PageHeader, Select, Tabs, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import { ACCOUNT_TYPES, TYPE_LABELS, TYPE_SINGULAR, naturalBalance, useForm } from './shared';

const SUBTYPES = {
  asset: ['cash', 'bank', 'current', 'inventory', 'fixed', 'contra'],
  liability: ['current', 'long_term'],
  equity: ['capital', 'drawings', 'retained'],
  income: ['sales', 'other', 'contra'],
  expense: ['cogs', 'opex', 'other'],
};

export default function Accounts() {
  const { can } = useAuth();
  const [tab, setTab] = useState('all');
  const [edit, setEdit] = useState(null); // account | {} for new
  const { data, loading, error, reload } = useApi(() => api.get('/finance/accounts'), []);
  const manage = can('finance.accounts');

  const rows = useMemo(() => (data || [])
    .filter((a) => tab === 'all' || a.type === tab)
    .map((a) => ({ ...a, nat_balance: naturalBalance(a) })), [data, tab]);

  const tabs = [{ value: 'all', label: `All (${data?.length || 0})` },
    ...ACCOUNT_TYPES.map((t) => ({ value: t, label: `${TYPE_LABELS[t]} (${(data || []).filter((a) => a.type === t).length})` }))];

  const columns = [
    { key: 'code', label: 'Code', width: 80, render: (r) => <span className="bold mono">{r.code}</span> },
    { key: 'name', label: 'Account name', render: (r) => (
      <div>
        {r.name}
        {r.description && <div className="muted small">{r.description}</div>}
      </div>
    ), exportValue: (r) => r.name },
    { key: 'type', label: 'Type', render: (r) => TYPE_SINGULAR[r.type], exportValue: (r) => TYPE_SINGULAR[r.type] },
    { key: 'subtype', label: 'Subtype', render: (r) => <span className="muted">{(r.subtype || '').replace('_', ' ')}</span> },
    { key: 'is_system', label: 'System', render: (r) => (r.is_system ? <Badge color="blue">system</Badge> : ''), exportValue: (r) => (r.is_system ? 'Yes' : '') },
    { key: 'active', label: 'Active', render: (r) => (r.active ? <Badge color="green">active</Badge> : <Badge color="gray">inactive</Badge>), exportValue: (r) => (r.active ? 'Yes' : 'No') },
    { key: 'nat_balance', label: 'Balance', type: 'money', total: tab !== 'all',
      render: (r) => <span className={r.nat_balance < 0 ? 'text-red' : ''}>{money(r.nat_balance)}</span> },
    { key: '_act', label: '', noExport: true, sortable: false, render: (r) => (
      <div className="row gap-sm" style={{ justifyContent: 'flex-end' }} onClick={(e) => e.stopPropagation()}>
        <Link className="btn btn-ghost btn-sm" to={`/reports/finance?report=gl&account_id=${r.id}`}>View ledger</Link>
        {manage && <Button size="sm" onClick={() => setEdit(r)}>Edit</Button>}
      </div>
    ) },
  ];

  return (
    <div className="stack">
      <PageHeader
        title="Chart of Accounts"
        subtitle="All general ledger accounts. System accounts are used by automatic postings (POS, inventory, petty cash, banks, payables…) and cannot be deleted. Balances are shown in their normal sign (liabilities, equity and income as positive)."
        actions={manage && <Button variant="primary" onClick={() => setEdit({})}>+ New account</Button>}
      />
      <ErrorBox error={error} />
      <Card pad={false}>
        <div style={{ padding: '0 12px' }}><Tabs tabs={tabs} value={tab} onChange={setTab} /></div>
        <DataTable columns={columns} rows={rows} loading={loading} rowClass={(r) => (r.active ? '' : 'muted-row')}
          onRowClick={manage ? (r) => setEdit(r) : undefined} pageSize={500}
          exportName="chart-of-accounts" exportTitle="Chart of Accounts" exportSubtitle={tab === 'all' ? 'All accounts' : TYPE_LABELS[tab]} />
      </Card>
      {edit && <AccountModal account={edit} defaultType={tab === 'all' ? 'expense' : tab} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); reload(); }} />}
    </div>
  );
}

function AccountModal({ account, defaultType, onClose, onSaved }) {
  const toast = useToast();
  const dialog = useDialog();
  const isNew = !account.id;
  const hasTx = (account.total_debit || 0) + (account.total_credit || 0) > 0;
  const [f, set] = useForm({
    code: account.code || '', name: account.name || '', type: account.type || defaultType, subtype: account.subtype || '',
    description: account.description || '', active: account.active === undefined ? true : !!account.active,
  });
  const [busy, setBusy] = useState(false);
  const typeLocked = !isNew && (account.is_system || hasTx);

  const save = async () => {
    if (!f.code.trim() || !f.name.trim()) return toast('Code and name are required', 'error');
    setBusy(true);
    try {
      if (isNew) await api.post('/finance/accounts', f);
      else await api.put(`/finance/accounts/${account.id}`, f);
      toast(isNew ? 'Account created' : 'Account saved');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  const remove = async () => {
    if (!(await dialog({ title: 'Delete account', message: `Delete ${account.code} · ${account.name}? This cannot be undone.`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/finance/accounts/${account.id}`);
      toast('Account deleted');
      onSaved();
    } catch (e) { toast(e.message, 'error'); }
  };

  return (
    <Modal title={isNew ? 'New account' : `Edit account ${account.code}`} onClose={onClose} width={600}
      footer={<>
        {!isNew && !account.is_system && !hasTx && <Button variant="danger" onClick={remove} style={{ marginRight: 'auto' }}>Delete</Button>}
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} onClick={save}>Save</Button>
      </>}>
      {account.is_system ? <div className="alert alert-info">System account used by automatic postings. You may rename it, but its type cannot change and it cannot be deleted.</div> : null}
      {!account.is_system && hasTx ? <div className="alert alert-info">This account has transactions: its type is locked and it cannot be deleted. Deactivate it to hide it from selections.</div> : null}
      <div className="form-grid">
        <Field label="Code" hint="e.g. 6150 — the first digit follows the type (1 asset … 6 expense)"><Input value={f.code} onChange={set('code')} autoFocus={isNew} /></Field>
        <Field label="Type">
          <Select value={f.type} onChange={set('type')} disabled={typeLocked} options={ACCOUNT_TYPES.map((t) => ({ value: t, label: TYPE_SINGULAR[t] }))} />
        </Field>
        <Field label="Account name" span={2}><Input value={f.name} onChange={set('name')} placeholder="e.g. Gas & Fuel Expense" /></Field>
        <Field label="Subtype" hint="Used to group accounts on statements">
          <Input value={f.subtype} onChange={set('subtype')} list="acct-subtypes" />
          <datalist id="acct-subtypes">{(SUBTYPES[f.type] || []).map((s) => <option key={s} value={s} />)}</datalist>
        </Field>
        {!isNew && <Field label="Status"><Checkbox checked={f.active} onChange={set('active')} label="Active (available for new transactions)" /></Field>}
        <Field label="Description" span={2}><Textarea value={f.description} onChange={set('description')} rows={2} /></Field>
      </div>
    </Modal>
  );
}
