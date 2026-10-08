import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { money, fmtDateTime, fmtDate } from '../../format';

/** Renders children into a print-only area and opens the browser print dialog. */
export function PrintJob({ children, onDone }) {
  useEffect(() => {
    const t = setTimeout(() => {
      window.print();
      onDone && onDone();
    }, 50);
    return () => clearTimeout(t);
  }, [onDone]);
  return createPortal(<div id="print-root">{children}</div>, document.body);
}

const Line = ({ l, r, bold }) => (
  <div className="r" style={bold ? { fontWeight: 'bold' } : undefined}><span>{l}</span><span>{r}</span></div>
);

function Header({ business }) {
  return (
    <div className="c">
      <div className="big">{business.name}</div>
      {business.address && <div>{business.address}</div>}
      {business.tin && <div>TIN: {business.tin}</div>}
      {business.phone && <div>Tel: {business.phone}</div>}
    </div>
  );
}

const DISC_LABEL = { sc: 'Senior Citizen Disc.', pwd: 'PWD Disc.', percent: 'Discount', amount: 'Discount' };
const ORDER_TYPE = { dine_in: 'Dine-in', takeout: 'Take-out', delivery: 'Delivery' };

/** Customer receipt or pre-bill (when ticket is still open). */
export function Receipt({ ticket: t, business, tax, methods = [], reprint }) {
  const active = t.items.filter((i) => i.status === 'active');
  const isBill = t.status === 'open';
  const label = (m) => (methods.find((x) => x.key === m) || {}).label || m;
  return (
    <div className="receipt">
      <Header business={business} />
      <hr />
      <div className="c big">{isBill ? 'BILL / STATEMENT' : t.status === 'void' ? 'VOIDED RECEIPT' : business.receipt_title || 'RECEIPT'}</div>
      {reprint && <div className="c">*** REPRINT ***</div>}
      {t.receipt_no && <Line l="Receipt No:" r={t.receipt_no} />}
      <Line l="Ticket:" r={t.ticket_no} />
      <Line l="Date:" r={fmtDateTime(t.paid_at || t.created_at)} />
      <Line l={ORDER_TYPE[t.order_type] || t.order_type} r={t.table_name ? `Table ${t.table_name}` : ''} />
      {t.customer_name && <Line l="Customer:" r={t.customer_name} />}
      <Line l="Pax:" r={t.pax} />
      <Line l="Cashier:" r={t.paid_by_name || t.created_by_name || ''} />
      <hr />
      {active.map((i) => (
        <div key={i.id}>
          <div>{i.name}</div>
          <Line l={`  ${i.qty} x ${money(i.price)}`} r={money(i.line_total)} />
          {i.notes && <div>  * {i.notes}</div>}
        </div>
      ))}
      <hr />
      <Line l="Subtotal" r={money(t.subtotal)} />
      {t.discount_amount > 0 && <Line l={DISC_LABEL[t.discount_type] || 'Discount'} r={`-${money(t.discount_amount)}`} />}
      {t.service_charge > 0 && <Line l="Service Charge" r={money(t.service_charge)} />}
      <Line l="TOTAL DUE" r={money(t.total)} bold />
      {!isBill && t.payments.map((p) => (
        <Line key={p.id} l={`${label(p.method)}${p.reference ? ' #' + p.reference : ''}`} r={money(p.method === 'cash' ? p.tendered : p.amount)} />
      ))}
      {!isBill && t.change_amount > 0 && <Line l="CHANGE" r={money(t.change_amount)} bold />}
      <hr />
      {tax && tax.vatRegistered ? (
        <>
          <Line l="VATable Sales" r={money(t.vatable_sales)} />
          <Line l={`VAT (${Math.round(tax.vatRate * 100)}%)`} r={money(t.vat_amount)} />
          <Line l="VAT-Exempt Sales" r={money(t.vat_exempt_sales)} />
          <Line l="Zero-Rated Sales" r={money(0)} />
        </>
      ) : (
        <div className="c">NON-VAT REGISTERED</div>
      )}
      {(t.discount_type === 'sc' || t.discount_type === 'pwd') && t.sc_details.length > 0 && (
        <>
          <hr />
          {t.sc_details.map((d, i) => (
            <div key={i}>{t.discount_type === 'sc' ? 'SC' : 'PWD'}: {d.name} / ID {d.id_no}<br />Signature: ____________</div>
          ))}
        </>
      )}
      {isBill && (
        <>
          <hr />
          <div>Name: ______________________</div>
          <div>Address: ___________________</div>
          <div>TIN: _______________________</div>
        </>
      )}
      <hr />
      {t.status === 'void' && <div className="c">VOID: {t.void_reason}</div>}
      <div className="c">{business.receipt_footer}</div>
    </div>
  );
}

