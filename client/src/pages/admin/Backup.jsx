import React, { useRef, useState } from 'react';
import { api, download } from '../../api';
import { useAuth } from '../../auth';
import { fmtDateTime } from '../../format';
import DataTable from '../../components/DataTable';
import { Badge, Button, Card, ErrorBox, PageHeader, Stat, useApi, useDialog, useToast } from '../../components/ui';

const KIND = { auto: ['Automatic', 'blue'], manual: ['Manual', 'green'], 'pre-restore': ['Before restore', 'amber'] };
const RESTORE_MSG = 'This replaces ALL current data with the backup. A safety backup of the current data is created first. Everyone will be signed out.';

function fileSize(n) {
  n = Number(n) || 0;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

export default function Backup() {
  const toast = useToast();
  const dialog = useDialog();
  const { logout, settings } = useAuth();
  const fileRef = useRef(null);
  const [busy, setBusy] = useState('');
  const { data, loading, error, reload } = useApi(() => api.get('/backups'), []);

  const afterRestore = async (r) => {
    toast(`Data restored. Safety copy saved as ${r.safety_backup}. Please sign in again.`, 'success');
    setTimeout(async () => { await logout(); window.location.replace('/'); }, 1800);
  };

  const create = async () => {
    setBusy('create');
    try {
      const b = await api.post('/backups');
      toast(`Backup created: ${b.name}`);
      reload();
    } catch (e) { toast(e.message, 'error'); } finally { setBusy(''); }
  };

  const restore = async (b) => {
    if (!(await dialog({ title: 'Restore this backup?', message: <>{RESTORE_MSG}<br /><br />Restore from <b>{b.name}</b> ({fmtDateTime(b.created_at)})?</>, danger: true, okText: 'Yes, restore' }))) return;
    setBusy(b.name);
    try {
      afterRestore(await api.post(`/backups/${encodeURIComponent(b.name)}/restore`));
    } catch (e) { toast(e.message, 'error'); setBusy(''); }
  };

  const remove = async (b) => {
    if (!(await dialog({ title: 'Delete backup', message: `Permanently delete ${b.name}?`, danger: true, okText: 'Delete' }))) return;
    try {
      await api.del(`/backups/${encodeURIComponent(b.name)}`);
      toast('Backup deleted');
      reload();
    } catch (e) { toast(e.message, 'error'); }
  };

  const dl = async (b) => {
    try { await download('GET', `/backups/${encodeURIComponent(b.name)}/download`, undefined, b.name); } catch (e) { toast(e.message, 'error'); }
  };

  const uploadRestore = async (file) => {
    if (fileRef.current) fileRef.current.value = '';
    if (!file) return;
    if (!/\.db$/i.test(file.name)) return toast('Choose a .db backup file', 'error');
    if (!(await dialog({ title: 'Restore from file?', message: <>{RESTORE_MSG}<br /><br />Restore from <b>{file.name}</b> ({fileSize(file.size)})?</>, danger: true, okText: 'Yes, restore' }))) return;
    setBusy('upload');
    try {
      afterRestore(await api.upload('/restore-upload', file));
    } catch (e) { toast(e.message, 'error'); setBusy(''); }
  };

  const rows = data || [];
  const latest = rows[0];
  const columns = [
    { key: 'name', label: 'File', render: (b) => <span className="mono">{b.name}</span> },
    { key: 'kind', label: 'Type', exportValue: (b) => (KIND[b.kind] || [b.kind])[0], render: (b) => { const [l, c] = KIND[b.kind] || [b.kind, 'gray']; return <Badge color={c}>{l}</Badge>; } },
    { key: 'created_at', label: 'Created', type: 'datetime' },
    { key: 'size', label: 'Size', align: 'right', render: (b) => fileSize(b.size), exportValue: (b) => b.size },
    {
      key: '_a', label: '', noExport: true, sortable: false, align: 'right',
      render: (b) => (
        <span className="row gap-sm" style={{ justifyContent: 'flex-end' }}>
          <Button size="sm" onClick={() => dl(b)}>⬇ Download</Button>
          <Button size="sm" variant="dark" loading={busy === b.name} disabled={!!busy} onClick={() => restore(b)}>Restore</Button>
          <Button size="sm" variant="ghost" style={{ color: "var(--red)" }} disabled={!!busy} onClick={() => remove(b)}>Delete</Button>
        </span>
      ),
    },
  ];

  return (
    <div className="stack">
      <PageHeader title="Backup & Restore" subtitle="Protect your sales, inventory and accounting data."
        actions={<>
          <input ref={fileRef} type="file" accept=".db" style={{ display: 'none' }} onChange={(e) => uploadRestore(e.target.files[0])} />
          <Button onClick={() => fileRef.current && fileRef.current.click()} loading={busy === 'upload'} disabled={!!busy}>⬆ Restore from file…</Button>
          <Button variant="primary" onClick={create} loading={busy === 'create'} disabled={!!busy}>Create backup now</Button>
        </>} />

      <div className="alert alert-info" style={{ marginBottom: 0 }}>
        All your data lives in a <b>single database file</b> on the server.
        {settings.auto_backup === '0'
          ? <> Daily automatic backup is currently <b>turned off</b> — turn it on in Settings → Backups.</>
          : <> A backup is made <b>automatically every day</b> and the last {settings.backup_retention || 30} automatic copies are kept.</>}
        {' '}Backups stored on the same computer won't survive a broken disk, theft or fire — regularly <b>download a backup and keep it off-site</b> (USB drive or cloud storage like Google Drive).
      </div>

      <div className="stats">
        <Stat label="Backups on server" value={rows.length} />
        <Stat label="Latest backup" value={latest ? fmtDateTime(latest.created_at) : '—'} sub={latest ? (KIND[latest.kind] || [latest.kind])[0] : 'None yet'} />
        <Stat label="Total size" value={fileSize(rows.reduce((s, b) => s + b.size, 0))} />
      </div>

      <ErrorBox error={error} />
      <Card pad={false}>
        <DataTable columns={columns} rows={data} loading={loading} exportName="backups" exportTitle="Database backups" emptyText="No backups yet. Click “Create backup now”." />
      </Card>
    </div>
  );
}
