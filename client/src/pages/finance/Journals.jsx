import React, { useMemo, useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import { money, peso, today, fmtDate, fmtDateTime } from '../../format';
import {
  Badge, Button, Card, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Select, useApi, useDialog, useToast,
} from '../../components/ui';
import DataTable from '../../components/DataTable';
import DateRange, { useDateRange, rangeLabel } from '../../components/DateRange';
import { AccountPicker, InfoGrid, SOURCE_LABELS, confirmVoid, voidRow } from './shared';

const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

export default function Journals() {
  const { can } = useAuth();
  const [range, setRange] = useDateRange();
  const [source, setSource] = useState('');
  const [openId, setOpenId] = useState(null);
  const [creating, setCreating] = useState(false);
  const { data, loading, error, reload } = useApi(
    () => api.get('/finance/journals', { ...range, source_type: source }), [range.from, range.to, source]
  );

  const columns = [
    { key: 'entry_no', label: 'Entry no', render: (r) => <span className="bold nowrap">{r.entry_no}</span> },
    { key: 'entry_date', label: 'Date', type: 'date' },
    { key: 'memo', label: 'Memo' },
    { key: 'source_type', label: 'Source', render: (r) => SOURCE_LABELS[r.source_type] || r.source_type, exportValue: (r) => SOURCE_LABELS[r.source_type] || r.source_type },
    { key: 'ref_no', label: 'Ref no' },
    { key: 'amount', label: 'Amount', type: 'money' },
    { key: 'status', label: 'Status', render: (r) => (
      <span className="row gap-sm"><Badge>{r.status}</Badge>{r.reversal_of ? <Badge color="blue">reversal</Badge> : null}</span>
    ), exportValue: (r) => (r.reversal_of ? `${r.status} (reversal)` : r.status) },
    { key: 'created_by_name', label: 'By' },
  ];

  return (
    <div className="stack">
      <PageHeader
        title="Journal Entries"
        subtitle="Every posting to the general ledger. Most entries are created automatically by POS, inventory, petty cash, banks, payables and receivables. Use a manual entry for opening balances, adjustments, depreciation, payroll accruals, owner's contributions and drawings, and corrections."
        actions={can('finance.journal') && <Button variant="primary" onClick={() => setCreating(true)}>+ New journal entry</Button>}
      />
      <div className="row wrap gap">
        <DateRange value={range} onChange={setRange} />
        <div style={{ width: 230 }}>
          <Select value={source} onChange={setSource} placeholder="All sources"
            options={Object.entries(SOURCE_LABELS).map(([value, label]) => ({ value, label }))} />
        </div>
      </div>
      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} onRowClick={(r) => setOpenId(r.id)} rowClass={voidRow}
          exportName="journal-entries" exportTitle="Journal Entries" exportSubtitle={`${rangeLabel(range)}${source ? ' · ' + SOURCE_LABELS[source] : ''}`}
          emptyText="No journal entries in this period." />
      </Card>
      {openId && <EntryModal id={openId} onOpen={setOpenId} onClose={() => setOpenId(null)} onChanged={reload} />}
      {creating && <NewEntryModal onClose={() => setCreating(false)} onSaved={(je) => { setCreating(false); reload(); setOpenId(je.id); }} />}
    </div>
  );
}

function EntryModal({ id, onOpen, onClose, onChanged }) {
  const { can } = useAuth();
  const toast = useToast();
  const dialog = useDialog();
  const { data: je, loading, error, reload } = useApi(() => api.get(`/finance/journals/${id}`), [id]);
  const totals = useMemo(() => (je?.lines || []).reduce((t, l) => ({ debit: t.debit + l.debit, credit: t.credit + l.credit }), { debit: 0, credit: 0 }), [je]);
  const canVoid = je && je.source_type === 'manual' && je.status === 'posted' && !je.reversal_of && can('finance.journal');

  const doVoid = () => confirmVoid(dialog, toast, {
    title: `Void ${je.entry_no}`,
    message: 'A reversing entry dated today will be posted and this entry will be marked void.',
    run: (reason) => api.post(`/finance/journals/${je.id}/void`, { reason }),
  }).then((ok) => { if (ok) { reload(); onChanged(); } });

  const columns = [
    { key: 'code', label: 'Code', width: 70 },
    { key: 'account_name', label: 'Account' },
    { key: 'memo', label: 'Line memo' },
    { key: 'debit', label: 'Debit', type: 'money', total: true, render: (l) => (l.debit ? money(l.debit) : '') },
    { key: 'credit', label: 'Credit', type: 'money', total: true, render: (l) => (l.credit ? money(l.credit) : '') },
  ];

  return (
    <Modal title={je ? `Journal entry ${je.entry_no}` : 'Journal entry'} onClose={onClose} width={820}
      footer={<>
        {canVoid && <Button variant="danger" onClick={doVoid} style={{ marginRight: 'auto' }}>Void entry</Button>}
        <Button onClick={onClose}>Close</Button>
      </>}>
      <ErrorBox error={error} />
      {loading && !je ? <Loading /> : je && (
        <>
          <InfoGrid items={[
            ['Date', fmtDate(je.entry_date)],
            ['Source', SOURCE_LABELS[je.source_type] || je.source_type],
            ['Reference no', je.ref_no],
            ['Status', <span className="row gap-sm"><Badge>{je.status}</Badge>{je.reversal_of ? <Badge color="blue">reversal</Badge> : null}</span>],
            ['Memo', je.memo],
            ['Recorded by', `${je.created_by_name || ''}${je.created_at ? ' · ' + fmtDateTime(je.created_at) : ''}`],
            je.reversal_of && ['Reverses', <Button size="sm" variant="link" onClick={() => onOpen(je.reversal_of)}>Open original entry</Button>],
            je.reversed_by && ['Reversed by', <Button size="sm" variant="link" onClick={() => onOpen(je.reversed_by)}>Open reversing entry</Button>],
          ]} />
          {je.source_type !== 'manual' && je.status === 'posted' && !je.reversal_of && (
            <div className="alert alert-info">System-generated entry. To undo it, void the source document (receipt, delivery, payment…).</div>
          )}
          <div className="card">
            <DataTable columns={columns} rows={je.lines} searchable={false} dense
              exportName={`journal-${je.entry_no}`} exportTitle={`Journal Entry ${je.entry_no}`} exportSubtitle={`${fmtDate(je.entry_date)} · ${je.memo || ''}`} />
          </div>
          {Math.abs(totals.debit - totals.credit) > 0.009 && <div className="alert alert-error mt">Entry is out of balance.</div>}
        </>
      )}
    </Modal>
  );
}

