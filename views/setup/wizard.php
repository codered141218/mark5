<?php
/**
 * First-time setup wizard (blank layout). Variables: $step, $steps, $counts, $s (settings), $glGroups, $stations,
 * $categories, $units, $prev, $next
 */
use App\Services\Admin\SettingsForm;
use App\Services\GlSetup;

$v = fn ($k) => e($s[$k] ?? '');
$on = fn ($k) => ($s[$k] ?? '') === '1' ? 'checked' : '';
$keys = array_keys($steps);
$pos = array_search($step, $keys, true);
$isLast = $step === 'done';
?>
<div class="wizard-page">
  <aside class="wizard-side">
    <div class="brand"><div class="brand-logo">M5</div><div><div class="brand-name">Setup</div><div class="brand-sub">First-time configuration</div></div></div>
    <ol class="wizard-steps">
      <?php foreach ($steps as $k => $label): $i = array_search($k, $keys, true); ?>
        <li class="<?= $k === $step ? 'current' : ($i < $pos ? 'done' : '') ?>">
          <a href="<?= url('/setup', ['step' => $k]) ?>"><span class="ws-num"><?= $i < $pos ? '✓' : $i + 1 ?></span><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ol>
    <form method="post" action="<?= url('/setup/later') ?>" class="wizard-later"><?= csrf_field() ?>
      <button class="link-btn" type="submit">Finish later →</button></form>
  </aside>

  <main class="wizard-main">
    <div class="wizard-progress"><span style="width:<?= round(($pos) / (count($steps) - 1) * 100) ?>%"></span></div>
    <?php if ($m = flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>

    <form class="wizard-card" method="post" action="<?= url('/setup/' . $step) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="wizard-step-no">Step <?= $pos + 1 ?> of <?= count($steps) ?></div>

<?php switch ($step):
case 'welcome': ?>
      <h1>Welcome! Let's get your restaurant ready.</h1>
      <p class="lead">In a few short steps you'll set your business details, taxes, accounts, kitchen stations and menu categories.
        Everything can be changed later under Administration and Inventory.</p>
      <div class="wizard-facts">
        <div><b><?= $counts['items'] ?></b><span>items</span></div>
        <div><b><?= $counts['categories'] ?></b><span>categories</span></div>
        <div><b><?= $counts['accounts'] ?></b><span>GL accounts</span></div>
        <div><b><?= $counts['sales'] ?></b><span>sales</span></div>
        <div><b><?= $counts['users'] ?></b><span>users</span></div>
      </div>
      <?php if ($counts['items'] || $counts['sales'] || $counts['accounts']): ?>
        <details class="wizard-fresh">
          <summary>Start with an empty system (delete the sample / old data first)</summary>
          <p class="muted small">Deletes all items, sales, inventory, accounts, banks, suppliers, employees, discounts, stations, other users and roles.
            Your login and the business settings stay. A backup is saved first.</p>
          <div class="form-grid">
            <label class="field"><span class="field-label">Type DELETE ALL</span><input class="input" name="confirm" form="fresh-form" placeholder="DELETE ALL"></label>
            <label class="field"><span class="field-label">Your password</span><input class="input" type="password" name="password" form="fresh-form" autocomplete="current-password"></label>
          </div>
          <button class="btn btn-danger mt" type="submit" form="fresh-form">Delete all data and continue</button>
        </details>
      <?php endif; ?>
<?php break; case 'business': ?>
      <h1>Your business</h1>
      <p class="lead">Printed on receipts, bills and reports.</p>
      <div class="form-grid">
        <label class="field" style="grid-column:1/-1"><span class="field-label">Business / trade name *</span><input class="input" name="business_name" value="<?= $v('business_name') ?>" required autofocus></label>
        <label class="field" style="grid-column:1/-1"><span class="field-label">Address</span><input class="input" name="business_address" value="<?= $v('business_address') ?>"></label>
        <label class="field"><span class="field-label">TIN</span><input class="input" name="business_tin" value="<?= $v('business_tin') ?>" placeholder="000-000-000-00000"></label>
        <label class="field"><span class="field-label">Phone</span><input class="input" name="business_phone" value="<?= $v('business_phone') ?>"></label>
        <label class="field"><span class="field-label">Receipt title</span><input class="input" name="receipt_title" value="<?= $v('receipt_title') ?>"></label>
        <label class="field"><span class="field-label">Receipt number prefix</span><input class="input" name="receipt_prefix" maxlength="10" value="<?= $v('receipt_prefix') ?>"></label>
        <label class="field" style="grid-column:1/-1"><span class="field-label">Receipt footer</span><input class="input" name="receipt_footer" value="<?= $v('receipt_footer') ?>" placeholder="Thank you, please come again!"></label>
      </div>
<?php break; case 'taxes': ?>
      <h1>Taxes &amp; charges</h1>
      <p class="lead">How VAT, Senior Citizen / PWD discounts and service charge are computed at the POS.</p>
      <label class="checkbox"><input type="checkbox" name="vat_registered" value="1" <?= $on('vat_registered') ?>> <b>VAT-registered business</b> (receipts show VAT; non-VAT pay percentage tax)</label>
      <div class="wizard-choice mt">
        <label class="checkbox"><input type="radio" name="prices_include_vat" value="1" <?= ($s['prices_include_vat'] ?? '1') !== '0' ? 'checked' : '' ?>>
          <span><b>Menu prices include VAT</b><br><span class="muted small">₱112 on the menu = ₱100 + ₱12 VAT (most restaurants)</span></span></label>
        <label class="checkbox"><input type="radio" name="prices_include_vat" value="0" <?= ($s['prices_include_vat'] ?? '1') === '0' ? 'checked' : '' ?>>
          <span><b>VAT is added on top</b><br><span class="muted small">₱100 on the menu + ₱12 VAT at the POS</span></span></label>
      </div>
      <div class="form-grid mt">
        <label class="field"><span class="field-label">VAT rate (%)</span><input class="input" type="number" step="any" name="vat_rate" value="<?= $v('vat_rate') ?>"></label>
        <label class="field"><span class="field-label">Senior Citizen / PWD discount (%)</span><input class="input" type="number" step="any" name="sc_discount_rate" value="<?= $v('sc_discount_rate') ?>"></label>
        <label class="field"><span class="field-label">Service charge (%)</span><input class="input" type="number" step="any" name="service_charge_rate" value="<?= $v('service_charge_rate') ?>"><span class="field-hint">0 = none</span></label>
      </div>
      <div class="col gap-sm mt">
        <label class="checkbox"><input type="checkbox" name="service_charge_dine_in_only" value="1" <?= $on('service_charge_dine_in_only') ?>> Service charge on dine-in only</label>
        <label class="checkbox"><input type="checkbox" name="require_payment_ref" value="1" <?= $on('require_payment_ref') ?>> Require a reference no. for card and e-wallet payments</label>
      </div>
<?php break; case 'accounts': ?>
      <h1>Chart of accounts</h1>
      <p class="lead">The accounts your sales, purchases, cash and expenses are recorded in. You have <b><?= $counts['accounts'] ?></b> account(s) now.</p>
      <div class="wizard-choice">
        <label class="checkbox"><input type="radio" name="chart" value="standard" <?= $counts['accounts'] < 10 ? 'checked' : '' ?>>
          <span><b><?= $counts['accounts'] ? 'Add the standard restaurant accounts that are missing' : 'Load the standard Philippine restaurant chart of accounts' ?></b> (recommended)<br>
          <span class="muted small">Cash on Hand, Petty Cash, Banks, Inventory, Input/Output VAT, Sales, Discounts, Cost of Sales, Rent, Utilities, Salaries … ~45 accounts.
            Your own accounts are kept.</span></span></label>
        <label class="checkbox"><input type="radio" name="chart" value="keep" <?= $counts['accounts'] >= 10 ? 'checked' : '' ?>>
          <span><b>Keep my accounts as they are</b><br><span class="muted small">Add or edit accounts later under Cash &amp; Finance → Chart of Accounts
            (codes are numbered automatically).</span></span></label>
      </div>
<?php break; case 'gl': ?>
      <h1>GL accounts for automatic postings</h1>
      <p class="lead">Which account each automatic entry uses. The standard accounts are already chosen — change only what you need.</p>
      <?php if (!$counts['accounts']): ?>
        <div class="alert alert-warn">There are no accounts yet. Go back one step to load the standard chart, or skip and set this later in Finance → GL Account Setup.</div>
      <?php else: ?>
        <div class="wizard-gl">
        <?php foreach ($glGroups as $group => $rows): ?>
          <div class="wizard-gl-group"><?= e($group) ?></div>
          <?php foreach ($rows as $r): ?>
            <label class="wizard-gl-row"><span><b><?= e($r['label']) ?></b><span class="muted small"><?= e($r['used_for']) ?></span></span>
              <select class="input" name="gl[<?= e($r['role']) ?>]"><?= options(GlSetup::accountOptions($r['types']), $r['account_id'], '— Not set —') ?></select></label>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
<?php break; case 'units': ?>
      <h1>Units of measure</h1>
      <p class="lead">How ingredients and items are counted and bought: kg, g, L, ml, pc, pack, sack …</p>
      <label class="checkbox"><input type="checkbox" name="standard_units" value="1" <?= count($units) < 5 ? 'checked' : '' ?>>
        <b>Add the standard units</b> (pc, kg, g, L, ml, pack, btl, can, case, sack, tray, box, gal, srv, doz) with 1 kg = 1000 g, 1 L = 1000 ml …</label>
      <label class="field mt"><span class="field-label">Other units — one per line, e.g. <code>Bundle (bdl)</code></span>
        <textarea class="input" name="extra_units" rows="3" placeholder="Bundle (bdl)&#10;Tali (tali)"></textarea></label>
      <?php if ($units): ?><p class="muted small">Already set up: <?= e(implode(', ', array_map(fn ($u) => $u['abbr'], $units))) ?></p><?php endif; ?>
<?php break; case 'stations': ?>
      <h1>Prep stations</h1>
      <p class="lead">Where food is prepared. When the cashier presses <b>Done</b>, each station gets its own order slip (kitchen, grill, bar …).</p>
      <label class="field"><span class="field-label">Stations — one per line</span>
        <textarea class="input" name="stations" rows="4"><?= $stations ? '' : "Kitchen\nGrill" ?></textarea></label>
      <?php if ($stations): ?><p class="muted small">Already set up: <b><?= e(implode(', ', $stations)) ?></b>. Add more above, or continue.</p><?php endif; ?>
      <p class="muted small">Skip this if everything is prepared in one place — slips then print as one "ORDER SLIP".</p>
<?php break; case 'categories': ?>
      <h1>Menu &amp; inventory categories</h1>
      <p class="lead">Menu categories become the tabs on the POS. Inventory categories group ingredients for counts and reports.</p>
      <?php if ($categories): ?>
        <p class="muted small">Already set up: <?= e(implode(', ', array_map(fn ($c) => $c['name'] . ($c['station'] ? ' → ' . $c['station'] : ''), $categories))) ?></p>
      <?php endif; ?>
      <table class="table wizard-cats">
        <thead><tr><th>Category name</th><th>Used for</th><th>Prep station</th><th>Color</th></tr></thead>
        <tbody>
        <?php $suggest = $categories ? [] : [['Rice Meals', 'menu'], ['Grilled', 'menu'], ['Noodles', 'menu'], ['Beverages', 'menu'], ['Desserts', 'menu'], ['Meat & Poultry', 'inventory'], ['Dry Goods', 'inventory']];
          $colors = ['#ea580c', '#db2777', '#d97706', '#2563eb', '#7c3aed', '#64748b', '#64748b', '#0d9488', '#16a34a', '#64748b'];
          for ($i = 0; $i < 10; $i++): $sg = $suggest[$i] ?? ['', 'menu']; ?>
          <tr>
            <td><input class="input" name="cat[<?= $i ?>][name]" value="<?= e($sg[0]) ?>" placeholder="<?= $i === 0 && !$sg[0] ? 'e.g. Rice Meals' : '' ?>"></td>
            <td><select class="input" name="cat[<?= $i ?>][kind]"><?= options(['menu' => 'Menu (POS)', 'inventory' => 'Inventory only', 'both' => 'Both'], $sg[1]) ?></select></td>
            <td><select class="input" name="cat[<?= $i ?>][station_id]"><?= options($stations, $sg[1] === 'menu' && $stations ? array_key_first($stations) : null, '— None —') ?></select></td>
            <td><input class="input" type="color" name="cat[<?= $i ?>][color]" value="<?= $colors[$i] ?>" style="width:56px;padding:2px"></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
      <p class="muted small">Leave a row empty to skip it. Names that already exist are ignored.</p>
<?php break; case 'pos': ?>
      <h1>POS, discounts &amp; staff roles</h1>
      <p class="lead">A few cashier rules and the ready-made lists you can start with.</p>
      <div class="col gap-sm">
        <label class="checkbox"><input type="checkbox" name="require_table_dine_in" value="1" <?= $on('require_table_dine_in') ?>> Dine-in orders need a table number before <b>Done</b> / payment</label>
        <label class="checkbox"><input type="checkbox" name="blind_count" value="1" <?= $on('blind_count') ?>> Blind cash count at end of day (hide the expected cash while counting)</label>
      </div>
      <label class="field mt" style="max-width:360px"><span class="field-label">Order of the menu tiles</span>
        <select class="input" name="pos_menu_sort"><?= options(SettingsForm::MENU_SORTS, $s['pos_menu_sort'] ?? 'custom') ?></select></label>
      <div class="col gap-sm mt">
        <label class="checkbox"><input type="checkbox" name="standard_discounts" value="1" <?= $counts['discounts'] ? 'disabled' : 'checked' ?>>
          Add the standard discounts: Senior Citizen, PWD, Employee 10%, Promo 5%, Complimentary, open % and ₱
          <?= $counts['discounts'] ? '<span class="muted small">(you already have ' . $counts['discounts'] . ')</span>' : '' ?></label>
        <label class="checkbox"><input type="checkbox" name="standard_roles" value="1" <?= $counts['roles'] > 1 ? '' : 'checked' ?>>
          Add the standard staff roles: Manager, Cashier, Waiter, Inventory Clerk, Accountant <span class="muted small">(<?= $counts['roles'] ?> role(s) now)</span></label>
      </div>
<?php break; case 'done': ?>
      <h1>You're all set 🎉</h1>
      <p class="lead">Here is what's ready, and what to do next.</p>
      <ul class="wizard-checklist">
        <li class="<?= $counts['accounts'] ? 'ok' : '' ?>"><?= $counts['accounts'] ?> GL accounts<?= $counts['gl_missing'] ? ' · ' . $counts['gl_missing'] . ' automatic posting(s) without an account' : '' ?></li>
        <li class="<?= $counts['stations'] ? 'ok' : '' ?>"><?= $counts['stations'] ?> prep station(s)</li>
        <li class="<?= $counts['categories'] ? 'ok' : '' ?>"><?= $counts['categories'] ?> categories</li>
        <li class="<?= $counts['uoms'] ? 'ok' : '' ?>"><?= $counts['uoms'] ?> units of measure</li>
        <li class="<?= $counts['items'] ? 'ok' : '' ?>"><?= $counts['items'] ?> items</li>
      </ul>
      <div class="wizard-next">
        <a class="wizard-tile" href="<?= url('/inventory/items/import') ?>"><b>⬆ Import items from Excel</b><span>Your menu, ingredients and drinks in one go</span></a>
        <a class="wizard-tile" href="<?= url('/inventory/items/new') ?>"><b>＋ Add items one by one</b><span>With recipes for food costing</span></a>
        <a class="wizard-tile" href="<?= url('/finance/banks') ?>"><b>▣ Add your banks</b><span>Bank and e-wallet accounts with opening balances</span></a>
        <a class="wizard-tile" href="<?= url('/admin/users') ?>"><b>⚇ Add staff users</b><span>Cashiers, waiters, managers</span></a>
        <a class="wizard-tile" href="<?= url('/printer') ?>"><b>⎙ Set up the printer</b><span>On each tablet</span></a>
        <a class="wizard-tile" href="<?= url('/pos') ?>"><b>▶ Open the POS</b><span>Open the day and start selling</span></a>
      </div>
<?php break; endswitch; ?>

      <div class="wizard-foot">
        <?php if ($prev): ?><a class="btn btn-ghost" href="<?= url('/setup', ['step' => $prev]) ?>">← Back</a><?php endif; ?>
        <span class="grow"></span>
        <?php if (!in_array($step, ['welcome', 'done'], true)): ?><button class="btn btn-ghost" type="submit" name="skip" value="1" formnovalidate>Skip this step</button><?php endif; ?>
        <button class="btn btn-primary btn-lg" type="submit"><?= $step === 'welcome' ? "Let's start →" : ($isLast ? 'Finish setup ✓' : 'Save & continue →') ?></button>
      </div>
    </form>
    <?php if ($step === 'welcome'): ?>
      <form id="fresh-form" method="post" action="<?= url('/setup/fresh') ?>" data-confirm="Delete ALL data now? Only your login and the business settings are kept. A backup is saved first." data-danger data-ok="Delete everything"><?= csrf_field() ?></form>
    <?php endif; ?>
  </main>
</div>
