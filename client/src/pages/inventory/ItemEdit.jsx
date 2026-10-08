import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { peso, qty, pct, ITEM_TYPES, ITEM_TYPE_SHORT } from '../../format';
import {
  Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Loading, NumberInput, PageHeader, Select, Stat, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';
import { SearchSelect, clearUnitsCache, cost4, factorOf, n, useUnitsMap } from './shared';

const STOCKED = ['raw', 'retail'];
const TYPE_HINT = {
  raw: 'Ingredient you buy and keep in stock (e.g. pork, rice, cooking oil). Not usually sold directly.',
  composite: 'Menu item or sub-recipe made from other items. Not stocked itself: selling it deducts its recipe ingredients.',
  retail: 'Bought and sold as-is and tracked in stock (e.g. bottled softdrinks, chips).',
  non_inventory: 'Sold but not tracked in stock (e.g. service fee, corkage, gift wrap).',
};
const EMPTY = {
  name: '', sku: '', barcode: '', category_id: '', item_type: 'raw', base_uom_id: '', price: '', sellable: false, active: true,
  color: '', sort_order: '0', description: '', reorder_point: '', reorder_qty: '', avg_cost: '',
};

function fcTone(p) {
  if (p === null || !Number.isFinite(p)) return undefined;
  return p > 45 ? 'red' : p > 35 ? 'amber' : 'green';
}

export default function ItemEdit() {
  const { id } = useParams();
  const isNew = id === 'new';
  const navigate = useNavigate();
  const toast = useToast();
  const dialog = useDialog();
  const { can, settings } = useAuth();
  const ro = !can('inventory.manage');

  const [form, setForm] = useState(EMPTY);
  const [uoms, setUoms] = useState([]); // alternate units [{uom_id, factor}]
  const [comps, setComps] = useState([]); // recipe [{component_id, qty, uom_id, cost}]
  const [saving, setSaving] = useState(false);

  const item = useApi(() => (isNew ? Promise.resolve(null) : api.get(`/inventory/items/${id}`)), [id]);
  const cats = useApi(() => api.get('/inventory/categories'), []);
  const units = useApi(() => api.get('/inventory/uoms'), []);
  const all = useApi(() => api.get('/inventory/items', { active: 'all' }), []);

  useEffect(() => {
    const i = item.data;
    if (isNew) { setForm(EMPTY); setUoms([]); setComps([]); return; }
    if (!i) return;
    setForm({
      name: i.name, sku: i.sku || '', barcode: i.barcode || '', category_id: i.category_id ?? '', item_type: i.item_type,
      base_uom_id: i.base_uom_id ?? '', price: String(i.price ?? ''), sellable: !!i.sellable, active: !!i.active, color: i.color || '',
      sort_order: String(i.sort_order ?? 0), description: i.description || '', reorder_point: String(i.reorder_point ?? ''),
      reorder_qty: String(i.reorder_qty ?? ''), avg_cost: String(i.avg_cost ?? ''),
    });
    setUoms(i.uoms.map((u) => ({ uom_id: String(u.uom_id), factor: String(u.factor) })));
    setComps(i.components.map((c) => ({ component_id: String(c.component_id), qty: String(c.qty), uom_id: String(c.uom_id ?? ''), cost: c.cost, saved: true })));
  }, [item.data, isNew]);

  const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));
  const stocked = STOCKED.includes(form.item_type);
  const composite = form.item_type === 'composite';
  const baseAbbr = (units.data || []).find((u) => String(u.id) === String(form.base_uom_id))?.abbr || 'base unit';
  const uomOptions = (units.data || []).map((u) => ({ value: u.id, label: `${u.name} (${u.abbr})` }));

  // ---- recipe costing
  const byId = useMemo(() => Object.fromEntries((all.data || []).map((i) => [String(i.id), i])), [all.data]);
  const compOptions = useMemo(() => (all.data || [])
    .filter((i) => String(i.id) !== String(id) && i.item_type !== 'non_inventory' && (i.active || comps.some((c) => c.component_id === String(i.id))))
    .map((i) => ({ value: String(i.id), label: i.name, sub: `${ITEM_TYPE_SHORT[i.item_type]} · ${i.sku || ''} · ₱${cost4(i.unit_cost)}/${i.uom || ''}`, search: i.sku })),
  [all.data, id, comps]);
  const unitsMap = useUnitsMap(comps.map((c) => c.component_id));

  const compCost = (c) => {
    const it = byId[c.component_id];
    const f = factorOf(unitsMap[c.component_id], c.uom_id || it?.base_uom_id);
    if (it && f !== null) return n(c.qty) * f * n(it.unit_cost);
    return c.saved && c.cost !== undefined ? c.cost : null;
  };
  const recipeCost = comps.reduce((s, c) => s + (compCost(c) || 0), 0);
  const vatOn = settings?.vat_registered === '1';
  const vatRate = vatOn ? n(settings?.vat_rate ?? 12) : 0;
  const netPrice = n(form.price) / (1 + vatRate / 100);
  const itemCost = composite ? recipeCost : n(form.avg_cost);
  const fcPct = netPrice > 0 ? (itemCost / netPrice) * 100 : null;
  const margin = netPrice - itemCost;

  const setComp = (idx, patch) => setComps((cs) => cs.map((c, i) => (i === idx ? { ...c, ...patch, saved: false } : c)));
  const pickComp = (idx, value) => {
    const it = byId[value];
    setComp(idx, { component_id: value, uom_id: it ? String(it.base_uom_id ?? '') : '', qty: comps[idx].qty || '1' });
  };

  const changeType = (t) => setForm((f) => ({ ...f, item_type: t, sellable: isNew ? t !== 'raw' : f.sellable }));

  const save = async () => {
    if (!form.name.trim()) return toast('Enter the item name', 'error');
    if (!form.base_uom_id) return toast('Choose the base unit', 'error');
    const body = {
      ...form, category_id: form.category_id || null, color: form.color || null,
      uoms: stocked ? uoms.filter((u) => u.uom_id && n(u.factor) > 0) : undefined,
      components: composite ? comps.filter((c) => c.component_id && n(c.qty) > 0).map((c) => ({ component_id: c.component_id, qty: c.qty, uom_id: c.uom_id || null })) : [],
    };
    if (!stocked || (!isNew && String(item.data.avg_cost) === form.avg_cost)) delete body.avg_cost;
    setSaving(true);
    try {
      if (isNew) {
        const r = await api.post('/inventory/items', body);
        toast('Item created');
        clearUnitsCache();
        navigate(`/inventory/items/${r.id}`, { replace: true });
      } else {
        await api.put(`/inventory/items/${id}`, body);
        clearUnitsCache();
        const fresh = await item.reload();
        all.reload();
        if (body.avg_cost !== undefined && fresh && Math.abs(fresh.avg_cost - n(body.avg_cost)) > 0.00005) {
          toast('Saved. Cost was not changed because the item already has stock history.', 'info');
        } else toast('Saved');
      }
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!(await dialog({ title: 'Delete item', message: `Delete "${form.name}"? Items with history are deactivated instead.`, danger: true, okText: 'Delete' }))) return;
    try {
      const r = await api.del(`/inventory/items/${id}`);
      toast(r.message || 'Item deleted', r.deactivated ? 'info' : 'success');
      navigate('/inventory/items');
    } catch (e) { toast(e.message, 'error'); }
  };

  if (item.loading && !isNew) return <Loading />;
  if (item.error) return <ErrorBox error={item.error} />;
  const i = item.data;

  return (
    <div className="stack">
      <PageHeader title={isNew ? 'New item' : form.name || 'Item'}
        subtitle={isNew ? 'Add an ingredient, menu item, retail product or service.' : <>{i.sku} · {ITEM_TYPES[i.item_type]} {!i.active && <Badge>inactive</Badge>}</>}
        actions={<>
          <Button onClick={() => navigate('/inventory/items')}>← Back</Button>
          {!isNew && STOCKED.includes(i.item_type) && <Link className="btn btn-default" to={`/reports/inventory?report=stockcard&item_id=${id}`}>Stock card</Link>}
          {!ro && !isNew && <Button variant="danger" onClick={remove}>Delete</Button>}
          {!ro && <Button variant="primary" loading={saving} onClick={save}>{isNew ? 'Create item' : 'Save'}</Button>}
        </>} />
      {ro && <div className="alert alert-info">You can view this item but not change it.</div>}

      <fieldset className="inv-fieldset stack" disabled={ro}>
        <Card title="General">
          <div className="form-grid">
            <Field label="Item name *" span={2}><Input autoFocus={isNew} value={form.name} onChange={(e) => set('name')(e.target.value)} placeholder="e.g. Pork Liempo" /></Field>
            <Field label="SKU" hint="Leave blank to auto-number"><Input value={form.sku} onChange={(e) => set('sku')(e.target.value)} /></Field>
            <Field label="Barcode"><Input value={form.barcode} onChange={(e) => set('barcode')(e.target.value)} /></Field>
            <Field label="Item type *" span={2} hint={TYPE_HINT[form.item_type]}>
              <Select options={Object.entries(ITEM_TYPES).map(([value, label]) => ({ value, label }))} value={form.item_type} onChange={changeType} />
            </Field>
            <Field label="Category">
              <Select options={(cats.data || []).map((c) => ({ value: c.id, label: c.name }))} value={form.category_id} onChange={set('category_id')} placeholder="— None —" />
            </Field>
            <Field label="Base unit *" hint={composite ? 'Usually Serving (srv)' : 'Unit stock is counted in'}>
              <Select options={uomOptions} value={form.base_uom_id} onChange={set('base_uom_id')} placeholder="— Choose —" />
            </Field>
            <Field label="Selling price (VAT-inclusive)"><NumberInput value={form.price} onChange={set('price')} placeholder="0.00" /></Field>
            <Field label="Sort order"><NumberInput value={form.sort_order} onChange={set('sort_order')} /></Field>
            <Field label="POS tile color">
              <div className="color-row">
                <input type="color" value={form.color || '#868e96'} onChange={(e) => set('color')(e.target.value)} />
                {form.color ? <Button size="sm" variant="ghost" onClick={() => set('color')('')}>Clear</Button> : <span className="muted small">Default</span>}
              </div>
            </Field>
            <div className="field">
              <span className="field-label">Options</span>
              <div className="col" style={{ gap: 6, alignItems: 'flex-start' }}>
                <Checkbox checked={form.sellable} onChange={set('sellable')} label="Sellable on POS" />
                <Checkbox checked={form.active} onChange={set('active')} label="Active" />
              </div>
            </div>
            <Field label="Description" span={2}><Textarea rows={2} value={form.description} onChange={(e) => set('description')(e.target.value)} /></Field>
          </div>
        </Card>

        {stocked && (
          <div className="grid-2">
            <Card title="Stock settings">
              <div className="form-grid">
                <Field label={`Reorder point (${baseAbbr})`} hint="Flag for reorder when stock falls to this"><NumberInput value={form.reorder_point} onChange={set('reorder_point')} /></Field>
                <Field label={`Reorder qty (${baseAbbr})`} hint="Suggested quantity to order"><NumberInput value={form.reorder_qty} onChange={set('reorder_qty')} /></Field>
                <Field label={`${isNew ? 'Opening' : 'Standard'} cost per ${baseAbbr}`}
                  hint={isNew ? 'Optional starting cost; deliveries update it automatically' : 'Only applied if the item has no stock movements yet'}>
                  <NumberInput value={form.avg_cost} onChange={set('avg_cost')} placeholder="0.00" />
                </Field>
              </div>
              {!isNew && (
                <div className="kv mt">
                  <span>On hand</span><b className={i.stock_qty < 0 ? 'text-red' : ''}>{qty(i.stock_qty)} {i.uom}</b>
                  <span>Average cost</span><span>₱{cost4(i.avg_cost)} / {i.uom}</span>
                  <span>Last cost</span><span>₱{cost4(i.last_cost)} / {i.uom}</span>
                  <span>Stock value</span><b>{peso(i.stock_value)}</b>
                </div>
              )}
            </Card>
            <AltUnits rows={uoms} setRows={setUoms} uomOptions={uomOptions} baseId={form.base_uom_id} baseAbbr={baseAbbr} ro={ro} />
          </div>
        )}

        {composite && (
          <Card title="Recipe / components" actions={!ro && <Button size="sm" onClick={() => setComps((c) => [...c, { component_id: '', qty: '1', uom_id: '' }])}>+ Add ingredient</Button>}>
            <p className="muted small" style={{ marginTop: 0 }}>
              Ingredients used to make <b>1 {baseAbbr}</b>. Sub-recipes (other composite items) are allowed. Selling this item deducts these from stock.
            </p>
            <div className="table-wrap">
              <table className="inv-grid">
                <thead><tr><th className="idx">#</th><th>Component</th><th className="num" style={{ width: 110 }}>Qty</th><th style={{ width: 110 }}>Unit</th>
                  <th className="num" style={{ width: 120 }}>Unit cost</th><th className="num" style={{ width: 110 }}>Cost</th><th className="num" style={{ width: 70 }}>% of cost</th><th style={{ width: 36 }} /></tr></thead>
                <tbody>
                  {comps.map((c, idx) => {
                    const it = byId[c.component_id];
                    const cost = compCost(c);
                    const u = unitsMap[c.component_id];
                    const unitOpts = (u || []).map((x) => ({ value: String(x.uom_id), label: x.abbr }));
                    if (c.uom_id && !unitOpts.some((o) => o.value === c.uom_id)) unitOpts.push({ value: c.uom_id, label: (units.data || []).find((x) => String(x.id) === c.uom_id)?.abbr || '?' });
                    return (
                      <tr key={idx}>
                        <td className="idx">{idx + 1}</td>
                        <td>
                          <SearchSelect options={compOptions} value={c.component_id} onChange={(v) => pickComp(idx, v)} placeholder="Search ingredient…" disabled={ro} />
                          {it && <div className="muted small" style={{ marginTop: 2 }}>{ITEM_TYPE_SHORT[it.item_type]}{it.item_type === 'composite' ? ' (sub-recipe)' : ''}{!it.active ? ' · inactive' : ''}</div>}
                        </td>
                        <td><NumberInput value={c.qty} onChange={(v) => setComp(idx, { qty: v })} /></td>
                        <td><Select options={unitOpts} value={c.uom_id} onChange={(v) => setComp(idx, { uom_id: v })} /></td>
                        <td className="num muted">{it ? `₱${cost4(it.unit_cost)}/${it.uom}` : ''}</td>
                        <td className="num">{cost === null ? '—' : cost4(cost)}</td>
                        <td className="num muted">{cost && recipeCost ? pct((cost / recipeCost) * 100) : ''}</td>
                        <td>{!ro && <button type="button" className="icon-btn rm" title="Remove" onClick={() => setComps((cs) => cs.filter((_, i2) => i2 !== idx))}>✕</button>}</td>
                      </tr>
                    );
                  })}
                  {!comps.length && <tr><td colSpan={8} className="muted center" style={{ padding: 20 }}>No ingredients yet. Add the ingredients that make up one {baseAbbr}.</td></tr>}
                </tbody>
                {comps.length > 0 && <tfoot><tr><td colSpan={5}>Total recipe cost per {baseAbbr}</td><td className="num">{peso(recipeCost)}</td><td colSpan={2} /></tr></tfoot>}
              </table>
            </div>
          </Card>
        )}

        {(composite || (form.item_type === 'retail')) && n(form.price) > 0 && (
          <Card title="Menu costing">
            <div className="costing">
              <Stat label="Selling price" value={peso(form.price)} sub={vatOn ? 'VAT-inclusive' : 'non-VAT'} />
              <Stat label="Net of VAT" value={peso(netPrice)} sub={vatOn ? `÷ ${(1 + vatRate / 100).toFixed(2)}` : 'same as price'} />
              <Stat label={composite ? 'Recipe cost' : 'Unit cost'} value={peso(itemCost)} />
              <Stat label="Food cost %" value={fcPct === null ? '—' : pct(fcPct)} tone={fcTone(fcPct)} sub="target 25–35%" />
              <Stat label="Gross margin" value={peso(margin)} tone={margin < 0 ? 'red' : undefined} sub={netPrice > 0 ? `${pct((margin / netPrice) * 100)} of net price` : ''} />
            </div>
            {fcPct !== null && fcPct > 45 && <div className="alert alert-warn mt">Food cost is high. Consider raising the price or reviewing portion sizes.</div>}
          </Card>
        )}

        {!isNew && i.used_in?.length > 0 && (
          <Card title={`Used in ${i.used_in.length} recipe${i.used_in.length > 1 ? 's' : ''}`}>
            <div className="row wrap gap-sm">
              {i.used_in.map((p) => <Link key={p.id} className="badge badge-blue" to={`/inventory/items/${p.id}`}>{p.name}</Link>)}
            </div>
          </Card>
        )}
      </fieldset>

      {!ro && (
        <div className="row gap-sm sticky-actions">
          <Button variant="primary" loading={saving} onClick={save}>{isNew ? 'Create item' : 'Save changes'}</Button>
          <Button onClick={() => navigate('/inventory/items')}>Cancel</Button>
        </div>
      )}
    </div>
  );
}

