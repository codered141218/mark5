import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { peso, qty, fmtDate, fmtDateTime, today, ITEM_TYPE_SHORT } from '../../format';
import {
  Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Select, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import { SearchSelect, cost4, factorOf, n, r2, r4, useUnitsMap } from './shared';
import { DOC_TYPES, ISSUE_TO, PAYMENT_LABEL, PAYMENT_MODES, WASTE_REASONS } from './docTypes';

let keySeq = 0;
const blankLine = () => ({ key: ++keySeq, item_id: '', qty: '', uom_id: '', unit_cost: '', line_total: '', notes: '', auto: false });
const fromServerLine = (l) => ({
  key: ++keySeq, item_id: String(l.item_id), qty: String(l.qty), uom_id: String(l.uom_id ?? ''), unit_cost: String(l.unit_cost ?? ''),
  line_total: String(l.line_total ?? ''), notes: l.notes || '', auto: false, saved_total: l.line_total, item_name: l.item_name, sku: l.sku,
});
const focusCell = (key, col) => setTimeout(() => {
  const el = document.querySelector(`[data-cell="${key}-${col}"]`);
  if (el) { el.focus(); if (el.select) el.select(); }
}, 0);

export default function InvDocEdit({ type }) {
  const cfg = DOC_TYPES[type];
  const { id } = useParams();
  const isNew = id === 'new';
  const navigate = useNavigate();
  const toast = useToast();
  const dialog = useDialog();
  const { can, settings } = useAuth();
  const isRec = type === 'RECEIVE';

  const [doc, setDoc] = useState(null);
  const [head, setHead] = useState(null);
  const [lines, setLines] = useState([blankLine()]);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState('');
  const [addSup, setAddSup] = useState(null);
  const acctTouched = useRef(false);

  const loaded = useApi(() => (isNew ? Promise.resolve(null) : api.get(`/inventory/docs/${id}`)), [id]);
  const items = useApi(() => api.get('/inventory/items', { type: isRec ? 'raw,retail' : 'raw,retail,composite' }), [type]);
  const suppliers = useApi(() => (isRec ? api.get('/finance/suppliers') : Promise.resolve([])), [type]);
  const banks = useApi(() => (isRec ? api.get('/finance/banks').catch(() => []) : Promise.resolve([])), [type]);
  const accounts = useApi(() => (type === 'ISSUE' ? api.get('/finance/accounts') : Promise.resolve([])), [type]);

  const expenseAccts = useMemo(() => (accounts.data || []).filter((a) => a.type === 'expense' && a.active), [accounts.data]);
  const acctByKey = (k) => expenseAccts.find((a) => a.system_key === k);

  // initialise from server doc / defaults
  useEffect(() => {
    if (isNew) {
      setDoc(null);
      setHead({ doc_date: today(), supplier_id: '', invoice_no: '', payment_mode: 'credit', bank_account_id: '', vat_inclusive: false, issued_to: '', reason: type === 'WASTE' ? 'spoilage' : '', expense_account_id: '', notes: '' });
      setLines([blankLine()]);
      setDirty(false);
      acctTouched.current = false;
      return;
    }
    const d = loaded.data;
    if (!d) return;
    setDoc(d);
    setHead({
      doc_date: d.doc_date, supplier_id: d.supplier_id ? String(d.supplier_id) : '', invoice_no: d.invoice_no || '', payment_mode: d.payment_mode || 'credit',
      bank_account_id: d.bank_account_id ? String(d.bank_account_id) : '', vat_inclusive: !!d.vat_inclusive, issued_to: d.issued_to || '', reason: d.reason || '',
      expense_account_id: d.expense_account_id ? String(d.expense_account_id) : '', notes: d.notes || '',
    });
    const ls = d.lines.map(fromServerLine);
    setLines(d.status === 'draft' ? [...ls, blankLine()] : ls);
    setDirty(false);
    acctTouched.current = true;
  }, [loaded.data, isNew, type]);

  // default expense account for issuance
  useEffect(() => {
    if (type !== 'ISSUE' || !head || head.expense_account_id || !expenseAccts.length) return;
    const def = acctByKey('supplies') || expenseAccts[0];
    if (def) setHead((h) => ({ ...h, expense_account_id: String(def.id) }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [expenseAccts, head, type]);

  const editable = isNew || (doc && doc.status === 'draft');
  const byId = useMemo(() => Object.fromEntries((items.data || []).map((i) => [String(i.id), i])), [items.data]);
  const unitsMap = useUnitsMap(lines.map((l) => l.item_id));
  const itemOptions = useMemo(() => {
    const opts = (items.data || []).map((i) => ({
      value: String(i.id), label: i.name, search: i.sku,
      sub: `${ITEM_TYPE_SHORT[i.item_type]}${['raw', 'retail'].includes(i.item_type) ? ` · ${qty(i.stock_qty)} ${i.uom || ''} on hand` : ' · recipe'}`,
    }));
    for (const l of lines) if (l.item_id && !byId[l.item_id] && l.item_name) opts.push({ value: l.item_id, label: l.item_name, sub: 'inactive' });
    return opts;
  }, [items.data, lines, byId]);

  const setH = (k) => (v) => { setHead((h) => ({ ...h, [k]: v })); setDirty(true); };

  // ---- line editing
  const baseCost = (it) => n(it?.last_cost) || n(it?.avg_cost) || n(it?.unit_cost);
  const update = (key, fn) => { setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...fn(l) } : l))); setDirty(true); };
  const pickItem = (key, value) => {
    const it = byId[value];
    setLines((ls) => {
      const out = ls.map((l) => {
        if (l.key !== key) return l;
        const nl = { ...l, item_id: value, uom_id: it ? String(it.base_uom_id ?? '') : '' };
        if (isRec) {
          const c = baseCost(it);
          nl.unit_cost = c ? String(r4(c)) : '';
          nl.auto = !!c;
          nl.line_total = nl.qty && c ? String(r2(n(nl.qty) * c)) : '';
        }
        return nl;
      });
      return out[out.length - 1].item_id ? [...out, blankLine()] : out;
    });
    setDirty(true);
    focusCell(key, 'qty');
  };
  const setQty = (key, v) => update(key, (l) => (isRec && l.unit_cost !== '' ? { qty: v, line_total: String(r2(n(v) * n(l.unit_cost))) } : { qty: v }));
  const setCost = (key, v) => update(key, (l) => ({ unit_cost: v, auto: false, line_total: l.qty !== '' && v !== '' ? String(r2(n(l.qty) * n(v))) : l.line_total }));
  const setTotal = (key, v) => update(key, (l) => ({ line_total: v, auto: false, unit_cost: n(l.qty) ? String(r4(n(v) / n(l.qty))) : l.unit_cost }));
  const setUom = (key, v) => update(key, (l) => {
    if (!isRec || !l.auto) return { uom_id: v };
    const f = factorOf(unitsMap[l.item_id], v) ?? 1;
    const c = r4(baseCost(byId[l.item_id]) * f);
    return { uom_id: v, unit_cost: String(c), line_total: l.qty !== '' ? String(r2(n(l.qty) * c)) : l.line_total };
  });
  const removeLine = (key) => {
    setLines((ls) => { const out = ls.filter((l) => l.key !== key); return out.length && !out[out.length - 1].item_id ? out : [...out, blankLine()]; });
    setDirty(true);
  };
  const enterNext = (e, key) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const idx = lines.findIndex((l) => l.key === key);
    const next = lines[idx + 1];
    if (next) focusCell(next.key, 'item');
  };

  const estCost = (l) => {
    if (isRec) return n(l.line_total);
    if (!editable) return n(l.saved_total);
    const it = byId[l.item_id];
    const f = factorOf(unitsMap[l.item_id], l.uom_id || it?.base_uom_id);
    if (it && f !== null) return n(l.qty) * f * n(it.unit_cost);
    return n(l.saved_total);
  };
  const realLines = lines.filter((l) => l.item_id);
  const total = editable ? realLines.reduce((s, l) => s + estCost(l), 0) : doc?.total_cost || 0;
  const vatRate = settings?.vat_registered === '1' ? n(settings?.vat_rate ?? 12) / 100 : 0;
  const inputVat = isRec && head?.vat_inclusive && vatRate ? total - total / (1 + vatRate) : 0;

  // ---- actions
  const body = (post) => ({
    doc_type: type, ...head, post: post || undefined,
    lines: realLines.map((l) => ({ item_id: l.item_id, qty: l.qty, uom_id: l.uom_id || null, notes: l.notes || null, ...(isRec ? { unit_cost: l.unit_cost, line_total: l.line_total } : {}) })),
  });
  const validate = () => {
    if (!realLines.length) { toast('Add at least one item', 'error'); return false; }
    const bad = realLines.find((l) => !(n(l.qty) > 0));
    if (bad) { toast(`Enter a quantity for "${byId[bad.item_id]?.name || bad.item_name}"`, 'error'); focusCell(bad.key, 'qty'); return false; }
    if (isRec && head.payment_mode === 'credit' && !head.supplier_id) { toast('Choose the supplier for a delivery on credit', 'error'); return false; }
    if (isRec && head.payment_mode === 'bank' && !head.bank_account_id) { toast('Choose the bank account', 'error'); return false; }
    return true;
  };
  const confirmPost = () => {
    const acct = expenseAccts.find((a) => String(a.id) === String(head.expense_account_id));
    const msg = {
      RECEIVE: `Posting adds these quantities to stock, updates the average cost and records the journal entry (Inventory${inputVat ? ' + Input VAT' : ''} against ${PAYMENT_LABEL[head.payment_mode]}).${head.payment_mode === 'credit' ? ' A payable bill is created for the supplier.' : ''}`,
      ISSUE: `Posting deducts these items from stock at average cost (menu items are broken down into their recipe ingredients) and charges ${acct ? acct.name : 'the expense account'} in the general ledger.`,
      WASTE: 'Posting deducts these items from stock at average cost (menu items are broken down into their recipe ingredients) and books the cost to Spoilage & Wastage expense.',
    }[type];
    return dialog({ title: `Post ${doc?.doc_no || 'document'}?`, message: `${msg} Total: ${peso(total)}. Posted documents can no longer be edited, only voided.`, okText: 'Post' });
  };

  const save = async (post) => {
    if (!validate()) return;
    if (post && !(await confirmPost())) return;
    setBusy(post ? 'post' : 'save');
    try {
      const d = isNew ? await api.post('/inventory/docs', body(post)) : await api.put(`/inventory/docs/${id}`, body(post));
      toast(post ? `${d.doc_no} posted` : `${d.doc_no} saved as draft`);
      setDirty(false);
      if (isNew) navigate(`${cfg.basePath}/${d.id}`, { replace: true });
      else loaded.setData(d);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(''); }
  };
  const postSaved = async () => {
    if (!(await confirmPost())) return;
    setBusy('post');
    try {
      const d = await api.post(`/inventory/docs/${id}/post`);
      toast(`${d.doc_no} posted`);
      loaded.setData(d);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(''); }
  };
  const remove = async () => {
    if (!(await dialog({ title: 'Delete draft', message: `Delete draft ${doc.doc_no}? This cannot be undone.`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/inventory/docs/${id}`);
      toast('Draft deleted');
      navigate(cfg.basePath);
    } catch (e) { toast(e.message, 'error'); }
  };
  const voidDoc = async () => {
    const r = await dialog({
      title: `Void ${doc.doc_no}?`, danger: true, okText: 'Void', input: 'Reason', required: true,
      message: `Voiding reverses the stock movements and the journal entry${doc.ap_bill_id ? ' and cancels the supplier payable' : ''}. This cannot be undone.`,
    });
    if (!r) return;
    setBusy('void');
    try {
      const d = await api.post(`/inventory/docs/${id}/void`, { reason: r.value });
      toast(`${d.doc_no} voided`);
      loaded.setData(d);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(''); }
  };
  const back = async () => {
    if (dirty && editable && !(await dialog({ title: 'Discard changes?', message: 'You have unsaved changes on this document.', danger: true, okText: 'Discard' }))) return;
    navigate(cfg.basePath);
  };

  if (!isNew && loaded.loading && !loaded.data) return <Loading />;
  if (loaded.error) return <ErrorBox error={loaded.error} />;
  if (!head || (!isNew && !doc)) return <Loading />;

  const unitOpts = (l) => {
    const u = unitsMap[l.item_id] || [];
    const o = u.map((x) => ({ value: String(x.uom_id), label: x.abbr }));
    if (l.uom_id && !o.some((x) => x.value === l.uom_id)) o.push({ value: l.uom_id, label: byId[l.item_id]?.uom || '…' });
    return o;
  };
  const canPost = can('inventory.post');

  return (
    <div className="stack">
      <PageHeader
        title={isNew ? cfg.newTitle : <>{doc.doc_no} <Badge>{doc.status}</Badge></>}
        subtitle={isNew ? cfg.subtitle : `${cfg.title} · ${fmtDate(doc.doc_date)}${doc.created_by_name ? ` · prepared by ${doc.created_by_name}` : ''}`}
        actions={<>
          <Button onClick={back}>← Back</Button>
          {!isNew && doc.status === 'posted' && canPost && <Button variant="danger" loading={busy === 'void'} onClick={voidDoc}>Void</Button>}
        </>} />

      {!isNew && doc.status !== 'draft' && (
        <div className={`alert ${doc.status === 'posted' ? 'alert-success' : 'alert-warn'}`}>
          <PostedInfo doc={doc} can={can} />
        </div>
      )}

      <Card title="Details">
        {editable ? (
          <div className="form-grid">
            <Field label="Date *"><Input type="date" value={head.doc_date} onChange={(e) => setH('doc_date')(e.target.value)} /></Field>
            {isRec && <>
              <Field label={`Supplier${head.payment_mode === 'credit' ? ' *' : ''}`} span={2}>
                <div className="row gap-sm">
                  <div className="grow">
                    <SearchSelect options={(suppliers.data || []).map((s) => ({ value: String(s.id), label: s.name, sub: s.terms_days ? `${s.terms_days} days` : 'COD' }))}
                      value={head.supplier_id} onChange={setH('supplier_id')} placeholder="Search supplier…" />
                  </div>
                  <Button type="button" onClick={(e) => { e.preventDefault(); setAddSup({ name: '', terms_days: '0' }); }} title="Add a new supplier">+ New</Button>
                </div>
              </Field>
              <Field label="Supplier invoice / DR no."><Input value={head.invoice_no} onChange={(e) => setH('invoice_no')(e.target.value)} /></Field>
              <Field label="Payment" span={2}><Select options={PAYMENT_MODES} value={head.payment_mode} onChange={setH('payment_mode')} /></Field>
              {head.payment_mode === 'bank' && (
                <Field label="Bank account *">
                  <Select options={(banks.data || []).filter((b) => b.active).map((b) => ({ value: String(b.id), label: `${b.bank_name}${b.account_no ? ' ' + String(b.account_no).slice(-4) : ''}` }))}
                    value={head.bank_account_id} onChange={setH('bank_account_id')} placeholder="— Choose bank —" />
                </Field>
              )}
              <div className="field" style={{ gridColumn: 'span 2', justifyContent: 'flex-end' }}>
                <Checkbox checked={head.vat_inclusive} onChange={setH('vat_inclusive')} label="Supplier price is VAT-inclusive (claim input VAT)" />
                <span className="field-hint">Tick only for VAT-registered suppliers issuing a VAT invoice.</span>
              </div>
            </>}
            {type === 'ISSUE' && <>
              <Field label="Issued to">
                <Input list="inv-issue-to" value={head.issued_to} placeholder="e.g. Kitchen"
                  onChange={(e) => {
                    const v = e.target.value;
                    setH('issued_to')(v);
                    if (!acctTouched.current) {
                      const a = acctByKey(/staff/i.test(v) ? 'staff_meals' : 'supplies');
                      if (a) setHead((h) => ({ ...h, issued_to: v, expense_account_id: String(a.id) }));
                    }
                  }} />
                <datalist id="inv-issue-to">{ISSUE_TO.map((x) => <option key={x} value={x} />)}</datalist>
              </Field>
              <Field label="Reason / purpose"><Input value={head.reason} onChange={(e) => setH('reason')(e.target.value)} placeholder="e.g. Daily kitchen requisition" /></Field>
              <Field label="Charge to expense account" span={2}>
                <Select options={expenseAccts.map((a) => ({ value: String(a.id), label: `${a.code} · ${a.name}` }))} value={head.expense_account_id}
                  onChange={(v) => { acctTouched.current = true; setH('expense_account_id')(v); }} />
              </Field>
            </>}
            {type === 'WASTE' && (
              <Field label="Reason">
                <Select options={WASTE_REASONS.map((r) => ({ value: r, label: r[0].toUpperCase() + r.slice(1) }))} value={head.reason} onChange={setH('reason')} />
              </Field>
            )}
            <Field label="Notes" span={2}><Textarea rows={1} value={head.notes} onChange={(e) => setH('notes')(e.target.value)} /></Field>
          </div>
        ) : (
          <ReadHead type={type} doc={doc} />
        )}
      </Card>

      <Card title="Items" pad={false} actions={editable && <span className="muted small">Type to search · Enter to move to the next line</span>}>
        <div className="table-wrap">
          <table className="inv-grid">
            <thead>
              <tr>
                <th className="idx">#</th>
                <th style={{ minWidth: 240 }}>Item</th>
                <th className="num" style={{ width: 110 }}>Qty</th>
                <th style={{ width: 100 }}>Unit</th>
                {isRec ? <>
                  <th className="num" style={{ width: 130 }}>Unit cost</th>
                  <th className="num" style={{ width: 130 }}>Line total</th>
                </> : <th className="num" style={{ width: 130 }}>{editable ? 'Est. cost' : 'Cost'}</th>}
                <th style={{ minWidth: 140 }}>Notes</th>
                {editable && <th style={{ width: 36 }} />}
              </tr>
            </thead>
            <tbody>
              {lines.map((l, idx) => {
                const it = byId[l.item_id];
                const isBlank = !l.item_id;
                if (!editable) {
                  const sl = doc.lines[idx];
                  return (
                    <tr key={l.key}>
                      <td className="idx">{idx + 1}</td>
                      <td><b>{sl.item_name}</b> <span className="muted small">{sl.sku}{sl.item_type === 'composite' ? ' · recipe' : ''}</span></td>
                      <td className="num">{qty(sl.qty)}</td>
                      <td>{sl.uom}{sl.uom !== sl.base_uom && <span className="muted small"> ({qty(sl.base_qty)} {sl.base_uom})</span>}</td>
                      {isRec && <td className="num">{cost4(sl.unit_cost)}</td>}
                      <td className="num">{peso(sl.line_total)}</td>
                      <td className="muted">{sl.notes}</td>
                    </tr>
                  );
                }
                const est = estCost(l);
                return (
                  <tr key={l.key}>
                    <td className="idx">{idx + 1}</td>
                    <td>
                      <SearchSelect options={itemOptions} value={l.item_id} onChange={(v) => pickItem(l.key, v)} placeholder={isBlank ? '+ Add item…' : 'Search item…'}
                        ref={(el) => { if (el) el.dataset.cell = `${l.key}-item`; }} />
                      {it && it.item_type === 'composite' && <div className="muted small" style={{ marginTop: 2 }}>Recipe item: deducts its ingredients</div>}
                    </td>
                    <td><NumberInput data-cell={`${l.key}-qty`} value={l.qty} onChange={(v) => setQty(l.key, v)} disabled={isBlank} onKeyDown={(e) => !isRec && enterNext(e, l.key)} /></td>
                    <td><Select options={unitOpts(l)} value={l.uom_id} onChange={(v) => setUom(l.key, v)} disabled={isBlank} /></td>
                    {isRec ? <>
                      <td><NumberInput value={l.unit_cost} onChange={(v) => setCost(l.key, v)} disabled={isBlank} title={l.auto ? 'Pre-filled from last cost' : undefined} /></td>
                      <td><NumberInput value={l.line_total} onChange={(v) => setTotal(l.key, v)} disabled={isBlank} onKeyDown={(e) => enterNext(e, l.key)} /></td>
                    </> : <td className="num">{isBlank ? '' : peso(est)}</td>}
                    <td><Input value={l.notes} onChange={(e) => update(l.key, () => ({ notes: e.target.value }))} disabled={isBlank} onKeyDown={(e) => enterNext(e, l.key)} /></td>
                    <td>{!isBlank && <button type="button" className="icon-btn rm" title="Remove line" onClick={() => removeLine(l.key)}>✕</button>}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={isRec ? 5 : 4}>
                  {realLines.length} item{realLines.length === 1 ? '' : 's'}
                  {inputVat > 0 && <span className="muted small" style={{ fontWeight: 400, marginLeft: 12 }}>incl. input VAT {peso(inputVat)} · net {peso(total - inputVat)}</span>}
                  {!isRec && editable && <span className="muted small" style={{ fontWeight: 400, marginLeft: 12 }}>Estimated at current average cost; final cost is set when posted.</span>}
                </td>
                <td className="num">{peso(total)}</td>
                <td colSpan={editable ? 2 : 1} />
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>

      {editable && (
        <div className="row gap-sm wrap sticky-actions">
          <Button loading={busy === 'save'} disabled={!!busy} onClick={() => save(false)}>Save draft</Button>
          {canPost && (!isNew && !dirty
            ? <Button variant="success" loading={busy === 'post'} disabled={!!busy} onClick={postSaved}>Post</Button>
            : <Button variant="success" loading={busy === 'post'} disabled={!!busy} onClick={() => save(true)}>Save & Post</Button>)}
          {!canPost && <span className="muted small">A manager with posting rights must post this document.</span>}
          <div className="grow" />
          {dirty && <span className="muted small">Unsaved changes</span>}
          {!isNew && <Button variant="ghost" onClick={remove}>Delete draft</Button>}
        </div>
      )}

      {addSup && (
        <QuickSupplier value={addSup} setValue={setAddSup} onSaved={async (sid) => {
          await suppliers.reload();
          setH('supplier_id')(String(sid));
          setAddSup(null);
        }} />
      )}
    </div>
  );
}

function ReadHead({ type, doc }) {
  return (
    <div className="kv">
      <span>Date</span><span>{fmtDate(doc.doc_date)}</span>
      {type === 'RECEIVE' && <>
        <span>Supplier</span><b>{doc.supplier_name || '—'}</b>
        <span>Invoice / DR no.</span><span>{doc.invoice_no || '—'}</span>
        <span>Payment</span><span>{PAYMENT_LABEL[doc.payment_mode] || doc.payment_mode}{doc.payment_mode === 'bank' && doc.bank_name ? ` — ${doc.bank_name}` : ''}</span>
        <span>VAT</span><span>{doc.vat_inclusive ? 'VAT-inclusive (input VAT claimed)' : 'No input VAT'}</span>
      </>}
      {type === 'ISSUE' && <>
        <span>Issued to</span><b>{doc.issued_to || '—'}</b>
        <span>Reason</span><span>{doc.reason || '—'}</span>
        <span>Expense account</span><span>{doc.expense_account_name}</span>
      </>}
      {type === 'WASTE' && <><span>Reason</span><span style={{ textTransform: 'capitalize' }}>{doc.reason}</span></>}
      {doc.notes && <><span>Notes</span><span>{doc.notes}</span></>}
    </div>
  );
}

function PostedInfo({ doc, can }) {
  const bill = useApi(() => (doc.ap_bill_id && can('finance.ap') ? api.get(`/finance/ap/bills/${doc.ap_bill_id}`).catch(() => null) : Promise.resolve(null)), [doc.ap_bill_id]);
  if (doc.status === 'cancelled') return <span><b>Voided.</b> Stock and journal entries were reversed.</span>;
  const b = bill.data;
  return (
    <div className="row wrap gap">
      <span><b>Posted</b>{doc.posted_by_name ? ` by ${doc.posted_by_name}` : ''} {doc.posted_at && `on ${fmtDateTime(doc.posted_at)}`}</span>
      {doc.journal_entry_id && (
        <span>Journal entry {can('finance.view') ? <Link to="/finance/journals">#{doc.journal_entry_id}</Link> : `#${doc.journal_entry_id}`}</span>
      )}
      {doc.ap_bill_id && (
        <span>
          Payable {b ? <>
            {can('finance.ap') ? <Link to="/finance/payables">{b.bill_no}</Link> : b.bill_no} — {peso(b.amount)}, due {fmtDate(b.due_date)} <Badge>{b.status}</Badge>
          </> : `bill #${doc.ap_bill_id}`} created
        </span>
      )}
    </div>
  );
}

function QuickSupplier({ value, setValue, onSaved }) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const save = async (e) => {
    e?.preventDefault();
    if (!value.name.trim()) return toast('Enter the supplier name', 'error');
    setSaving(true);
    try {
      const r = await api.post('/finance/suppliers', { name: value.name.trim(), terms_days: value.terms_days });
      toast('Supplier added');
      await onSaved(r.id);
    } catch (err) { toast(err.message, 'error'); } finally { setSaving(false); }
  };
  return (
    <Modal title="New supplier" onClose={() => setValue(null)} width={420}
      footer={<><Button onClick={() => setValue(null)}>Cancel</Button><Button variant="primary" loading={saving} onClick={save}>Add supplier</Button></>}>
      <form className="form-grid" onSubmit={save}>
        <Field label="Supplier name *" span={2}><Input autoFocus value={value.name} onChange={(e) => setValue({ ...value, name: e.target.value })} /></Field>
        <Field label="Payment terms (days)" hint="0 = cash on delivery"><NumberInput value={value.terms_days} onChange={(v) => setValue({ ...value, terms_days: v })} /></Field>
        <button type="submit" hidden />
      </form>
      <p className="muted small">Add contact details later under Suppliers & Customers.</p>
    </Modal>
  );
}
