import React, { useState } from 'react';
import { api } from '../../api';
import { peso, money, today, fmtTime } from '../../format';
import { Modal, Button, Input, NumberInput, Field, Select, useToast, useApi, useDialog, Badge, Loading, Tabs } from '../../components/ui';
import { Receipt, ReadingReport } from './Print';

const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

function DenominationCounter({ denominations, counts, setCounts }) {
  const total = r2(denominations.reduce((s, d) => s + d * (Number(counts[d]) || 0), 0));
  return (
    <>
      <div className="denoms">
        {denominations.map((d) => (
          <div key={d} className="row">
            <span style={{ width: 70 }}><b>{d >= 1 ? '₱' + d.toLocaleString() : `${Math.round(d * 100)}¢`}</b></span>
            <span className="muted">×</span>
            <NumberInput value={counts[d] ?? ''} onChange={(v) => setCounts({ ...counts, [d]: v })} placeholder="0" />
            <span className="right grow mono">{money(d * (Number(counts[d]) || 0))}</span>
          </div>
        ))}
      </div>
      <div className="row between mt" style={{ fontSize: 20, fontWeight: 800 }}><span>Total counted</span><span>{peso(total)}</span></div>
    </>
  );
}
const countTotal = (denoms, counts) => r2(denoms.reduce((s, d) => s + d * (Number(counts[d]) || 0), 0));

// ------------------------------------------------------------------ open day
export function OpenDayModal({ denominations, onClose, onDone }) {
  const toast = useToast();
  const [mode, setMode] = useState('count');
  const [counts, setCounts] = useState({});
  const [amount, setAmount] = useState('');
  const [bdate, setBdate] = useState(today());
  const [busy, setBusy] = useState(false);
  const opening = mode === 'count' ? countTotal(denominations, counts) : r2(amount);
  const submit = async () => {
    setBusy(true);
    try {
      const s = await api.post('/pos/sessions/open', { opening_cash: opening, business_date: bdate, denominations: mode === 'count' ? counts : undefined });
      toast('Business day opened');
      onDone(s);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title="Open business day — beginning cash" onClose={onClose} width={560}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="success" size="lg" loading={busy} onClick={submit}>Open day with {peso(opening)}</Button></>}>
      <Field label="Business date" hint="Sales after midnight still count to this date until the day is closed.">
        <Input type="date" value={bdate} onChange={(e) => setBdate(e.target.value)} />
      </Field>
      <div className="mt">
        <Tabs value={mode} onChange={setMode} tabs={[{ value: 'count', label: 'Count by denomination' }, { value: 'amount', label: 'Enter total' }]} />
        {mode === 'count'
          ? <DenominationCounter denominations={denominations} counts={counts} setCounts={setCounts} />
          : <Field label="Beginning cash / change fund"><NumberInput value={amount} onChange={setAmount} autoFocus /></Field>}
      </div>
    </Modal>
  );
}

// ------------------------------------------------------------------ X-reading
export function XReadingModal({ business, tax, onClose, onPrint }) {
  const rep = useApi(() => api.get('/pos/sessions/current/xreading'), []);
  return (
    <Modal title="X-Reading (current shift)" onClose={onClose} width={420}
      footer={<><Button onClick={onClose}>Close</Button><Button variant="primary" disabled={!rep.data} onClick={() => onPrint(<ReadingReport report={rep.data} business={business} tax={tax} />)}>Print</Button></>}>
      {rep.loading && <Loading />}
      {rep.error && <div className="alert alert-error">{rep.error.message}</div>}
      {rep.data && <ReadingReport report={rep.data} business={business} tax={tax} />}
    </Modal>
  );
}

