// Shared screen for Accounts Payable (bills) and Accounts Receivable (invoices).
import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { fmtDate, money, peso, today, toISO } from '../../format';
import {
  Badge, Button, Card, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Select, Stat, Tabs, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { AccountPicker, InfoGrid, METHOD_LABELS, PayMethodFields, confirmVoid, sumPosted, useForm, voidRow } from './shared';

export const AP = {
  title: 'Accounts Payable',
  subtitle: 'Bills from suppliers and their payments. Deliveries received on credit automatically create bills here. Every bill and payment is posted to the general ledger.',
  doc: 'Bill', docs: 'bills', no: 'bill_no', date: 'bill_date', partyId: 'supplier_id', partyName: 'supplier_name', party: 'Supplier',
  list: '/finance/ap/bills', partyUrl: '/finance/suppliers', aging: '/reports/finance/ap-aging',
  pay: (id) => `/finance/ap/bills/${id}/pay`, payDate: 'pay_date', payments: 'payments', voidPayment: (id) => `/finance/ap/payments/${id}/void`,
  payVerb: 'Pay', payDone: 'Payment recorded', paidLabel: 'Paid', methods: ['cash', 'petty_cash', 'bank'], methodLabel: 'Paid from',
  account: 'expense_account_id', accountLabel: 'Expense / asset account', accountTypes: ['expense', 'asset'],
  suggest: ['inventory', 'supplies', '6100', '6200', '6210', '6220', '6300', '6600', 'misc_expense'],
  refLabel: 'Supplier invoice / ref no', autoSource: 'inv_receive', autoNote: 'This bill came from a delivery receipt. To void it, void the delivery instead.',
};
export const AR = {
  title: 'Accounts Receivable',
  subtitle: 'Charge accounts and invoices to customers, and their collections. POS sales settled with “Charge to account” automatically create receivables here.',
  doc: 'Invoice', docs: 'invoices', no: 'invoice_no', date: 'inv_date', partyId: 'customer_id', partyName: 'customer_name', party: 'Customer',
  list: '/finance/ar/invoices', partyUrl: '/finance/customers', aging: '/reports/finance/ar-aging',
  pay: (id) => `/finance/ar/invoices/${id}/collect`, payDate: 'rcpt_date', payments: 'receipts', voidPayment: (id) => `/finance/ar/receipts/${id}/void`,
  payVerb: 'Collect', payDone: 'Collection recorded', paidLabel: 'Collected', methods: ['cash', 'bank'], methodLabel: 'Received into',
  account: 'income_account_id', accountLabel: 'Income account', accountTypes: ['income'], defaultKey: 'sales',
  suggest: ['sales', 'other_income'],
  refLabel: 'Reference / PO no', autoSource: 'pos_sale', autoNote: 'This receivable came from a POS charge sale. To void it, void the POS receipt instead.',
};

const STATUS = [
  { value: '', label: 'All statuses' }, { value: 'unpaid', label: 'Unpaid (open + partial)' }, { value: 'open', label: 'Open' },
  { value: 'partial', label: 'Partially paid' }, { value: 'paid', label: 'Paid' }, { value: 'void', label: 'Void' },
];
const isUnpaid = (r) => r.status === 'open' || r.status === 'partial';
const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + n); return toISO(d); };

export default function PartyDocs({ cfg }) {
  const [tab, setTab] = useState('docs');
  return (
    <div className="stack">
      <PageHeader title={cfg.title} subtitle={cfg.subtitle} />
      <Tabs tabs={[{ value: 'docs', label: cfg.docs[0].toUpperCase() + cfg.docs.slice(1) }, { value: 'aging', label: 'Aging' }]} value={tab} onChange={setTab} />
      {tab === 'docs' ? <DocList cfg={cfg} /> : <Aging cfg={cfg} />}
    </div>
  );
}

