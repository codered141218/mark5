import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, exportExcel } from '../../api';
import { useAuth } from '../../auth';
import DataTable from '../../components/DataTable';
import {
  Badge, Button, Card, Checkbox, Empty, ErrorBox, Field, Input, Loading, Modal, NumberInput, PageHeader, Tabs, Textarea, useApi, useDialog, useToast,
} from '../../components/ui';

const TABS = [
  { value: 'business', label: 'Business & Receipt' },
  { value: 'tax', label: 'Tax & Charges' },
  { value: 'backup', label: 'Backups' },
  { value: 'tables', label: 'Dining Tables' },
];

// Keys managed here, with defaults used when a key has never been saved.
const DEFAULTS = {
  business_name: '', business_address: '', business_tin: '', business_phone: '',
  receipt_title: 'ORDER RECEIPT', receipt_footer: '', receipt_prefix: 'OR',
  vat_registered: '1', vat_rate: '12', sc_discount_rate: '20', service_charge_rate: '0', service_charge_dine_in_only: '1', require_payment_ref: '0',
  auto_backup: '1', backup_retention: '30',
};
const BOOL_KEYS = ['vat_registered', 'service_charge_dine_in_only', 'require_payment_ref', 'auto_backup'];

export default function Settings() {
  const [tab, setTab] = useState('business');
  return (
    <div>
      <PageHeader title="Settings" subtitle="Business details printed on receipts, tax rules, backups and dining tables." />
      <Tabs tabs={TABS} value={tab} onChange={setTab} />
      {tab === 'tables' ? <TablesTab /> : <SettingsForm tab={tab} />}
    </div>
  );
}

