/**
 * Inventory screens (plain JavaScript). Loaded at the end of the inventory views; uses window.App from app.js.
 *
 *  <button data-ask="Post this document?" data-ask-message="…" data-ok="Post">   confirm, then submit with this button
 *  <button data-print>                                print the page (only #print-root is printed, see app.css)
 *  <form data-item-form>      item page: sections per item type, live recipe cost, food cost % and margin
 *  <form data-doc-form>       receiving / issuance / wastage: units per item, costs and totals, auto-added blank line
 *  <form data-count-sheet>    count sheet: live variance and totals, filters, Enter / arrow keys move between inputs
 *
 * JSON blocks embedded by the views: #inv-items (item data by id), #inv-type-hints, #inv-uom-abbr.
 * The server recalculates and validates everything on save; these figures are only a preview.
 */
(function () {
  'use strict';
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const n = (v) => { const x = parseFloat(v); return Number.isFinite(x) ? x : 0; };
  const r2 = (v) => Math.round(v * 100) / 100;
  const r4 = (v) => Math.round(v * 10000) / 10000;
  const json = (id) => { const el = document.getElementById(id); return el ? JSON.parse(el.textContent) : {}; };
  const fmt4 = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
  const cost4 = (v) => fmt4.format(v);

  // ------------------------------------------------------------------ units an item can be entered in (cached)
  const unitsCache = {};
  function unitsFor(itemId) {
    if (!unitsCache[itemId]) {
      unitsCache[itemId] = App.api('GET', `/api/inventory/items/${itemId}/units`).catch((err) => {
        delete unitsCache[itemId];
        App.toast(err.message, 'error');
        return [];
      });
    }
    return unitsCache[itemId];
  }

  /** Replace a unit <select>'s options; each option carries data-factor (base units per 1). */
  function fillUnits(select, units, selected) {
    select.innerHTML = units.map((u) => `<option value="${u.uom_id}" data-factor="${u.factor}">${App.esc(u.abbr)}</option>`).join('');
    if (selected && units.some((u) => String(u.uom_id) === String(selected))) select.value = String(selected);
  }

  /** Base units per 1 of the selected unit, or null when unknown. */
  function factorOf(select) {
    const o = select && select.selectedOptions[0];
    return o && o.dataset.factor !== '' ? n(o.dataset.factor) : null;
  }

  // ------------------------------------------------------------------ confirm buttons, printing, unsaved changes
  document.addEventListener('click', async (e) => {
    if (e.target.closest('[data-print]')) { window.print(); return; }
    const btn = e.target.closest('button[data-ask]');
    if (!btn || !btn.form) return;
    e.preventDefault();
    if (!btn.form.reportValidity()) return;
    const message = [btn.dataset.askMessage, btn.form.dataset.askExtra].filter(Boolean).join(' ');
    if (await App.ask({ title: btn.dataset.ask, message, okText: btn.dataset.ok || 'OK' })) btn.form.requestSubmit(btn);
  });

  /** Warn before leaving a form with unsaved changes. */
  function warnUnsaved(form) {
    let dirty = false;
    const mark = (e) => { if (e.target.name) dirty = true; };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  // ------------------------------------------------------------------ item form
  function initItemForm(form) {
    const items = json('inv-items');
    const hints = json('inv-type-hints');
    const abbr = json('inv-uom-abbr');
    const typeSel = $('[data-item-type]', form);
    const baseSel = $('[data-base-unit]', form);
    const price = $('[data-price]', form);
    const avgCost = $('[data-avg-cost]', form);
    const comps = $('[data-rows="components"]', form);
    const vatRate = n(form.dataset.vatRate);
    const type = () => typeSel.value;
    const out = (key, text) => { const el = $(`[data-out="${key}"]`, form); if (el) el.textContent = text; };
    const tone = (el, t) => { el.classList.remove('stat-red', 'stat-amber', 'stat-green'); if (t) el.classList.add('stat-' + t); };
    const field = (tr, name) => $(`[data-field="${name}"]`, tr);

    function showSections() {
      $$('[data-show-for]', form).forEach((el) => el.classList.toggle('hidden', !el.dataset.showFor.split(' ').includes(type())));
      $('[data-type-hint]', form).textContent = hints[type()] || '';
    }

    function updateAbbr() {
      $$('[data-base-abbr]', form).forEach((el) => { el.textContent = abbr[baseSel.value] || 'base unit'; });
    }

    /** Cost of one recipe line = qty x factor x component unit cost (null when the unit is unknown). */
    function lineCost(tr) {
      const it = items[field(tr, 'component_id').value];
      const f = factorOf(field(tr, 'uom_id'));
      return it && f !== null ? n(field(tr, 'qty').value) * f * n(it.cost) : null;
    }

    function costing(cost) {
      const card = $('[data-costing]', form);
      const p = n(price.value);
      const show = ['composite', 'retail'].includes(type()) && p > 0;
      card.classList.toggle('hidden', !show);
      if (!show) return;
      const net = p / (1 + vatRate);
      const fc = net > 0 ? (cost / net) * 100 : null;
      const margin = net - cost;
      out('price', App.peso(p));
      out('net', App.peso(net));
      out('cost-label', type() === 'composite' ? 'Recipe cost' : 'Unit cost');
      out('cost', App.peso(cost));
      out('fc', fc === null ? '—' : fc.toFixed(2) + '%');
      out('margin', App.peso(margin));
      out('margin-pct', net > 0 ? `${((margin / net) * 100).toFixed(2)}% of net price` : '');
      tone($('[data-tone="fc"]', card), fc === null ? '' : fc > 45 ? 'red' : fc > 35 ? 'amber' : 'green');
      tone($('[data-tone="margin"]', card), margin < 0 ? 'red' : '');
      $('[data-out="fc-warn"]', card).classList.toggle('hidden', !(fc > 45));
    }

    function recalc() {
      let total = 0;
      const rows = $$('[data-row]', comps).map((tr) => {
        const it = items[field(tr, 'component_id').value];
        const c = lineCost(tr);
        total += c || 0;
        $('[data-unit-cost]', tr).textContent = it ? `₱${cost4(it.cost)}/${it.uom || ''}` : '';
        $('[data-cost]', tr).textContent = c === null ? (it ? '—' : '') : cost4(c);
        return [tr, c];
      });
      rows.forEach(([tr, c]) => { $('[data-share]', tr).textContent = c && total ? ((c / total) * 100).toFixed(1) + '%' : ''; });
      $('[data-recipe-total]', form).textContent = App.peso(total);
      costing(type() === 'composite' ? total : n(avgCost ? avgCost.value : 0));
    }

    typeSel.addEventListener('change', () => {
      if (form.dataset.new === '1') $('[data-sellable]', form).checked = type() !== 'raw';
      showSections();
      recalc();
    });
    baseSel.addEventListener('change', updateAbbr);
    price.addEventListener('input', recalc);
    if (avgCost) avgCost.addEventListener('input', recalc);
    comps.addEventListener('input', recalc);
    comps.addEventListener('change', async (e) => {
      if (e.target.matches('[data-field="component_id"]')) {
        const unitSel = field(e.target.closest('[data-row]'), 'uom_id');
        if (e.target.value) fillUnits(unitSel, await unitsFor(e.target.value), null);
        else unitSel.innerHTML = '';
      }
      recalc();
    });
    form.addEventListener('rows:change', () => { updateAbbr(); recalc(); });

    showSections();
    recalc();
    warnUnsaved(form);
  }

  // ------------------------------------------------------------------ receiving / issuance / wastage form
  function initDocForm(form) {
    const items = json('inv-items');
    const isRec = form.dataset.docType === 'RECEIVE';
    const vatRate = n(form.dataset.vatRate);
    const box = $('[data-rows="lines"]', form);
    const field = (tr, name) => $(`[data-field="${name}"]`, tr);

    /** Keep one empty line at the bottom to type the next item into. */
    function ensureBlankRow() {
      const rows = $$('[data-row]', box);
      const last = rows[rows.length - 1];
      if (!last || field(last, 'item_id').value) App.addRow('lines');
    }

    function syncTotal(tr) {
      const q = field(tr, 'qty').value;
      const c = field(tr, 'unit_cost').value;
      if (q !== '' && c !== '') field(tr, 'line_total').value = r2(n(q) * n(c)).toFixed(2);
    }

    /** Delivery cost pre-filled from the item's last cost, converted to the chosen unit. */
    function setAutoCost(tr) {
      const it = items[field(tr, 'item_id').value];
      const f = factorOf(field(tr, 'uom_id'));
      const c = it ? r4(n(it.cost) * (f === null ? 1 : f)) : 0;
      field(tr, 'unit_cost').value = c ? String(c) : '';
      tr.dataset.auto = c ? '1' : '';
      syncTotal(tr);
    }

    /** Line value: the delivery line total, or for issuance / wastage qty x factor x average (recipe) cost. */
    function lineCost(tr) {
      if (isRec) return n(field(tr, 'line_total').value);
      const it = items[field(tr, 'item_id').value];
      const f = factorOf(field(tr, 'uom_id'));
      return it && f !== null ? n(field(tr, 'qty').value) * f * n(it.unit_cost) : 0;
    }

    function recalc() {
      let total = 0;
      let count = 0;
      $$('[data-row]', box).forEach((tr) => {
        const has = !!field(tr, 'item_id').value;
        const c = has ? lineCost(tr) : 0;
        if (has) { count++; total += c; }
        if (!isRec) $('[data-est]', tr).textContent = has ? App.peso(c) : '';
      });
      $('[data-line-count]', form).textContent = `${count} item${count === 1 ? '' : 's'}`;
      $('[data-doc-total]', form).textContent = App.peso(total);
      const vatBox = $('[data-vat-inclusive]', form);
      const vat = isRec && vatBox && vatBox.checked && vatRate ? total - total / (1 + vatRate) : 0;
      $('[data-vat-note]', form).textContent = vat ? `incl. input VAT ${App.peso(vat)} · net ${App.peso(total - vat)}` : '';
      form.dataset.askExtra = `Total: ${App.peso(total)}.`;
    }

    box.addEventListener('change', async (e) => {
      const tr = e.target.closest('[data-row]');
      if (!tr) return;
      const name = e.target.dataset.field;
      if (name === 'item_id') {
        const id = e.target.value;
        const unitSel = field(tr, 'uom_id');
        if (id) {
          fillUnits(unitSel, await unitsFor(id), items[id] && items[id].base);
          if (isRec) setAutoCost(tr);
          ensureBlankRow();
          field(tr, 'qty').focus();
        } else {
          unitSel.innerHTML = '';
        }
      } else if (name === 'uom_id' && isRec && tr.dataset.auto === '1') {
        setAutoCost(tr);
      }
      recalc();
    });
    box.addEventListener('input', (e) => {
      const tr = e.target.closest('[data-row]');
      const name = e.target.dataset.field;
      if (tr && isRec && (name === 'qty' || name === 'unit_cost')) {
        if (name === 'unit_cost') tr.dataset.auto = '';
        syncTotal(tr);
      }
      if (tr && isRec && name === 'line_total') {
        tr.dataset.auto = '';
        const q = n(field(tr, 'qty').value);
        if (q) field(tr, 'unit_cost').value = String(r4(n(e.target.value) / q));
      }
      recalc();
    });
    form.addEventListener('rows:change', () => { ensureBlankRow(); recalc(); });

    const mode = $('[data-payment-mode]', form);
    if (mode) {
      const sync = () => {
        $('[data-bank-field]', form).classList.toggle('hidden', mode.value !== 'bank');
        $('[data-supplier-required]', form).classList.toggle('hidden', mode.value !== 'credit');
      };
      mode.addEventListener('change', sync);
      sync();
      $('[data-vat-inclusive]', form).addEventListener('change', recalc);
    }

    // Issuance: "Staff meal" defaults the expense account to Staff Meals, anything else to Supplies,
    // until the user picks an account themselves.
    const issued = $('[data-issued-to]', form);
    const acct = $('[data-expense-account]', form);
    if (issued && acct) {
      acct.addEventListener('change', () => { acct.dataset.touched = '1'; });
      issued.addEventListener('input', () => {
        if (acct.dataset.touched !== undefined) return;
        const id = /staff/i.test(issued.value) ? form.dataset.accountStaff : form.dataset.accountSupplies;
        if (id) acct.value = id;
      });
    }

    ensureBlankRow();
    recalc();
    warnUnsaved(form);
  }

  // ------------------------------------------------------------------ count sheet
  function initCountSheet(form) {
    const lines = $$('tr[data-line]', form);
    const inputs = $$('input[data-count]', form);
    const search = $('[data-count-search]', form);
    const signed = (v, fmt) => (v > 0 ? '+' : '') + fmt(v);
    const setTone = (el, v) => {
      el.classList.remove('text-red', 'text-green');
      if (Math.abs(v) >= 0.00005) el.classList.add(v < 0 ? 'text-red' : 'text-green');
    };
    const stat = (key, text, v) => { const el = $(`[data-stat="${key}"]`); if (!el) return; el.textContent = text; if (v !== undefined) setTone(el, v); };

    function recalc() {
      let counted = 0;
      let short = 0;
      let over = 0;
      lines.forEach((tr) => {
        const input = $('[data-count]', tr);
        const varCell = $('[data-var]', tr);
        const valCell = $('[data-value]', tr);
        tr.dataset.counted = input.value === '' ? '' : '1';
        if (input.value === '') {
          tr.dataset.variance = '';
          varCell.textContent = '';
          valCell.textContent = '';
          return;
        }
        const v = r4(n(input.value) - n(tr.dataset.sys));
        const value = r2(v * n(tr.dataset.cost));
        counted++;
        if (value < 0) short += value; else over += value;
        tr.dataset.variance = Math.abs(v) >= 0.00005 ? '1' : '';
        varCell.textContent = signed(v, App.qty);
        valCell.textContent = signed(value, App.money);
        setTone(varCell, v);
        setTone(valCell, value);
      });
      stat('counted', `${counted} / ${lines.length}`);
      stat('short', App.peso(short), short);
      stat('over', App.peso(over), over);
      stat('net', App.peso(short + over), short + over);
      stat('net-foot', App.peso(short + over), short + over);
      const changed = inputs.filter((i) => i.value !== i.defaultValue).length;
      $('[data-dirty-note]', form).textContent = changed ? `${changed} unsaved change${changed === 1 ? '' : 's'}` : '';
    }

    function filter() {
      const q = search.value.trim().toLowerCase();
      const onlyUncounted = $('[data-count-filter="uncounted"]', form).checked;
      const onlyVariance = $('[data-count-filter="variance"]', form).checked;
      let shown = 0;
      lines.forEach((tr) => {
        const ok = (!q || tr.dataset.search.includes(q)) && (!onlyUncounted || !tr.dataset.counted) && (!onlyVariance || tr.dataset.variance);
        tr.classList.toggle('hidden', !ok);
        if (ok) shown++;
      });
      $$('tr[data-group]', form).forEach((g) => {
        let any = false;
        for (let el = g.nextElementSibling; el && !el.hasAttribute('data-group'); el = el.nextElementSibling) {
          if (!el.classList.contains('hidden')) any = true;
        }
        g.classList.toggle('hidden', !any);
      });
      $('[data-count-shown]', form).textContent = `${shown} of ${lines.length} items`;
    }

    search.addEventListener('input', filter);
    $$('[data-count-filter]', form).forEach((cb) => cb.addEventListener('change', filter));
    if (!inputs.length) return; // posted or cancelled: figures come from the server

    form.addEventListener('input', (e) => { if (e.target.matches('[data-count]')) recalc(); });
    form.addEventListener('keydown', (e) => {
      if (!e.target.matches('[data-count]') || !['Enter', 'ArrowDown', 'ArrowUp'].includes(e.key)) return;
      e.preventDefault();
      const visible = inputs.filter((i) => !i.closest('tr').classList.contains('hidden'));
      const next = visible[visible.indexOf(e.target) + (e.key === 'ArrowUp' ? -1 : 1)];
      if (next) next.focus();
    });
    recalc();
    warnUnsaved(form);
  }

  document.addEventListener('DOMContentLoaded', () => {
    $$('form[data-item-form]').forEach(initItemForm);
    $$('form[data-doc-form]').forEach(initDocForm);
    $$('form[data-count-sheet]').forEach(initCountSheet);
  });
})();
