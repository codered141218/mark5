import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api, exportExcel } from '../../api';
import { useAuth } from '../../auth';
import { peso, qty, money, fmtDate, fmtDateTime } from '../../format';
import { Badge, Button, Card, Checkbox, ErrorBox, Loading, NumberInput, PageHeader, Stat, useApi, useDialog, useToast } from '../../components/ui';
import { PrintArea, cost4, n, r2, r4 } from './shared';

const varClass = (v) => (v === null || Math.abs(v) < 0.00005 ? '' : v < 0 ? 'text-red' : 'text-green');
const signed = (v, f = qty) => (v === null ? '' : `${v > 0 ? '+' : ''}${f(v)}`);

export default function CountSheet() {
  const { id } = useParams();
  const navigate = useNavigate();
  const toast = useToast();
  const dialog = useDialog();
  const { can, settings } = useAuth();
  const { data: s, loading, error, setData } = useApi(() => api.get(`/inventory/counts/${id}`), [id]);
  const [vals, setVals] = useState({});
  const [q, setQ] = useState('');
  const [onlyUncounted, setOnlyUncounted] = useState(false);
  const [onlyVar, setOnlyVar] = useState(false);
  const [busy, setBusy] = useState('');
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    if (s) setVals(Object.fromEntries(s.lines.map((l) => [l.id, l.counted_qty === null ? '' : String(l.counted_qty)])));
  }, [s]);
  useEffect(() => {
    if (!printing) return undefined;
    const t = setTimeout(() => { window.print(); setPrinting(false); }, 50);
    return () => clearTimeout(t);
  }, [printing]);

  const open = s?.status === 'open';
  const rows = useMemo(() => (s ? s.lines.map((l) => {
    if (!open) {
      return { ...l, sys: l.system_qty, counted: l.counted_qty, var: l.variance, value: l.variance_value, cost: l.unit_cost };
    }
    const v = vals[l.id];
    const counted = v === '' || v === undefined ? null : n(v);
    const variance = counted === null ? null : r4(counted - l.current_qty);
    return { ...l, sys: l.current_qty, counted, var: variance, value: variance === null ? null : r2(variance * l.current_cost), cost: l.current_cost };
  }) : []), [s, vals, open]);

  const dirtyLines = s && open ? s.lines.filter((l) => (l.counted_qty === null ? '' : String(l.counted_qty)) !== (vals[l.id] ?? '')) : [];
  const dirty = dirtyLines.length > 0;

  const shown = rows.filter((r) => {
    if (onlyUncounted && r.counted !== null) return false;
    if (onlyVar && !(r.var !== null && Math.abs(r.var) > 0.00005)) return false;
    if (q) {
      const t = q.toLowerCase();
      if (!`${r.item_name} ${r.sku} ${r.category_name || ''}`.toLowerCase().includes(t)) return false;
    }
    return true;
  });
  const groups = useMemo(() => {
    const out = [];
    for (const r of shown) {
      const g = r.category_name || 'Uncategorized';
      if (!out.length || out[out.length - 1].name !== g) out.push({ name: g, rows: [] });
      out[out.length - 1].rows.push(r);
    }
    return out;
  }, [shown]);

  const totals = useMemo(() => {
    const t = { counted: 0, short: 0, over: 0, net: 0, sysValue: 0 };
    for (const r of rows) {
      if (r.counted !== null) t.counted++;
      if (r.value !== null && r.value !== undefined) {
        if (r.value < 0) t.short += r.value; else t.over += r.value;
        t.net += r.value;
      }
      t.sysValue += n(r.sys) * n(r.cost);
    }
    return t;
  }, [rows]);

  // ---- actions
  const save = async (quiet) => {
    if (!dirty) return s;
    setBusy('save');
    try {
      const fresh = await api.put(`/inventory/counts/${id}`, { lines: dirtyLines.map((l) => ({ id: l.id, counted_qty: vals[l.id] })) });
      setData(fresh);
      if (!quiet) toast('Count saved');
      return fresh;
    } catch (e) { toast(e.message, 'error'); return null; } finally { setBusy(''); }
  };
  const post = async () => {
    if (!totals.counted) return toast('Enter at least one counted quantity before posting', 'error');
    const ok = await dialog({
      title: `Post count ${s.doc_no}?`, okText: 'Post count',
      message: `This adjusts system stock to the counted quantities and books the variance (net ${peso(totals.net)}) to Inventory Variance in the general ledger. `
        + `${rows.length - totals.counted} item(s) left blank will not be adjusted. This cannot be undone.`,
    });
    if (!ok) return;
    if (dirty && !(await save(true))) return;
    setBusy('post');
    try {
      const fresh = await api.post(`/inventory/counts/${id}/post`);
      setData(fresh);
      toast('Count posted, stock adjusted');
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(''); }
  };
  const cancel = async () => {
    if (!(await dialog({ title: 'Cancel count session', message: 'Cancel this count? Entered quantities are kept for reference but stock will not be adjusted.', danger: true, okText: 'Cancel session' }))) return;
    try {
      await api.post(`/inventory/counts/${id}/cancel`);
      toast('Count session cancelled');
      setData(await api.get(`/inventory/counts/${id}`));
    } catch (e) { toast(e.message, 'error'); }
  };
  const back = async () => {
    if (dirty && !(await dialog({ title: 'Discard changes?', message: 'You have unsaved counts. Leave without saving?', danger: true, okText: 'Discard' }))) return;
    navigate('/inventory/counts');
  };
  const doExport = () => exportExcel({
    filename: `count-${s.doc_no}`, title: `Inventory Count ${s.doc_no}`, subtitle: `${fmtDate(s.count_date)} · ${s.category_name || 'All stocked items'} · ${s.status}`,
    columns: [
      { key: 'category_name', label: 'Category' }, { key: 'sku', label: 'SKU' }, { key: 'item_name', label: 'Item' }, { key: 'uom', label: 'Unit' },
      { key: 'sys', label: 'System qty', type: 'qty' }, { key: 'counted', label: 'Counted', type: 'qty' }, { key: 'var', label: 'Variance', type: 'qty' },
      { key: 'cost', label: 'Unit cost', type: 'money' }, { key: 'value', label: 'Variance value', type: 'money' },
    ],
    rows: shown, totals: { category_name: 'TOTAL', value: totals.net },
  }).catch((e) => toast(e.message, 'error'));

  const onEnter = (e) => {
    if (e.key !== 'Enter' && e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    e.preventDefault();
    const all = [...document.querySelectorAll('input[data-count]')];
    const i = all.indexOf(e.target);
    const next = all[i + (e.key === 'ArrowUp' ? -1 : 1)];
    if (next) next.focus();
  };

  if (loading && !s) return <Loading />;
  if (error) return <ErrorBox error={error} />;

  return (
    <div className="stack">
      <PageHeader title={<>{s.doc_no} <Badge>{s.status}</Badge></>}
        subtitle={`Count date ${fmtDate(s.count_date)} · ${s.category_name || 'All stocked items'}${s.created_by_name ? ` · started by ${s.created_by_name}` : ''}${s.notes ? ` · ${s.notes}` : ''}`}
        actions={<>
          <Button onClick={back}>← Back</Button>
          <Button onClick={() => setPrinting(true)}>🖶 Print sheet</Button>
          <Button onClick={doExport}>⬇ Excel</Button>
          {open && <Button variant="ghost" onClick={cancel}>Cancel session</Button>}
        </>} />

      {s.status === 'posted' && (
        <div className="alert alert-success">
          <b>Posted</b>{s.posted_by_name ? ` by ${s.posted_by_name}` : ''}{s.posted_at ? ` on ${fmtDateTime(s.posted_at)}` : ''}. Stock was adjusted to the counted quantities
          {s.journal_entry_id ? ` (journal entry #${s.journal_entry_id})` : ''}.
        </div>
      )}
      {s.status === 'cancelled' && <div className="alert alert-warn">This count session was cancelled. Stock was not adjusted.</div>}
      {open && <div className="alert alert-info">Enter the quantity physically counted for each item, in its base unit. System qty is live stock; leave an item blank to skip it. Save often.</div>}

      <div className="stats">
        <Stat label="Items counted" value={`${totals.counted} / ${rows.length}`} tone={totals.counted === rows.length ? 'green' : undefined} />
        <Stat label="Shortage value" value={peso(totals.short)} tone={totals.short < 0 ? 'red' : undefined} />
        <Stat label="Overage value" value={peso(totals.over)} tone={totals.over > 0 ? 'green' : undefined} />
        <Stat label="Net variance" value={peso(totals.net)} tone={totals.net < 0 ? 'red' : totals.net > 0 ? 'green' : undefined} sub={`system stock value ${peso(totals.sysValue)}`} />
      </div>

      <Card pad={false}>
        <div className="dt-toolbar">
          <input className="input dt-search" placeholder="Search item or SKU…" value={q} onChange={(e) => setQ(e.target.value)} />
          <Checkbox checked={onlyUncounted} onChange={setOnlyUncounted} label="Only uncounted" />
          <Checkbox checked={onlyVar} onChange={setOnlyVar} label="Only with variance" />
          <div className="grow" />
          <span className="muted small">{shown.length} of {rows.length} items</span>
        </div>
        <div className="table-wrap">
          <table className="inv-grid">
            <thead>
              <tr>
                <th>Item</th><th>SKU</th><th>Unit</th>
                <th className="num">{open ? 'System qty (live)' : 'System qty'}</th>
                <th className="num" style={{ width: 140 }}>Counted</th>
                <th className="num">Variance</th>
                <th className="num">Unit cost</th>
                <th className="num">Variance value</th>
              </tr>
            </thead>
            <tbody>
              {groups.map((g) => (
                <React.Fragment key={g.name}>
                  <tr className="group"><td colSpan={8}>{g.name} <span className="muted">({g.rows.length})</span></td></tr>
                  {g.rows.map((r) => (
                    <tr key={r.id} className={r.counted !== null ? 'counted' : ''}>
                      <td className="bold">{r.item_name}</td>
                      <td className="muted">{r.sku}</td>
                      <td>{r.uom}</td>
                      <td className="num">{qty(r.sys)}{open && Math.abs(r.current_qty - r.system_qty) > 0.00005 && <div className="muted small" title="Quantity when the count was started">start {qty(r.system_qty)}</div>}</td>
                      <td className="num">
                        {open
                          ? <NumberInput data-count="1" value={vals[r.id] ?? ''} onChange={(v) => setVals((x) => ({ ...x, [r.id]: v }))} onKeyDown={onEnter} placeholder="—" />
                          : r.counted === null ? <span className="muted small">not counted</span> : qty(r.counted)}
                      </td>
                      <td className={`num ${varClass(r.var)}`}>{signed(r.var)}</td>
                      <td className="num muted">{cost4(r.cost)}</td>
                      <td className={`num ${varClass(r.value)}`}>{r.value === null ? '' : signed(r.value, money)}</td>
                    </tr>
                  ))}
                </React.Fragment>
              ))}
              {!shown.length && <tr><td colSpan={8} className="muted center" style={{ padding: 24 }}>No items match.</td></tr>}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={7}>Net variance{shown.length !== rows.length ? ' (all items)' : ''}</td>
                <td className={`num ${varClass(totals.net)}`}>{peso(totals.net)}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>

      {open && (
        <div className="row gap-sm wrap sticky-actions">
          <Button variant="primary" loading={busy === 'save'} disabled={!dirty || !!busy} onClick={() => save(false)}>Save counts</Button>
          {can('inventory.post') && <Button variant="success" loading={busy === 'post'} disabled={!!busy} onClick={post}>Post count</Button>}
          {!can('inventory.post') && <span className="muted small">A manager with posting rights must post this count.</span>}
          <div className="grow" />
          {dirty && <span className="text-amber small bold">{dirtyLines.length} unsaved change{dirtyLines.length === 1 ? '' : 's'}</span>}
        </div>
      )}

      {printing && (
        <PrintArea>
          <div className="print-sheet">
            <h2>{settings?.business_name || 'Inventory'} — Count Sheet {s.doc_no}</h2>
            <div>Date: {fmtDate(s.count_date)} · Scope: {s.category_name || 'All stocked items'} · Status: {s.status}</div>
            <table>
              <thead><tr><th>Item</th><th>SKU</th><th>Unit</th><th className="num">System qty</th><th className="num blank">Counted</th>{!open && <th className="num">Variance</th>}</tr></thead>
              <tbody>
                {groups.map((g) => (
                  <React.Fragment key={g.name}>
                    <tr className="group"><td colSpan={open ? 5 : 6}>{g.name}</td></tr>
                    {g.rows.map((r) => (
                      <tr key={r.id}>
                        <td>{r.item_name}</td><td>{r.sku}</td><td>{r.uom}</td><td className="num">{qty(r.sys)}</td>
                        <td className="num blank">{r.counted === null ? '' : qty(r.counted)}</td>
                        {!open && <td className="num">{signed(r.var)}</td>}
                      </tr>
                    ))}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
            <p style={{ marginTop: 24 }}>Counted by: ____________________ &nbsp;&nbsp; Checked by: ____________________</p>
          </div>
        </PrintArea>
      )}
    </div>
  );
}
