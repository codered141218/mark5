import React, { useState } from 'react';
import { api } from '../../api';
import { peso, money } from '../../format';
import { Modal, Button, Input, NumberInput, Field, Select, useToast, Tabs } from '../../components/ui';

function useRun(onDone) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const run = async (fn) => {
    setBusy(true);
    try { const r = await fn(); onDone(r); } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return [busy, run];
}

const PinField = ({ value, onChange }) => (
  <Field label="Manager PIN" hint="Required only if your role does not allow this action.">
    <Input type="password" inputMode="numeric" value={value} onChange={(e) => onChange(e.target.value)} />
  </Field>
);

// ------------------------------------------------------------------ line edit / void
export function LineModal({ ticket, line, onClose, onDone }) {
  const [qtyVal, setQty] = useState(String(line.qty));
  const [notes, setNotes] = useState(line.notes || '');
  const [reason, setReason] = useState('');
  const [pin, setPin] = useState('');
  const [busy, run] = useRun(onDone);
  const reducing = Number(qtyVal) < line.qty;
  const needsAuth = line.kitchen_sent && reducing;
  const step = (d) => setQty((q) => String(Math.max(1, (Number(q) || 0) + d)));

  return (
    <Modal title={line.name} onClose={onClose} width={460}
      footer={<>
        <Button variant="danger" loading={busy} onClick={() => run(() => api.post(`/pos/tickets/${ticket.id}/items/${line.id}/void`, { reason: reason || 'Cancelled', pin }))}>
          {line.kitchen_sent ? 'Void item' : 'Remove'}
        </Button>
        <div className="grow" />
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} onClick={() => run(() => api.put(`/pos/tickets/${ticket.id}/items/${line.id}`, { qty: Number(qtyVal), notes, pin, reason }))}>Update</Button>
      </>}>
      <div className="row gap-sm">
        <Button size="lg" onClick={() => step(-1)}>−</Button>
        <NumberInput value={qtyVal} onChange={setQty} style={{ height: 48, fontSize: 20, textAlign: 'center' }} />
        <Button size="lg" onClick={() => step(1)}>+</Button>
      </div>
      <p className="muted small">{peso(line.price)} each · {line.kitchen_sent ? 'Already sent to kitchen' : 'Not yet sent to kitchen'}</p>
      <Field label="Kitchen note / modifier">
        <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. no onions, extra spicy, less ice" />
      </Field>
      <div className="row gap-sm wrap mt">
        {['No onions', 'Extra spicy', 'Not spicy', 'Less ice', 'Well done', 'Take-out'].map((n) => (
          <Button key={n} size="sm" onClick={() => setNotes((x) => (x ? x + ', ' : '') + n)}>{n}</Button>
        ))}
      </div>
      {(line.kitchen_sent || needsAuth) ? (
        <div className="form-grid mt">
          <Field label="Void reason"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. wrong order, customer changed mind" /></Field>
          <PinField value={pin} onChange={setPin} />
        </div>
      ) : null}
    </Modal>
  );
}

// ------------------------------------------------------------------ ticket info
export function TicketInfoModal({ ticket, onClose, onDone }) {
  const [f, setF] = useState({ customer_name: ticket.customer_name || '', pax: String(ticket.pax), order_type: ticket.order_type, notes: ticket.notes || '' });
  const [busy, run] = useRun(onDone);
  return (
    <Modal title="Ticket details" onClose={onClose} width={460}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={() => run(() => api.put(`/pos/tickets/${ticket.id}`, { ...f, pax: Number(f.pax) }))}>Save</Button></>}>
      <div className="form-grid">
        <Field label="Customer name"><Input value={f.customer_name} onChange={(e) => setF({ ...f, customer_name: e.target.value })} /></Field>
        <Field label="No. of guests (pax)"><NumberInput value={f.pax} onChange={(v) => setF({ ...f, pax: v })} /></Field>
        <Field label="Order type">
          <Select value={f.order_type} onChange={(v) => setF({ ...f, order_type: v })} options={[{ value: 'dine_in', label: 'Dine-in' }, { value: 'takeout', label: 'Take-out' }, { value: 'delivery', label: 'Delivery' }]} />
        </Field>
      </div>
      <Field label="Notes" className="mt"><Input value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
    </Modal>
  );
}

