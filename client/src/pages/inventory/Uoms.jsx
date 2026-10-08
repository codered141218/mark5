import React, { useState } from 'react';
import { api } from '../../api';
import { qty } from '../../format';
import './inventory.css';
import DataTable from '../../components/DataTable';
import { Button, Card, ErrorBox, Field, Input, Modal, NumberInput, PageHeader, Select, useApi, useDialog, useToast } from '../../components/ui';

export default function Uoms() {
  const toast = useToast();
  const dialog = useDialog();
  const uoms = useApi(() => api.get('/inventory/uoms'), []);
  const convs = useApi(() => api.get('/inventory/uom-conversions'), []);
  const [edit, setEdit] = useState(null);
  const [conv, setConv] = useState({ from_uom_id: '', factor: '', to_uom_id: '' });
  const [saving, setSaving] = useState(false);

  const saveUom = async (e) => {
    e?.preventDefault();
    if (!edit.name.trim() || !edit.abbr.trim()) return toast('Enter both name and abbreviation', 'error');
    setSaving(true);
    try {
      const body = { name: edit.name.trim(), abbr: edit.abbr.trim() };
      if (edit.id) await api.put(`/inventory/uoms/${edit.id}`, body);
      else await api.post('/inventory/uoms', body);
      toast('Saved');
      setEdit(null);
      uoms.reload(); convs.reload();
    } catch (err) { toast(err.message, 'error'); } finally { setSaving(false); }
  };
  const delUom = async (u) => {
    if (!(await dialog({ title: 'Delete unit', message: `Delete "${u.name} (${u.abbr})"? Its conversions are removed too.`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/inventory/uoms/${u.id}`);
      toast('Unit deleted');
      uoms.reload(); convs.reload();
    } catch (err) { toast(err.message, 'error'); }
  };

  const addConv = async (e) => {
    e?.preventDefault();
    if (!conv.from_uom_id || !conv.to_uom_id || !(Number(conv.factor) > 0)) return toast('Choose both units and a factor greater than zero', 'error');
    try {
      await api.post('/inventory/uom-conversions', conv);
      toast('Conversion saved');
      setConv({ from_uom_id: '', factor: '', to_uom_id: '' });
      convs.reload();
    } catch (err) { toast(err.message, 'error'); }
  };
  const delConv = async (c) => {
    if (!(await dialog({ title: 'Delete conversion', message: `Remove "1 ${c.from_abbr} = ${qty(c.factor)} ${c.to_abbr}"?`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/inventory/uom-conversions/${c.id}`);
      toast('Conversion removed');
      convs.reload();
    } catch (err) { toast(err.message, 'error'); }
  };

  const opts = (uoms.data || []).map((u) => ({ value: u.id, label: `${u.abbr} — ${u.name}` }));

  return (
    <div className="stack">
      <PageHeader title="Units & Conversions" subtitle="Units used to count, buy and use stock."
        actions={<Button variant="primary" onClick={() => setEdit({ name: '', abbr: '' })}>+ New unit</Button>} />
      <ErrorBox error={uoms.error || convs.error} />
      <div className="grid-2" style={{ alignItems: 'start' }}>
        <Card title="Units of measure" pad={false}>
          <DataTable rows={uoms.data} loading={uoms.loading} exportName="units-of-measure" exportTitle="Units of Measure" onRowClick={(u) => setEdit({ ...u })}
            columns={[
              { key: 'name', label: 'Name' },
              { key: 'abbr', label: 'Abbreviation', render: (r) => <b>{r.abbr}</b> },
              { key: '_a', label: '', noExport: true, sortable: false, align: 'right', render: (r) => <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); delUom(r); }}>Delete</Button> },
            ]} />
        </Card>

        <Card title="Global conversions">
          <p className="muted small" style={{ marginTop: 0 }}>
            Standard conversions that apply to every item (e.g. <b>1 kg = 1000 g</b>, <b>1 L = 1000 ml</b>). Recipes and deliveries can then use
            either unit. Item-specific purchase units such as <b>1 sack = 50 kg</b> or <b>1 case = 24 btl</b> are set on each item.
          </p>
          <form className="row gap-sm wrap mb" onSubmit={addConv}>
            <span className="muted">1</span>
            <div style={{ width: 150 }}><Select options={opts} value={conv.from_uom_id} onChange={(v) => setConv((c) => ({ ...c, from_uom_id: v }))} placeholder="from unit" /></div>
            <span className="muted">=</span>
            <div style={{ width: 110 }}><NumberInput value={conv.factor} onChange={(v) => setConv((c) => ({ ...c, factor: v }))} placeholder="factor" /></div>
            <div style={{ width: 150 }}><Select options={opts} value={conv.to_uom_id} onChange={(v) => setConv((c) => ({ ...c, to_uom_id: v }))} placeholder="to unit" /></div>
            <Button type="submit" variant="primary">Add</Button>
          </form>
          <div className="table-wrap">
            <table className="inv-grid">
              <thead><tr><th>Conversion</th><th className="num">Reverse</th><th style={{ width: 40 }} /></tr></thead>
              <tbody>
                {(convs.data || []).map((c) => (
                  <tr key={c.id}>
                    <td><b>1 {c.from_abbr}</b> = {qty(c.factor)} {c.to_abbr}</td>
                    <td className="num muted">1 {c.to_abbr} = {qty(1 / c.factor)} {c.from_abbr}</td>
                    <td><button type="button" className="icon-btn rm" title="Remove" onClick={() => delConv(c)}>✕</button></td>
                  </tr>
                ))}
                {convs.data && !convs.data.length && <tr><td colSpan={3} className="muted center" style={{ padding: 16 }}>No conversions yet.</td></tr>}
              </tbody>
            </table>
          </div>
          <p className="muted small">Adding a conversion that already exists updates its factor.</p>
        </Card>
      </div>

      {edit && (
        <Modal title={edit.id ? 'Edit unit' : 'New unit'} onClose={() => setEdit(null)} width={420}
          footer={<><Button onClick={() => setEdit(null)}>Cancel</Button><Button variant="primary" loading={saving} onClick={saveUom}>Save</Button></>}>
          <form className="form-grid" onSubmit={saveUom}>
            <Field label="Name *"><Input autoFocus value={edit.name} onChange={(e) => setEdit((x) => ({ ...x, name: e.target.value }))} placeholder="e.g. Sack" /></Field>
            <Field label="Abbreviation *"><Input value={edit.abbr} onChange={(e) => setEdit((x) => ({ ...x, abbr: e.target.value }))} placeholder="e.g. sack" /></Field>
            <button type="submit" hidden />
          </form>
        </Modal>
      )}
    </div>
  );
}