function DocList({ cfg }) {
  const [range, setRange] = useDateRange();
  const [status, setStatus] = useState('');
  const [partyId, setPartyId] = useState('');
  const [openId, setOpenId] = useState(null);
  const [creating, setCreating] = useState(false);
  const parties = useApi(() => api.get(cfg.partyUrl), [cfg.partyUrl]);
  const docs = useApi(() => api.get(cfg.list, { ...range, status, [cfg.partyId]: partyId }), [range.from, range.to, status, partyId]);
  const unpaid = useApi(() => api.get(cfg.list, { status: 'unpaid' }), []);
  const reload = () => { docs.reload(); unpaid.reload(); parties.reload(); };

  const t = today();
  const in7 = addDays(t, 7);
  const stats = useMemo(() => {
    const s = { total: 0, n: 0, overdue: 0, no: 0, soon: 0, ns: 0 };
    for (const r of unpaid.data || []) {
      s.total += r.balance; s.n += 1;
      const due = r.due_date || r[cfg.date];
      if (due < t) { s.overdue += r.balance; s.no += 1; } else if (due <= in7) { s.soon += r.balance; s.ns += 1; }
    }
    return s;
  }, [unpaid.data, t, in7, cfg.date]);

  const columns = [
    { key: cfg.no, label: `${cfg.doc} no`, render: (r) => <span className="bold nowrap">{r[cfg.no]}</span> },
    { key: cfg.date, label: 'Date', type: 'date' },
    { key: 'due_date', label: 'Due date', type: 'date', render: (r) => {
      const overdue = isUnpaid(r) && r.due_date && r.due_date < t;
      return <span className={overdue ? 'text-red bold' : ''}>{fmtDate(r.due_date)}{overdue ? ' (overdue)' : ''}</span>;
    } },
    { key: cfg.partyName, label: cfg.party },
    { key: 'ref_no', label: 'Ref no' },
    { key: 'description', label: 'Description', render: (r) => r.description || (r.source_type === cfg.autoSource ? <span className="muted">{cfg.doc === 'Bill' ? 'From delivery' : 'From POS charge'}</span> : '') },
    { key: 'amount', label: 'Amount', type: 'money', total: sumPosted('amount') },
    { key: 'paid_amount', label: cfg.paidLabel, type: 'money', total: sumPosted('paid_amount') },
    { key: 'balance', label: 'Balance', type: 'money', total: sumPosted('balance') },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
  ];

  return (
    <>
      <div className="stats">
        <Stat label={`Total unpaid ${cfg.docs}`} value={peso(stats.total)} sub={`${stats.n} ${cfg.docs} · all dates`} tone="brand" />
        <Stat label="Overdue" value={peso(stats.overdue)} sub={`${stats.no} past due date`} tone={stats.overdue > 0 ? 'red' : undefined} />
        <Stat label="Due in the next 7 days" value={peso(stats.soon)} sub={`${stats.ns} ${cfg.docs}`} tone={stats.soon > 0 ? 'amber' : undefined} />
      </div>
      <div className="row wrap gap between">
        <div className="row wrap gap">
          <DateRange value={range} onChange={setRange} />
          <div style={{ width: 200 }}><Select value={status} onChange={setStatus} options={STATUS} /></div>
          <div style={{ width: 220 }}>
            <Select value={partyId} onChange={setPartyId} placeholder={`All ${cfg.party.toLowerCase()}s`} options={(parties.data || []).map((p) => ({ value: String(p.id), label: p.name }))} />
          </div>
        </div>
        <Button variant="primary" onClick={() => setCreating(true)}>+ New {cfg.doc.toLowerCase()}</Button>
      </div>
      <ErrorBox error={docs.error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={docs.data} loading={docs.loading} onRowClick={(r) => setOpenId(r.id)} rowClass={voidRow}
          exportName={cfg.docs === 'bills' ? 'payables' : 'receivables'} exportTitle={`${cfg.title} — ${cfg.docs}`} exportSubtitle={rangeLabel(range)}
          emptyText={`No ${cfg.docs} in this period.`} />
      </Card>
      {creating && <NewDocModal cfg={cfg} parties={parties.data || []} onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); reload(); setOpenId(id); }} />}
      {openId && <DocModal cfg={cfg} id={openId} onClose={() => setOpenId(null)} onChanged={reload} />}
    </>
  );
}

