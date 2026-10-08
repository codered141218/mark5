import React, { useState } from 'react';
import { presetRange, fmtDate } from '../format';

const PRESETS = [
  ['today', 'Today'], ['yesterday', 'Yesterday'], ['week', 'This week'], ['last7', 'Last 7 days'],
  ['month', 'This month'], ['lastmonth', 'Last month'], ['quarter', 'This quarter'], ['year', 'This year'], ['lastyear', 'Last year'],
];

/** Date range filter used by every report. value = { from, to } */
export default function DateRange({ value, onChange, single }) {
  const [preset, setPreset] = useState('');
  if (single) {
    return (
      <div className="daterange">
        <span className="muted small">As of</span>
        <input className="input" type="date" value={value.to} onChange={(e) => onChange({ ...value, to: e.target.value })} />
      </div>
    );
  }
  return (
    <div className="daterange">
      <select className="input" value={preset} onChange={(e) => { setPreset(e.target.value); if (e.target.value) onChange(presetRange(e.target.value)); }}>
        <option value="">Custom range</option>
        {PRESETS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
      </select>
      <input className="input" type="date" value={value.from} max={value.to} onChange={(e) => { setPreset(''); onChange({ ...value, from: e.target.value }); }} />
      <span className="muted">to</span>
      <input className="input" type="date" value={value.to} min={value.from} onChange={(e) => { setPreset(''); onChange({ ...value, to: e.target.value }); }} />
    </div>
  );
}

export const rangeLabel = (r) => (r.from === r.to ? fmtDate(r.from) : `${fmtDate(r.from)} – ${fmtDate(r.to)}`);

/** Hook that remembers the chosen range for the session (shared across pages). */
let lastRange = null;
export function useDateRange(initial = 'month') {
  const [range, setRange] = useState(() => lastRange || presetRange(initial));
  const set = (r) => { lastRange = r; setRange(r); };
  return [range, set];
}
