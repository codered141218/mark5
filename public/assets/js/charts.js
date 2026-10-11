/**
 * Mark5 report charts (no library): inline SVG drawn from the JSON in [data-chart] cards (views/reports/_charts.php).
 *   trend  — one measure over time, this period vs the period before (dashed); measure tabs; crosshair tooltip
 *   series — top 5 items / categories over time (categorical colors in fixed order), legend, "View as table"
 *   bars   — horizontal bars (or vertical for the hours of the day) with a value at the end of each bar
 * Charts redraw on resize; arrow keys move the crosshair when a chart has focus.
 */
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  // Categorical slots in fixed order (validated for colour-blind separation); slot colours never follow rank changes.
  const CATS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];
  const MONEY = new Set(['net_sales', 'avg_ticket', 'gross', 'discounts']);
  const METRIC_LABEL = { net_sales: 'Net sales', receipts: 'Receipts', avg_ticket: 'Avg ticket', pax: 'Guests' };

  const peso = (v) => '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const int = (v) => Number(v || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
  const short = (v, money) => {
    const a = Math.abs(v);
    const s = a >= 1e6 ? (v / 1e6).toFixed(a >= 1e7 ? 0 : 1) + 'M' : a >= 1e3 ? (v / 1e3).toFixed(a >= 1e4 ? 0 : 1) + 'k' : String(Math.round(v * 10) / 10);
    return (money ? '₱' : '') + s.replace(/\.0(?=[kM]?$)/, '');
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDate = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  const rangeText = (r) => (!r ? '' : r[0] === r[1] ? fmtDate(r[0]) : `${fmtDate(r[0])} – ${fmtDate(r[1])}`);

  function el(name, attrs = {}, parent = null) {
    const n = document.createElementNS(NS, name);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    if (parent) parent.appendChild(n);
    return n;
  }

  /** Axis maximum and step: 1 / 2 / 2.5 / 5 × 10^n, about `ticks` gridlines. */
  function niceScale(max, ticks = 4, integer = false) {
    if (!(max > 0)) return { max: integer ? 4 : 1, step: integer ? 1 : 0.25 };
    const raw = max / ticks;
    const p = 10 ** Math.floor(Math.log10(raw));
    const n = raw / p;
    let step = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
    if (integer) step = Math.max(1, Math.ceil(step));
    return { max: Math.ceil(max / step - 1e-9) * step, step };
  }

  /** Tooltip box inside the card body (position: relative). */
  function tooltip(host) {
    const t = document.createElement('div');
    t.className = 'rc-tip';
    t.hidden = true;
    host.appendChild(t);
    return {
      show(html, x, y) {
        t.innerHTML = html;
        t.hidden = false;
        const w = t.offsetWidth;
        const hw = host.clientWidth;
        let left = x + 14;
        if (left + w > hw - 4) left = x - 14 - w;      // flip to the left of the crosshair near the right edge
        t.style.left = Math.max(4, left) + 'px';
        const above = y - t.offsetHeight - 10;
        t.style.top = (above >= 0 ? above : Math.min(y + 16, host.clientHeight - t.offsetHeight - 4)) + 'px';   // no room above: below the point
      },
      hide() { t.hidden = true; },
    };
  }

  /**
   * Line chart. opt = {labels, series: [{name, values, color, dashed, area}], money, integer, tip(i) -> html, height}
   */
  function lineChart(host, opt) {
    host.innerHTML = '';
    const W = Math.max(host.clientWidth, 280);
    const H = opt.height || (W < 520 ? 220 : 280);
    const n = opt.labels.length;
    const all = opt.series.flatMap((s) => s.values);
    const { max, step } = niceScale(Math.max(0, ...all), 4, opt.integer);
    const yLabels = [];
    for (let v = 0; v <= max + 1e-9; v += step) yLabels.push(v);
    const padL = Math.max(...yLabels.map((v) => short(v, opt.money).length)) * 7 + 14;
    const padR = 14, padT = 14, padB = 30;
    const pw = W - padL - padR, ph = H - padT - padB;
    const X = (i) => padL + (n <= 1 ? pw / 2 : (i * pw) / (n - 1));
    const Y = (v) => padT + ph - (v / max) * ph;

    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img', tabindex: '0', class: 'chart-svg',
      'aria-label': opt.aria || 'Chart' });
    host.appendChild(svg);
    // recessive grid + y labels
    for (const v of yLabels) {
      el('line', { x1: padL, x2: W - padR, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis' : 'grid' }, svg);
      const t = el('text', { x: padL - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'tick' }, svg);
      t.textContent = short(v, opt.money);
    }
    // x labels, thinned so they never collide
    const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 64))));
    opt.labels.forEach((lab, i) => {
      if (i % every && i !== n - 1) return;
      if (i === n - 1 && i % every && n > 1 && X(i) - X(i - (i % every)) < 56) return;
      const t = el('text', { x: X(i), y: H - 9, 'text-anchor': n === 1 ? 'middle' : i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle', class: 'tick' }, svg);
      t.textContent = lab;
    });
    // series: dashed comparison first (behind), then the rest
    const order = [...opt.series].sort((a, b) => (b.dashed ? 1 : 0) - (a.dashed ? 1 : 0));
    for (const s of order) {
      const pts = s.values.map((v, i) => [X(i), Y(Math.max(0, v || 0))]);
      if (s.area && pts.length > 1) {
        el('path', { d: `M${pts[0][0]},${Y(0)} ` + pts.map((p) => `L${p[0]},${p[1]}`).join(' ') + ` L${pts[pts.length - 1][0]},${Y(0)} Z`,
          fill: s.color, 'fill-opacity': '0.10', stroke: 'none' }, svg);
      }
      if (pts.length > 1) {
        el('path', { d: 'M' + pts.map((p) => p.join(',')).join(' L'), fill: 'none', stroke: s.color, 'stroke-width': 2,
          'stroke-linejoin': 'round', 'stroke-linecap': 'round', ...(s.dashed ? { 'stroke-dasharray': '5 5' } : {}) }, svg);
      }
      if (pts.length <= 1 || n <= 14) {      // few points: show them
        pts.forEach((p) => el('circle', { cx: p[0], cy: p[1], r: s.dashed ? 2.5 : 3.5, fill: s.color, class: 'pt' }, svg));
      }
    }
    // hover layer: crosshair + rings + tooltip
    const cross = el('line', { y1: padT, y2: padT + ph, class: 'cross', visibility: 'hidden' }, svg);
    const rings = opt.series.map((s) => el('circle', { r: 5, fill: s.color, class: 'ring', visibility: 'hidden' }, svg));
    const tip = tooltip(host);
    let cur = -1;
    const show = (i) => {
      cur = i;
      cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.setAttribute('visibility', 'visible');
      opt.series.forEach((s, k) => {
        rings[k].setAttribute('cx', X(i)); rings[k].setAttribute('cy', Y(Math.max(0, s.values[i] || 0)));
        rings[k].setAttribute('visibility', 'visible');
      });
      tip.show(opt.tip(i), X(i), Math.min(...opt.series.map((s) => Y(Math.max(0, s.values[i] || 0)))));
    };
    const hide = () => { cur = -1; cross.setAttribute('visibility', 'hidden'); rings.forEach((r) => r.setAttribute('visibility', 'hidden')); tip.hide(); };
    const hit = el('rect', { x: padL - 6, y: padT, width: pw + 12, height: ph, fill: 'transparent', class: 'hit' }, svg);
    const pick = (ev) => {
      const r = svg.getBoundingClientRect();
      const x = ((ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left) * (W / r.width);
      show(n <= 1 ? 0 : Math.max(0, Math.min(n - 1, Math.round(((x - padL) / pw) * (n - 1)))));
    };
    hit.addEventListener('pointermove', pick);
    hit.addEventListener('pointerdown', pick);
    hit.addEventListener('pointerleave', (ev) => { if (ev.pointerType !== 'touch') hide(); });   // touch: stays until the next tap
    document.addEventListener('pointerdown', (ev) => { if (cur >= 0 && !svg.contains(ev.target)) hide(); });
    svg.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
        ev.preventDefault();
        show(Math.max(0, Math.min(n - 1, (cur < 0 ? (ev.key === 'ArrowRight' ? -1 : n) : cur) + (ev.key === 'ArrowRight' ? 1 : -1))));
      } else if (ev.key === 'Escape') hide();
    });
    svg.addEventListener('blur', hide);
  }

  /** Horizontal bars as HTML rows (label · bar · value); vertical bars (hours) as SVG with a tooltip. */
  function barChart(host, d) {
    host.innerHTML = '';
    const fmt = d.money ? peso : int;
    if (!d.values.length) { host.innerHTML = '<div class="empty">No sales in this period</div>'; return; }
    const max = Math.max(...d.values, 0) || 1;
    if (!d.vertical) {
      const total = d.values.reduce((a, b) => a + b, 0) || 1;
      host.innerHTML = '<div class="hbars">' + d.labels.map((lab, i) => `
        <div class="hbar" title="${esc(lab)}: ${esc(fmt(d.values[i]))} (${(d.values[i] / total * 100).toFixed(1)}%)">
          <span class="hbar-label">${esc(lab)}</span>
          <span class="hbar-track"><span class="hbar-fill" style="width:${Math.max(0.5, (d.values[i] / max) * 100)}%"></span></span>
          <span class="hbar-value">${esc(d.money ? short(d.values[i], true) : int(d.values[i]))}</span>
        </div>`).join('') + '</div>';
      return;
    }
    const W = Math.max(host.clientWidth, 280), H = W < 520 ? 220 : 260;
    const { max: top, step } = niceScale(max, 4);
    const padL = Math.max(...[0, top].map((v) => short(v, true).length)) * 7 + 14, padR = 8, padT = 12, padB = 28;
    const pw = W - padL - padR, ph = H - padT - padB, n = d.values.length, slot = pw / n, bw = Math.max(2, Math.min(slot - 2, 34));
    const Y = (v) => padT + ph - (v / top) * ph;
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img', class: 'chart-svg', 'aria-label': d.title }, null);
    host.appendChild(svg);
    for (let v = 0; v <= top + 1e-9; v += step) {
      el('line', { x1: padL, x2: W - padR, y1: Y(v), y2: Y(v), class: v === 0 ? 'axis' : 'grid' }, svg);
      el('text', { x: padL - 8, y: Y(v) + 4, 'text-anchor': 'end', class: 'tick' }, svg).textContent = short(v, d.money);
    }
    const tip = tooltip(host);
    const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 44))));
    d.values.forEach((v, i) => {
      const x = padL + i * slot + (slot - bw) / 2, y = Y(v), h = Y(0) - y, r = Math.min(4, bw / 2, h);
      const g = el('g', { class: 'vbar' }, svg);
      if (h > 0) {
        el('path', { d: `M${x},${Y(0)} V${y + r} Q${x},${y} ${x + r},${y} H${x + bw - r} Q${x + bw},${y} ${x + bw},${y + r} V${Y(0)} Z`, fill: 'var(--chart-1)' }, g);
      }
      const hit = el('rect', { x: padL + i * slot, y: padT, width: slot, height: ph, fill: 'transparent' }, g);
      hit.addEventListener('pointerenter', () => { g.classList.add('on'); tip.show(`<b>${esc(d.labels[i])}</b><div class="tip-row"><span>${esc(d.money ? 'Net sales' : 'Value')}</span><b>${esc(fmt(v))}</b></div>`, x + bw / 2, y); });
      hit.addEventListener('pointerleave', (ev) => { if (ev.pointerType === 'touch') return; g.classList.remove('on'); tip.hide(); });
      hit.addEventListener('pointerdown', (ev) => { if (ev.pointerType !== 'touch') return; svg.querySelectorAll('.vbar.on').forEach((x) => x.classList.remove('on')); hit.dispatchEvent(new PointerEvent('pointerenter')); });
      if (i % every === 0) el('text', { x: x + bw / 2, y: H - 9, 'text-anchor': 'middle', class: 'tick' }, svg).textContent = d.labels[i].slice(0, 2);
    });
  }

  // ------------------------------------------------------------------ cards
  function trendCard(card, d) {
    const plot = card.querySelector('[data-plot]');
    const compare = card.querySelector('[data-compare]');
    let metric = 'net_sales';
    const brand = getComputedStyle(card).getPropertyValue('--chart-1').trim() || '#ea580c';
    const prevColor = getComputedStyle(card).getPropertyValue('--chart-prev').trim() || '#9ca3af';
    const draw = () => {
      const money = MONEY.has(metric);
      const fmt = money ? peso : int;
      const cur = d.current.map((b) => b[metric]);
      // the previous period lined up bucket by bucket (it can have one bucket more or less when weeks/months split differently)
      const prev = d.labels.map((_, i) => (d.previous[i] ? d.previous[i][metric] : 0));
      const series = [{ name: 'This period', values: cur, color: brand, area: true }];
      if (compare.checked) series.push({ name: 'Previous period', values: prev, color: prevColor, dashed: true });
      lineChart(plot, {
        labels: d.labels, series, money, integer: !money, aria: `${METRIC_LABEL[metric]} ${d.periods[0]}`,
        tip: (i) => {
          const a = cur[i], b = prev[i];
          let h = `<b>${esc(d.grain === 'day' ? fmtDate(d.ranges[i][0]) : rangeText(d.ranges[i]))}</b>`;
          h += `<div class="tip-row"><span><i class="sw" style="background:${brand}"></i>${esc(METRIC_LABEL[metric])}</span><b>${esc(fmt(a))}</b></div>`;
          if (compare.checked && d.prevRanges[i]) {
            h += `<div class="tip-row"><span><i class="sw sw-dash"></i>${esc(rangeText(d.prevRanges[i]))}</span><b>${esc(fmt(b))}</b></div>`;
            if (b) {
              const pct = ((a - b) / Math.abs(b)) * 100;
              h += `<div class="tip-delta ${pct >= 0 ? 'up' : 'down'}">${pct >= 0 ? '▲ +' : '▼ −'}${Math.abs(pct).toFixed(1)}%</div>`;
            }
          }
          if (metric === 'net_sales') h += `<div class="tip-sub">${int(d.current[i].receipts)} receipts · ${int(d.current[i].pax)} guests</div>`;
          return h;
        },
      });
    };
    card.querySelectorAll('[data-metric]').forEach((b) => b.addEventListener('click', () => {
      metric = b.dataset.metric;
      card.querySelectorAll('[data-metric]').forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      draw();
    }));
    compare.addEventListener('change', draw);
    return draw;
  }

  function seriesCard(card, d) {
    const plot = card.querySelector('[data-plot]');
    const legend = card.querySelector('[data-legend]');
    const table = card.querySelector('[data-table]');
    const toggle = card.querySelector('[data-table-toggle]');
    if (!d.series.length) { plot.innerHTML = '<div class="empty">No sales in this period</div>'; if (toggle) toggle.hidden = true; return () => {}; }
    const series = d.series.map((s, i) => ({ name: s.name, values: s.values, color: CATS[i % CATS.length] }));
    const totals = series.map((s) => s.values.reduce((a, b) => a + b, 0));
    legend.innerHTML = series.map((s, i) => `<span class="lg"><i class="sw" style="background:${s.color}"></i>${esc(s.name)} <span class="muted">${esc(short(totals[i], true))}</span></span>`).join('');
    table.innerHTML = `<div class="table-wrap"><table class="table"><thead><tr><th>${d.grain === 'day' ? 'Date' : d.grain === 'week' ? 'Week of' : 'Month'}</th>${series.map((s) => `<th class="right">${esc(s.name)}</th>`).join('')}</tr></thead>
      <tbody>${d.labels.map((l, i) => `<tr><td>${esc(l)}</td>${series.map((s) => `<td class="right">${esc(peso(s.values[i]))}</td>`).join('')}</tr>`).join('')}</tbody>
      <tfoot><tr><td>Total</td>${totals.map((t) => `<td class="right">${esc(peso(t))}</td>`).join('')}</tr></tfoot></table></div>`;
    toggle.addEventListener('click', () => {
      const on = table.classList.toggle('hidden') === false;
      plot.classList.toggle('hidden', on);
      legend.classList.toggle('hidden', on);
      toggle.textContent = on ? 'View as chart' : 'View as table';
      toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (!on) draw();
    });
    const draw = () => {
      if (plot.classList.contains('hidden')) return;
      lineChart(plot, {
        labels: d.labels, series, money: true, aria: 'Sales over time of the top sellers',
        tip: (i) => {
          const rows = series.map((s) => ({ s, v: s.values[i] })).sort((a, b) => b.v - a.v);
          return `<b>${esc(d.grain === 'day' ? fmtDate(d.ranges[i][0]) : rangeText(d.ranges[i]))}</b>` +
            rows.map(({ s, v }) => `<div class="tip-row"><span><i class="sw" style="background:${s.color}"></i>${esc(s.name)}</span><b>${esc(peso(v))}</b></div>`).join('');
        },
      });
    };
    return draw;
  }

  function init() {
    document.querySelectorAll('[data-chart]').forEach((card) => {
      let d;
      try { d = JSON.parse(card.dataset.json); } catch (e) { return; }
      const kind = card.dataset.chart;
      const draw = kind === 'trend' ? trendCard(card, d) : kind === 'series' ? seriesCard(card, d) : () => barChart(card.querySelector('[data-plot]'), d);
      draw();
      let w = card.clientWidth;
      if (window.ResizeObserver) {
        new ResizeObserver(() => { if (Math.abs(card.clientWidth - w) > 4) { w = card.clientWidth; draw(); } }).observe(card);
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