const blankLine = (debit = '', credit = '') => ({ k: Math.random().toString(36).slice(2), account_id: '', debit, credit, memo: '' });

function NewEntryModal({ onClose, onSaved }) {
  const toast = useToast();
  const dialog = useDialog();
  const accounts = useApi(() => api.get('/finance/accounts'), []);
  const [head, setHead] = useState({ entry_date: today(), ref_no: '', memo: '' });
  const [lines, setLines] = useState([blankLine(), blankLine()]);
  const [busy, setBusy] = useState(false);

  const dr = r2(lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
  const cr = r2(lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
  const diff = r2(dr - cr);
  const balanced = Math.abs(diff) < 0.005;
  const missingAccount = lines.some((l) => (Number(l.debit) || Number(l.credit)) && !l.account_id);
  const ok = balanced && dr > 0 && !missingAccount;

  const upd = (k, patch) => setLines((ls) => ls.map((l) => (l.k === k ? { ...l, ...patch } : l)));
  const addLine = () => setLines((ls) => [...ls, diff > 0 ? blankLine('', String(diff)) : diff < 0 ? blankLine(String(-diff), '') : blankLine()]);

  const post = async () => {
    if (!ok) return;
    if (!(await dialog({ title: 'Post journal entry', message: `Post this entry for ${peso(dr)} to the general ledger?`, okText: 'Post' }))) return;
    setBusy(true);
    try {
      const je = await api.post('/finance/journals', {
        ...head,
        lines: lines.filter((l) => l.account_id && (Number(l.debit) || Number(l.credit)))
          .map((l) => ({ account_id: Number(l.account_id), debit: Number(l.debit) || 0, credit: Number(l.credit) || 0, memo: l.memo })),
      });
      toast(`Journal entry ${je.entry_no} posted`);
      onSaved(je);
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };

  return (
    <Modal title="New journal entry" onClose={onClose} width={980}
      footer={<>
        <div className="grow small">
          {missingAccount ? <span className="text-red">Select an account on every line with an amount.</span>
            : balanced && dr > 0 ? <span className="text-green bold">✓ Balanced</span>
              : dr === 0 && cr === 0 ? <span className="muted">Enter debit and credit amounts.</span>
                : <span className="text-red bold">Out of balance by {peso(Math.abs(diff))} ({diff > 0 ? 'more debits' : 'more credits'})</span>}
        </div>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="primary" loading={busy} disabled={!ok} onClick={post}>Post entry</Button>
      </>}>
      <div className="form-grid mb">
        <Field label="Date"><Input type="date" value={head.entry_date} onChange={(e) => setHead({ ...head, entry_date: e.target.value })} /></Field>
        <Field label="Reference no"><Input value={head.ref_no} onChange={(e) => setHead({ ...head, ref_no: e.target.value })} placeholder="e.g. OR/CV no" /></Field>
        <Field label="Memo" span={2}>
          <Input value={head.memo} onChange={(e) => setHead({ ...head, memo: e.target.value })} placeholder="e.g. Opening balances as of Jan 1 / Monthly depreciation" />
        </Field>
      </div>
      <div className="card">
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th style={{ width: '38%' }}>Account</th>
                <th style={{ width: 140, textAlign: 'right' }}>Debit</th>
                <th style={{ width: 140, textAlign: 'right' }}>Credit</th>
                <th>Line memo</th>
                <th style={{ width: 40 }} />
              </tr>
            </thead>
            <tbody>
              {lines.map((l) => (
                <tr key={l.k}>
                  <td><AccountPicker accounts={accounts.data} value={l.account_id} onChange={(v) => upd(l.k, { account_id: v })} /></td>
                  <td><NumberInput value={l.debit} min="0" onChange={(v) => upd(l.k, { debit: v, ...(Number(v) ? { credit: '' } : {}) })} /></td>
                  <td><NumberInput value={l.credit} min="0" onChange={(v) => upd(l.k, { credit: v, ...(Number(v) ? { debit: '' } : {}) })} /></td>
                  <td><Input value={l.memo} onChange={(e) => upd(l.k, { memo: e.target.value })} /></td>
                  <td>
                    <button className="icon-btn" title="Remove line" disabled={lines.length <= 2}
                      onClick={() => setLines((ls) => ls.filter((x) => x.k !== l.k))}>✕</button>
                  </td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td><Button size="sm" onClick={addLine}>+ Add line</Button></td>
                <td style={{ textAlign: 'right' }}>{money(dr)}</td>
                <td style={{ textAlign: 'right' }}>{money(cr)}</td>
                <td className={balanced ? 'text-green' : 'text-red'}>{balanced ? (dr > 0 ? 'Balanced' : '') : `Difference ${money(Math.abs(diff))}`}</td>
                <td />
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <p className="muted small">Debits must equal credits. Tip: “Add line” pre-fills the amount needed to balance the entry.</p>
    </Modal>
  );
}