function SettingsForm({ tab }) {
  const toast = useToast();
  const { reloadSettings } = useAuth();
  const { data, loading, error, reload } = useApi(() => api.get('/settings'), []);
  const [f, setF] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (data) setF({ ...DEFAULTS, ...pick(data) }); }, [data]);
  if (loading && !f) return <Loading />;
  if (error) return <ErrorBox error={error} />;
  if (!f) return null;

  const dirty = data && Object.keys(DEFAULTS).some((k) => String(f[k] ?? '') !== String(data[k] ?? DEFAULTS[k]));
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v && v.target ? v.target.value : v }));
  const bool = (k) => f[k] === '1';
  const setBool = (k) => (on) => setF((x) => ({ ...x, [k]: on ? '1' : '0' }));

  const save = async () => {
    for (const k of ['vat_rate', 'sc_discount_rate', 'service_charge_rate']) {
      const n = Number(f[k]);
      if (f[k] === '' || Number.isNaN(n) || n < 0 || n > 100) return toast('Rates must be between 0 and 100', 'error');
    }
    if (!(Number(f.backup_retention) >= 1)) return toast('Keep at least 1 automatic backup', 'error');
    setBusy(true);
    try {
      const body = Object.fromEntries(Object.keys(DEFAULTS).map((k) => [k, BOOL_KEYS.includes(k) ? (bool(k) ? '1' : '0') : String(f[k] ?? '').trim()]));
      await api.put('/settings', body);
      await reloadSettings();
      await reload();
      toast('Settings saved');
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      {tab === 'business' && (
        <div className="stack">
          <div className="form-grid">
            <Field label="Business / trade name" span={2}><Input value={f.business_name} onChange={set('business_name')} /></Field>
            <Field label="TIN" hint="e.g. 000-000-000-00000"><Input value={f.business_tin} onChange={set('business_tin')} /></Field>
            <Field label="Address" span={2}><Input value={f.business_address} onChange={set('business_address')} /></Field>
            <Field label="Phone"><Input value={f.business_phone} onChange={set('business_phone')} /></Field>
          </div>
          <h3>Receipt</h3>
          <div className="form-grid">
            <Field label="Receipt title" hint="Printed at the top of every receipt"><Input value={f.receipt_title} onChange={set('receipt_title')} /></Field>
            <Field label="Receipt number prefix" hint={`Receipts are numbered like ${f.receipt_prefix || 'OR'}-000123`}><Input value={f.receipt_prefix} onChange={set('receipt_prefix')} maxLength={10} /></Field>
            <Field label="Receipt footer" span={3} hint="e.g. thank-you message, wifi password, social media"><Textarea value={f.receipt_footer} onChange={set('receipt_footer')} rows={2} /></Field>
          </div>
        </div>
      )}

      {tab === 'tax' && (
        <div className="stack">
          <div>
            <Checkbox checked={bool('vat_registered')} onChange={setBool('vat_registered')} label={<b>VAT-registered business</b>} />
            <p className="muted small" style={{ margin: '4px 0 0 24px' }}>
              {bool('vat_registered')
                ? 'Menu prices are VAT-inclusive; receipts show VATable sales and VAT amount. Senior Citizen / PWD sales are VAT-exempt.'
                : 'Non-VAT: prices carry no VAT and receipts show no VAT breakdown. Non-VAT businesses generally pay the 3% percentage tax on gross sales instead.'}
            </p>
          </div>
          <div className="form-grid">
            <Field label="VAT rate (%)" hint="Philippine VAT is 12%"><NumberInput value={f.vat_rate} onChange={set('vat_rate')} disabled={!bool('vat_registered')} /></Field>
            <Field label="Senior Citizen / PWD discount (%)" hint="20% under RA 9994 (Seniors) and RA 10754 (PWD)"><NumberInput value={f.sc_discount_rate} onChange={set('sc_discount_rate')} /></Field>
            <Field label="Service charge (%)" hint="0 = no service charge"><NumberInput value={f.service_charge_rate} onChange={set('service_charge_rate')} /></Field>
          </div>
          <div className="col gap-sm" style={{ alignItems: 'flex-start' }}>
            <Checkbox checked={bool('service_charge_dine_in_only')} onChange={setBool('service_charge_dine_in_only')} label="Apply service charge to dine-in orders only (not take-out / delivery)" />
            <Checkbox checked={bool('require_payment_ref')} onChange={setBool('require_payment_ref')} label="Require approval / reference no. for card and e-wallet (GCash, Maya) payments" />
          </div>
        </div>
      )}

      {tab === 'backup' && (
        <div className="stack">
          <div>
            <Checkbox checked={bool('auto_backup')} onChange={setBool('auto_backup')} label={<b>Daily automatic backup</b>} />
            <p className="muted small" style={{ margin: '4px 0 0 24px' }}>A copy of the database is saved automatically once a day while the server is running.</p>
          </div>
          <div className="form-grid">
            <Field label="Automatic backups to keep" hint="Older automatic backups are deleted. Manual backups are never deleted automatically.">
              <NumberInput value={f.backup_retention} onChange={set('backup_retention')} min={1} step={1} />
            </Field>
          </div>
          <p className="small"><Link to="/admin/backup">Go to Backup &amp; Restore →</Link></p>
        </div>
      )}

      <div className="row gap-sm mt-lg" style={{ justifyContent: 'flex-end' }}>
        {dirty && <span className="muted small">Unsaved changes</span>}
        <Button onClick={() => setF({ ...DEFAULTS, ...pick(data) })} disabled={!dirty}>Discard</Button>
        <Button variant="primary" loading={busy} onClick={save} disabled={!dirty}>Save settings</Button>
      </div>
    </Card>
  );
}

const pick = (s) => Object.fromEntries(Object.keys(DEFAULTS).filter((k) => s[k] !== undefined && s[k] !== null).map((k) => [k, String(s[k])]));

