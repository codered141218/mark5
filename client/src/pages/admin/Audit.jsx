import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import DataTable from '../../components/DataTable';
import DateRange, { rangeLabel, useDateRange } from '../../components/DateRange';
import { Badge, Card, ErrorBox, PageHeader, Select, useApi } from '../../components/ui';

const ACTION_COLOR = { create: 'green', update: 'blue', delete: 'red', void: 'red', disable: 'red', delete_backup: 'red', restore: 'amber', login: 'gray' };

// Compact one-line rendering of the JSON details string.
function compact(details) {
  if (details == null || details === '') return '';
  let v = details;
  try { v = JSON.parse(details); } catch { return String(details); }
  if (v && typeof v === 'object' && !Array.isArray(v)) {
    return Object.entries(v).map(([k, x]) => `${k}: ${x !== null && typeof x === 'object' ? JSON.stringify(x) : x}`).join(', ');
  }
  return typeof v === 'string' ? v : JSON.stringify(v);
}
const MAX = 120;

export default function Audit() {
  const [range, setRange] = useDateRange('last7');
  const [action, setAction] = useState('');
  const { data, loading, error } = useApi(() => api.get('/audit', range), [range.from, range.to]);

  const rows = useMemo(() => (data || []).map((r) => ({ ...r, details_text: compact(r.details), user_label: r.username || (r.user_id ? `#${r.user_id}` : 'system') })), [data]);
  const actions = useMemo(() => [...new Set(rows.map((r) => r.action).filter(Boolean))].sort(), [rows]);
  const shown = action ? rows.filter((r) => r.action === action) : rows;

  const columns = [
    { key: 'ts', label: 'Time', type: 'datetime', width: 170 },
    { key: 'user_label', label: 'User' },
    { key: 'action', label: 'Action', render: (r) => <Badge color={ACTION_COLOR[r.action]}>{r.action}</Badge> },
    { key: 'entity', label: 'Entity' },
    { key: 'entity_id', label: 'ID', align: 'right' },
    {
      key: 'details_text', label: 'Details',
      render: (r) => (r.details_text.length > MAX
        ? <span title={r.details_text} className="small">{r.details_text.slice(0, MAX)}…</span>
        : <span className="small">{r.details_text}</span>),
    },
  ];

  return (
    <div>
      <PageHeader title="Audit Trail" subtitle="Who did what, and when. Up to 2,000 most recent entries for the selected period."
        actions={<DateRange value={range} onChange={setRange} />} />
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={shown} loading={loading} exportName="audit-trail" exportTitle="Audit trail"
          exportSubtitle={`${rangeLabel(range)}${action ? ` · action: ${action}` : ''}`}
          toolbar={<div style={{ width: 200 }}><Select options={actions.map((a) => ({ value: a, label: a }))} value={action} onChange={setAction} placeholder="All actions" /></div>}
          emptyText="No activity in this period." dense />
      </Card>
    </div>
  );
}
