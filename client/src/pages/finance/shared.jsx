// Helpers shared by the finance / cash pages.
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Field, Select } from '../../components/ui';

export const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];
export const TYPE_LABELS = { asset: 'Assets', liability: 'Liabilities', equity: 'Equity', income: 'Income', expense: 'Expenses' };
export const TYPE_SINGULAR = { asset: 'Asset', liability: 'Liability', equity: 'Equity', income: 'Income', expense: 'Expense' };

/** Balance in the account's natural sign (credit-normal accounts shown positive). */
export const naturalBalance = (a) => (['liability', 'equity', 'income'].includes(a.type) ? -(a.balance || 0) : a.balance || 0);

export const SOURCE_LABELS = {
  manual: 'Manual entry',
  pos_sale: 'POS sale',
  pos_eod: 'POS end of day',
  inv_receive: 'Delivery / stock in',
  inv_issue: 'Stock issuance',
  inv_waste: 'Spoilage & wastage',
  inv_count: 'Inventory count',
  petty_cash: 'Petty cash',
  bank: 'Bank transaction',
  bank_opening: 'Bank opening balance',
  ap_bill: 'Supplier bill',
  ap_payment: 'Supplier payment',
  ar_invoice: 'Customer invoice',
  ar_receipt: 'Customer collection',
  cash_advance: 'Cash advance release',
  ca_repayment: 'Cash advance repayment',
};

export const METHOD_LABELS = {
  cash: 'Cash on hand',
  petty_cash: 'Petty cash fund',
  bank: 'Bank account',
  payroll: 'Salary deduction (payroll)',
};

export const bankLabel = (b) => `${b.bank_name}${b.account_no ? ' ••' + String(b.account_no).slice(-4) : ''}${b.account_name ? ' – ' + b.account_name : ''}`;
export const bankOptions = (banks) => (banks || []).filter((b) => b.active).map((b) => ({ value: b.id, label: bankLabel(b) }));
export const accountLabel = (a) => (a ? `${a.code} · ${a.name}` : '');
export const voidRow = (r) => (r.status === 'void' || r.active === 0 ? 'muted-row' : '');
/** Sum `key` over posted (non-void) rows — for DataTable totals. */
export const sumPosted = (key) => (rows) => rows.reduce((s, r) => s + (r.status === 'void' ? 0 : Number(r[key]) || 0), 0);

/** Tiny form state helper: const [f, set, setF] = useForm({...}); <Input onChange={set('name')}/> <Select onChange={set('x')}/> */
export function useForm(initial) {
  const [f, setF] = useState(initial);
  const set = (k) => (v) => setF((s) => ({ ...s, [k]: v && v.target ? (v.target.type === 'checkbox' ? v.target.checked : v.target.value) : v }));
  return [f, set, setF];
}

/** Ask for a void reason and run fn(reason). Returns true when done. */
export async function confirmVoid(dialog, toast, { title, message, run, done = 'Voided' }) {
  const r = await dialog({ title, message, input: 'Reason', required: true, danger: true, okText: 'Void' });
  if (!r) return false;
  try {
    await run(r.value);
    toast(done);
    return true;
  } catch (e) {
    toast(e.message, 'error');
    return false;
  }
}

/** Payment / funding source: method select + bank select when method is bank. */
export function PayMethodFields({ methods, method, onMethod, bankId, onBank, banks, label = 'Paid from' }) {
  return (
    <>
      <Field label={label}>
        <Select options={methods.map((m) => ({ value: m, label: METHOD_LABELS[m] || m }))} value={method} onChange={onMethod} />
      </Field>
      {method === 'bank' && (
        <Field label="Bank account" hint={!bankOptions(banks).length ? 'No bank accounts yet — add one under Banks.' : undefined}>
          <Select placeholder="Select bank…" options={bankOptions(banks)} value={bankId} onChange={onBank} />
        </Field>
      )}
    </>
  );
}

/**
 * Searchable account picker grouped by type.
 * accounts: rows from GET /finance/accounts; value: account id; onChange(idString, account)
 * types: limit to these account types; suggest: system_keys or codes shown first; exclude: ids to hide.
 */
