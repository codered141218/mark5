<?php /** My account: change password / PIN and see my permissions. Variables: $groups */
$u = user(); $all = in_array('*', $u['permissions'], true); ?>
<div class="page-header"><div><h1>My account</h1><p class="muted"><?= e($u['full_name']) ?> · <?= e($u['username']) ?> · <?= e($u['role_name']) ?></p></div></div>
<div class="grid-2">
  <div class="card"><div class="card-head"><h3>Change password / POS PIN</h3></div>
    <form class="card-body stack" method="post" action="<?= url('/account') ?>">
      <?= csrf_field() ?>
      <label class="field"><span class="field-label">Current password</span><input class="input" type="password" name="current_password" required></label>
      <div class="form-grid">
        <label class="field"><span class="field-label">New password</span><input class="input" type="password" name="new_password" minlength="6"></label>
        <label class="field"><span class="field-label">Confirm new password</span><input class="input" type="password" name="confirm_password"></label>
        <label class="field"><span class="field-label">New manager PIN (4-8 digits)</span><input class="input" type="password" name="new_pin" inputmode="numeric"></label>
      </div>
      <p class="muted small">Leave a field blank to keep it unchanged. The PIN is used to approve voids and discounts on another cashier's POS.</p>
      <div><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
  <div class="card"><div class="card-head"><h3>My permissions</h3><?= $all ? badge('Full access', 'green') : '' ?></div>
    <div class="card-body">
      <?php foreach ($groups as $group => $perms):
          $mine = array_filter(array_keys($perms), fn ($p) => $all || in_array($p, $u['permissions'], true));
          if (!$mine) continue; ?>
        <div class="bold mt"><?= e($group) ?></div>
        <ul class="perm-ul"><?php foreach ($mine as $p): ?><li><?= e($perms[$p]) ?></li><?php endforeach; ?></ul>
      <?php endforeach; ?>
    </div>
  </div>
</div>