function NewDocModal({ cfg, parties, onClose, onSaved }) {
  const toast = useToast();
  const accounts = useApi(() => api.get('/finance/accounts'), []);
  const [f, set, setF] = useForm({ [cfg.partyId]: '', [cfg.date]: today(), due_date: '', ref_no: '', description: '', [cfg.account]: '', amount: '' });
  const [busy, setBusy] = useState(false);
  const party = parties.find((p) => String(p.id) === String(f[cfg.partyId]));
  const defaultAcct = cfg.defaultKey && (accounts.data || []).find((a) => a.system_key === cfg.defaultKey);
  const acctValue = f[cfg.account] || (defaultAcct ? String(defaultAcct.id) : '');

  const save = async () => {
    if (!party) return toast(`Select the ${cfg.party.toLowerCase()}`, 'error');
    if (!acctValue) return toast(`Select the ${cfg.accountLabel.toLowerCase()}`, 'error');
    if (!(Number(f.amount) > 0)) return toast('Enter the amount', 'error');
    setBusy(true);
    try {
      const r = await api.post(cfg.list, { ...f, [cfg.account]: Number(acctValue), due_date: f.due_date || undefined });
      toast(`${cfg.doc} recorded`);
      onSaved(r.id);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };

  const terms = party ? Number(party.terms_days) || 0 : null;
  return (
    <Modal title={`New ${cfg.doc.toLowerCase()}`} onClose={onClose} width={640}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save {cfg.doc.toLowerCase()}</Button></>}>
      {!parties.length && <div className="alert alert-warn">No {cfg.party.toLowerCase()}s yet — add one under Suppliers & Customers.</div>}
      <div className="form-grid">
        <Field label={cfg.party} span={2}>
          <Select value={f[cfg.partyId]} onChange={set(cfg.partyId)} placeholder={`Select ${cfg.party.toLowerCase()}…`}
            options={parties.map((p) => ({ value: String(p.id), label: `${p.name}${p.balance ? ` — balance ${peso(p.balance)}` : ''}` }))} />
        </Field>
        <Field label={`${cfg.doc} date`}><Input type="date" value={f[cfg.date]} onChange={set(cfg.date)} /></Field>
        <Field label="Due date" hint={party ? `Blank = ${terms ? `${terms}-day terms (${fmtDate(addDays(f[cfg.date] || today(), terms))})` : 'due on the same day (no terms)'}` : 'Blank = based on terms'}>
          <Input type="date" value={f.due_date} onChange={set('due_date')} min={f[cfg.date]} />
        </Field>
        <Field label={cfg.refLabel}><Input value={f.ref_no} onChange={set('ref_no')} /></Field>
        <Field label="Amount (₱)"><NumberInput value={f.amount} onChange={set('amount')} min="0" /></Field>
        <Field label={cfg.accountLabel} span={2}>
          <AccountPicker accounts={accounts.data} value={acctValue} onChange={(v) => setF((s) => ({ ...s, [cfg.account]: v }))} types={cfg.accountTypes} suggest={cfg.suggest} />
        </Field>
        <Field label="Description" span={2}><Input value={f.description} onChange={set('description')} /></Field>
      </div>
      {party && cfg.doc === 'Invoice' && party.credit_limit > 0 && party.balance + (Number(f.amount) || 0) > party.credit_limit && (
        <div className="alert alert-warn mt">This exceeds the customer's credit limit of {peso(party.credit_limit)} (current balance {peso(party.balance)}).</div>
      )}
    </Modal>
  );
}

