import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api';
import { peso } from '../../format';
import './inventory.css';
import DataTable from '../../components/DataTable';
import DateRange, { rangeLabel, useDateRange } from '../../components/DateRange';
import { Badge, Button, Card, ErrorBox, PageHeader, Select, Stat, useApi } from '../../components/ui';
import { DOC_TYPES, PAYMENT_LABEL } from './docTypes';

const STATUSES = [{ value: 'draft', label: 'Draft' }, { value: 'posted', label: 'Posted' }, { value: 'cancelled', label: 'Cancelled (void)' }];

export default function InvDocs({ type }) {
  const cfg = DOC_TYPES[type];
  const navigate = useNavigate();
  const [range, setRange] = useDateRange();
  const [status, setStatus] = useState('');
  const { data, loading, error } = useApi(() => api.get('/inventory/docs', { type, from: range.from, to: range.to, status }), [type, range.from, range.to, status]);

  const rows = data || [];
  const posted = rows.filter((r) => r.status === 'posted');
  const drafts = rows.filter((r) => r.status === 'draft');

  const partyCol = {
    RECEIVE: {
      key: 'supplier_name', label: 'Supplier / Invoice',
      render: (r) => <><div className="bold">{r.supplier_name || <span className="muted">—</span>}</div>{(r.invoice_no || r.payment_mode) && <div className="muted small">{r.invoice_no ? `Inv/DR ${r.invoice_no} · ` : ''}{PAYMENT_LABEL[r.payment_mode] || r.payment_mode}</div>}</>,
      exportValue: (r) => [r.supplier_name, r.invoice_no && `Inv ${r.invoice_no}`].filter(Boolean).join(' · '),
    },
    ISSUE: {
      key: 'issued_to', label: 'Issued to / Reason',
      render: (r) => <><div className="bold">{r.issued_to || <span className="muted">—</span>}</div>{r.reason && <div className="muted small">{r.reason}</div>}</>,
      exportValue: (r) => [r.issued_to, r.reason].filter(Boolean).join(' · '),
    },
    WASTE: { key: 'reason', label: 'Reason', render: (r) => <span style={{ textTransform: 'capitalize' }}>{r.reason}</span> },
  }[type];

  const columns = [
    { key: 'doc_no', label: 'Doc no.', render: (r) => <b>{r.doc_no}</b> },
    { key: 'doc_date', label: 'Date', type: 'date' },
    partyCol,
    { key: 'line_count', label: 'Lines', type: 'number' },
    { key: 'total_cost', label: 'Total cost', type: 'money', total: (rs) => rs.filter((r) => r.status !== 'cancelled').reduce((s, r) => s + r.total_cost, 0) },
    { key: 'status', label: 'Status', render: (r) => <Badge>{r.status}</Badge> },
    { key: 'created_by_name', label: 'Prepared by' },
  ];

  return (
    <div className="stack">
      <PageHeader title={cfg.title} subtitle={cfg.subtitle}
        actions={<Button variant="primary" onClick={() => navigate(`${cfg.basePath}/new`)}>+ {cfg.newTitle}</Button>} />
      <div className="stats">
        <Stat label="Posted total" value={peso(posted.reduce((s, r) => s + r.total_cost, 0))} sub={`${posted.length} document${posted.length === 1 ? '' : 's'} · ${rangeLabel(range)}`} />
        <Stat label="Drafts (not yet posted)" value={drafts.length} tone={drafts.length ? 'amber' : undefined} sub={drafts.length ? peso(drafts.reduce((s, r) => s + r.total_cost, 0)) : 'none pending'} />
      </div>
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} onRowClick={(r) => navigate(`${cfg.basePath}/${r.id}`)}
          exportName={cfg.basePath.split('/').pop()} exportTitle={cfg.title} exportSubtitle={rangeLabel(range)}
          rowClass={(r) => (r.status === 'cancelled' ? 'muted-row' : '')}
          toolbar={<>
            <DateRange value={range} onChange={setRange} />
            <Select options={STATUSES} value={status} onChange={setStatus} placeholder="All statuses" style={{ width: 160 }} />
          </>}
          emptyText="No documents in this period." />
      </Card>
    </div>
  );
}
