/**
 * Mark5 back-office helpers (plain JavaScript, no framework).
 * Everything is driven by data-* attributes in the PHP views:
 *
 *  <input data-table-search>                 filters the rows of the table below it
 *  <table data-table>                        click a header (or use the "Sort by" dropdown) to sort; rows with data-href are clickable
 *                                            bulk: [data-bulk-all] / [data-bulk-id] checkboxes + [data-bulk-bar] buttons (see Table.php)
 *  <button data-open="dlgId" data-fill='{"name":"Rice"}' data-action="/x/1">   opens <dialog id="dlgId">, fills its form
 *  <button data-close>                       closes the dialog it is in
 *  <form data-confirm="Delete this item?">   asks before submitting
 *  <form data-prompt="Reason" data-pin>      asks for a reason (and optional manager PIN) → hidden inputs reason / pin
 *  <div data-rows="lines"> + <template id="lines-template"> + <button data-add-row="lines">   dynamic form rows
 *      template HTML uses __i__ as the row index; rows contain <button data-remove-row>
 *  <select data-combo>                       type-to-search dropdown
 *  <select data-range-preset>                date range presets for the from/to inputs in the same form
 *
 * JS API (used by pages and the POS):  App.api(method, url, body), App.toast(msg, type), App.ask({...}),
 *                                       App.money(n), App.peso(n), App.url(path)
 */
