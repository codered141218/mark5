import React, { useState } from 'react';

/**
 * Single-series vertical bar chart (SVG) with hover tooltip.
 * data: [{ label, value, tip? }]
 */
export function BarChart({ data, height = 200, format = (v) => v, color = 'var(--brand)', emptyText = 'No data for this period' }) {
  const [hover, setHover] = useState(null);
  if (!data.length) return <div className="empty">{emptyText}</div>;
  const W = 640; const H = height; const padL = 52; const padB = 24; const padT = 10;
  const max = Math.max(...data.map((d) => d.value), 0) || 1;
  const nice = niceMax(max);
  const plotW = W - padL - 8; const plotH = H - padB - padT;
  const slot = plotW / data.length;
  const bw = Math.max(Math.min(slot - 2, 36), 2);
  const ticks = [0, nice / 2, nice];
  const labelEvery = Math.ceil(data.length / 12);
  return (
    <div style={{ position: 'relative' }}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" style={{ display: 'block' }} onMouseLeave={() => setHover(null)}>
        {ticks.map((t) => {
          const y = padT + plotH - (t / nice) * plotH;
          return (
            <g key={t}>
              <line x1={padL} x2={W - 8} y1={y} y2={y} stroke="var(--border)" strokeWidth="1" />
              <text x={padL - 6} y={y + 4} textAnchor="end" fontSize="11" fill="var(--muted)">{shortNum(t)}</text>
            </g>
          );
        })}
        {data.map((d, i) => {
          const h = (d.value / nice) * plotH;
          const x = padL + i * slot + (slot - bw) / 2;
          const y = padT + plotH - h;
          const r = Math.min(4, bw / 2, h);
          return (
            <g key={i} onMouseEnter={() => setHover({ i, x: x + bw / 2, y })}>
              <rect x={padL + i * slot} y={padT} width={slot} height={plotH} fill="transparent" />
              {h > 0 && (
                <path d={`M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + bw - r} Q${x + bw},${y} ${x + bw},${y + r} V${y + h} Z`}
                  fill={color} opacity={hover && hover.i !== i ? 0.55 : 1} />
              )}
              {i % labelEvery === 0 && (
                <text x={padL + i * slot + slot / 2} y={H - 6} textAnchor="middle" fontSize="11" fill="var(--muted)">{d.label}</text>
              )}
            </g>
          );
        })}
      </svg>
      {hover && (
        <div className="chart-tip" style={{ left: `${(hover.x / W) * 100}%`, top: `${(hover.y / H) * 100}%` }}>
          <b>{data[hover.i].tip || data[hover.i].label}</b><br />{format(data[hover.i].value)}
        </div>
      )}
    </div>
  );
}

/** Horizontal bars for ranked lists (categories, top items, payment mix). data: [{ label, value, sub? }] */
export function HBars({ data, format = (v) => v, emptyText = 'No data for this period' }) {
  if (!data.length) return <div className="empty">{emptyText}</div>;
  const max = Math.max(...data.map((d) => d.value), 0) || 1;
  return (
    <div>
      {data.map((d) => (
        <div key={d.label} className="hbar" title={`${d.label}: ${format(d.value)}`}>
          <span className="nowrap" style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{d.label}</span>
          <div className="hbar-track"><div className="hbar-fill" style={{ width: `${(d.value / max) * 100}%` }} /></div>
          <span className="mono right">{format(d.value)}{d.sub ? <span className="muted small"> {d.sub}</span> : null}</span>
        </div>
      ))}
    </div>
  );
}

function niceMax(v) {
  const p = 10 ** Math.floor(Math.log10(v));
  const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
}
function shortNum(v) {
  if (v >= 1e6) return `${+(v / 1e6).toFixed(1)}M`;
  if (v >= 1e3) return `${+(v / 1e3).toFixed(1)}k`;
  return String(+v.toFixed(v < 10 ? 1 : 0));
}
