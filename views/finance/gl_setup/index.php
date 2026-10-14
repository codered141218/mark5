<?php /** GL account determination. Variables: $groups (group => rows), $categories (categories with overrides) */ use App\Services\GlSetup; ?>
<div class="page-header">
  <div>
    <h1>GL Account Setup</h1>
    <p class="muted">Choose the account each automatic posting uses — POS sales, deliveries, wastage, petty cash, payables and so on.
      Changes apply to new transactions; posted entries are not changed. A posting left blank has no account: transactions that need it
      stop with an error until you choose one.
      Need another account? Add it first under <a href="<?= url('/finance/accounts') ?>">Chart of Accounts</a>.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" data-gl-all="clear" title="Empty every field (nothing is saved until you press Save)">Clear all</button>
    <button class="btn" type="button" data-gl-all="default" title="Put the default account back in every field">Use all defaults</button>
  </div>
</div>

<?php $acctLabels = GlSetup::accountOptions(['asset', 'liability', 'equity', 'income', 'expense']); ?>
<form method="post" action="<?= url('/finance/gl-setup') ?>" id="gl-form">
  <?= csrf_field() ?>
  <?php foreach ($groups as $group => $rows): ?>
    <div class="card mb" data-gl-section>
      <div class="card-head"><h3><?= e($group) ?></h3>
        <div class="row gap-sm"><button class="btn btn-sm btn-ghost" type="button" data-gl-section-act="clear">Clear</button>
          <button class="btn btn-sm btn-ghost" type="button" data-gl-section-act="default">Use defaults</button></div></div>
      <div class="table-wrap">
        <table class="table gl-setup">
          <thead><tr><th style="width:26%">Posting</th><th>Used for</th><th style="width:34%">Account</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="bold"><?= e($r['label']) ?><?= $r['changed'] ? ' ' . badge('changed', 'amber') : '' ?><?= !$r['account_id'] ? ' ' . badge('not set', 'red') : '' ?></td>
              <td class="muted small"><?= e($r['used_for']) ?></td>
              <td>
                <select class="input" name="gl[<?= e($r['role']) ?>]" data-combo data-default="<?= e((string) ($r['default_id'] ?? '')) ?>">
                  <?= options(GlSetup::accountOptions($r['types']), $r['account_id'], '— Not set —') ?>
                </select>
                <div class="field-hint" data-gl-hint data-default-label="<?= e($r['default_id'] ? ($acctLabels[$r['default_id']] ?? '') : '') ?>"></div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card mb">
    <div class="card-head"><h3>Per category</h3></div>
    <div class="card-body">
      <p class="muted">A category can post to its own <b>Sales</b>, <b>Cost of goods sold</b> and <b>Inventory</b> accounts — for example
        Beverage Sales separate from Food Sales, or a separate inventory account for liquor. Set them when editing a category under
        <a href="<?= url('/inventory/categories') ?>">Inventory → Categories</a>. Sales and COGS follow the category of the menu item sold;
        inventory follows the category of each ingredient.</p>
      <?php if ($categories): ?>
        <table class="table">
          <thead><tr><th>Category</th><th>Sales</th><th>COGS</th><th>Inventory</th></tr></thead>
          <tbody>
          <?php foreach ($categories as $c): ?>
            <tr><td class="bold"><?= e($c['name']) ?></td>
              <td><?= $c['s_code'] ? e("{$c['s_code']} · {$c['s_name']}") : '<span class="muted">default</span>' ?></td>
              <td><?= $c['g_code'] ? e("{$c['g_code']} · {$c['g_name']}") : '<span class="muted">default</span>' ?></td>
              <td><?= $c['v_code'] ? e("{$c['v_code']} · {$c['v_name']}") : '<span class="muted">default</span>' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="muted small">No category has its own accounts yet — every category uses the defaults above.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="row gap-sm"><span class="muted small" data-gl-dirty hidden>Unsaved changes</span><span class="grow"></span><button class="btn btn-primary btn-lg" type="submit">Save GL accounts</button></div>
</form>
<script>
  // Clear (empty) or put the default accounts back, per section or for all; a blank field shows which account it falls back to.
  (() => {
    const form = document.getElementById('gl-form');
    const selects = (root) => Array.from(root.querySelectorAll('select[name^="gl["]'));
    const hint = (sel) => {
      const h = sel.closest('td').querySelector('[data-gl-hint]');
      const def = h.dataset.defaultLabel;
      h.textContent = sel.value ? '' : 'Not set — anything that posts here stops with an error until you choose an account' + (def ? ' (default: ' + def + ')' : '');
    };
    const set = (sel, value) => { sel.value = value; sel.dispatchEvent(new Event('change', { bubbles: true })); };
    const apply = (root, act) => selects(root).forEach((sel) => set(sel, act === 'clear' ? '' : (sel.dataset.default || '')));
    document.querySelectorAll('[data-gl-all]').forEach((b) => b.addEventListener('click', () => apply(form, b.dataset.glAll)));
    form.addEventListener('click', (e) => {
      const b = e.target.closest('[data-gl-section-act]');
      if (b) apply(b.closest('[data-gl-section]'), b.dataset.glSectionAct);
    });
    form.addEventListener('change', (e) => {
      if (!e.target.matches('select[name^="gl["]')) return;
      hint(e.target);
      form.querySelector('[data-gl-dirty]').hidden = false;
    });
    selects(form).forEach(hint);
    let saving = false;
    form.addEventListener('submit', () => { saving = true; });
    window.addEventListener('beforeunload', (e) => { if (!saving && !form.querySelector('[data-gl-dirty]').hidden) { e.preventDefault(); e.returnValue = ''; } });
  })();
</script>
