/**
 * Production plan and BOM template forms (views/inventory/production). Uses window.App from app.js.
 *  - finished product chosen -> its units fill the unit picker (data-output-unit)
 *  - production plan: a BOM template fixes the finished product and its unit; batches = quantity / template yield;
 *    editing a material line marks the plan as hand-edited (otherwise the server re-scales the template's ingredients)
 */
(function () {
  'use strict';
  const $ = (sel, root = document) => root.querySelector(sel);

  async function fillUnits(select, itemId, selected) {
    if (!select) return;
    if (!itemId) { select.innerHTML = ''; return; }
    try {
      const units = await App.api('GET', `/api/inventory/items/${itemId}/units`);
      select.innerHTML = units.map((u) => `<option value="${u.uom_id}" data-factor="${u.factor}">${App.esc(u.abbr)}</option>`).join('');
      if (selected && units.some((u) => String(u.uom_id) === String(selected))) select.value = String(selected);
    } catch (e) { App.toast(e.message, 'error'); }
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  document.addEventListener('DOMContentLoaded', () => {
    const form = $('form[data-prod-form]') || $('form[data-doc-form]');
    if (!form) return;
    const output = $('[data-output]', form);
    const unit = $('[data-output-unit]', form);
    const tplSel = $('[data-template]', form);
    const planned = $('[data-planned]', form);
    const tplData = $('#prod-templates') ? JSON.parse($('#prod-templates').textContent) : {};
    const factor = () => { const o = unit && unit.selectedOptions[0]; return o ? Number(o.dataset.factor || 1) : 1; };

    if (output) output.addEventListener('change', () => { if (!tplSel || !tplSel.value) fillUnits(unit, output.value, null); });
    if (!tplSel) return;   // BOM template form

    function batches() {
      const t = tplData[tplSel.value];
      const box = $('[data-batches]', form);
      if (!box) return;
      if (!t || !planned.value) { box.textContent = '—'; return; }
      // yield and planned quantity in base units (the template's unit factor comes from the same unit list)
      const opt = Array.from(unit.options).find((o) => o.value === String(t.uom));
      const yieldBase = t.yield * (opt ? Number(opt.dataset.factor || 1) : 1);
      const b = yieldBase > 0 ? (Number(planned.value) * factor()) / yieldBase : 0;
      box.textContent = b ? `${Math.round(b * 100) / 100} batch${b === 1 ? '' : 'es'} (${t.yield} ${t.uom_abbr} each)` : '—';
    }

    function syncTemplate(first) {
      const t = tplData[tplSel.value];
      const outField = $('[data-output-field]', form);
      if (outField) outField.classList.toggle('hidden', !!t);
      const linesBox = $('[data-lines-box]', form);
      const tplNote = $('[data-tpl-note]', form);
      if (tplNote) tplNote.classList.toggle('hidden', !t);
      if (linesBox && tplNote) linesBox.classList.toggle('hidden', !!t);   // new plan from a template: ingredients come from it
      if (t) {
        if (output) output.value = String(t.output);
        if (!first) fillUnits(unit, t.output, t.uom).then(batches);
      }
      batches();
    }
    tplSel.addEventListener('change', () => syncTemplate(false));
    planned.addEventListener('input', batches);
    if (unit) unit.addEventListener('change', batches);
    const lines = $('[data-rows="lines"]', form);
    if (lines) ['input', 'change'].forEach((ev) => lines.addEventListener(ev, (e) => { if (e.isTrusted) form.lines_edited.value = '1'; }));
    syncTemplate(true);
    if (tplSel.value && unit && !unit.options.length) fillUnits(unit, tplData[tplSel.value].output, tplData[tplSel.value].uom).then(batches);
  });
})();
