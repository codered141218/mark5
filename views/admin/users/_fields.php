<?php /** Fields of the add / edit user dialog. Variables: $isNew, $roles [id => name], $employees [id => label] */ ?>
<div class="form-grid">
  <label class="field"><span class="field-label">Username<?= $isNew ? ' *' : '' ?></span>
    <input class="input" name="username" <?= $isNew ? 'required' : 'readonly' ?> autocomplete="off"></label>
  <label class="field"><span class="field-label">Full name *</span><input class="input" name="full_name" required></label>
  <label class="field"><span class="field-label"><?= $isNew ? 'Password *' : 'New password' ?></span>
    <input class="input" type="password" name="password" minlength="6" <?= $isNew ? 'required' : '' ?> autocomplete="new-password">
    <span class="field-hint"><?= $isNew ? 'At least 6 characters' : 'Leave blank to keep the current password' ?></span></label>
  <label class="field"><span class="field-label"><?= $isNew ? 'Manager PIN (optional)' : 'New manager PIN' ?></span>
    <input class="input" type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" maxlength="8" autocomplete="new-password">
    <span class="field-hint"><?= $isNew ? '4-8 digits' : 'Leave blank to keep the current PIN' ?></span></label>
  <label class="field"><span class="field-label">Role *</span>
    <select class="input" name="role_id" required><?= options($roles, null, '— Choose role —') ?></select></label>
  <label class="field"><span class="field-label">Linked employee</span>
    <select class="input" name="employee_id"><?= options($employees, null, '— None —') ?></select></label>
</div>
<div class="alert alert-info mt small">
  <b>Manager PIN</b> is a short number code for quick override approvals on the POS, e.g. authorizing a void or a discount
  for a cashier without signing in. It only works if this user's role allows the action.<br>
  <b>Linked employee</b> lets this user file their own cash advance requests.
</div>
<?php if (!$isNew): ?>
  <label class="checkbox mt"><input type="checkbox" name="active" value="1"> Active (can sign in)</label>
  <p class="muted small">Untick to disable the account. You cannot disable your own account.</p>
<?php endif; ?>
