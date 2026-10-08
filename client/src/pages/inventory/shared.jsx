// Helpers shared by the inventory pages.
import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { api } from '../../api';
import './inventory.css';

const costFmt = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
/** Unit cost with up to 4 decimals (costs per gram / ml are tiny). */
export const cost4 = (n) => (n === null || n === undefined || n === '' ? '' : costFmt.format(Number(n) || 0));
export const n = (v) => (v === '' || v === null || v === undefined ? 0 : Number(v) || 0);
export const r2 = (v) => Math.round((Number(v) || 0) * 100) / 100;
export const r4 = (v) => Math.round((Number(v) || 0) * 10000) / 10000;

// ------------------------------------------------------------------ transactable units per item (cached)
const unitsCache = new Map();
/** GET /inventory/items/:id/units -> [{uom_id, abbr, factor}] (factor = base units per 1 of that unit) */
export function getUnits(itemId) {
  if (!itemId) return Promise.resolve([]);
  const key = String(itemId);
  if (!unitsCache.has(key)) {
    const p = api.get(`/inventory/items/${itemId}/units`).catch((e) => { unitsCache.delete(key); throw e; });
    unitsCache.set(key, p);
  }
  return unitsCache.get(key);
}
export const clearUnitsCache = () => unitsCache.clear();

/** Loads units for a set of item ids; returns { [itemId]: units[] } */
export function useUnitsMap(ids) {
  const [map, setMap] = useState({});
  const key = [...new Set(ids.filter(Boolean).map(String))].sort().join(',');
  useEffect(() => {
    let alive = true;
    for (const id of key ? key.split(',') : []) {
      if (map[id]) continue;
      getUnits(id).then((u) => { if (alive) setMap((m) => ({ ...m, [id]: u })); }).catch(() => {});
    }
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  return map;
}

export const factorOf = (units, uomId) => {
  if (!units) return null;
  const u = units.find((x) => String(x.uom_id) === String(uomId));
  return u ? Number(u.factor) : null;
};

// ------------------------------------------------------------------ searchable select (combobox)
/**
 * <SearchSelect options={[{value,label,sub}]} value onChange(value, option) placeholder />
 * Click or type to open, arrows to move, Enter/Tab to pick, Esc to close.
 */
export const SearchSelect = React.forwardRef(function SearchSelect(
  { options, value, onChange, placeholder = 'Search…', disabled, autoFocus, className = '' }, outerRef
) {
  const ref = useRef(null);
  const listRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [hi, setHi] = useState(0);
  const [pos, setPos] = useState(null);
  const selected = options.find((o) => String(o.value) === String(value));

  const setRefs = (el) => {
    ref.current = el;
    if (typeof outerRef === 'function') outerRef(el);
    else if (outerRef) outerRef.current = el;
  };

  const filtered = useMemo(() => {
    if (!q) return options.slice(0, 200);
    const t = q.trim().toLowerCase();
    const words = t.split(/\s+/).filter(Boolean);
    const rank = (o) => {
      const l = String(o.label).toLowerCase();
      if (l === t || String(o.search || '').toLowerCase() === t) return 0;
      if (l.startsWith(t)) return 1;
      return l.includes(t) ? 2 : 3;
    };
    return options
      .filter((o) => {
        const hay = `${o.label} ${o.sub || ''} ${o.search || ''}`.toLowerCase();
        return words.every((w) => hay.includes(w));
      })
      .map((o, i) => [rank(o), i, o])
      .sort((a, b) => a[0] - b[0] || a[1] - b[1])
      .slice(0, 200)
      .map((x) => x[2]);
  }, [options, q]);

  const place = () => {
    if (!ref.current) return;
    const r = ref.current.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    const up = below < 240 && r.top > below;
    setPos({ left: r.left, width: Math.max(r.width, 280), top: up ? undefined : r.bottom + 2, bottom: up ? window.innerHeight - r.top + 2 : undefined });
  };
  useLayoutEffect(() => {
    if (!open) return undefined;
    place();
    const onScroll = (e) => { if (!listRef.current || !listRef.current.contains(e.target)) place(); };
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', place);
    return () => { window.removeEventListener('scroll', onScroll, true); window.removeEventListener('resize', place); };
  }, [open]);
  useEffect(() => { setHi(0); }, [q]);
  useEffect(() => {
    const el = listRef.current && listRef.current.children[hi];
    if (el) el.scrollIntoView({ block: 'nearest' });
  }, [hi]);

  const pick = (o) => {
    setOpen(false); setQ('');
    if (o) onChange(o.value, o);
  };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!open) setOpen(true); else setHi((h) => Math.min(h + 1, filtered.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
    else if (e.key === 'Enter') { if (open && filtered[hi]) { e.preventDefault(); pick(filtered[hi]); } }
    else if (e.key === 'Tab') { if (open && q && filtered[hi]) pick(filtered[hi]); else setOpen(false); }
    else if (e.key === 'Escape') { if (open) { e.stopPropagation(); setOpen(false); setQ(''); } }
  };

  return (
    <>
      <input ref={setRefs} className={`input ${className}`} disabled={disabled} autoFocus={autoFocus}
        value={open ? q : selected ? selected.label : ''} placeholder={open && selected ? selected.label : placeholder}
        onFocus={(e) => { setQ(''); e.target.select(); }}
        onBlur={() => { setOpen(false); setQ(''); }}
        onChange={(e) => { setQ(e.target.value); if (!open) setOpen(true); }}
        onKeyDown={onKey} onClick={() => !open && setOpen(true)} />
      {open && pos && createPortal(
        <div className="ss-list" ref={listRef} style={{ left: pos.left, top: pos.top, bottom: pos.bottom, width: pos.width }}>
          {filtered.length === 0 && <div className="ss-empty">No matches</div>}
          {filtered.map((o, i) => (
            <div key={o.value} className={`ss-opt ${i === hi ? 'hi' : ''} ${String(o.value) === String(value) ? 'sel' : ''}`}
              onMouseDown={(e) => { e.preventDefault(); pick(o); }} onMouseEnter={() => setHi(i)}>
              <span>{o.label}</span>
              {o.sub && <span className="ss-sub">{o.sub}</span>}
            </div>
          ))}
        </div>,
        document.body
      )}
    </>
  );
});

// ------------------------------------------------------------------ print helper
/** Renders children into #print-root (created on demand) so window.print() shows only them. */
export function PrintArea({ children }) {
  const [el] = useState(() => {
    let root = document.getElementById('print-root');
    if (!root) { root = document.createElement('div'); root.id = 'print-root'; document.body.appendChild(root); }
    return root;
  });
  return createPortal(children, el);
}