// ------------------------------------------------------------------ end of day (blind cash count)
export function CloseDayModal({ denominations, business, tax, onClose, onClosed, onPrint }) {
  const toast = useToast();
  const dialog = useDialog();
  const [counts, setCounts] = useState({});
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const counted = countTotal(denominations, counts);

  const submit = async () => {
    const ok = await dialog({ title: 'Close the business day?', message: `Counted cash: ${peso(counted)}. After closing, no more sales can be recorded for this day and the Z-reading is generated.`, okText: 'Close day' });
    if (!ok) return;
    setBusy(true);
    try {
      const r = await api.post('/pos/sessions/current/close', { denominations: counts, notes });
      setResult(r);
      toast('Day closed');
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };

  if (result) {
    const v = result.cash.variance;
    return (
      <Modal title="Z-Reading — day closed" onClose={() => onClosed(result)} width={460}
        footer={<><Button onClick={() => onClosed(result)}>Done</Button><Button variant="primary" onClick={() => onPrint(<ReadingReport report={result} business={business} tax={tax} z />)}>Print Z-Reading</Button></>}>
        <div className={`alert ${v === 0 ? 'alert-success' : v < 0 ? 'alert-error' : 'alert-warn'}`}>
          Expected {peso(result.cash.expected)} · Counted {peso(result.cash.counted)} · {v === 0 ? 'Balanced' : v < 0 ? `SHORT ${peso(-v)}` : `OVER ${peso(v)}`}
        </div>
        <ReadingReport report={result} business={business} tax={tax} z />
      </Modal>
    );
  }
  return (
    <Modal title="End of day — cash count" onClose={onClose} width={600}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="danger" size="lg" loading={busy} onClick={submit}>Close day</Button></>}>
      <div className="alert alert-info small">Count all cash in the drawer, including the beginning cash. The expected amount is revealed after you submit (blind count) — any shortage or overage is posted to the books automatically.</div>
      <DenominationCounter denominations={denominations} counts={counts} setCounts={setCounts} />
      <Field label="Remarks" className="mt"><Input value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
    </Modal>
  );
}

