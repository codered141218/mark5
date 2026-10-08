import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { fmtDate, fmtDateTime, peso, today } from '../../format';
import {
  Badge, Button, Card, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Select, Stat, Tabs, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { InfoGrid, METHOD_LABELS, PayMethodFields, confirmVoid, sumPosted, useForm, voidRow } from './shared';

const STATUS_TABS = [
  { value: '', label: 'All' }, { value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved (outstanding)' },
  { value: 'settled', label: 'Settled' }, { value: 'rejected', label: 'Rejected' }, { value: 'cancelled', label: 'Cancelled' },
];

export default function CashAdvances() {
  const { can } = useAuth();
  const [range, setRange] = useDateRange('year');
  const [status, setStatus] = useState('');
  const [empId, setEmpId] = useState('');
  const [openId, setOpenId] = useState(null);
  const [requesting, setRequesting] = useState(false);
  const employees = useApi(() => api.get('/finance/employees'), []);
  const list = useApi(() => api.get('/finance/cash-advances', { ...range, employee_id: empId }), [range.from, range.to, empId]);
  const pending = useApi(() => api.get('/finance/cash-advances', { status: 'pending' }), []);
  const outstanding = useApi(() => api.get('/finance/cash-advances', { status: 'approved' }), []);
  const reload = () => { list.reload(); pending.reload(); outstanding.reload(); employees.reload(); };

  const rows = useMemo(() => (list.data || []).filter((r) => !status || r.status === status), [list.data, status]);
  const counts = useMemo(() => {
    const c = {};
    for (const r of list.data || []) c[r.status] = (c[r.status] || 0) + 1;
    return c;
  }, [list.data]);
  const sum = (d, k) => (d || []).reduce((s, r) => s + (Number(r[k]) || 0), 0);
  const seeAll = can('ca.approve', 'ca.manage');

  const columns = [
    { key: 'doc_no', label: 'Doc no', render: (r) => <span className="bold nowrap">{r.doc_no}</span> },
    { key: 'request_date', label: 'Request date', type: 'date' },
    { key: 'employee_name', label: 'Employee' },
    { key: 'amount', label: 'Amount', type: 'money', total: (rs) => rs.reduce((s, r) => s + (['rejected', 'cancelled'].includes(r.status) ? 0 : r.amount), 0) },
    { key: 'reason', label: 'Reason' },
    { key: 'repayment_terms', label: 'Repayment terms' },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    { key: 'balance', label: 'Balance', type: 'money', total: true, render: (r) => (r.status === 'approved' ? <span className="bold">{peso(r.balance)}</span> : r.balance ? peso(r.balance) : '') },
    { key: 'requested_by_name', label: 'Requested by' },
    { key: 'approved_by_name', label: 'Approved by', render: (r) => (['approved', 'settled', 'rejected'].includes(r.status) ? r.approved_by_name : '') },
  ];

  return (
    <div className="stack">
      <PageHeader
        title="Cash Advances"
        subtitle={seeAll
          ? 'Employee cash advance (vale) requests, approvals, releases and repayments. Approvals and repayments are posted to the general ledger (Advances to Employees).'
          : 'File a cash advance (vale) request and follow its approval and repayment.'}
        actions={can('ca.request') && <Button variant="primary" onClick={() => setRequesting(true)}>+ Request cash advance</Button>}
      />
      <div className="stats">
        <Stat label="Pending requests" value={pending.data ? pending.data.length : '…'} sub={`${peso(sum(pending.data, 'amount'))} requested · all dates`} tone={pending.data?.length ? 'amber' : undefined} />
        <Stat label="Outstanding balance" value={peso(sum(outstanding.data, 'balance'))} sub={`${outstanding.data?.length || 0} approved advance(s) not yet fully repaid`} tone="brand" />
        <Stat label="Released in period" value={peso(sum((list.data || []).filter((r) => ['approved', 'settled'].includes(r.status)), 'amount'))} sub={rangeLabel(range)} />
      </div>
      <div className="row wrap gap">
        <DateRange value={range} onChange={setRange} />
        {seeAll && (
          <div style={{ width: 240 }}>
            <Select value={empId} onChange={setEmpId} placeholder="All employees" options={(employees.data || []).map((e) => ({ value: String(e.id), label: e.full_name }))} />
          </div>
        )}
      </div>
      <ErrorBox error={list.error} />
      <Card pad={false}>
        <div style={{ padding: '0 12px' }}>
          <Tabs tabs={STATUS_TABS.map((t) => ({ ...t, label: `${t.label}${t.value ? ` (${counts[t.value] || 0})` : ` (${list.data?.length || 0})`}` }))} value={status} onChange={setStatus} />
        </div>
        <DataTable columns={columns} rows={rows} loading={list.loading} onRowClick={(r) => setOpenId(r.id)}
          rowClass={(r) => (['rejected', 'cancelled'].includes(r.status) ? 'muted-row' : '')}
          exportName="cash-advances" exportTitle="Cash Advances" exportSubtitle={`${rangeLabel(range)}${status ? ' · ' + status : ''}`}
          emptyText="No cash advances in this period." />
      </Card>
      {requesting && <RequestModal employees={employees.data} loading={employees.loading} onClose={() => setRequesting(false)}
        onSaved={(ca) => { setRequesting(false); reload(); setOpenId(ca.id); }} />}
      {openId && <AdvanceModal id={openId} onClose={() => setOpenId(null)} onChanged={reload} />}
    </div>
  );
}

function RequestModal({ employees, loading, onClose, onSaved }) {
  const toast = useToast();
  const emps = employees || [];
  const [f, set] = useForm({ employee_id: emps.length === 1 ? String(emps[0].id) : '', amount: '', reason: '', repayment_terms: '', request_date: today() });
  const [busy, setBusy] = useState(false);
  const emp = emps.find((e) => String(e.id) === String(f.employee_id));
  const save = async () => {
    if (!f.employee_id) return toast('Select the employee', 'error');
    if (!(Number(f.amount) > 0)) return toast('Enter the amount', 'error');
    setBusy(true);
    try {
      const ca = await api.post('/finance/cash-advances', f);
      toast(`Request ${ca.doc_no} filed — waiting for approval`);
      onSaved(ca);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title="Request cash advance" onClose={onClose} width={560}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} disabled={!emps.length} onClick={save}>Submit request</Button></>}>
      {loading && !employees ? <Loading /> : !emps.length ? (
        <div className="alert alert-warn">
          Your user account is not linked to an employee record, so you cannot file a cash advance yet.
          Ask the administrator to link your user to your employee record (Users → Employee).
        </div>
      ) : (
        <div className="form-grid">
          <Field label="Employee" span={2}>
            <Select value={f.employee_id} onChange={set('employee_id')} placeholder="Select employee…" disabled={emps.length === 1}
              options={emps.map((e) => ({ value: String(e.id), label: `${e.full_name}${e.position ? ' – ' + e.position : ''}` }))} />
          </Field>
          {emp && emp.ca_balance > 0 && (
            <div className="alert alert-warn" style={{ gridColumn: '1 / -1', marginBottom: 0 }}>
              {emp.full_name} still has an outstanding cash advance balance of {peso(emp.ca_balance)}.
            </div>
          )}
          <Field label="Amount (₱)"><NumberInput value={f.amount} onChange={set('amount')} min="0" /></Field>
          <Field label="Request date"><Input type="date" value={f.request_date} onChange={set('request_date')} /></Field>
          <Field label="Reason" span={2}><Textarea value={f.reason} onChange={set('reason')} rows={2} placeholder="e.g. Tuition fee, medical, emergency" /></Field>
          <Field label="Repayment terms" span={2}><Input value={f.repayment_terms} onChange={set('repayment_terms')} placeholder="e.g. ₱500 per payroll" /></Field>
        </div>
      )}
    </Modal>
  );
}

function AdvanceModal({ id, onClose, onChanged }) {
  const { can, user } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const { data: c, loading, error, reload, setData } = useApi(() => api.get(`/finance/cash-advances/${id}`), [id]);
  const needBanks = can('ca.approve', 'ca.manage');
  const banks = useApi(() => (needBanks ? api.get('/finance/banks') : []), [needBanks]);
  const [ap, setAp] = useForm({ amount: '', release_date: today(), release_method: 'cash', bank_account_id: '', remarks: '' });
  const [rp, setRp, setRpF] = useForm({ pay_date: today(), amount: '', method: 'payroll', bank_account_id: '', reference: '' });
  const [busy, setBusy] = useState('');

  const act = async (key, fn, msg) => {
    setBusy(key);
    try {
      const r = await fn();
      if (r && r.id) setData(r); else reload();
      toast(msg);
      onChanged();
      return true;
    } catch (e) { toast(e.message, 'error'); return false; } finally { setBusy(''); }
  };

  const approve = async () => {
    const amount = Number(ap.amount || c.amount);
    if (!(amount > 0)) return toast('Enter the approved amount', 'error');
    if (ap.release_method === 'bank' && !ap.bank_account_id) return toast('Select the bank account', 'error');
    if (!(await dialog({ title: `Approve ${c.doc_no}`, message: `Approve and release ${peso(amount)} to ${c.employee_name} from ${METHOD_LABELS[ap.release_method].toLowerCase()}?`, okText: 'Approve & release' }))) return;
    act('approve', () => api.post(`/finance/cash-advances/${id}/approve`, { ...ap, amount, bank_account_id: ap.release_method === 'bank' ? ap.bank_account_id : null }), 'Cash advance approved and released');
  };
  const reject = async () => {
    const r = await dialog({ title: `Reject ${c.doc_no}`, message: 'The employee will see this request as rejected.', input: 'Remarks', danger: true, okText: 'Reject', defaultValue: ap.remarks });
    if (!r) return;
    act('reject', () => api.post(`/finance/cash-advances/${id}/reject`, { remarks: r.value }), 'Request rejected');
  };
  const cancel = async () => {
    if (!(await dialog({ title: `Cancel ${c.doc_no}`, message: 'Cancel this cash advance request?', danger: true, okText: 'Cancel request' }))) return;
    act('cancel', () => api.post(`/finance/cash-advances/${id}/cancel`), 'Request cancelled');
  };
  const repay = async () => {
    const amount = Number(rp.amount);
    if (!(amount > 0)) return toast('Enter the repayment amount', 'error');
    if (rp.method === 'bank' && !rp.bank_account_id) return toast('Select the bank account', 'error');
    const ok = await act('repay', () => api.post(`/finance/cash-advances/${id}/repay`, { ...rp, amount, bank_account_id: rp.method === 'bank' ? rp.bank_account_id : null }), 'Repayment recorded');
    if (ok) setRpF((s) => ({ ...s, amount: '', reference: '' }));
  };
  const voidRepayment = (r) => confirmVoid(dialog, toast, {
    title: `Void ${r.doc_no}`, message: `Void this repayment of ${peso(r.amount)}? The advance balance goes back up.`,
    run: (reason) => api.post(`/finance/ca-repayments/${r.id}/void`, { reason }),
  }).then((ok) => { if (ok) { reload(); onChanged(); } });

  const repayCols = [
    { key: 'doc_no', label: 'Doc no' },
    { key: 'pay_date', label: 'Date', type: 'date' },
    { key: 'method', label: 'Method', render: (r) => METHOD_LABELS[r.method] || r.method },
    { key: 'reference', label: 'Reference' },
    { key: 'amount', label: 'Amount', type: 'money', total: sumPosted('amount') },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    { key: 'created_by_name', label: 'By' },
    ...(can('ca.manage') ? [{ key: '_act', label: '', noExport: true, sortable: false, render: (r) => r.status === 'posted' && <Button size="sm" variant="ghost" onClick={() => voidRepayment(r)}>Void</Button> }] : []),
  ];

  const canCancel = c && c.status === 'pending' && (c.requested_by === user.id || can('ca.approve')) && can('ca.request');
  return (
    <Modal title={c ? `Cash advance ${c.doc_no}` : 'Cash advance'} onClose={onClose} width={820}
      footer={<>
        {canCancel && <Button variant="ghost" loading={busy === 'cancel'} onClick={cancel} style={{ marginRight: 'auto' }}>Cancel request</Button>}
        <Button onClick={onClose}>Close</Button>
      </>}>
      <ErrorBox error={error} />
      {loading && !c ? <Loading /> : c && (
        <>
          <InfoGrid items={[
            ['Employee', <span className="bold">{c.employee_name}{c.emp_no ? <span className="muted"> · {c.emp_no}</span> : null}</span>],
            ['Status', <Badge>{c.status}</Badge>],
            ['Request date', fmtDate(c.request_date)],
            [c.status === 'pending' ? 'Requested amount' : 'Amount', peso(c.amount)],
            ['Balance', ['approved', 'settled'].includes(c.status) ? <span className="bold">{peso(c.balance)}</span> : null],
            ['Repayment terms', c.repayment_terms],
            ['Reason', c.reason],
            ['Requested by', c.requested_by_name],
            c.approved_by && [c.status === 'rejected' ? 'Rejected by' : 'Approved by', `${c.approved_by_name || ''}${c.approved_at ? ' · ' + fmtDateTime(c.approved_at) : ''}`],
            c.release_date && ['Released', `${fmtDate(c.release_date)} · ${METHOD_LABELS[c.release_method] || c.release_method || ''}${c.bank_name ? ' · ' + c.bank_name : ''}`],
            c.remarks && ['Remarks', c.remarks],
          ]} />

          {c.status === 'pending' && can('ca.approve') && (
            <Card title="Approve & release" className="mb">
              <div className="form-grid">
                <Field label="Approved amount (₱)" hint={`Requested ${peso(c.amount)}`}>
                  <NumberInput value={ap.amount} placeholder={String(c.amount)} onChange={setAp('amount')} min="0" />
                </Field>
                <Field label="Release date"><Input type="date" value={ap.release_date} onChange={setAp('release_date')} /></Field>
                <PayMethodFields label="Released from" methods={['cash', 'petty_cash', 'bank']} method={ap.release_method}
                  onMethod={setAp('release_method')} bankId={ap.bank_account_id} onBank={setAp('bank_account_id')} banks={banks.data} />
                <Field label="Remarks" span={2}><Input value={ap.remarks} onChange={setAp('remarks')} /></Field>
              </div>
              <div className="alert alert-info mt">
                Approval posts to the GL: <b>Dr Advances to Employees / Cr {ap.release_method === 'bank' ? 'Cash in Bank' : ap.release_method === 'petty_cash' ? 'Petty Cash Fund' : 'Cash on Hand'}</b>.
              </div>
              <div className="row gap-sm" style={{ justifyContent: 'flex-end' }}>
                <Button variant="danger" loading={busy === 'reject'} onClick={reject}>Reject</Button>
                <Button variant="success" loading={busy === 'approve'} onClick={approve}>Approve & release {peso(Number(ap.amount) || c.amount)}</Button>
              </div>
            </Card>
          )}

          {c.status === 'approved' && can('ca.manage') && (
            <Card title="Record repayment" className="mb">
              <div className="form-grid">
                <Field label="Date"><Input type="date" value={rp.pay_date} onChange={setRp('pay_date')} /></Field>
                <Field label="Amount (₱)" hint={`Balance ${peso(c.balance)}`}><NumberInput value={rp.amount} onChange={setRp('amount')} min="0" max={c.balance} /></Field>
                <PayMethodFields label="Repaid via" methods={['payroll', 'cash', 'bank']} method={rp.method}
                  onMethod={setRp('method')} bankId={rp.bank_account_id} onBank={setRp('bank_account_id')} banks={banks.data} />
                <Field label="Reference" hint={rp.method === 'payroll' ? 'e.g. Payroll Oct 1–15' : 'OR / slip no'}><Input value={rp.reference} onChange={setRp('reference')} /></Field>
              </div>
              <div className="row gap-sm mt" style={{ justifyContent: 'flex-end' }}>
                <Button size="sm" onClick={() => setRpF((s) => ({ ...s, amount: String(c.balance) }))}>Full balance</Button>
                <Button variant="primary" loading={busy === 'repay'} onClick={repay}>Record repayment</Button>
              </div>
            </Card>
          )}

          {(c.repayments.length > 0 || ['approved', 'settled'].includes(c.status)) && (
            <>
              <h3 className="mb">Repayments</h3>
              <div className="card">
                <DataTable columns={repayCols} rows={c.repayments} searchable={false} dense rowClass={voidRow} emptyText="No repayments yet." />
              </div>
            </>
          )}
        </>
      )}
    </Modal>
  );
}