/** Kitchen order slip for newly sent lines. */
export function KitchenSlip({ ticket, lines }) {
  return (
    <div className="receipt" style={{ fontSize: 14 }}>
      <div className="c big">KITCHEN ORDER</div>
      <Line l={ticket.table_name ? `TABLE ${ticket.table_name}` : (ORDER_TYPE[ticket.order_type] || '').toUpperCase()} r={ticket.ticket_no} bold />
      <Line l={fmtDateTime(new Date().toISOString())} r={`Pax ${ticket.pax}`} />
      {ticket.customer_name && <div>Customer: {ticket.customer_name}</div>}
      <hr />
      {lines.map((l) => (
        <div key={l.id} style={{ marginBottom: 4 }}>
          <div className="big">{l.qty} x {l.name}</div>
          {l.notes && <div>  ** {l.notes}</div>}
        </div>
      ))}
      <hr />
    </div>
  );
}

/** X-reading (mid-shift) or Z-reading (end of day). */
export function ReadingReport({ report: r, business, tax, z }) {
  const s = r.session;
  const DT = { sc: 'Senior Citizen', pwd: 'PWD', percent: 'Promo %', amount: 'Promo amount' };
  return (
    <div className="receipt">
      <Header business={business} />
      <hr />
      <div className="c big">{z ? 'Z-READING (END OF DAY)' : 'X-READING'}</div>
      <Line l="Business date:" r={fmtDate(s.business_date)} />
      <Line l="Opened:" r={fmtDateTime(s.opened_at)} />
      <Line l="Opened by:" r={s.opened_by_name || ''} />
      {z && <Line l="Closed:" r={fmtDateTime(s.closed_at)} />}
      {z && <Line l="Closed by:" r={s.closed_by_name || ''} />}
      <Line l="Printed:" r={fmtDateTime(new Date().toISOString())} />
      <hr />
      <Line l="Beginning OR" r={r.sales.first_or || '-'} />
      <Line l="Ending OR" r={r.sales.last_or || '-'} />
      <Line l="Receipts" r={r.sales.cnt} />
      <Line l="Guests (pax)" r={r.sales.pax} />
      <hr />
      <Line l="Gross Sales" r={money(r.sales.gross)} />
      <Line l="Less: Discounts" r={money(r.sales.discounts)} />
      <Line l="Add: Service Charge" r={money(r.sales.svc)} />
      <Line l="NET SALES" r={money(r.sales.net)} bold />
      {tax && tax.vatRegistered && (
        <>
          <Line l="VATable Sales" r={money(r.sales.vatable)} />
          <Line l="VAT Amount" r={money(r.sales.vat)} />
          <Line l="VAT-Exempt Sales" r={money(r.sales.exempt)} />
        </>
      )}
      {r.discounts.length > 0 && <hr />}
      {r.discounts.map((d) => <Line key={d.discount_type} l={`${DT[d.discount_type] || d.discount_type} (${d.cnt})`} r={money(d.amount)} />)}
      <hr />
      <div className="bold">PAYMENTS</div>
      {r.payments.map((p) => <Line key={p.method} l={`${p.label} (${p.cnt})`} r={money(p.amount)} />)}
      <hr />
      <Line l={`Voided receipts (${r.voided.cnt})`} r={money(r.voided.amount)} />
      <Line l={`Voided items (${r.item_voids.cnt})`} r={money(r.item_voids.amount)} />
      <Line l="Cancelled tickets" r={r.cancelled} />
      <hr />
      <div className="bold">CASH DRAWER</div>
      <Line l="Beginning cash" r={money(r.cash.opening)} />
      <Line l="Cash sales" r={money(r.cash.cash_sales)} />
      <Line l="Less: Payouts / petty cash" r={money(r.cash.payouts)} />
      {r.cash.refunds > 0 && <Line l="Less: Refunds" r={money(r.cash.refunds)} />}
      <Line l="EXPECTED CASH" r={money(r.cash.expected)} bold />
      {z && <Line l="ACTUAL CASH COUNT" r={money(r.cash.counted)} bold />}
      {z && <Line l={r.cash.variance < 0 ? 'SHORT' : r.cash.variance > 0 ? 'OVER' : 'VARIANCE'} r={money(r.cash.variance)} bold />}
      <hr />
      <div className="bold">SALES BY CATEGORY</div>
      {r.categories.map((c) => <Line key={c.category} l={`${c.category} (${c.qty})`} r={money(c.amount)} />)}
      <hr />
      <Line l="Accum. Grand Total Beg." r={money(r.grand_total.beginning)} />
      <Line l="Accum. Grand Total End" r={money(r.grand_total.ending)} />
      <hr />
      <div className="c">Cashier: ____________ Manager: ____________</div>
    </div>
  );
}