(function () {
  'use strict';
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const meta = (name) => (document.querySelector(`meta[name="${name}"]`) || {}).content || '';
  const baseUrl = meta('base-url').replace(/\/$/, '');

  const fmt2 = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const App = {
    url: (path) => baseUrl + (path.startsWith('/') ? path : '/' + path),
    money: (n) => fmt2.format(Number(n) || 0),
    peso: (n) => '₱' + fmt2.format(Number(n) || 0),
    qty: (n) => String(Math.round((Number(n) || 0) * 10000) / 10000),
    esc: (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),

    /** JSON request to a PHP endpoint. Throws Error(message) on failure. */
    async api(method, url, body) {
      const opts = { method, headers: { Accept: 'application/json', 'X-CSRF-Token': meta('csrf-token') } };
      if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
      const res = await fetch(App.url(url), opts);
      let data = null;
      try { data = await res.json(); } catch (e) { data = null; }
      if (res.status === 401) { window.location.href = App.url('/login'); }
      if (!res.ok) throw new Error((data && data.error) || `Request failed (${res.status})`);
      return data;
    },

    toast(msg, type = 'success') {
      let box = $('.toasts');
      if (!box) { box = document.createElement('div'); box.className = 'toasts'; document.body.appendChild(box); }
      const t = document.createElement('div');
      t.className = `toast toast-${type}`;
      t.textContent = msg;
      box.appendChild(t);
      setTimeout(() => t.remove(), type === 'error' ? 6000 : 3000);
    },

    /**
     * Small modal prompt. Resolves to null (cancelled), true (confirmed) or {value, pin}.
     * App.ask({ title, message, input: 'Reason', required: true, pin: true, danger: true, okText, defaultValue, inputType })
     */
    ask(o) {
      return new Promise((resolve) => {
        const d = document.createElement('dialog');
        d.className = 'modal';
        d.innerHTML = `<form method="dialog">
          <div class="modal-head"><h3>${App.esc(o.title || 'Please confirm')}</h3></div>
          <div class="modal-body">
            ${o.message ? `<p style="margin-top:0">${App.esc(o.message)}</p>` : ''}
            ${o.input ? `<label class="field"><span class="field-label">${App.esc(o.input)}</span><input class="input" name="v" type="${o.inputType || 'text'}" ${o.inputType === 'number' ? 'step="any" inputmode="decimal"' : ''} value="${App.esc(o.defaultValue || '')}"></label>` : ''}
            ${o.pin ? `<label class="field mt"><span class="field-label">Manager PIN (if required)</span><input class="input" name="pin" type="password" inputmode="numeric" autocomplete="off"><span class="field-hint">Leave blank if your role allows this action.</span></label>` : ''}
          </div>
          <div class="modal-foot"><button class="btn" value="cancel" type="button" data-cancel>Cancel</button>
            <button class="btn ${o.danger ? 'btn-danger' : 'btn-primary'}" value="ok" type="submit">${App.esc(o.okText || 'OK')}</button></div>
        </form>`;
        document.body.appendChild(d);
        const done = (v) => { d.close(); d.remove(); resolve(v); };
        $('[data-cancel]', d).onclick = () => done(null);
        d.addEventListener('cancel', (e) => { e.preventDefault(); done(null); });
        $('form', d).addEventListener('submit', (e) => {
          e.preventDefault();
          const v = o.input ? $('[name=v]', d).value : '';
          if (o.input && o.required && !v.trim()) { $('[name=v]', d).focus(); return; }
          done(o.input || o.pin ? { value: v, pin: o.pin ? $('[name=pin]', d).value : '' } : true);
        });
        d.showModal();
        const first = $('input', d);
        if (first) { first.focus(); first.select(); }
      });
    },
  };
  window.App = App;

  // ------------------------------------------------------------------ sidebar drop-down sections: remember which are open
  (function navGroups() {
    const KEY = 'mark5_nav_open';
    let open = [];
    try { open = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { open = []; }
    $$('details[data-nav-group]').forEach((d) => {
      if (open.includes(d.dataset.navGroup)) d.open = true;
      // only the user's own clicks are remembered (not the section opened because it holds the current page)
      d.querySelector('summary').addEventListener('click', () => setTimeout(() => {
        const name = d.dataset.navGroup;
        open = open.filter((x) => x !== name);
        if (d.open) open.push(name);
        try { localStorage.setItem(KEY, JSON.stringify(open)); } catch (e) { /* private mode */ }
      }));
    });
  })();

  // ------------------------------------------------------------------ sidebar (mobile)
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-toggle-sidebar]')) $('#sidebar')?.classList.toggle('open');
  });

  // ------------------------------------------------------------------ tables: search, sort, row links
  function initTables(root = document) {
    $$('[data-table-search]', root).forEach((input) => {
      const table = input.closest('.datatable')?.querySelector('table');
      if (!table) return;
      input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        $$('tbody tr', table).forEach((tr) => { tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none'; });
      });
    });
    $$('table[data-table]', root).forEach((table, tIndex) => {
      const wrap = table.closest('.datatable');
      const sortSel = wrap && $('[data-table-sort]', wrap);
      const storeKey = `mark5_sort:${location.pathname}:${tIndex}`;
      const ths = $$('thead th', table);
      const sortable = ths.map((th, idx) => ({ th, idx })).filter(({ th }) => !('nosort' in th.dataset) && th.textContent.trim());
      ths.forEach((th, idx) => {
        if ('nosort' in th.dataset || !th.textContent.trim()) return;
        th.classList.add('sortable');
        th.title = 'Click to sort';
        th.addEventListener('click', () => sortTable(table, idx, th.dataset.sortDir === 'asc' ? 'desc' : 'asc'));
      });
      if (sortSel) {
        const words = (type) => (['money', 'qty', 'int', 'percent'].includes(type) ? ['low → high', 'high → low']
          : ['date', 'datetime'].includes(type) ? ['oldest first', 'newest first'] : ['A → Z', 'Z → A']);
        for (const { th, idx } of sortable) {
          const [a, d] = words(th.dataset.type);
          sortSel.insertAdjacentHTML('beforeend', `<option value="${idx}:asc">${App.esc(th.textContent.trim())}: ${a}</option><option value="${idx}:desc">${App.esc(th.textContent.trim())}: ${d}</option>`);
        }
        sortSel.addEventListener('change', () => {
          if (!sortSel.value) { try { localStorage.removeItem(storeKey); } catch (e) { /* ignore */ } window.location.reload(); return; }
          const [idx, dir] = sortSel.value.split(':');
          sortTable(table, Number(idx), dir);
        });
      }
      table._sortChanged = (idx, dir) => {
        if (sortSel) sortSel.value = `${idx}:${dir}`;
        try { localStorage.setItem(storeKey, `${idx}:${dir}`); } catch (e) { /* ignore */ }
      };
      // Re-apply the sort the user chose last time on this page
      let saved = '';
      try { saved = localStorage.getItem(storeKey) || ''; } catch (e) { /* ignore */ }
      if (saved && sortable.some(({ idx }) => String(idx) === saved.split(':')[0])) {
        const [idx, dir] = saved.split(':');
        sortTable(table, Number(idx), dir);
      }
      table.addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-href]');
        if (tr && !e.target.closest('a,button,input,select,form,label,.dt-check')) window.location.href = tr.dataset.href;
      });
      if (wrap && $('[data-bulk-bar]', wrap)) initBulk(wrap, table);
    });
  }

  function sortTable(table, idx, dir) {
    const th = $$('thead th', table)[idx];
    if (!th) return;
    $$('thead th', table).forEach((h) => delete h.dataset.sortDir);
    th.dataset.sortDir = dir;
    const numeric = ['money', 'qty', 'int', 'percent'].includes(th.dataset.type);
    const rows = $$('tbody tr', table);
    const val = (tr) => { const td = tr.children[idx]; return td ? (td.dataset.sort ?? td.textContent.trim()) : ''; };
    rows.sort((a, b) => {
      const x = val(a);
      const y = val(b);
      if (x === '' && y !== '') return 1;          // empty values always last
      if (y === '' && x !== '') return -1;
      const c = numeric ? (parseFloat(x) || 0) - (parseFloat(y) || 0) : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
      return dir === 'asc' ? c : -c;
    });
    const body = $('tbody', table);
    rows.forEach((r) => body.appendChild(r));
    if (table._sortChanged) table._sortChanged(idx, dir);
  }

  /** Row checkboxes + the bulk action bar of a Table::html(..., ['bulk' => ...]) table. */
  function initBulk(wrap, table) {
    const bar = $('[data-bulk-bar]', wrap);
    const all = $('[data-bulk-all]', table);
    const visible = () => $$('tbody tr', table).filter((tr) => tr.style.display !== 'none');
    const boxes = () => $$('[data-bulk-id]', table);
    const chosen = () => boxes().filter((b) => b.checked && b.closest('tr').style.display !== 'none');
    const paint = () => {
      const n = chosen().length;
      $('[data-bulk-count]', bar).textContent = n;
      bar.hidden = n === 0;
      boxes().forEach((b) => b.closest('tr').classList.toggle('selected', b.checked));
      const vis = visible().map((tr) => $('[data-bulk-id]', tr)).filter(Boolean);
      if (all) { all.checked = vis.length > 0 && vis.every((b) => b.checked); all.indeterminate = !all.checked && vis.some((b) => b.checked); }
    };
    table.addEventListener('change', (e) => {
      if (e.target === all) visible().forEach((tr) => { const b = $('[data-bulk-id]', tr); if (b) b.checked = all.checked; });
      if (e.target.matches('[data-bulk-id], [data-bulk-all]')) paint();
    });
    const search = $('[data-table-search]', wrap);
    if (search) search.addEventListener('input', paint);
    bar.addEventListener('click', async (e) => {
      if (e.target.closest('[data-bulk-clear]')) { boxes().forEach((b) => { b.checked = false; }); paint(); return; }
      const btn = e.target.closest('[data-bulk-action]');
      if (!btn) return;
      const ids = chosen().map((b) => b.value);
      if (!ids.length) return;
      const msg = (btn.dataset.confirm || '').replace('{n}', ids.length);
      if (msg && !(await App.ask({ title: btn.textContent.trim(), message: msg, okText: btn.textContent.trim(), danger: btn.classList.contains('btn-danger') }))) return;
      const form = document.createElement('form');
      form.method = 'post';
      form.action = btn.dataset.url;
      const add = (name, value) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = name; i.value = value; form.appendChild(i); };
      add('_token', meta('csrf-token'));
      add('action', btn.dataset.bulkAction);
      ids.forEach((id) => add('ids[]', id));
      document.body.appendChild(form);
      form.submit();
    });
    paint();
  }

  // ------------------------------------------------------------------ dialogs
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open]');
    if (opener) {
      e.preventDefault();
      const d = document.getElementById(opener.dataset.open);
      if (!d) return;
      const form = $('form', d);
      if (form && opener.dataset.reset !== undefined) form.reset();
      if (form && opener.dataset.action) form.action = App.url(opener.dataset.action);
      if (form && opener.dataset.fill) {
        const data = JSON.parse(opener.dataset.fill);
        Object.entries(data).forEach(([k, v]) => {
          $$(`[name="${k}"]`, form).forEach((el) => {
            if (el.type === 'checkbox') el.checked = !!Number(v) || v === true;
            else el.value = v ?? '';
            el.dispatchEvent(new Event('change', { bubbles: true }));
          });
        });
      }
      const title = opener.dataset.title;
      if (title && $('.modal-head h3', d)) $('.modal-head h3', d).textContent = title;
      d.showModal();
      const first = $('input:not([type=hidden]),select,textarea', d);
      if (first) first.focus();
    }
    if (e.target.closest('[data-close]')) e.target.closest('dialog')?.close();
  });

  // ------------------------------------------------------------------ confirm / prompt before submit
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    if (form.dataset.confirmed === '1') return;
    const needs = form.dataset.confirm || form.dataset.prompt || form.dataset.pin !== undefined;
    if (!needs) return;
    e.preventDefault();
    const r = await App.ask({
      title: form.dataset.title || form.dataset.confirm || 'Please confirm',
      message: form.dataset.prompt ? form.dataset.confirm : '',
      input: form.dataset.prompt, required: form.dataset.prompt !== undefined, pin: form.dataset.pin !== undefined,
      danger: form.dataset.danger !== undefined, okText: form.dataset.ok,
    });
    if (!r) return;
    const setHidden = (name, value) => {
      let el = form.querySelector(`input[type=hidden][name="${name}"]`);
      if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = name; form.appendChild(el); }
      el.value = value;
    };
    if (typeof r === 'object') {
      if (form.dataset.prompt) setHidden(form.dataset.promptName || 'reason', r.value);
      if (form.dataset.pin !== undefined) setHidden('pin', r.pin);
    }
    form.dataset.confirmed = '1';
    if (e.submitter && e.submitter.name) setHidden(e.submitter.name, e.submitter.value);
    form.submit();
  }, true);

  // ------------------------------------------------------------------ dynamic rows
  let rowCounter = 1000;
  function addRow(containerId, values) {
    const box = document.querySelector(`[data-rows="${containerId}"]`);
    const tpl = document.getElementById(`${containerId}-template`);
    if (!box || !tpl) return null;
    const html = tpl.innerHTML.replace(/__i__/g, String(rowCounter++));
    const wrap = document.createElement(box.tagName === 'TBODY' ? 'tbody' : 'div');
    wrap.innerHTML = html.trim();
    const row = wrap.firstElementChild;
    box.appendChild(row);
    if (values) Object.entries(values).forEach(([k, v]) => { const el = row.querySelector(`[data-field="${k}"]`); if (el) el.value = v; });
    initCombos(row);
    box.dispatchEvent(new CustomEvent('rows:change', { bubbles: true }));
    return row;
  }
  App.addRow = addRow;
  document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-add-row]');
    if (add) { e.preventDefault(); const row = addRow(add.dataset.addRow); row?.querySelector('input,select')?.focus(); }
    const rm = e.target.closest('[data-remove-row]');
    if (rm) {
      e.preventDefault();
      const box = rm.closest('[data-rows]');
      rm.closest('[data-row]')?.remove();
      box?.dispatchEvent(new CustomEvent('rows:change', { bubbles: true }));
    }
  });

  // ------------------------------------------------------------------ searchable select
  function initCombos(root = document) {
    $$('select[data-combo]', root).forEach((sel) => {
      if (sel.dataset.comboReady) return;
      sel.dataset.comboReady = '1';
      const input = document.createElement('input');
      input.className = 'input';
      input.type = 'text';
      input.autocomplete = 'off';
      input.placeholder = sel.dataset.placeholder || 'Type to search…';
      const sync = () => { const o = sel.options[sel.selectedIndex]; input.value = o && o.value ? o.text : ''; };
      sync();
      sel.style.display = 'none';
      sel.after(input);
      let list = null; let hl = 0; let matches = [];
      const close = () => { list?.remove(); list = null; };
      const choose = (opt) => { sel.value = opt.value; sync(); close(); sel.dispatchEvent(new Event('change', { bubbles: true })); };
      const render = () => {
        const q = input.value.toLowerCase();
        matches = Array.from(sel.options).filter((o) => o.value && (!q || o.text.toLowerCase().includes(q))).slice(0, 80);
        if (!list) { list = document.createElement('div'); list.className = 'combo-list'; (input.closest('dialog') || document.body).appendChild(list); }
        const r = input.getBoundingClientRect();
        Object.assign(list.style, { left: r.left + 'px', top: r.bottom + 2 + 'px', width: Math.max(r.width, 220) + 'px' });
        list.innerHTML = matches.length ? '' : '<div class="combo-group">No match</div>';
        matches.forEach((o, i) => {
          const d = document.createElement('div');
          d.textContent = o.text;
          if (i === hl) d.className = 'hl';
          d.addEventListener('mousedown', (ev) => { ev.preventDefault(); choose(o); });
          list.appendChild(d);
        });
      };
      input.addEventListener('focus', () => { input.select(); hl = 0; render(); });
      input.addEventListener('input', () => { hl = 0; render(); });
      input.addEventListener('blur', () => { setTimeout(() => { close(); sync(); }, 120); });
      input.addEventListener('keydown', (ev) => {
        if (!list) return;
        if (ev.key === 'ArrowDown') { hl = Math.min(hl + 1, matches.length - 1); render(); ev.preventDefault(); }
        if (ev.key === 'ArrowUp') { hl = Math.max(hl - 1, 0); render(); ev.preventDefault(); }
        if (ev.key === 'Enter') { if (matches[hl]) choose(matches[hl]); ev.preventDefault(); }
        if (ev.key === 'Escape') close();
      });
      sel.addEventListener('change', sync);
      window.addEventListener('scroll', close, true);
    });
  }
  App.initCombos = initCombos;

  // ------------------------------------------------------------------ date range presets
  const pad = (n) => String(n).padStart(2, '0');
  const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  function preset(key) {
    const n = new Date(); const y = n.getFullYear(); const m = n.getMonth(); const d = n.getDate();
    const D = (yy, mm, dd) => iso(new Date(yy, mm, dd));
    switch (key) {
      case 'today': return [iso(n), iso(n)];
      case 'yesterday': return [D(y, m, d - 1), D(y, m, d - 1)];
      case 'week': { const dow = (n.getDay() + 6) % 7; return [D(y, m, d - dow), iso(n)]; }
      case 'last7': return [D(y, m, d - 6), iso(n)];
      case 'month': return [D(y, m, 1), iso(n)];
      case 'lastmonth': return [D(y, m - 1, 1), D(y, m, 0)];
      case 'quarter': return [D(y, Math.floor(m / 3) * 3, 1), iso(n)];
      case 'year': return [D(y, 0, 1), iso(n)];
      case 'lastyear': return [D(y - 1, 0, 1), D(y - 1, 11, 31)];
      default: return null;
    }
  }
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('select[data-range-preset]');
    if (!sel) return;
    const r = preset(sel.value);
    if (!r) return;
    const form = sel.form;
    form.querySelector('[name=from]').value = r[0];
    form.querySelector('[name=to]').value = r[1];
    form.submit();
  });

  // ------------------------------------------------------------------ misc
  document.addEventListener('DOMContentLoaded', () => {
    initTables();
    initCombos();
    $$('[data-autohide]').forEach((el) => setTimeout(() => { el.style.display = 'none'; }, 4000));
    // Select the content of numeric inputs on focus (faster data entry)
    document.addEventListener('focusin', (e) => { if (e.target.matches('input[type=number]')) e.target.select(); });
  });
})();
