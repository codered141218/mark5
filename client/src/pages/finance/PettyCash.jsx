import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { peso, today } from '../../format';
import {
  Badge, Button, Card, ErrorBox, Field, Input, Modal, NumberInput, PageHeader, Select, Stat, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { AccountPicker, PayMethodFields, confirmVoid, sumPosted, useForm, voidRow } from './shared';

const EXPENSE_SUGGEST = ['supplies', '6400', '6210', '6510', '6300', 'misc_expense'];
const SOURCE_LABEL = { fund: 'Petty cash box', drawer: 'POS cash drawer' };

export default function PettyCash() {
  const { can } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [range, setRange] = useDateRange();
  const [modal, setModal] = useState(null); // 'expense' | 'replenish'
  const { data, loading, error, reload } = useApi(() => api.get('/finance/petty-cash', range), [range.from, range.to]);
  const rows = data?.rows;
  const manage = can('pettycash.manage');

  const stats = useMemo(() => {
    const s = { fund: 0, drawer: 0, replenish: 0 };
    for (const r of rows || []) {
      if (r.status === 'void') continue;
      if (r.txn_type === 'replenish') s.replenish += r.amount;
      else s[r.source === 'drawer' ? 'drawer' : 'fund'] += r.amount;
    }
    return s;
  }, [rows]);

  const voidTxn = (r) => confirmVoid(dialog, toast, {
    title: `Void ${r.doc_no}`,
    message: `Void this ${r.txn_type} of ${peso(r.amount)}? The general ledger posting will be reversed.`,
    run: (reason) => api.post(`/finance/petty-cash/${r.id}/void`, { reason }),
  }).then((ok) => ok && reload());

  const columns = [
    { key: 'txn_date', label: 'Date', type: 'date' },
    { key: 'doc_no', label: 'PCV No', render: (r) => <span className="bold nowrap">{r.doc_no}</span> },
    { key: 'txn_type', label: 'Type', render: (r) => <Badge color={r.txn_type === 'replenish' ? 'blue' : 'gray'}>{r.txn_type}</Badge> },
    { key: 'source', label: 'Source', render: (r) => SOURCE_LABEL[r.source] || r.source, exportValue: (r) => SOURCE_LABEL[r.source] || r.source },
    { key: 'payee', label: 'Payee' },
    { key: 'description', label: 'Description', render: (r) => r.description || (r.txn_type === 'replenish' ? `Fund replenishment${r.bank_name ? ' from ' + r.bank_name : ''}` : '') },
    { key: 'account_name', label: 'Expense account', render: (r) => (r.account_code ? `${r.account_code} · ${r.account_name}` : ''), exportValue: (r) => (r.account_code ? `${r.account_code} ${r.account_name}` : '') },
    { key: 'or_no', label: 'OR / Receipt no' },
    { key: 'amount', label: 'Amount', type: 'money', total: sumPosted('amount') },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    { key: 'created_by_name', label: 'Recorded by' },
    ...(manage ? [{
      key: '_act', label: '', noExport: true, sortable: false,
      render: (r) => r.status === 'posted' && <Button size="sm" variant="ghost" onClick={() => voidTxn(r)}>Void</Button>,
    }] : []),
  ];

  return (
    <div className="stack">
      <PageHeader
        title="Petty Cash"
        subtitle="Small cash expenses paid from the petty cash box or the POS cash drawer. Every petty cash entry is automatically posted to the general ledger."
        actions={manage && (
          <>
            <Button onClick={() => setModal('replenish')}>Replenish fund</Button>
            <Button variant="primary" onClick={() => setModal('expense')}>+ Record expense</Button>
          </>
        )}
      />
      <div className="row between wrap gap"><DateRange value={range} onChange={setRange} /></div>
      <ErrorBox error={error} />
      <div className="stats">
        <Stat label="Petty cash fund balance" value={peso(data?.fund_balance ?? 0)} sub="Current balance (all dates)" tone={data && data.fund_balance < 0 ? 'red' : 'brand'} />
        <Stat label="Expenses from fund" value={peso(stats.fund)} sub="Paid from the petty cash box" />
        <Stat label="Replenishments" value={peso(stats.replenish)} sub="Added to the fund" tone="green" />
        <Stat label="Drawer payouts" value={peso(stats.drawer)} sub="Paid from the POS cash drawer" tone="amber" />
      </div>
      <Card pad={false}>
        <DataTable columns={columns} rows={rows} loading={loading} rowClass={voidRow}
          exportName="petty-cash" exportTitle="Petty Cash Transactions" exportSubtitle={rangeLabel(range)}
          emptyText="No petty cash transactions in this period." />
      </Card>
      {modal === 'expense' && <ExpenseModal onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
      {modal === 'replenish' && <ReplenishModal onClose={() => setModal(null)} onSaved={() => { setModal(null); reload(); }} />}
    </div>
  );
}

function ExpenseModal({ onClose, onSaved }) {
  const toast = useToast();
  const accounts = useApi(() => api.get('/finance/accounts'), []);
  const [f, set] = useForm({ txn_date: today(), source: 'fund', account_id: '', payee: '', description: '', or_no: '', amount: '' });
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!f.account_id) return toast('Select the expense account', 'error');
    if (!(Number(f.amount) > 0)) return toast('Enter the amount', 'error');
    setBusy(true);
    try {
      await api.post('/finance/petty-cash', { ...f, txn_type: 'expense' });
      toast('Expense recorded and posted to the GL');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title="Record petty cash expense" onClose={onClose} width={620}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save expense</Button></>}>
      <div className="form-grid">
        <Field label="Date" hint={f.source === 'drawer' ? 'Drawer payouts use the open business day.' : undefined}>
          <Input type="date" value={f.txn_date} onChange={set('txn_date')} disabled={f.source === 'drawer'} />
        </Field>
        <Field label="Paid from">
          <Select value={f.source} onChange={set('source')} options={[{ value: 'fund', label: 'Petty cash box (fund)' }, { value: 'drawer', label: 'POS cash drawer' }]} />
        </Field>
        <Field label="Expense account" span={2}>
          <AccountPicker accounts={accounts.data} value={f.account_id} onChange={set('account_id')} types={['expense']} suggest={EXPENSE_SUGGEST} />
        </Field>
        <Field label="Payee"><Input value={f.payee} onChange={set('payee')} placeholder="e.g. Shell, Puregold, tricycle" /></Field>
        <Field label="OR / Receipt no"><Input value={f.or_no} onChange={set('or_no')} /></Field>
        <Field label="Description" span={2}><Input value={f.description} onChange={set('description')} placeholder="e.g. LPG refill, ice, dishwashing soap" /></Field>
        <Field label="Amount (₱)"><NumberInput value={f.amount} onChange={set('amount')} min="0" /></Field>
      </div>
    </Modal>
  );
}

