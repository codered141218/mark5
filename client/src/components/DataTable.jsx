import React, { useMemo, useState } from 'react';
import { exportExcel } from '../api';
import { money, qty, int, pct, fmtDate, fmtDateTime } from '../format';
import { Button, Loading, Empty, useToast } from './ui';

const FORMATTERS = {
  money, qty, number: int, percent: pct, date: fmtDate, datetime: fmtDateTime,
};
const NUMERIC = ['money', 'qty', 'number', 'percent'];

export function formatCell(col, row) {
  if (col.render) return col.render(row);
  const v = row[col.key];
  const f = FORMATTERS[col.type];
  return f ? f(v) : v ?? '';
}

/**
 * Generic table with search, sort, totals and Excel export.
 * columns: [{ key, label, type?: money|qty|number|percent|date|datetime, render?, total?: true|fn(rows), noExport?, exportValue?, align?, width? }]
 */
export default function DataTable({
  columns, rows, loading, searchable = true, exportName, exportTitle, exportSubtitle, onRowClick, emptyText,
  toolbar, pageSize = 100, rowClass, dense, footerRows,
}) {
  const toast = useToast();
  const [q, setQ] = useState('');
  const [sort, setSort] = useState(null);
  const [limit, setLimit] = useState(pageSize);
  const [exporting, setExporting] = useState(false);

  const filtered = useMemo(() => {
    let out = rows || [];
    if (q) {
      const s = q.toLowerCase();
      out = out.filter((r) => columns.some((c) => String(r[c.key] ?? '').toLowerCase().includes(s)));
    }
    if (sort) {
      const col = columns.find((c) => c.key === sort.key);
      const numeric = col && NUMERIC.includes(col.type);
      out = [...out].sort((a, b) => {
        const x = a[sort.key]; const y = b[sort.key];
        const cmp = numeric ? (Number(x) || 0) - (Number(y) || 0) : String(x ?? '').localeCompare(String(y ?? ''), undefined, { numeric: true });
        return sort.dir === 'asc' ? cmp : -cmp;
      });
    }
    return out;
  }, [rows, q, sort, columns]);

  const totals = useMemo(() => {
    if (!columns.some((c) => c.total)) return null;
    const t = {};
    for (const c of columns) {
      if (typeof c.total === 'function') t[c.key] = c.total(filtered);
      else if (c.total) t[c.key] = filtered.reduce((s, r) => s + (Number(r[c.key]) || 0), 0);
    }
    const first = columns[0];
    if (t[first.key] === undefined) t[first.key] = 'TOTAL';
    return t;
  }, [filtered, columns]);

  const doExport = async () => {
    setExporting(true);
    try {
      await exportExcel({
        filename: exportName || 'report', title: exportTitle || exportName, subtitle: exportSubtitle,
        columns, rows: [...filtered, ...(footerRows || [])], totals,
      });
    } catch (e) {
      toast(e.message, 'error');
    } finally {
      setExporting(false);
    }
  };

  const toggleSort = (key) => {
    setSort((s) => (!s || s.key !== key ? { key, dir: 'asc' } : s.dir === 'asc' ? { key, dir: 'desc' } : null));
  };

  const align = (c) => c.align || (NUMERIC.includes(c.type) ? 'right' : 'left');

  return (
    <div className="datatable">
      {(searchable || exportName || toolbar) && (
        <div className="dt-toolbar">
          {searchable && <input className="input dt-search" placeholder="Search…" value={q} onChange={(e) => setQ(e.target.value)} />}
          <div className="row gap-sm wrap">{toolbar}</div>
          <div className="grow" />
          <span className="muted small">{filtered.length} record{filtered.length === 1 ? '' : 's'}</span>
          {exportName && <Button size="sm" onClick={doExport} loading={exporting} disabled={!filtered.length}>⬇ Excel</Button>}
        </div>
      )}
      <div className="table-wrap">
        <table className={`table ${dense ? 'dense' : ''} ${onRowClick ? 'clickable' : ''}`}>
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} style={{ textAlign: align(c), width: c.width }} onClick={() => c.sortable !== false && toggleSort(c.key)}>
                  {c.label}
                  {sort && sort.key === c.key ? (sort.dir === 'asc' ? ' ▲' : ' ▼') : ''}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {filtered.slice(0, limit).map((r, i) => (
              <tr key={r.id ?? i} onClick={onRowClick ? () => onRowClick(r) : undefined} className={rowClass ? rowClass(r) : r._bold ? 'bold' : ''}>
                {columns.map((c) => (
                  <td key={c.key} style={{ textAlign: align(c), paddingLeft: c === columns[0] && r._indent ? 12 + r._indent * 16 : undefined }}>{formatCell(c, r)}</td>
                ))}
              </tr>
            ))}
            {footerRows && footerRows.map((r, i) => (
              <tr key={'f' + i} className="bold">
                {columns.map((c) => <td key={c.key} style={{ textAlign: align(c) }}>{formatCell(c, r)}</td>)}
              </tr>
            ))}
          </tbody>
          {totals && filtered.length > 0 && (
            <tfoot>
              <tr>
                {columns.map((c) => (
                  <td key={c.key} style={{ textAlign: align(c) }}>
                    {totals[c.key] === undefined ? '' : typeof totals[c.key] === 'string' ? totals[c.key] : (FORMATTERS[c.type] || money)(totals[c.key])}
                  </td>
                ))}
              </tr>
            </tfoot>
          )}
        </table>
        {loading && !rows && <Loading />}
        {!loading && rows && !filtered.length && <Empty>{emptyText}</Empty>}
      </div>
      {filtered.length > limit && (
        <div className="center pad">
          <Button size="sm" onClick={() => setLimit((l) => l + pageSize * 5)}>Show more ({filtered.length - limit} remaining)</Button>
        </div>
      )}
    </div>
  );
}