export function AccountPicker({ accounts, value, onChange, types, suggest = [], exclude = [], placeholder = 'Type code or name…', autoFocus }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [hi, setHi] = useState(0);
  const [pos, setPos] = useState(null);
  const inputRef = useRef(null);
  const listRef = useRef(null);

  const selected = (accounts || []).find((a) => String(a.id) === String(value));
  const pool = useMemo(
    () => (accounts || []).filter((a) => a.active && (!types || types.includes(a.type)) && !exclude.map(String).includes(String(a.id))),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [accounts, (types || []).join(), exclude.join()]
  );
  const items = useMemo(() => {
    const s = q.trim().toLowerCase();
    const match = s ? pool.filter((a) => a.code.toLowerCase().startsWith(s) || a.name.toLowerCase().includes(s)) : pool;
    const out = [];
    if (!s) {
      const sug = suggest.map((k) => pool.find((a) => a.system_key === k || a.code === k)).filter(Boolean);
      if (sug.length) out.push({ header: 'Suggested' }, ...sug.map((a) => ({ a, key: 's' + a.id })));
    }
    for (const t of ACCOUNT_TYPES) {
      const g = match.filter((a) => a.type === t);
      if (g.length) out.push({ header: TYPE_LABELS[t] }, ...g.map((a) => ({ a, key: a.id })));
    }
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pool, q, suggest.join()]);
  const options = items.filter((i) => i.a);

  const show = () => {
    const r = inputRef.current.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    const up = below < 280 && r.top > below;
    setPos({ left: r.left, width: Math.max(r.width, 320), ...(up ? { bottom: window.innerHeight - r.top + 2 } : { top: r.bottom + 2 }), maxHeight: Math.min(320, (up ? r.top : below) - 12) });
    setOpen(true);
    setHi(0);
  };
  const hide = () => { setOpen(false); setQ(''); };
  const pick = (a) => { onChange(String(a.id), a); hide(); };

  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (!inputRef.current?.contains(e.target) && !listRef.current?.contains(e.target)) hide(); };
    const onScroll = (e) => { if (!listRef.current?.contains(e.target)) hide(); };
    document.addEventListener('mousedown', onDown);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', hide);
    return () => {
      document.removeEventListener('mousedown', onDown);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', hide);
    };
  }, [open]);
  useEffect(() => { setHi(0); }, [q]);
  useEffect(() => {
    if (open && listRef.current) listRef.current.querySelector(`[data-i="${hi}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [hi, open]);

  const onKey = (e) => {
    if (!open) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') { e.preventDefault(); show(); }
      return;
    }
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi((h) => Math.min(h + 1, options.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); if (options[hi]) pick(options[hi].a); }
    else if (e.key === 'Escape') { e.stopPropagation(); hide(); }
    else if (e.key === 'Tab') hide();
  };

  let idx = -1;
  return (
    <>
      <input
        ref={inputRef} className="input" autoFocus={autoFocus} placeholder={selected ? accountLabel(selected) : placeholder}
        value={open ? q : accountLabel(selected)} onChange={(e) => { if (!open) show(); setQ(e.target.value); }}
        onFocus={show} onClick={() => !open && show()} onKeyDown={onKey} autoComplete="off"
      />
      {open && pos && (
        <div ref={listRef} style={{
          position: 'fixed', zIndex: 300, left: pos.left, top: pos.top, bottom: pos.bottom, width: pos.width, maxHeight: pos.maxHeight,
          overflowY: 'auto', background: 'var(--surface)', border: '1px solid var(--border-strong)', borderRadius: 'var(--radius-sm)', boxShadow: 'var(--shadow-lg)',
        }}>
          {!options.length && <div className="pad muted small">No matching account</div>}
          {items.map((it) => {
            if (it.header) {
              return <div key={'h' + it.header} className="small bold muted" style={{ padding: '6px 10px 2px', textTransform: 'uppercase', letterSpacing: '.04em', fontSize: 11 }}>{it.header}</div>;
            }
            idx += 1;
            const i = idx;
            const active = i === hi;
            return (
              <div key={it.key} data-i={i} onMouseDown={(e) => { e.preventDefault(); pick(it.a); }} onMouseEnter={() => setHi(i)}
                style={{ padding: '6px 10px', cursor: 'pointer', display: 'flex', gap: 8, background: active ? 'var(--brand-soft)' : String(it.a.id) === String(value) ? 'var(--gray-soft)' : undefined }}>
                <span className="mono muted" style={{ minWidth: 44 }}>{it.a.code}</span>
                <span>{it.a.name}</span>
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}

/** Small label/value list used in detail modals. */
export function InfoGrid({ items }) {
  return (
    <div className="form-grid mb">
      {items.filter(Boolean).map(([label, value]) => (
        <div key={label} className="field">
          <span className="field-label">{label}</span>
          <span>{value === null || value === undefined || value === '' ? <span className="muted">—</span> : value}</span>
        </div>
      ))}
    </div>
  );
}