// ------------------------------------------------------------------ dining tables
function TablesTab() {
  const toast = useToast();
  const dialog = useDialog();
  const { data, loading, error, reload } = useApi(() => api.get('/tables'), []);
  const [editing, setEditing] = useState(null);
  const [bulk, setBulk] = useState(false);

  const areas = useMemo(() => {
    const m = new Map();
    for (const t of data || []) {
      const a = t.area || 'No area';
      if (!m.has(a)) m.set(a, []);
      m.get(a).push(t);
    }
    return [...m.entries()];
  }, [data]);

  const remove = async (t) => {
    if (!(await dialog({ title: 'Delete table', message: `Delete table "${t.name}"? Tables that already have orders are deactivated instead.`, danger: true, okText: 'Delete' }))) return;
    try {
      const r = await api.del(`/tables/${t.id}`);
      toast(r.deactivated ? 'Table has order history — deactivated instead' : 'Table deleted', r.deactivated ? 'info' : 'success');
      reload();
    } catch (e) { toast(e.message, 'error'); }
  };

  const columns = [
    { key: 'name', label: 'Table', render: (t) => <span className="bold">{t.name}</span> },
    { key: 'area', label: 'Area' },
    { key: 'seats', label: 'Seats', type: 'number', total: true },
    { key: 'sort_order', label: 'Sort', type: 'number' },
    { key: 'active', label: 'Status', exportValue: (t) => (t.active ? 'Active' : 'Inactive'), render: (t) => <Badge color={t.active ? 'green' : 'gray'}>{t.active ? 'active' : 'inactive'}</Badge> },
    {
      key: '_a', label: '', noExport: true, sortable: false, align: 'right',
      render: (t) => (
        <span className="row gap-sm" style={{ justifyContent: 'flex-end' }}>
          <Button size="sm" onClick={() => setEditing({ ...t, active: !!t.active })}>Edit</Button>
          <Button size="sm" variant="ghost" style={{ color: "var(--red)" }} onClick={() => remove(t)}>Delete</Button>
        </span>
      ),
    },
  ];

  const nextSort = (data || []).reduce((m, t) => Math.max(m, Number(t.sort_order) || 0), 0) + 1;

  return (
    <div className="stack">
      <div className="row between wrap gap">
        <span className="muted">Tables appear on the POS floor plan, grouped by area. {data ? `${data.filter((t) => t.active).length} active tables, ${data.filter((t) => t.active).reduce((s, t) => s + (Number(t.seats) || 0), 0)} seats.` : ''}</span>
        <span className="row gap-sm">
          <Button disabled={!data || !data.length} onClick={() => exportExcel({ filename: 'dining-tables', title: 'Dining tables', columns, rows: data }).catch((e) => toast(e.message, 'error'))}>⬇ Excel</Button>
          <Button onClick={() => setBulk(true)}>+ Add multiple tables</Button>
          <Button variant="primary" onClick={() => setEditing({ name: '', area: areas.length ? areas[0][0] === 'No area' ? '' : areas[0][0] : '', seats: '4', sort_order: String(nextSort), active: true })}>+ Add table</Button>
        </span>
      </div>
      <ErrorBox error={error} />
      {loading && !data ? <Loading /> : !areas.length ? <Card><Empty>No tables yet. Use “Add multiple tables” to set up your floor quickly.</Empty></Card> : areas.map(([area, rows]) => (
        <Card key={area} title={`${area} · ${rows.length} table${rows.length === 1 ? '' : 's'}`} pad={false}>
          <DataTable columns={columns} rows={rows} searchable={false} rowClass={(t) => (t.active ? '' : 'muted-row')} dense />
        </Card>
      ))}
      {editing && <TableForm initial={editing} areas={areas.map((a) => a[0]).filter((a) => a !== 'No area')} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload(); }} />}
      {bulk && <BulkTables areas={areas.map((a) => a[0]).filter((a) => a !== 'No area')} existing={(data || []).map((t) => t.name)} nextSort={nextSort} onClose={() => setBulk(false)} onSaved={() => { setBulk(false); reload(); }} />}
    </div>
  );
}

function AreaList({ areas }) {
  return <datalist id="table-areas">{areas.map((a) => <option key={a} value={a} />)}</datalist>;
}

