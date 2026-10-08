import React, { useState } from 'react';
import { exportExcel } from '../../api';
import { money, fmtDate } from '../../format';
import { Button, useToast } from '../../components/ui';

function ExportBtn({ build, name }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  return (
    <Button size="sm" loading={busy} onClick={async () => {
      setBusy(true);
      try { await exportExcel(build()); } catch (e) { toast(e.message, 'error'); } finally { setBusy(false); }
    }}>⬇ Excel{name ? ` (${name})` : ''}</Button>
  );
}

const COLS = [{ key: 'name', label: 'Account' }, { key: 'amount', label: 'Amount', type: 'money' }];

function Section({ title, rows, totalLabel, total }) {
  return (
    <>
      <tr className="sec"><td colSpan={2}>{title}</td></tr>
      {rows.map((r) => <tr key={r.code + r.name}><td style={{ paddingLeft: 20 }}>{r.code ? <span className="muted">{r.code} </span> : null}{r.name}</td><td className="amt">{money(r.amount)}</td></tr>)}
      {!rows.length && <tr><td style={{ paddingLeft: 20 }} className="muted">—</td><td /></tr>}
      <tr className="tot"><td>{totalLabel}</td><td className="amt">{money(total)}</td></tr>
    </>
  );
}

const flat = (title, rows, totalLabel, total) => [
  { name: title.toUpperCase(), _bold: true },
  ...rows.map((r) => ({ name: `${r.code ? r.code + ' ' : ''}${r.name}`, amount: r.amount, _indent: 1 })),
  { name: totalLabel, amount: total, _bold: true },
];

export function IncomeStatement({ data: d, business }) {
  const build = () => ({
    filename: `income-statement_${d.from}_${d.to}`, title: 'Income Statement', subtitle: `${fmtDate(d.from)} – ${fmtDate(d.to)}`, columns: COLS,
    rows: [
      ...flat('Revenue', d.income, 'Net Revenue', d.revenue),
      ...flat('Cost of Sales', d.cogs, 'Total Cost of Sales', d.total_cogs),
      { name: 'GROSS PROFIT', amount: d.gross_profit, _bold: true },
      ...flat('Operating Expenses', d.opex, 'Total Operating Expenses', d.total_opex),
      { name: 'NET INCOME (LOSS)', amount: d.net_income, _bold: true },
    ],
  });
  const gpPct = d.revenue ? (d.gross_profit / d.revenue) * 100 : 0;
  const niPct = d.revenue ? (d.net_income / d.revenue) * 100 : 0;
  return (
    <div className="card-body statement">
      <div className="row between mb">
        <div><h3>{business}</h3><div className="muted">Income Statement · {fmtDate(d.from)} – {fmtDate(d.to)}</div></div>
        <ExportBtn build={build} />
      </div>
      <table>
        <tbody>
          <Section title="Revenue" rows={d.income} totalLabel="Net Revenue" total={d.revenue} />
          <Section title="Cost of Sales" rows={d.cogs} totalLabel="Total Cost of Sales" total={d.total_cogs} />
          <tr className="grand"><td>GROSS PROFIT <span className="muted small">({gpPct.toFixed(1)}%)</span></td><td className="amt">{money(d.gross_profit)}</td></tr>
          <Section title="Operating Expenses" rows={d.opex} totalLabel="Total Operating Expenses" total={d.total_opex} />
          <tr className="grand"><td>NET INCOME (LOSS) <span className="muted small">({niPct.toFixed(1)}%)</span></td>
            <td className={`amt ${d.net_income < 0 ? 'text-red' : ''}`}>{money(d.net_income)}</td></tr>
        </tbody>
      </table>
    </div>
  );
}

export function BalanceSheet({ data: d, business }) {
  const build = () => ({
    filename: `balance-sheet_${d.as_of}`, title: 'Balance Sheet', subtitle: `As of ${fmtDate(d.as_of)}`, columns: COLS,
    rows: [
      ...flat('Assets', d.assets, 'TOTAL ASSETS', d.total_assets),
      ...flat('Liabilities', d.liabilities, 'Total Liabilities', d.total_liabilities),
      ...flat('Equity', d.equity, 'Total Equity', d.total_equity),
      { name: 'TOTAL LIABILITIES & EQUITY', amount: d.total_liabilities + d.total_equity, _bold: true },
    ],
  });
  return (
    <div className="card-body statement">
      <div className="row between mb">
        <div><h3>{business}</h3><div className="muted">Balance Sheet · as of {fmtDate(d.as_of)}</div></div>
        <ExportBtn build={build} />
      </div>
      {Math.abs(d.check) > 0.01 && <div className="alert alert-error">Out of balance by {money(d.check)}</div>}
      <table>
        <tbody>
          <Section title="Assets" rows={d.assets} totalLabel="TOTAL ASSETS" total={d.total_assets} />
          <Section title="Liabilities" rows={d.liabilities} totalLabel="Total Liabilities" total={d.total_liabilities} />
          <Section title="Equity" rows={d.equity} totalLabel="Total Equity" total={d.total_equity} />
          <tr className="grand"><td>TOTAL LIABILITIES &amp; EQUITY</td><td className="amt">{money(d.total_liabilities + d.total_equity)}</td></tr>
        </tbody>
      </table>
    </div>
  );
}
