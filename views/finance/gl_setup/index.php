<?php /** GL account determination. Variables: $groups (group => rows), $categories (categories with overrides) */ use App\Services\GlSetup; ?>
<div class="page-header">
  <div>
    <h1>GL Account Setup</h1>
    <p class="muted">Choose the account each automatic posting uses — POS sales, deliveries, wastage, petty cash, payables and so on.
      Changes apply to new transactions; posted entries are not changed.
      Need another account? Add it first under <a href="<?= url('/finance/accounts') ?>">Chart of Accounts</a>.</p>
  </div>
</div>

<form method="post" action="<?= url('/finance/gl-setup') ?>">
  <?= csrf_field() ?>
  <?php foreach ($groups as $group => $rows): ?>
    <div class="card mb">
      <div class="card-head"><h3><?= e($group) ?></h3></div>
      <div class="table-wrap">
        <table class="table gl-setup">
          <thead><tr><th style="width:26%">Posting</th><th>Used for</th><th style="width:34%">Account</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="bold"><?= e($r['label']) ?><?= $r['changed'] ? ' ' . badge('changed', 'amber') : '' ?><?= !$r['account_id'] ? ' ' . badge('not set', 'red') : '' ?></td>
              <td class="muted small"><?= e($r['used_for']) ?></td>
              <td>
                <select class="input" name="gl[<?= e($r['role']) ?>]" data-combo>
                  <?= options(GlSetup::accountOptions($r['types']), $r['account_id'], '— Not set —') ?>
                </select>
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

  <div class="row gap-sm"><span class="grow"></span><button class="btn btn-primary btn-lg" type="submit">Save GL accounts</button></div>
</form>
