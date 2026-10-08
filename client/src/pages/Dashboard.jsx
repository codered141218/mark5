import React from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { peso, money, int, pct, qty, fmtDate } from '../format';
import { PageHeader, Card, Stat, useApi, Loading, ErrorBox, Badge } from '../components/ui';
import DateRange, { useDateRange, rangeLabel } from '../components/DateRange';
import { BarChart, HBars } from '../components/Charts';

const ORDER_TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };

export default function Dashboard() {
  const { can } = useAuth();
  const [range, setRange] = useDateRange('month');
  const { data: d, loading, error } = useApi(() => api.get('/reports/dashboard', range), [range.from, range.to]);

  return (
    <div>
      <PageHeader title="Dashboard" subtitle={rangeLabel(range)} actions={<DateRange value={range} onChange={setRange} />} />
      <ErrorBox error={error} />
      {loading && !d && <Loading />}
      {d && <DashboardBody d={d} can={can} />}
    </div>
  );
}

function DashboardBody({ d, can }) {
  const k = d.kpi;
  const p = d.position;
  const singleDay = d.daily.length <= 1;
  return (
    <div className="stack">
      {d.session ? (
        <div className="alert alert-success">Business day <b>{fmtDate(d.session.business_date)}</b> is open · {d.open_tickets.cnt} open ticket(s) worth {peso(d.open_tickets.amt)}.</div>
      ) : (
        <div className="alert alert-warn">No business day is open on the POS.</div>
      )}

      <div className="stats">
        <Stat tone="brand" label="Net sales" value={peso(k.net_sales)} sub={`${int(k.receipts)} receipts · ${int(k.pax)} guests`} />
        <Stat label="Sales net of VAT" value={peso(k.net_of_vat)} sub={`VAT ${money(k.vat)} · Svc ${money(k.service_charge)}`} />
        <Stat label="Average ticket" value={peso(k.avg_ticket)} sub={k.pax ? `${peso(k.net_sales / k.pax)} per guest` : ''} />
        <Stat label="Gross profit" tone={k.gross_profit >= 0 ? 'green' : 'red'} value={peso(k.gross_profit)} sub={`COGS ${money(k.cogs)}`} />
        <Stat label="Food cost %" tone={k.food_cost_pct > 40 ? 'red' : k.food_cost_pct > 35 ? 'amber' : 'green'} value={pct(k.food_cost_pct)} sub="target: 28–35%" />
        <Stat label="Discounts given" value={peso(k.discounts)} sub="SC / PWD / promo" />
        <Stat label="Voided receipts" tone={k.voids ? 'red' : undefined} value={int(k.voids)} sub={peso(k.void_amount)} />
        <Stat label="Spoilage & wastage" tone={k.wastage ? 'amber' : undefined} value={peso(k.wastage)} sub={k.net_of_vat ? `${pct((k.wastage / k.net_of_vat) * 100)} of sales` : ''} />
        <Stat label="Purchases (stock in)" value={peso(k.purchases)} />
        <Stat label="Operating expenses" value={peso(k.operating_expenses)} sub={`incl. petty cash ${money(k.petty_cash)}`} />
        <Stat label="Est. net income" tone={k.net_income_est >= 0 ? 'green' : 'red'} value={peso(k.net_income_est)} sub="GP − wastage − expenses" />
      </div>

      <div className="grid-2">
        <Card title={singleDay ? 'Sales by hour' : 'Daily net sales'}>
          {singleDay
            ? <BarChart data={d.hourly.map((h) => ({ label: `${h.hour}h`, tip: `${h.hour}:00 – ${h.hour}:59 · ${h.receipts} receipts`, value: h.net }))} format={peso} />
            : <BarChart data={d.daily.map((x) => ({ label: x.date.slice(8), tip: `${fmtDate(x.date)} · ${x.receipts} receipts`, value: x.net }))} format={peso} />}
        </Card>
        <Card title="Sales by category">
          <HBars data={d.categories.map((c) => ({ label: c.name, value: c.amount }))} format={money} />
        </Card>
      </div>

      <div className="grid-3">
        <Card title="Top 10 items" actions={can('reports.sales') && <Link to="/reports/sales?report=items" className="small">All items →</Link>}>
          <HBars data={d.top_items.map((i) => ({ label: i.name, value: i.amount, sub: `×${qty(i.qty)}` }))} format={money} />
        </Card>
        <Card title="Payment mix">
          <HBars data={d.payments.map((x) => ({ label: x.label, value: x.amount }))} format={money} />
          {d.order_types.length > 0 && <div className="mt muted small">{d.order_types.map((o) => `${ORDER_TYPE[o.order_type] || o.order_type}: ${o.cnt} (${peso(o.amount)})`).join(' · ')}</div>}
        </Card>
        <Card title="Busiest hours">
          <BarChart height={180} data={d.hourly.map((h) => ({ label: `${h.hour}`, tip: `${h.hour}:00 · ${h.receipts} receipts`, value: h.receipts }))} format={(v) => `${v} receipts`} />
        </Card>
      </div>

      <div className="grid-2">
        <Card title="Cash & financial position" actions={<span className="muted small">as of {fmtDate(d.to)}</span>}>
          <table className="table dense">
            <tbody>
              <tr><td>Cash on hand</td><td className="right">{peso(p.cash_on_hand)}</td></tr>
              <tr><td>Petty cash fund</td><td className="right">{peso(p.petty_cash)}</td></tr>
              {p.banks.map((b) => <tr key={b.id}><td>{b.bank_name} {b.account_no ? `···${String(b.account_no).slice(-4)}` : ''}</td><td className="right">{peso(b.balance)}</td></tr>)}
              <tr><td>Inventory value (current)</td><td className="right">{peso(p.inventory_value)}</td></tr>
              <tr><td>Receivables {p.ar_overdue > 0 && <Badge color="red">overdue {money(p.ar_overdue)}</Badge>}</td><td className="right">{peso(p.ar_total)}</td></tr>
              <tr><td>Payables {p.ap_due_count > 0 && <Badge color="amber">{p.ap_due_count} due within 7 days</Badge>}</td><td className="right">{peso(p.ap_total)}</td></tr>
              <tr><td>Employee advances outstanding</td><td className="right">{peso(p.ca_outstanding)}</td></tr>
            </tbody>
          </table>
          {p.ca_pending > 0 && can('ca.approve') && (
            <div className="alert alert-warn mt"><Link to="/cash-advances">{p.ca_pending} cash advance request(s) waiting for approval ({peso(p.ca_pending_amount)}) →</Link></div>
          )}
        </Card>
        <Card title="Low stock — reorder now" actions={can('reports.inventory') && <Link to="/reports/inventory?report=reorder" className="small">Reorder report →</Link>}>
          {!d.low_stock.length ? <div className="empty">All stock levels are above reorder points.</div> : (
            <table className="table dense">
              <thead><tr><th>Item</th><th className="right">On hand</th><th className="right">Reorder pt.</th><th className="right">Order qty</th></tr></thead>
              <tbody>
                {d.low_stock.map((i) => (
                  <tr key={i.id}>
                    <td>{i.name}</td>
                    <td className={`right ${i.stock_qty <= 0 ? 'text-red bold' : 'text-amber'}`}>{qty(i.stock_qty)} {i.uom}</td>
                    <td className="right">{qty(i.reorder_point)}</td>
                    <td className="right">{qty(Math.max(i.reorder_qty, i.reorder_point - i.stock_qty))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      </div>
    </div>
  );
}