function ReplenishModal({ onClose, onSaved }) {
  const toast = useToast();
  const banks = useApi(() => api.get('/finance/banks'), []);
  const [f, set] = useForm({ txn_date: today(), amount: '', from_method: 'cash', bank_account_id: '', description: '' });
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!(Number(f.amount) > 0)) return toast('Enter the amount', 'error');
    if (f.from_method === 'bank' && !f.bank_account_id) return toast('Select the bank account', 'error');
    setBusy(true);
    try {
      await api.post('/finance/petty-cash', {
        txn_type: 'replenish', source: 'fund', txn_date: f.txn_date, amount: f.amount, from_method: f.from_method,
        bank_account_id: f.from_method === 'bank' ? f.bank_account_id : null, description: f.description,
      });
      toast('Petty cash fund replenished');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title="Replenish petty cash fund" onClose={onClose} width={520}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Replenish</Button></>}>
      <div className="form-grid">
        <Field label="Date"><Input type="date" value={f.txn_date} onChange={set('txn_date')} /></Field>
        <Field label="Amount (₱)"><NumberInput value={f.amount} onChange={set('amount')} min="0" /></Field>
        <PayMethodFields label="Money comes from" methods={['cash', 'bank']} method={f.from_method} onMethod={set('from_method')}
          bankId={f.bank_account_id} onBank={set('bank_account_id')} banks={banks.data} />
        <Field label="Notes" span={2}><Input value={f.description} onChange={set('description')} placeholder="e.g. Check no. / withdrawal slip" /></Field>
      </div>
      <p className="muted small">Posts Dr Petty Cash Fund / Cr {f.from_method === 'bank' ? 'Cash in Bank' : 'Cash on Hand'}.</p>
    </Modal>
  );
}
