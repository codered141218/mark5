import React, { useState } from 'react';
import { api } from '../../api';
import './inventory.css';
import DataTable from '../../components/DataTable';
import { Badge, Button, Card, Checkbox, ErrorBox, Field, Input, Modal, NumberInput, PageHeader, Select, useApi, useDialog, useToast } from '../../components/ui';

const KINDS = [
  { value: 'menu', label: 'Menu (POS)' },
  { value: 'inventory', label: 'Inventory' },
  { value: 'both', label: 'Both' },
];
const KIND_LABEL = Object.fromEntries(KINDS.map((k) => [k.value, k.label]));

export default function Categories() {
  const toast = useToast();
  const dialog = useDialog();
  const { data, loading, error, reload } = useApi(() => api.get('/inventory/categories'), []);
  const [edit, setEdit] = useState(null);
  const [saving, setSaving] = useState(false);

  const openNew = () => setEdit({ name: '', kind: 'menu', color: '#868e96', sort_order: String((data || []).length + 1), active: true });
  const openEdit = (c) => setEdit({ ...c, sort_order: String(c.sort_order), active: !!c.active, color: c.color || '' });
  const set = (k) => (v) => setEdit((e) => ({ ...e, [k]: v }));

  const save = async (e) => {
    e?.preventDefault();
    if (!edit.name.trim()) return toast('Enter a category name', 'error');
    setSaving(true);
    try {
      const body = { name: edit.name.trim(), kind: edit.kind, color: edit.color || null, sort_order: edit.sort_order, active: edit.active };
      if (edit.id) await api.put(`/inventory/categories/${edit.id}`, body);
      else await api.post('/inventory/categories', body);
      toast('Saved');
      setEdit(null);
      reload();
    } catch (err) { toast(err.message, 'error'); } finally { setSaving(false); }
  };

  const remove = async (c) => {
    if (!(await dialog({ title: 'Delete category', message: `Delete "${c.name}"?`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/inventory/categories/${c.id}`);
      toast('Category deleted');
      setEdit(null);
      reload();
    } catch (err) { toast(err.message, 'error'); }
  };

  const columns = [
    { key: 'name', label: 'Name', render: (r) => <><span className="swatch" style={{ background: r.color || 'transparent' }} /><b>{r.name}</b></> },
    { key: 'kind', label: 'Used for', render: (r) => KIND_LABEL[r.kind] || r.kind, exportValue: (r) => KIND_LABEL[r.kind] || r.kind },
    { key: 'color', label: 'Color', render: (r) => <span className="muted small">{r.color}</span> },
    { key: 'sort_order', label: 'Sort', type: 'number' },
    { key: 'item_count', label: 'Items', type: 'number', total: true },
    { key: 'active', label: 'Status', render: (r) => <Badge color={r.active ? 'green' : 'gray'}>{r.active ? 'active' : 'inactive'}</Badge>, exportValue: (r) => (r.active ? 'Active' : 'Inactive') },
    {
      key: '_act', label: '', noExport: true, sortable: false, align: 'right',
      render: (r) => <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); remove(r); }} disabled={r.item_count > 0} title={r.item_count > 0 ? 'Move its items first' : 'Delete'}>Delete</Button>,
    },
  ];

  return (
    <div className="stack">
      <PageHeader title="Categories" subtitle="Menu categories group POS buttons; inventory categories group ingredients for counts and reports."
        actions={<Button variant="primary" onClick={openNew}>+ New category</Button>} />
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} onRowClick={openEdit} exportName="categories" exportTitle="Item Categories" />
      </Card>

      {edit && (
        <Modal title={edit.id ? 'Edit category' : 'New category'} onClose={() => setEdit(null)} width={480}
          footer={<>
            {edit.id && <Button variant="danger" onClick={() => remove(edit)} disabled={edit.item_count > 0}>Delete</Button>}
            <div className="grow" />
            <Button onClick={() => setEdit(null)}>Cancel</Button>
            <Button variant="primary" loading={saving} onClick={save}>Save</Button>
          </>}>
          <form className="form-grid" onSubmit={save}>
            <Field label="Name *" span={2}><Input autoFocus value={edit.name} onChange={(e) => set('name')(e.target.value)} /></Field>
            <Field label="Used for"><Select options={KINDS} value={edit.kind} onChange={set('kind')} /></Field>
            <Field label="Sort order"><NumberInput value={edit.sort_order} onChange={set('sort_order')} /></Field>
            <Field label="Color">
              <div className="color-row">
                <input type="color" value={edit.color || '#868e96'} onChange={(e) => set('color')(e.target.value)} />
                <span className="muted small">{edit.color || 'none'}</span>
              </div>
            </Field>
            <div className="field"><span className="field-label">Status</span><Checkbox checked={edit.active} onChange={set('active')} label="Active" /></div>
            <button type="submit" hidden />
          </form>
        </Modal>
      )}
    </div>
  );
}