// ------------------------------------------------------------------ drawer payouts (petty cash at POS)
export function PettyCashModal({ onClose }) {
  const toast = useToast();
  const dialog = useDialog();
  const accounts = useApi(() => api.get('/finance/accounts'), []);
  const list = useApi(() => api.get('/finance/petty-cash', { from: today(), to: today() }), []);
  const [f, setF] = useState({ source: 'drawer', account_id: '', payee: '', description: '', or_no: '', amount: '' });
  const [busy, setBusy] = useState(false);
  const expenseAccts = (accounts.data || []).filter((a) => a.type === 'expense' && a.active);
  const submit = async () => {
    setBusy(true);
    try {
      await api.post('/finance/petty-cash', { ...f, txn_type: 'expense', amount: Number(f.amount) });
      toast('Payout recorded');
      setF({ ...f, payee: '', description: '', or_no: '', amount: '' });
      list.reload();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  const voidTxn = async (p) => {
    const r = await dialog({ title: `Void ${p.doc_no}?`, input: 'Reason', required: true, pin: true, danger: true, okText: 'Void' });
    if (!r) return;
    try { await api.post(`/finance/petty-cash/${p.id}/void`, { reason: r.value, pin: r.pin }); toast('Voided'); list.reload(); } catch (e) { toast(e.message, 'error'); }
  };
  return (
    <Modal title="Petty cash / cash payout" onClose={onClose} width={720}>
      <div className="form-grid">
        <Field label="Paid from">
          <Select value={f.source} onChange={(v) => setF({ ...f, source: v })} options={[{ value: 'drawer', label: 'Cash drawer (reduces expected cash)' }, { value: 'fund', label: 'Petty cash fund / box' }]} />
        </Field>
        <Field label="Expense type">
          <Select value={f.account_id} onChange={(v) => setF({ ...f, account_id: v })} placeholder="Select…" options={expenseAccts.map((a) => ({ value: a.id, label: a.name }))} />
        </Field>
        <Field label="Paid to"><Input value={f.payee} onChange={(e) => setF({ ...f, payee: e.target.value })} placeholder="e.g. Petron, palengke" /></Field>
        <Field label="Description"><Input value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="e.g. LPG refill, ice, tricycle fare" /></Field>
        <Field label="OR / receipt no."><Input value={f.or_no} onChange={(e) => setF({ ...f, or_no: e.target.value })} /></Field>
        <Field label="Amount"><NumberInput value={f.amount} onChange={(v) => setF({ ...f, amount: v })} /></Field>
      </div>
      <div className="right mt"><Button variant="primary" loading={busy} disabled={!f.account_id || !(Number(f.amount) > 0)} onClick={submit}>Record payout</Button></div>
      <h3 className="mt-lg mb">Today&apos;s payouts</h3>
      {(list.data?.rows || []).length === 0 && <div className="muted small">None yet.</div>}
      {(list.data?.rows || []).map((p) => (
        <div key={p.id} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--border)' }}>
          <div><b>{p.description || p.account_name}</b> <span className="muted small">{p.payee} · {p.source === 'drawer' ? 'drawer' : 'fund'} · {fmtTime(p.created_at)}</span></div>
          <div className="row gap-sm"><b>{peso(p.amount)}</b><Badge>{p.status}</Badge>{p.status === 'posted' && <Button size="sm" variant="ghost" onClick={() => voidTxn(p)}>Void</Button>}</div>
        </div>
      ))}
    </Modal>
  );
}

// ------------------------------------------------------------------ receipts (reprint / void)
export function ReceiptsModal({ business, tax, methods, canVoid, onClose, onPrint, onChanged }) {
  const toast = useToast();
  const dialog = useDialog();
  const [q, setQ] = useState('');
  const [selected, setSelected] = useState(null);
  const list = useApi(() => api.get('/pos/tickets', q ? { q } : {}), [q]);
  const rows = (list.data || []).filter((t) => t.status !== 'open');

  const open = async (t) => {
    try { setSelected(await api.get(`/pos/tickets/${t.id}`)); } catch (e) { toast(e.message, 'error'); }
  };
  const reprint = async () => {
    try {
      const t = await api.post(`/pos/tickets/${selected.id}/reprint`);
      onPrint(<Receipt ticket={t} business={business} tax={tax} methods={methods} reprint />);
    } catch (e) { toast(e.message, 'error'); }
  };
  const voidIt = async () => {
    const r = await dialog({
      title: `Void receipt ${selected.receipt_no}?`, danger: true, okText: 'Void receipt', input: 'Reason', required: true, pin: true,
      message: 'Stock will be returned to inventory, the sale reversed in the books and any cash refund deducted from the drawer.',
    });
    if (!r) return;
    try {
      const t = await api.post(`/pos/tickets/${selected.id}/void`, { reason: r.value, pin: r.pin });
      setSelected(t); list.reload(); onChanged && onChanged();
      toast('Receipt voided');
    } catch (e) { toast(e.message, 'error'); }
  };

  return (
    <Modal title="Receipts" onClose={onClose} width={900}>
      <div className="grid-2">
        <div>
          <Input placeholder="Search receipt / ticket no. / customer (blank = current day)" value={q} onChange={(e) => setQ(e.target.value)} />
          <div style={{ maxHeight: '60vh', overflowY: 'auto', marginTop: 8 }}>
            {list.loading && !list.data && <Loading />}
            {rows.map((t) => (
              <div key={t.id} className={`tline ${selected && selected.id === t.id ? 'selected' : ''}`} style={{ gridTemplateColumns: '1fr auto' }} onClick={() => open(t)}>
                <div>
                  <b>{t.receipt_no || t.ticket_no}</b> {t.table_name && <span className="muted">· {t.table_name}</span>}
                  <div className="muted small">{fmtTime(t.paid_at || t.created_at)} · {t.cashier}{t.customer_name ? ' · ' + t.customer_name : ''}</div>
                </div>
                <div className="right"><b>{peso(t.total)}</b><div><Badge>{t.status}</Badge></div></div>
              </div>
            ))}
            {list.data && !rows.length && <div className="empty">No receipts.</div>}
          </div>
        </div>
        <div>
          {selected ? (
            <>
              <div style={{ border: '1px solid var(--border)', borderRadius: 8, maxHeight: '55vh', overflowY: 'auto' }}>
                <Receipt ticket={selected} business={business} tax={tax} methods={methods} />
              </div>
              <div className="row gap-sm mt">
                <Button className="grow" onClick={reprint} disabled={selected.status === 'open'}>Reprint</Button>
                {canVoid && selected.status === 'paid' && <Button className="grow" variant="danger" onClick={voidIt}>Void receipt</Button>}
              </div>
            </>
          ) : <div className="empty">Select a receipt</div>}
        </div>
      </div>
    </Modal>
  );
}
