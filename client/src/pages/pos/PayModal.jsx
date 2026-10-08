import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { peso, money } from '../../format';
import { Modal, Button, Input, Select, Field, useToast, useApi } from '../../components/ui';

const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
const NEEDS_REF = ['card', 'gcash', 'maya', 'bank_transfer', 'grabfood', 'foodpanda'];

/** Settle a ticket with one or more payments (split cash / card / e-wallet / charge). */
export default function PayModal({ ticket, methods, onClose, onPaid }) {
  const toast = useToast();
  const [payments, setPayments] = useState([]);
  const [method, setMethod] = useState('cash');
  const [entry, setEntry] = useState('');
  const [reference, setReference] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [busy, setBusy] = useState(false);
  const customers = useApi(() => (method === 'charge' ? api.get('/finance/customers') : Promise.resolve([])), [method === 'charge']);

  const total = ticket.total;
  const paid = r2(payments.reduce((s, p) => s + p.amount, 0));
  const remaining = r2(Math.max(total - paid, 0));
  const change = r2(Math.max(paid - total, 0));
  const nonCash = r2(payments.filter((p) => p.method !== 'cash').reduce((s, p) => s + p.amount, 0));

  const quick = useMemo(() => {
    const due = remaining || total;
    const set = new Set([due]);
    for (const step of [20, 50, 100, 500, 1000]) set.add(Math.ceil(due / step) * step);
    return [...set].filter((v) => v >= due).sort((a, b) => a - b).slice(0, 4);
  }, [remaining, total]);

  const label = (m) => (methods.find((x) => x.key === m) || {}).label || m;

  const add = (amountArg) => {
    let amount = r2(amountArg !== undefined ? amountArg : entry === '' ? remaining : entry);
    if (!(amount > 0)) return;
    if (method !== 'cash' && r2(nonCash + amount) > total) {
      amount = r2(total - nonCash);
      if (!(amount > 0)) { toast('Non-cash payments cannot exceed the amount due', 'error'); return; }
    }
    if (method === 'charge' && !customerId) { toast('Select the customer account', 'error'); return; }
    setPayments((p) => [...p, { method, amount, reference: reference || null, customer_id: method === 'charge' ? Number(customerId) : null }]);
    setEntry(''); setReference('');
    if (method !== 'cash') setMethod('cash');
  };

  const key = (k) => {
    if (k === 'C') return setEntry('');
    if (k === '⌫') return setEntry((e) => e.slice(0, -1));
    if (k === '.' && entry.includes('.')) return undefined;
    return setEntry((e) => (e + k).replace(/^0+(?=\d)/, ''));
  };

  const complete = async () => {
    let pays = payments;
    // Convenience: nothing added yet but an amount typed -> treat as one payment.
    if (!pays.length) {
      const amount = r2(entry === '' ? total : entry);
      if (method === 'charge' && !customerId) { toast('Select the customer account', 'error'); return; }
      pays = [{ method, amount, reference: reference || null, customer_id: method === 'charge' ? Number(customerId) : null }];
    }
    setBusy(true);
    try {
      const t = await api.post(`/pos/tickets/${ticket.id}/pay`, { payments: pays });
      onPaid(t);
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const canComplete = payments.length ? paid >= total : true;

  return (
    <Modal title={`Settle ${ticket.table_name ? 'Table ' + ticket.table_name : ticket.ticket_no}`} onClose={onClose} width={760}
      footer={<>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="success" size="lg" onClick={complete} loading={busy} disabled={!canComplete}>
          Complete payment {change > 0 ? `· Change ${peso(change)}` : ''}
        </Button>
      </>}>
      <div className="grid-2">
        <div>
          <div className="stats" style={{ gridTemplateColumns: '1fr 1fr' }}>
            <div className="stat stat-brand"><div className="stat-label">Amount due</div><div className="stat-value">{peso(total)}</div></div>
            <div className={`stat ${change > 0 ? 'stat-green' : remaining > 0 ? 'stat-amber' : ''}`}>
              <div className="stat-label">{change > 0 ? 'Change' : 'Remaining'}</div>
              <div className="stat-value">{peso(change > 0 ? change : remaining)}</div>
            </div>
          </div>
          <div className="mt">
            <div className="field-label mb">Payment method</div>
            <div className="pay-methods">
              {methods.map((m) => (
                <button key={m.key} className={`btn ${method === m.key ? 'sel' : ''}`} onClick={() => setMethod(m.key)}>{m.label}</button>
              ))}
            </div>
          </div>
          {NEEDS_REF.includes(method) && (
            <Field label="Approval / reference no." className="mt">
              <Input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="e.g. card approval code or GCash ref no." />
            </Field>
          )}
          {method === 'charge' && (
            <Field label="Customer account" className="mt">
              <Select value={customerId} onChange={setCustomerId} placeholder="Select customer…"
                options={(customers.data || []).map((c) => ({ value: c.id, label: `${c.name} (bal ${money(c.balance)})` }))} />
            </Field>
          )}
          <div className="mt">
            <div className="field-label mb">Payments</div>
            {!payments.length && <div className="muted small">Add one or more payments, or just press Complete to pay the full amount with {label(method)}.</div>}
            {payments.map((p, i) => (
              <div key={i} className="row between" style={{ padding: '6px 0', borderBottom: '1px solid var(--border)' }}>
                <span>{label(p.method)}{p.reference ? <span className="muted small"> #{p.reference}</span> : null}</span>
                <span className="row gap-sm"><b>{peso(p.amount)}</b><button className="icon-btn" onClick={() => setPayments((x) => x.filter((_, j) => j !== i))}>✕</button></span>
              </div>
            ))}
          </div>
        </div>
        <div>
          <input className="input num" style={{ height: 56, fontSize: 26, fontWeight: 700 }} value={entry} placeholder={money(remaining || total)}
            onChange={(e) => setEntry(e.target.value.replace(/[^\d.]/g, ''))} onKeyDown={(e) => { if (e.key === 'Enter') add(); }} />
          {method === 'cash' && (
            <div className="quick-cash mt">
              {quick.map((q) => <Button key={q} onClick={() => add(q)}>{q === (remaining || total) ? 'Exact' : '₱' + q.toLocaleString()}</Button>)}
            </div>
          )}
          <div className="keypad mt">
            {['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'].map((k) => <Button key={k} onClick={() => key(k)}>{k}</Button>)}
          </div>
          <div className="row gap-sm mt">
            <Button className="grow" onClick={() => key('C')}>Clear</Button>
            <Button className="grow" variant="dark" onClick={() => add()}>Add {label(method)}</Button>
          </div>
        </div>
      </div>
    </Modal>
  );
}