// ------------------------------------------------------------------ discounts
export function DiscountModal({ ticket, tax, onClose, onDone }) {
  const [type, setType] = useState(ticket.discount_type === 'none' ? 'sc' : ticket.discount_type);
  const [rate, setRate] = useState(String(ticket.discount_rate || ''));
  const [pax, setPax] = useState(String(ticket.pax));
  const [count, setCount] = useState(String(Math.max(ticket.sc_count || 1, 1)));
  const [people, setPeople] = useState(ticket.sc_details.length ? ticket.sc_details : [{ name: '', id_no: '' }]);
  const [pin, setPin] = useState('');
  const [busy, run] = useRun(onDone);
  const n = Math.max(Number(count) || 1, 1);
  const list = Array.from({ length: n }, (_, i) => people[i] || { name: '', id_no: '' });
  const setPerson = (i, k, v) => setPeople(() => list.map((p, j) => (j === i ? { ...p, [k]: v } : p)));

  const apply = () => run(() => api.post(`/pos/tickets/${ticket.id}/discount`, {
    discount_type: type, discount_rate: Number(rate), sc_count: n, pax: Number(pax), sc_details: list, pin,
  }));

  return (
    <Modal title="Discount" onClose={onClose} width={560}
      footer={<>
        {ticket.discount_type !== 'none' && <Button variant="danger" loading={busy} onClick={() => run(() => api.post(`/pos/tickets/${ticket.id}/discount`, { discount_type: 'none' }))}>Remove discount</Button>}
        <div className="grow" />
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} onClick={apply}>Apply</Button>
      </>}>
      <Tabs value={type} onChange={setType} tabs={[
        { value: 'sc', label: 'Senior Citizen' }, { value: 'pwd', label: 'PWD' }, { value: 'percent', label: 'Promo %' }, { value: 'amount', label: 'Fixed ₱' },
      ]} />
      {(type === 'sc' || type === 'pwd') ? (
        <>
          <div className="alert alert-info small">
            {tax.vatRegistered ? 'VAT-exempt + ' : ''}{Math.round(tax.scRate * 100)}% discount on the qualified share of the bill
            ({n} of {pax || 1} guests). Enter each {type === 'sc' ? 'senior citizen' : 'PWD'}&apos;s name and ID number for the BIR sales book.
          </div>
          <div className="form-grid">
            <Field label="Total guests (pax)"><NumberInput value={pax} onChange={setPax} /></Field>
            <Field label={`No. of ${type === 'sc' ? 'senior citizens' : 'PWDs'}`}><NumberInput value={count} onChange={setCount} /></Field>
          </div>
          {list.map((p, i) => (
            <div key={i} className="form-grid mt">
              <Field label={`Name #${i + 1}`}><Input value={p.name} onChange={(e) => setPerson(i, 'name', e.target.value)} /></Field>
              <Field label="OSCA / PWD ID no."><Input value={p.id_no} onChange={(e) => setPerson(i, 'id_no', e.target.value)} /></Field>
            </div>
          ))}
        </>
      ) : (
        <Field label={type === 'percent' ? 'Discount %' : 'Discount amount (₱)'}>
          <NumberInput value={rate} onChange={setRate} autoFocus />
        </Field>
      )}
      <div className="mt"><PinField value={pin} onChange={setPin} /></div>
    </Modal>
  );
}

// ------------------------------------------------------------------ table picker (move)
export function TablePicker({ tables, currentId, onPick, onClose, title = 'Move to table', allowNone }) {
  const areas = [...new Set(tables.map((t) => t.area || 'Tables'))];
  return (
    <Modal title={title} onClose={onClose} width={720}>
      {allowNone && <Button className="mb" onClick={() => onPick(null)}>No table (take-out / counter)</Button>}
      {areas.map((a) => (
        <div key={a} className="floor-area">
          <h4>{a}</h4>
          <div className="tables">
            {tables.filter((t) => (t.area || 'Tables') === a).map((t) => (
              <button key={t.id} className={`table-tile ${t.tickets.length ? 'busy' : ''}`} disabled={t.id === currentId} onClick={() => onPick(t.id)}
                style={t.id === currentId ? { opacity: 0.4 } : undefined}>
                <span className="tname">{t.name}</span>
                <span className="tinfo">{t.tickets.length ? `${t.tickets.length} ticket · ${peso(t.tickets.reduce((s, x) => s + x.total, 0))}` : `${t.seats} seats · free`}</span>
              </button>
            ))}
          </div>
        </div>
      ))}
    </Modal>
  );
}

