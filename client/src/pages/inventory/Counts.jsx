import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import { peso, today } from '../../format';
import './inventory.css';
import DataTable from '../../components/DataTable';
import DateRange, { rangeLabel, useDateRange } from '../../components/DateRange';
import { Badge, Button, Card, ErrorBox, Field, Input, Modal, PageHeader, Select, Textarea, useApi, useToast } from '../../components/ui';

export default function Counts() {
  const navigate = useNavigate();
  const toast = useToast();
  const [range, setRange] = useDateRange();
  const { data, loading, error } = useApi(() => api.get('/inventory/counts', { from: range.from, to: range.to }), [range.from, range.to]);
  const cats = useApi(() => api.get('/inventory/categories'), []);
  const [start, setStart] = useState(null);
  const [busy, setBusy] = useState(false);

  const create = async (e) => {
    e?.preventDefault();
    setBusy(true);
    try {
      const s = await api.post('/inventory/counts', { count_date: start.count_date, category_id: start.category_id || null, notes: start.notes || null });
      toast(`${s.doc_no} started with ${s.lines.length} items`);
      navigate(`/inventory/counts/${s.id}`);
    } catch (err) { toast(err.message, 'error'); } finally { setBusy(false); }
  };

  const columns = [
    { key: 'doc_no', label: 'Count no.', render: (r) => <b>{r.doc_no}</b> },
    { key: 'count_date', label: 'Date', type: 'date' },
    { key: 'category_name', label: 'Scope', render: (r) => r.category_name || <span className="muted">All stocked items</span>, exportValue: (r) => r.category_name || 'All stocked items' },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    {
      key: 'progress', label: 'Counted', align: 'right', sortable: false,
      render: (r) => {
        const done = r.line_count && r.counted_count === r.line_count;
        return <span className={done ? 'text-green bold' : ''}>{r.counted_count} / {r.line_count}</span>;
      },
      exportValue: (r) => `${r.counted_count} / ${r.line_count}`,
    },
    {
      key: 'total_variance_value', label: 'Variance value', type: 'money', total: true,
      render: (r) => (r.status === 'posted'
        ? <span className={r.total_variance_value < 0 ? 'text-red' : r.total_variance_value > 0 ? 'text-green' : ''}>{peso(r.total_variance_value)}</span>
        : <span className="muted">—</span>),
    },
    { key: 'created_by_name', label: 'Started by' },
    { key: 'notes', label: 'Notes', render: (r) => <span className="muted small">{r.notes}</span> },
  ];

  return (
    <div className="stack">
      <PageHeader title="Inventory Count" subtitle="Physical stock counts. Posting a count adjusts system stock to what was counted and books the over/short to the books."
        actions={<Button variant="primary" onClick={() => setStart({ count_date: today(), category_id: '', notes: '' })}>+ Start new count</Button>} />
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} onRowClick={(r) => navigate(`/inventory/counts/${r.id}`)}
          exportName="inventory-counts" exportTitle="Inventory Count Sessions" exportSubtitle={rangeLabel(range)}
          rowClass={(r) => (r.status === 'cancelled' ? 'muted-row' : '')}
          toolbar={<DateRange value={range} onChange={setRange} />} emptyText="No counts in this period." />
      </Card>

      {start && (
        <Modal title="Start new count" onClose={() => setStart(null)} width={460}
          footer={<><Button onClick={() => setStart(null)}>Cancel</Button><Button variant="primary" loading={busy} onClick={create}>Start count</Button></>}>
          <form className="form-grid" onSubmit={create}>
            <Field label="Count date"><Input type="date" value={start.count_date} onChange={(e) => setStart({ ...start, count_date: e.target.value })} /></Field>
            <Field label="Category" hint="Blank = all stocked items">
              <Select options={(cats.data || []).filter((c) => c.active).map((c) => ({ value: c.id, label: c.name }))}
                value={start.category_id} onChange={(v) => setStart({ ...start, category_id: v })} placeholder="All stocked items" />
            </Field>
            <Field label="Notes" span={2}><Textarea rows={2} value={start.notes} onChange={(e) => setStart({ ...start, notes: e.target.value })} placeholder="e.g. Month-end count, storeroom" /></Field>
            <button type="submit" hidden />
          </form>
          <p className="muted small">A count sheet is created with the current system quantity of every active raw material and retail item in scope.</p>
        </Modal>
      )}
    </div>
  );
}