function DocModal({ cfg, id, onClose, onChanged }) {
  const toast = useToast();
  const dialog = useDialog();
  const detailUrl = `${cfg.list}/${id}`;
  const { data: d, loading, error, reload } = useApi(() => api.get(detailUrl), [detailUrl]);
  const banks = useApi(() => api.get('/finance/banks'), []);
  const [f, set, setF] = useForm({ date: today(), amount: '', method: 'cash', bank_account_id: '', reference: '' });
  const [busy, setBusy] = useState(false);
  const balance = d ? Math.round((d.amount - d.paid_amount) * 100) / 100 : 0;
  const refresh = () => { reload().then((x) => x && setF((s) => ({ ...s, amount: '' }))); onChanged(); };

  const pay = async () => {
    const amount = Number(f.amount || balance);
    if (!(amount > 0)) return toast('Enter the amount', 'error');
    if (f.method === 'bank' && !f.bank_account_id) return toast('Select the bank account', 'error');
    setBusy(true);
    try {
      await api.post(cfg.pay(id), { amount, method: f.method, bank_account_id: f.method === 'bank' ? f.bank_account_id : null, reference: f.reference, [cfg.payDate]: f.date });
      toast(cfg.payDone);
      setF((s) => ({ ...s, reference: '' }));
      refresh();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  const voidPayment = (p) => confirmVoid(dialog, toast, {
    title: `Void ${p.doc_no}`, message: `Void this ${cfg.payVerb === 'Pay' ? 'payment' : 'collection'} of ${peso(p.amount)}? The ${cfg.doc.toLowerCase()} balance goes back up.`,
    run: (reason) => api.post(cfg.voidPayment(p.id), { reason }),
  }).then((ok) => ok && refresh());
  const voidDoc = () => confirmVoid(dialog, toast, {
    title: `Void ${d[cfg.no]}`, message: `Void this ${cfg.doc.toLowerCase()} of ${peso(d.amount)}? Its journal entry will be reversed.`,
    run: (reason) => api.post(`${cfg.list}/${id}/void`, { reason }),
  }).then((ok) => ok && refresh());

  const payments = d ? d[cfg.payments] || [] : [];
  const payCols = [
    { key: 'doc_no', label: 'Doc no' },
    { key: cfg.payDate, label: 'Date', type: 'date' },
    { key: 'method', label: 'Method', render: (p) => `${METHOD_LABELS[p.method] || p.method}${p.bank_name ? ' · ' + p.bank_name : ''}` },
    { key: 'reference', label: 'Reference' },
    { key: 'amount', label: 'Amount', type: 'money', total: sumPosted('amount') },
    { key: 'status', label: 'Status', render: (p) => <Badge>{p.status}</Badge> },
    { key: '_act', label: '', noExport: true, sortable: false, render: (p) => p.status === 'posted' && <Button size="sm" variant="ghost" onClick={() => voidPayment(p)}>Void</Button> },
  ];
  const overdue = d && isUnpaid(d) && d.due_date && d.due_date < today();

  return (
    <Modal title={d ? `${cfg.doc} ${d[cfg.no]}` : cfg.doc} onClose={onClose} width={820}
      footer={<>
        {d && d.status !== 'void' && !(d.paid_amount > 0) && <Button variant="danger" onClick={voidDoc} style={{ marginRight: 'auto' }}>Void {cfg.doc.toLowerCase()}</Button>}
        <Button onClick={onClose}>Close</Button>
      </>}>
      <ErrorBox error={error} />
      {loading && !d ? <Loading /> : d && (
        <>
          <InfoGrid items={[
            [cfg.party, <span className="bold">{d[cfg.partyName]}</span>],
            ['Status', <Badge>{d.status}</Badge>],
            [`${cfg.doc} date`, fmtDate(d[cfg.date])],
            ['Due date', <span className={overdue ? 'text-red bold' : ''}>{fmtDate(d.due_date)}{overdue ? ' (overdue)' : ''}</span>],
            ['Ref no', d.ref_no],
            ['Description', d.description],
            ['Amount', peso(d.amount)],
            [cfg.paidLabel, peso(d.paid_amount)],
            ['Balance', <span className="bold">{peso(balance)}</span>],
          ]} />
          {d.source_type === cfg.autoSource && <div className="alert alert-info">{cfg.autoNote}</div>}
          {isUnpaid(d) && (
            <Card title={cfg.payVerb === 'Pay' ? 'Record payment' : 'Record collection'} className="mb">
              <div className="form-grid">
                <Field label="Date"><Input type="date" value={f.date} onChange={set('date')} /></Field>
                <Field label="Amount (₱)" hint={`Balance ${peso(balance)}`}><NumberInput value={f.amount} placeholder={String(balance)} onChange={set('amount')} min="0" max={balance} /></Field>
                <PayMethodFields label={cfg.methodLabel} methods={cfg.methods} method={f.method} onMethod={set('method')}
                  bankId={f.bank_account_id} onBank={set('bank_account_id')} banks={banks.data} />
                <Field label={cfg.payVerb === 'Pay' ? 'Reference / check no' : 'OR / reference no'}><Input value={f.reference} onChange={set('reference')} /></Field>
              </div>
              <div className="row mt" style={{ justifyContent: 'flex-end' }}>
                <Button variant="primary" loading={busy} onClick={pay}>{cfg.payVerb} {peso(Number(f.amount) || balance)}</Button>
              </div>
            </Card>
          )}
          <h3 className="mb">{cfg.payVerb === 'Pay' ? 'Payments' : 'Collections'}</h3>
          <div className="card">
            <DataTable columns={payCols} rows={payments} searchable={false} dense rowClass={voidRow}
              emptyText={`No ${cfg.payVerb === 'Pay' ? 'payments' : 'collections'} yet.`} />
          </div>
        </>
      )}
    </Modal>
  );
}

function Aging({ cfg }) {
  const [range, setRange] = useDateRange();
  const { data, loading, error } = useApi(() => api.get(cfg.aging, { to: range.to }), [range.to]);
  const columns = [
    { key: 'party', label: cfg.party },
    { key: 'current', label: 'Current', type: 'money', total: true },
    { key: 'd1_30', label: '1–30 days', type: 'money', total: true },
    { key: 'd31_60', label: '31–60 days', type: 'money', total: true },
    { key: 'd61_90', label: '61–90 days', type: 'money', total: true },
    { key: 'over_90', label: 'Over 90 days', type: 'money', total: true, render: (r) => <span className={r.over_90 > 0 ? 'text-red' : ''}>{money(r.over_90)}</span> },
    { key: 'total', label: 'Total', type: 'money', total: true, render: (r) => <span className="bold">{money(r.total)}</span> },
  ];
  return (
    <>
      <div className="row wrap gap">
        <DateRange single value={range} onChange={setRange} />
        <span className="muted small">Unpaid balances grouped by days past the due date.</span>
      </div>
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} exportName={cfg.docs === 'bills' ? 'ap-aging' : 'ar-aging'}
          exportTitle={`${cfg.title} Aging`} exportSubtitle={`As of ${fmtDate(range.to)}`} emptyText={`No unpaid ${cfg.docs} as of this date.`} />
      </Card>
    </>
  );
}