// ------------------------------------------------------------------ split ticket
export function SplitModal({ ticket, tables, openTickets, onClose, onDone }) {
  const lines = ticket.items.filter((i) => i.status === 'active');
  const [sel, setSel] = useState({});
  const [target, setTarget] = useState('new');
  const [tableId, setTableId] = useState(ticket.table_id ? String(ticket.table_id) : '');
  const [busy, run] = useRun(onDone);
  const total = lines.reduce((s, l) => s + (sel[l.id] || 0) * l.price, 0);
  const setQ = (l, q) => setSel((s) => ({ ...s, [l.id]: Math.max(0, Math.min(l.qty, q)) }));
  const others = openTickets.filter((t) => t.id !== ticket.id);

  const submit = () => run(() => api.post(`/pos/tickets/${ticket.id}/split`, {
    lines: Object.entries(sel).filter(([, q]) => q > 0).map(([id, q]) => ({ id: Number(id), qty: q })),
    target_ticket_id: target === 'new' ? undefined : Number(target),
    table_id: target === 'new' ? (tableId ? Number(tableId) : null) : undefined,
  }));

  return (
    <Modal title="Split ticket — select items to move" onClose={onClose} width={620}
      footer={<><span className="muted">Moving {peso(total)}</span><div className="grow" /><Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} disabled={!total} onClick={submit}>Split</Button></>}>
      <div className="row gap-sm mb">
        <Button size="sm" onClick={() => setSel(Object.fromEntries(lines.map((l) => [l.id, l.qty])))}>Select all</Button>
        <Button size="sm" onClick={() => setSel({})}>Clear</Button>
      </div>
      {lines.map((l) => (
        <div key={l.id} className="row between" style={{ padding: '8px 0', borderBottom: '1px solid var(--border)' }}>
          <div><b>{l.name}</b><div className="muted small">{l.qty} × {money(l.price)}</div></div>
          <div className="row gap-sm">
            <Button size="sm" onClick={() => setQ(l, (sel[l.id] || 0) - 1)}>−</Button>
            <span style={{ width: 40, textAlign: 'center', fontWeight: 700 }}>{sel[l.id] || 0}</span>
            <Button size="sm" onClick={() => setQ(l, (sel[l.id] || 0) + 1)}>+</Button>
            <Button size="sm" variant="ghost" onClick={() => setQ(l, l.qty)}>All</Button>
          </div>
        </div>
      ))}
      <div className="form-grid mt">
        <Field label="Move selected items to">
          <Select value={target} onChange={setTarget} options={[{ value: 'new', label: 'A new ticket (separate bill)' },
            ...others.map((t) => ({ value: t.id, label: `${t.ticket_no}${t.table_name ? ' · ' + t.table_name : ''}${t.customer_name ? ' · ' + t.customer_name : ''}` }))]} />
        </Field>
        {target === 'new' && (
          <Field label="New ticket table">
            <Select value={tableId} onChange={setTableId} placeholder="No table / take-out" options={tables.map((t) => ({ value: t.id, label: t.name }))} />
          </Field>
        )}
      </div>
    </Modal>
  );
}

// ------------------------------------------------------------------ merge tickets
export function MergeModal({ ticket, openTickets, onClose, onDone }) {
  const [busy, run] = useRun(onDone);
  const others = openTickets.filter((t) => t.id !== ticket.id);
  return (
    <Modal title={`Merge another ticket into ${ticket.ticket_no}`} onClose={onClose} width={520}>
      {!others.length && <div className="empty">No other open tickets.</div>}
      {others.map((t) => (
        <div key={t.id} className="row between" style={{ padding: '10px 0', borderBottom: '1px solid var(--border)' }}>
          <div><b>{t.ticket_no}</b> {t.table_name && <span>· {t.table_name}</span>} {t.customer_name && <span className="muted">· {t.customer_name}</span>}
            <div className="muted small">{t.item_count} items · {peso(t.total)}</div></div>
          <Button loading={busy} onClick={() => run(() => api.post(`/pos/tickets/${ticket.id}/merge`, { source_ticket_id: t.id }))}>Merge</Button>
        </div>
      ))}
    </Modal>
  );
}