function TableForm({ initial, areas, onClose, onSaved }) {
  const toast = useToast();
  const [f, setF] = useState({ ...initial, seats: String(initial.seats ?? ''), sort_order: String(initial.sort_order ?? '') });
  const [busy, setBusy] = useState(false);
  const save = async () => {
    if (!String(f.name).trim()) return toast('Table name is required', 'error');
    setBusy(true);
    try {
      const body = { name: String(f.name).trim(), area: f.area || null, seats: Number(f.seats) || 4, sort_order: Number(f.sort_order) || 0 };
      if (f.id) await api.put(`/tables/${f.id}`, { ...body, active: f.active });
      else await api.post('/tables', body);
      toast(f.id ? 'Table saved' : 'Table added');
      onSaved();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
  };
  return (
    <Modal title={f.id ? `Edit table ${initial.name}` : 'Add table'} onClose={onClose} width={480}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save}>Save</Button></>}>
      <div className="form-grid">
        <Field label="Table name *"><Input autoFocus value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="e.g. T1" /></Field>
        <Field label="Area"><Input list="table-areas" value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} placeholder="e.g. Main, Al fresco, VIP" /></Field>
        <Field label="Seats"><NumberInput value={f.seats} onChange={(v) => setF({ ...f, seats: v })} min={1} step={1} /></Field>
        <Field label="Sort order" hint="Lower numbers show first"><NumberInput value={f.sort_order} onChange={(v) => setF({ ...f, sort_order: v })} step={1} /></Field>
      </div>
      <AreaList areas={areas} />
      {f.id && <div className="mt"><Checkbox checked={f.active} onChange={(v) => setF({ ...f, active: v })} label="Active (shown on POS)" /></div>}
    </Modal>
  );
}

function BulkTables({ areas, existing, nextSort, onClose, onSaved }) {
  const toast = useToast();
  const [f, setF] = useState({ area: areas[0] || 'Main', prefix: 'T', start: '1', count: '10', seats: '4' });
  const [busy, setBusy] = useState(false);
  const count = Math.max(0, Math.min(100, Math.floor(Number(f.count) || 0)));
  const start = Math.floor(Number(f.start) || 1);
  const names = Array.from({ length: count }, (_, i) => `${f.prefix}${start + i}`);
  const set = (k) => (v) => setF((x) => ({ ...x, [k]: v && v.target ? v.target.value : v }));

  const save = async () => {
    if (!count) return toast('Enter how many tables to add', 'error');
    setBusy(true);
    let done = 0;
    try {
      for (const [i, name] of names.entries()) {
        await api.post('/tables', { name, area: f.area || null, seats: Number(f.seats) || 4, sort_order: nextSort + i });
        done++;
      }
      toast(`${done} tables added`);
      onSaved();
    } catch (e) {
      toast(`${e.message} (${done} of ${count} added)`, 'error');
      if (done) onSaved();
    } finally { setBusy(false); }
  };

  return (
    <Modal title="Add multiple tables" onClose={onClose} width={520}
      footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} onClick={save} disabled={!count}>Add {count} tables</Button></>}>
      <div className="form-grid">
        <Field label="Area"><Input list="table-areas" value={f.area} onChange={set('area')} /></Field>
        <Field label="Name prefix" hint="e.g. T, VIP-, A"><Input value={f.prefix} onChange={set('prefix')} /></Field>
        <Field label="Start number"><NumberInput value={f.start} onChange={set('start')} step={1} /></Field>
        <Field label="How many"><NumberInput value={f.count} onChange={set('count')} min={1} max={100} step={1} /></Field>
        <Field label="Seats each"><NumberInput value={f.seats} onChange={set('seats')} min={1} step={1} /></Field>
      </div>
      <AreaList areas={areas} />
      {names.some((n) => existing.includes(n)) && <div className="alert alert-warn mt small" style={{ marginBottom: 0 }}>Some of these names already exist ({names.filter((n) => existing.includes(n)).slice(0, 5).join(', ')}). Change the prefix or start number to avoid duplicates.</div>}
      {count > 0 && <p className="muted small mt">Will create: {names.length > 8 ? `${names.slice(0, 4).join(', ')} … ${names.slice(-2).join(', ')}` : names.join(', ')}</p>}
    </Modal>
  );
}
