import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { peso, money, fmtTime, fmtDate } from '../../format';
import { Button, useToast, useDialog, Loading, Badge } from '../../components/ui';
import { PrintJob, Receipt, KitchenSlip } from './Print';
import PayModal from './PayModal';
import { LineModal, TicketInfoModal, DiscountModal, TablePicker, SplitModal, MergeModal } from './TicketModals';
import { OpenDayModal, XReadingModal, CloseDayModal, PettyCashModal, ReceiptsModal } from './DayModals';

const ORDER_TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };
const DISC = { sc: 'SC', pwd: 'PWD', percent: 'Promo', amount: 'Promo' };

function elapsed(ts) {
  const m = Math.floor((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000);
  if (m < 60) return `${m}m`;
  return `${Math.floor(m / 60)}h ${m % 60}m`;
}

export default function Pos() {
  const { user, can, logout } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const [state, setState] = useState(null);
  const [menu, setMenu] = useState({ categories: [], items: [] });
  const [floor, setFloor] = useState({ tables: [], others: [] });
  const [ticket, setTicket] = useState(null);
  const [cat, setCat] = useState('all');
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState(null);
  const [printJob, setPrintJob] = useState(null);
  const [busy, setBusy] = useState(false);
  const [, tick] = useState(0);

  const loadState = useCallback(async () => {
    const [s, m] = await Promise.all([api.get('/pos/state'), api.get('/pos/menu')]);
    setState(s); setMenu(m);
  }, []);
  const loadFloor = useCallback(async () => {
    try { setFloor(await api.get('/pos/tables')); } catch (e) { toast(e.message, 'error'); }
  }, [toast]);

  useEffect(() => {
    loadState().catch((e) => toast(e.message, 'error'));
    loadFloor();
    const iv = setInterval(() => { tick((x) => x + 1); }, 30000);
    return () => clearInterval(iv);
  }, [loadState, loadFloor, toast]);
  // Refresh the floor periodically so multiple terminals stay in sync.
  useEffect(() => {
    if (ticket) return undefined;
    const iv = setInterval(loadFloor, 15000);
    return () => clearInterval(iv);
  }, [ticket, loadFloor]);

  const session = state?.session;
  const print = (node) => setPrintJob(node);
  const close = () => setModal(null);
  const openTickets = useMemo(() => [
    ...floor.tables.flatMap((t) => t.tickets.map((x) => ({ ...x, table_name: t.name }))),
    ...floor.others,
  ], [floor]);

  const run = async (fn) => {
    setBusy(true);
    try { return await fn(); } catch (e) { toast(e.message, 'error'); return null; } finally { setBusy(false); }
  };
  const refreshTicket = (t) => { setTicket(t); setModal(null); };
  const backToFloor = () => { setTicket(null); setModal(null); setSearch(''); loadFloor(); };

  // ------------------------------------------------------------ floor actions
  const openTable = async (table) => {
    if (!session) { toast('Open the business day first', 'error'); return; }
    if (table.tickets.length === 1) { const t = await run(() => api.get(`/pos/tickets/${table.tickets[0].id}`)); if (t) setTicket(t); return; }
    if (table.tickets.length > 1) { setModal({ type: 'chooseTicket', table }); return; }
    const r = await dialog({ title: `New order — ${table.name}`, input: 'Number of guests (pax)', defaultValue: '2', okText: 'Start order' });
    if (!r) return;
    const t = await run(() => api.post('/pos/tickets', { table_id: table.id, pax: Number(r.value) || 1, order_type: 'dine_in' }));
    if (t) setTicket(t);
  };
  const newCounterOrder = async (order_type) => {
    if (!session) { toast('Open the business day first', 'error'); return; }
    const r = await dialog({ title: `New ${ORDER_TYPE[order_type]} order`, input: 'Customer name (optional)', okText: 'Start order' });
    if (!r) return;
    const t = await run(() => api.post('/pos/tickets', { order_type, customer_name: r.value, pax: 1 }));
    if (t) setTicket(t);
  };
  const openTicketById = async (id) => { const t = await run(() => api.get(`/pos/tickets/${id}`)); if (t) { setTicket(t); setModal(null); } };

  // ------------------------------------------------------------ ticket actions
  const addItem = async (item) => {
    if (busy) return;
    const t = await run(() => api.post(`/pos/tickets/${ticket.id}/items`, { item_id: item.id, qty: 1 }));
    if (t) setTicket(t);
  };
  const sendToKitchen = async (printSlip = true) => {
    const r = await run(() => api.post(`/pos/tickets/${ticket.id}/send`));
    if (!r) return;
    setTicket(r.ticket);
    if (!r.sent.length) { toast('Nothing new to send', 'info'); return; }
    toast(`${r.sent.length} item(s) sent to kitchen`);
    if (printSlip) print(<KitchenSlip ticket={r.ticket} lines={r.sent} />);
  };
  const voidTicket = async () => {
    const paid = ticket.status === 'paid';
    const r = await dialog({
      title: paid ? 'Void receipt' : 'Cancel this ticket?', danger: true, okText: paid ? 'Void receipt' : 'Cancel ticket', input: 'Reason', required: true,
      pin: ticket.items.some((i) => i.kitchen_sent), message: paid ? '' : 'All items on this ticket will be cancelled.',
    });
    if (!r) return;
    const t = await run(() => api.post(`/pos/tickets/${ticket.id}/void`, { reason: r.value, pin: r.pin }));
    if (t) { toast('Ticket cancelled'); backToFloor(); }
  };
  const moveTo = async (tableId) => {
    const t = await run(() => api.post(`/pos/tickets/${ticket.id}/move`, { table_id: tableId }));
    if (t) { toast(tableId ? 'Moved to new table' : 'Moved to counter'); refreshTicket(t); }
  };
  const onPaid = (t) => {
    setModal({ type: 'paid', ticket: t });
    print(<Receipt ticket={t} business={state.business} tax={state.tax} methods={state.payment_methods} />);
    setTicket(null);
    loadFloor();
  };

  // ------------------------------------------------------------ menu filtering
  const tiles = useMemo(() => {
    let items = menu.items;
    if (search) {
      const s = search.toLowerCase();
      items = items.filter((i) => i.name.toLowerCase().includes(s) || (i.sku || '').toLowerCase() === s || (i.barcode || '') === search);
    } else if (cat !== 'all') items = items.filter((i) => i.category_id === Number(cat));
    return items;
  }, [menu, cat, search]);
  const catColor = (id) => (menu.categories.find((c) => c.id === id) || {}).color;

  if (!state) return <div className="pos"><Loading /></div>;
  const tableList = floor.tables;
  const activeLines = ticket ? ticket.items.filter((i) => i.status === 'active') : [];
  const unsent = activeLines.filter((i) => !i.kitchen_sent).length;

  return (
    <div className="pos">
      {/* ------------------------------------------------ top bar */}
      <div className="pos-top">
        <span className="title">{state.business.name}</span>
        {session
          ? <span className="chip">Day open · {fmtDate(session.business_date)}</span>
          : <span className="chip" style={{ background: '#7f1d1d', color: '#fff' }}>Day not open</span>}
        <div className="grow" />
        {ticket && <Button variant="ghost" onClick={backToFloor}>◧ Tables</Button>}
        <Button variant="ghost" onClick={() => setModal({ type: 'receipts' })}>Receipts</Button>
        {can('pos.petty_cash') && session && <Button variant="ghost" onClick={() => setModal({ type: 'petty' })}>Payout</Button>}
        {can('pos.xreading', 'pos.close_day') && session && <Button variant="ghost" className="hide-sm" onClick={() => setModal({ type: 'x' })}>X-Read</Button>}
        {can('pos.close_day') && session && <Button variant="ghost" onClick={() => setModal({ type: 'close' })}>End of Day</Button>}
        {can('pos.open_day') && !session && <Button variant="success" onClick={() => setModal({ type: 'open' })}>Open Day</Button>}
        <span className="chip hide-sm">{user.full_name}</span>
        {can('dashboard.view', 'inventory.view', 'reports.sales', 'finance.view') && <Link className="btn btn-ghost hide-sm" style={{ color: '#e5e7eb' }} to="/">Back office</Link>}
        <Button variant="ghost" onClick={logout}>Log out</Button>
      </div>

      <div className="pos-body">
        {!ticket ? (
          /* ------------------------------------------------ floor plan */
          <div className="pos-left">
            {!session && (
              <div className="alert alert-warn">
                The business day is not open. {can('pos.open_day') ? 'Click “Open Day” and enter the beginning cash to start selling.' : 'Ask a cashier or manager to open the day.'}
              </div>
            )}
            <div className="row gap-sm wrap">
              <Button size="lg" variant="primary" onClick={() => newCounterOrder('takeout')}>+ Take-out</Button>
              <Button size="lg" onClick={() => newCounterOrder('delivery')}>+ Delivery</Button>
              <div className="grow" />
              <Button onClick={loadFloor}>↻ Refresh</Button>
            </div>
            <div className="floor">
              {floor.others.length > 0 && (
                <div className="floor-area">
                  <h4>Take-out / Delivery / Counter</h4>
                  <div className="tables">
                    {floor.others.map((t) => (
                      <button key={t.id} className="table-tile busy" onClick={() => openTicketById(t.id)}>
                        <span className="tname" style={{ fontSize: 15 }}>{t.customer_name || t.ticket_no}</span>
                        <span className="tinfo">{ORDER_TYPE[t.order_type]} · {peso(t.total)}</span>
                        <span className="tinfo">{elapsed(t.created_at)}</span>
                      </button>
                    ))}
                  </div>
                </div>
              )}
              {[...new Set(tableList.map((t) => t.area || 'Tables'))].map((area) => (
                <div key={area} className="floor-area">
                  <h4>{area}</h4>
                  <div className="tables">
                    {tableList.filter((t) => (t.area || 'Tables') === area).map((t) => {
                      const tot = t.tickets.reduce((s, x) => s + x.total, 0);
                      return (
                        <button key={t.id} className={`table-tile ${t.tickets.length ? 'busy' : ''}`} onClick={() => openTable(t)}>
                          <span className="tname">{t.name}</span>
                          {t.tickets.length ? (
                            <>
                              <span className="tinfo"><b>{peso(tot)}</b> · {t.tickets.reduce((s, x) => s + x.pax, 0)} pax</span>
                              <span className="tinfo">{t.tickets.length > 1 ? `${t.tickets.length} tickets · ` : ''}{elapsed(t.tickets[0].created_at)}</span>
                            </>
                          ) : <span className="tinfo">{t.seats} seats</span>}
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}
              {!tableList.length && <div className="empty">No tables yet — add them under Settings &amp; Tables.</div>}
            </div>
          </div>
        ) : (
          /* ------------------------------------------------ item tiles */
          <div className="pos-left">
            <div className="row gap-sm">
              <input className="input" style={{ height: 44, fontSize: 16 }} placeholder="Search item or scan barcode…" value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && tiles.length) { addItem(tiles[0]); setSearch(''); } }} />
              {search && <Button size="lg" onClick={() => setSearch('')}>Clear</Button>}
            </div>
            <div className="cat-bar">
              <button className={`cat-btn ${cat === 'all' ? 'active' : ''}`} onClick={() => { setCat('all'); setSearch(''); }}>All</button>
              {menu.categories.map((c) => (
                <button key={c.id} className={`cat-btn ${String(cat) === String(c.id) ? 'active' : ''}`} style={{ '--c': c.color || undefined }}
                  onClick={() => { setCat(c.id); setSearch(''); }}>{c.name}</button>
              ))}
            </div>
            <div className="tiles">
              {tiles.map((i) => {
                const out = i.item_type === 'retail' && i.stock_qty <= 0;
                return (
                  <button key={i.id} className={`tile ${out ? 'out' : ''}`} style={{ '--c': i.color || catColor(i.category_id) || undefined }} onClick={() => addItem(i)}>
                    <span className="tile-name">{i.name}</span>
                    <span>
                      <span className="tile-price">{peso(i.price)}</span>
                      {i.item_type === 'retail' && <span className="tile-stock"> · {out ? 'out of stock' : `${Math.floor(i.stock_qty)} left`}</span>}
                    </span>
                  </button>
                );
              })}
              {!tiles.length && <div className="empty" style={{ gridColumn: '1 / -1' }}>No items. Add sellable items under Inventory → Items.</div>}
            </div>
          </div>
        )}

        {/* ------------------------------------------------ ticket panel */}
        <div className="pos-right">
          {!ticket ? (
            <>
              <div className="ticket-head"><h3>Open tickets</h3><div className="muted small">{openTickets.length} open · {peso(openTickets.reduce((s, t) => s + t.total, 0))}</div></div>
              <div className="ticket-lines">
                {openTickets.map((t) => (
                  <div key={t.id} className="tline" style={{ gridTemplateColumns: '1fr auto' }} onClick={() => openTicketById(t.id)}>
                    <div>
                      <div className="tline-name">{t.table_name ? `Table ${t.table_name}` : t.customer_name || ORDER_TYPE[t.order_type]}</div>
                      <div className="muted small">{t.ticket_no} · {t.item_count} items · {elapsed(t.created_at)}</div>
                    </div>
                    <div className="tline-amt">{peso(t.total)}</div>
                  </div>
                ))}
                {!openTickets.length && <div className="empty">No open tickets</div>}
              </div>
            </>
          ) : (
            <>
              <div className="ticket-head row between" onClick={() => setModal({ type: 'info' })} style={{ cursor: 'pointer' }}>
                <div>
                  <h3>{ticket.table_name ? `Table ${ticket.table_name}` : ORDER_TYPE[ticket.order_type]}{ticket.customer_name ? ` · ${ticket.customer_name}` : ''}</h3>
                  <div className="muted small">{ticket.ticket_no} · {ticket.pax} pax · {fmtTime(ticket.created_at)} · {ticket.created_by_name}</div>
                </div>
                <span className="muted small">Edit ✎</span>
              </div>
              <div className="ticket-lines">
                {ticket.items.map((l) => (
                  <div key={l.id} className={`tline ${l.status === 'void' ? 'voided' : ''}`} onClick={() => l.status === 'active' && setModal({ type: 'line', line: l })}>
                    <span className="tline-qty">{l.qty}</span>
                    <div>
                      <div className="tline-name">{l.name}{l.kitchen_sent ? <span className="sent-dot" title="Sent to kitchen" /> : null}</div>
                      {l.notes && <div className="tline-note">{l.notes}</div>}
                      {l.status === 'void' && <div className="small">VOID: {l.void_reason}</div>}
                      <div className="muted small">@ {money(l.price)}</div>
                    </div>
                    <span className="tline-amt">{money(l.line_total)}</span>
                  </div>
                ))}
                {!ticket.items.length && <div className="empty">Tap items on the left to add them.</div>}
              </div>
              <div className="ticket-totals">
                <div className="row"><span>Subtotal</span><span>{money(ticket.subtotal)}</span></div>
                {ticket.discount_amount > 0 && (
                  <div className="row text-green"><span>Discount ({DISC[ticket.discount_type]}{ticket.discount_type === 'percent' ? ` ${ticket.discount_rate}%` : ''}{ticket.sc_count ? ` ×${ticket.sc_count}` : ''})</span><span>−{money(ticket.discount_amount)}</span></div>
                )}
                {ticket.service_charge > 0 && <div className="row"><span>Service charge</span><span>{money(ticket.service_charge)}</span></div>}
                {state.tax.vatRegistered && <div className="row muted small"><span>VAT incl. {money(ticket.vat_amount)} · VAT-exempt {money(ticket.vat_exempt_sales)}</span></div>}
                <div className="row grand"><span>TOTAL</span><span>{peso(ticket.total)}</span></div>
              </div>
              <div className="ticket-actions">
                <Button onClick={() => sendToKitchen(true)} disabled={!unsent || busy}>Send{unsent ? ` (${unsent})` : ''}</Button>
                <Button onClick={() => setModal({ type: 'discount' })} disabled={!activeLines.length}>Discount</Button>
                {can('pos.split_move') && <Button onClick={() => setModal({ type: 'split' })} disabled={!activeLines.length}>Split</Button>}
                {can('pos.split_move') && <Button onClick={() => setModal({ type: 'move' })}>Move table</Button>}
                {can('pos.split_move') && <Button onClick={() => setModal({ type: 'merge' })}>Merge</Button>}
                <Button onClick={() => print(<Receipt ticket={ticket} business={state.business} tax={state.tax} />)} disabled={!activeLines.length}>Print bill</Button>
                <Button variant="danger" onClick={voidTicket}>Cancel</Button>
                <Button onClick={backToFloor}>Done</Button>
                {can('pos.settle') && (
                  <Button className="pay" variant="success" style={{ gridColumn: 'span 4' }} disabled={!activeLines.length || busy}
                    onClick={() => setModal({ type: 'pay' })}>PAY {peso(ticket.total)}</Button>
                )}
              </div>
            </>
          )}
        </div>
      </div>

      {/* ------------------------------------------------ modals */}
      {modal?.type === 'open' && <OpenDayModal denominations={state.denominations} onClose={close} onDone={() => { close(); loadState(); }} />}
      {modal?.type === 'x' && <XReadingModal business={state.business} tax={state.tax} onClose={close} onPrint={print} />}
      {modal?.type === 'close' && <CloseDayModal denominations={state.denominations} business={state.business} tax={state.tax} onClose={close} onPrint={print}
        onClosed={() => { close(); setTicket(null); loadState(); loadFloor(); }} />}
      {modal?.type === 'petty' && <PettyCashModal onClose={close} />}
      {modal?.type === 'receipts' && <ReceiptsModal business={state.business} tax={state.tax} methods={state.payment_methods} canVoid onClose={close} onPrint={print} onChanged={loadFloor} />}
      {modal?.type === 'chooseTicket' && (
        <TablePickerTickets table={modal.table} onPick={openTicketById} onClose={close}
          onNew={async () => { const t = await run(() => api.post('/pos/tickets', { table_id: modal.table.id, pax: 1 })); if (t) refreshTicket(t); }} />
      )}
      {ticket && modal?.type === 'line' && <LineModal ticket={ticket} line={modal.line} onClose={close} onDone={refreshTicket} />}
      {ticket && modal?.type === 'info' && <TicketInfoModal ticket={ticket} onClose={close} onDone={refreshTicket} />}
      {ticket && modal?.type === 'discount' && <DiscountModal ticket={ticket} tax={state.tax} onClose={close} onDone={refreshTicket} />}
      {ticket && modal?.type === 'move' && <TablePicker tables={tableList} currentId={ticket.table_id} allowNone onClose={close} onPick={moveTo} />}
      {ticket && modal?.type === 'split' && (
        <SplitModal ticket={ticket} tables={tableList} openTickets={openTickets} onClose={close}
          onDone={(r) => { toast(`Split into ${r.target.ticket_no}`); refreshTicket(r.source); loadFloor(); }} />
      )}
      {ticket && modal?.type === 'merge' && <MergeModal ticket={ticket} openTickets={openTickets} onClose={close} onDone={(t) => { toast('Tickets merged'); refreshTicket(t); loadFloor(); }} />}
      {ticket && modal?.type === 'pay' && <PayModal ticket={ticket} methods={state.payment_methods} onClose={close} onPaid={onPaid} />}
      {modal?.type === 'paid' && <PaidModal ticket={modal.ticket} onClose={close}
        onReprint={() => print(<Receipt ticket={modal.ticket} business={state.business} tax={state.tax} methods={state.payment_methods} reprint />)} />}

      {printJob && <PrintJob onDone={() => setPrintJob(null)}>{printJob}</PrintJob>}
    </div>
  );
}

function TablePickerTickets({ table, onPick, onNew, onClose }) {
  return (
    <div className="modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: 460 }}>
        <div className="modal-head"><h3>Table {table.name} — choose ticket</h3><button className="icon-btn" onClick={onClose}>✕</button></div>
        <div className="modal-body">
          {table.tickets.map((t) => (
            <div key={t.id} className="tline" style={{ gridTemplateColumns: '1fr auto' }} onClick={() => onPick(t.id)}>
              <div><b>{t.ticket_no}</b><div className="muted small">{t.customer_name || ''} {t.item_count} items · {t.pax} pax</div></div>
              <b>{peso(t.total)}</b>
            </div>
          ))}
          <Button className="mt" onClick={onNew}>+ New separate ticket on this table</Button>
        </div>
      </div>
    </div>
  );
}

function PaidModal({ ticket, onClose, onReprint }) {
  useEffect(() => {
    const k = (e) => { if (e.key === 'Enter' || e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <div className="modal-backdrop">
      <div className="modal" style={{ maxWidth: 420 }}>
        <div className="modal-body center" style={{ padding: 32 }}>
          <div style={{ fontSize: 48 }}>✓</div>
          <h2>Payment complete</h2>
          <p className="muted">{ticket.receipt_no} · {peso(ticket.total)}</p>
          {ticket.change_amount > 0 && <div style={{ fontSize: 36, fontWeight: 800, color: 'var(--green)' }}>Change {peso(ticket.change_amount)}</div>}
          <div className="row gap-sm mt-lg" style={{ justifyContent: 'center' }}>
            <Button onClick={onReprint}>Print again</Button>
            <Button variant="primary" size="lg" onClick={onClose}>New order</Button>
          </div>
          <div className="mt"><Badge>{ticket.status}</Badge></div>
        </div>
      </div>
    </div>
  );
}