function AltUnits({ rows, setRows, uomOptions, baseId, baseAbbr, ro }) {
  const upd = (idx, patch) => setRows((rs) => rs.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  const opts = uomOptions.filter((o) => String(o.value) !== String(baseId));
  return (
    <Card title="Purchase / alternate units" actions={!ro && <Button size="sm" onClick={() => setRows((r) => [...r, { uom_id: '', factor: '' }])}>+ Add unit</Button>}>
      <p className="muted small" style={{ marginTop: 0 }}>How suppliers deliver this item, e.g. <b>1 sack = 50 kg</b>, <b>1 case = 24 btl</b>. Standard conversions like kg ↔ g are already built in.</p>
      {rows.length === 0 && <div className="muted small">No alternate units.</div>}
      {rows.map((r, idx) => (
        <div key={idx} className="row gap-sm mb">
          <span className="muted">1</span>
          <div style={{ width: 170 }}><Select options={opts} value={r.uom_id} onChange={(v) => upd(idx, { uom_id: v })} placeholder="— unit —" /></div>
          <span className="muted">=</span>
          <div style={{ width: 110 }}><NumberInput value={r.factor} onChange={(v) => upd(idx, { factor: v })} placeholder="factor" /></div>
          <span className="bold">{baseAbbr}</span>
          {!ro && <button type="button" className="icon-btn" title="Remove" onClick={() => setRows((rs) => rs.filter((_, i) => i !== idx))}>✕</button>}
        </div>
      ))}
    </Card>
  );
}
