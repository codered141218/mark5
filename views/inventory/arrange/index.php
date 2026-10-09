<?php /** Arrange the POS menu. Variables: $arrange (categories with items, uncategorized, discounts), $menuSort */ ?>
<div class="page-header">
  <div>
    <h1>Arrange Menu</h1>
    <p class="muted">Drag the rows (or use the arrows) to set the order of the category tabs, the item tiles and the discount buttons on the POS.
      No numbers to type — new categories and items are simply added at the end.</p>
  </div>
</div>

<?php if ($menuSort !== 'custom'): ?>
  <div class="alert alert-info">The POS currently sorts menu tiles <b><?= e(App\Services\Admin\SettingsForm::MENU_SORTS[$menuSort] ?? $menuSort) ?></b>, so the item order below is not used.
    Change it under <a href="<?= url('/admin/settings?tab=pos') ?>">Settings → POS</a>. (Category and discount order always apply.)</div>
<?php endif; ?>

<div class="grid-2 arrange" style="align-items:start">
  <div class="card">
    <div class="card-head"><h3>Categories</h3><span class="muted small">tap a category to arrange its items</span></div>
    <ul class="arrange-list" data-list="categories"></ul>
  </div>
  <div class="card">
    <div class="card-head"><h3 data-items-title>Items</h3>
      <div class="row gap-sm"><button class="btn btn-sm" type="button" data-sort-az>A → Z</button><button class="btn btn-sm" type="button" data-sort-za>Z → A</button></div></div>
    <ul class="arrange-list" data-list="items"></ul>
  </div>
</div>

<div class="card mt" style="max-width:640px">
  <div class="card-head"><h3>Discount buttons</h3></div>
  <ul class="arrange-list" data-list="discounts"></ul>
</div>

<script>
// Runs after app.js (loaded at the end of the page) so App.* is available
document.addEventListener('DOMContentLoaded', function () {
  const DATA = <?= json_encode($arrange, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const $ = (s, r = document) => r.querySelector(s);
  let current = DATA.categories[0] ? DATA.categories[0].id : null;

  function rowHtml(o, extra = '') {
    return `<li class="arrange-row${o.active ? '' : ' inactive'}" data-id="${o.id}">
      <span class="drag-handle" title="Drag to move">⠿</span>
      ${o.color ? `<span class="legend-dot" style="background:${App.esc(o.color)}"></span>` : ''}
      <span class="grow">${App.esc(o.name)}${o.active ? '' : ' <span class="muted small">(inactive)</span>'}${extra}</span>
      <button type="button" class="icon-btn" data-move="top" title="Move to top">⤒</button>
      <button type="button" class="icon-btn" data-move="up" title="Move up">▲</button>
      <button type="button" class="icon-btn" data-move="down" title="Move down">▼</button>
      <button type="button" class="icon-btn" data-move="bottom" title="Move to bottom">⤓</button></li>`;
  }
  function paint() {
    $('[data-list=categories]').innerHTML = DATA.categories.map((c) => rowHtml(c, ` <span class="muted small">· ${c.items.length} items</span>`)).join('')
      || '<li class="empty">No menu categories yet.</li>';
    [...document.querySelectorAll('[data-list=categories] li')].forEach((li) => li.classList.toggle('current', Number(li.dataset.id) === current));
    paintItems();
    $('[data-list=discounts]').innerHTML = DATA.discounts.map((d) => rowHtml(d)).join('') || '<li class="empty">No discounts.</li>';
  }
  function paintItems() {
    const cat = DATA.categories.find((c) => c.id === current);
    $('[data-items-title]').textContent = cat ? `Items in ${cat.name}` : 'Items';
    $('[data-list=items]').innerHTML = cat && cat.items.length ? cat.items.map((i) => rowHtml(i, ` <span class="muted small">${App.peso(i.price)}</span>`)).join('')
      : '<li class="empty">No sellable items in this category.</li>';
  }
  const listData = (name) => (name === 'categories' ? DATA.categories : name === 'discounts' ? DATA.discounts
    : (DATA.categories.find((c) => c.id === current) || { items: [] }).items);

  let saveTimer = {};
  function save(name) {
    const ids = [...document.querySelectorAll(`[data-list=${name}] li[data-id]`)].map((li) => Number(li.dataset.id));
    // keep the in-memory data in the same order as the screen
    const arr = listData(name);
    arr.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
    clearTimeout(saveTimer[name]);
    saveTimer[name] = setTimeout(async () => {
      try { await App.api('POST', '/inventory/arrange', { list: name, ids }); App.toast('Order saved'); } catch (e) { App.toast(e.message, 'error'); }
    }, 350);
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-move]');
    const li = e.target.closest('.arrange-row');
    if (btn && li) {
      const ul = li.parentElement;
      const m = btn.dataset.move;
      if (m === 'up' && li.previousElementSibling) ul.insertBefore(li, li.previousElementSibling);
      if (m === 'down' && li.nextElementSibling) ul.insertBefore(li.nextElementSibling, li);
      if (m === 'top') ul.prepend(li);
      if (m === 'bottom') ul.append(li);
      save(ul.dataset.list);
      return;
    }
    if (li && li.closest('[data-list=categories]') && !e.target.closest('.drag-handle')) {
      current = Number(li.dataset.id);
      document.querySelectorAll('[data-list=categories] li').forEach((x) => x.classList.toggle('current', x === li));
      paintItems();
    }
    const az = e.target.closest('[data-sort-az],[data-sort-za]');
    if (az) {
      const items = listData('items');
      const dir = az.matches('[data-sort-az]') ? 1 : -1;
      items.sort((a, b) => dir * a.name.localeCompare(b.name));
      paintItems();
      save('items');
    }
  });

  // Drag with mouse or finger: press the ⠿ handle and move
  let drag = null;
  document.addEventListener('pointerdown', (e) => {
    const h = e.target.closest('.drag-handle');
    if (!h) return;
    const li = h.closest('.arrange-row');
    drag = { li, ul: li.parentElement };
    li.classList.add('dragging');
    h.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  document.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const rows = [...drag.ul.children].filter((x) => x !== drag.li && x.matches('.arrange-row'));
    const after = rows.find((r) => { const b = r.getBoundingClientRect(); return e.clientY < b.top + b.height / 2; });
    if (after) drag.ul.insertBefore(drag.li, after); else drag.ul.append(drag.li);
  });
  const end = () => {
    if (!drag) return;
    drag.li.classList.remove('dragging');
    save(drag.ul.dataset.list);
    drag = null;
  };
  document.addEventListener('pointerup', end);
  document.addEventListener('pointercancel', end);
  paint();
});
</script>
