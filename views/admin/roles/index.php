<?php
/** Roles list + permission matrix editor. Variables: $roles, $sel (role being edited, id null = new), $groups, $total */
use App\Services\Admin\Roles;

$full = $sel && Roles::isFull($sel);
?>
<div class="page-header">
  <div>
    <h1>Roles &amp; Permissions</h1>
    <p class="muted">A role is a job function (Cashier, Cook, Manager…). Tick what that function is allowed to do, then assign the role to users.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(current_url(['export' => 'xlsx'])) ?>">⬇ Excel</a>
    <a class="btn btn-primary" href="<?= url('/admin/roles', ['new' => 1]) ?>">+ New role</a>
  </div>
</div>

<div class="row gap-lg wrap" style="align-items:flex-start">
  <div class="card" style="width:300px;max-width:100%">
    <div class="card-head"><h3>Roles</h3></div>
    <?php foreach ($roles as $r): $on = $sel && $sel['id'] === $r['id']; ?>
      <a href="<?= url('/admin/roles', ['id' => $r['id']]) ?>"
         style="display:block;padding:10px 14px;border-bottom:1px solid var(--border);color:inherit;text-decoration:none;border-left:3px solid <?= $on ? 'var(--brand)' : 'transparent' ?>;<?= $on ? 'background:var(--brand-soft)' : '' ?>">
        <div class="row between gap-sm"><b><?= e($r['name']) ?></b><span class="muted small nowrap"><?= $r['user_count'] ?> user<?= $r['user_count'] === 1 ? '' : 's' ?></span></div>
        <div class="muted small"><?= e($r['description'] ?: '—') ?></div>
        <div class="small mt" style="margin-top:4px"><?= Roles::isFull($r) ? badge('Full access', 'green') : '<span class="muted">' . count($r['permissions']) . " of $total permissions</span>" ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div style="flex:1 1 320px;min-width:0">
    <?php if (!$sel): ?>
      <div class="card"><div class="empty">Select a role on the left, or create a new one.</div></div>
    <?php else: ?>
      <form class="card" method="post" action="<?= url('/admin/roles') ?>" id="role-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($sel['id']) ?>">
        <div class="card-head">
          <h3><?= $sel['id'] ? 'Edit role — ' . e($sel['name']) : 'New role' ?></h3>
          <div class="row gap-sm">
            <?php if ($sel['id']): ?>
              <a class="btn btn-sm" href="<?= url('/admin/roles', ['duplicate' => $sel['id']]) ?>">⧉ Duplicate</a>
              <?php if (!$sel['is_system']): ?><button class="btn btn-sm btn-ghost" type="submit" form="role-delete">Delete</button><?php endif; ?>
            <?php endif; ?>
            <?php if (!$full): ?><button class="btn btn-sm btn-primary" type="submit"><?= $sel['id'] ? 'Save changes' : 'Create role' ?></button><?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <div class="form-grid">
            <label class="field"><span class="field-label">Role name *</span>
              <input class="input" name="name" value="<?= e($sel['name']) ?>" placeholder="e.g. Head Cook" required <?= $full ? 'disabled' : '' ?>></label>
            <label class="field" style="grid-column:span 2"><span class="field-label">Description</span>
              <input class="input" name="description" value="<?= e($sel['description']) ?>" placeholder="What this job function does" <?= $full ? 'disabled' : '' ?>></label>
          </div>

          <?php if ($full): ?>
            <div class="alert alert-success mt" style="margin-bottom:0">
              <b>Full access.</b> The <?= e($sel['name']) ?> role can do everything in the system, including managing users, roles and backups.
              It cannot be edited or deleted. Use <b>Duplicate</b> to create a restricted role from it.
            </div>
          <?php else: ?>
            <div class="row between wrap gap mt mb">
              <span class="muted small"><span data-perm-count><?= count($sel['permissions']) ?></span> of <?= $total ?> permissions selected.
                Users with this role only see the menus and buttons they are allowed to use.</span>
              <span class="row gap-sm">
                <button class="btn btn-sm btn-ghost" type="button" data-check="all">Select all</button>
                <button class="btn btn-sm btn-ghost" type="button" data-check="none">Clear all</button>
              </span>
            </div>
            <?php foreach ($groups as $group => $perms):
                $n = count(array_intersect(array_keys($perms), $sel['permissions'])); ?>
              <div class="perm-group" data-perm-group>
                <div class="perm-group-head">
                  <span><?= e($group) ?> <span class="muted small" style="font-weight:400">(<span data-group-count><?= $n ?></span>/<?= count($perms) ?>)</span></span>
                  <span class="row gap-sm small">
                    <button class="btn btn-sm btn-link" type="button" data-check="all">Select all</button><span class="muted">/</span>
                    <button class="btn btn-sm btn-link" type="button" data-check="none">None</button>
                  </span>
                </div>
                <div class="perm-list">
                  <?php foreach ($perms as $key => $label): ?>
                    <label class="checkbox"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= in_array($key, $sel['permissions'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit"><?= $sel['id'] ? 'Save changes' : 'Create role' ?></button></div>
          <?php endif; ?>
        </div>
      </form>
      <?php if ($sel['id'] && !$sel['is_system']): ?>
        <form method="post" action="<?= url('/admin/roles/' . $sel['id'] . '/delete') ?>" id="role-delete" data-danger data-ok="Delete"
              data-confirm="Delete the role “<?= e($sel['name']) ?>”? This cannot be undone. Roles still assigned to users cannot be deleted."><?= csrf_field() ?></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
  // Select all / none (whole role or one group) and live counters for the permission matrix.
  (function () {
    const form = document.getElementById('role-form');
    if (!form) return;
    const boxes = (root) => Array.from(root.querySelectorAll('input[name="permissions[]"]'));
    const update = () => {
      const total = form.querySelector('[data-perm-count]');
      if (total) total.textContent = boxes(form).filter((c) => c.checked).length;
      form.querySelectorAll('[data-perm-group]').forEach((g) => {
        g.querySelector('[data-group-count]').textContent = boxes(g).filter((c) => c.checked).length;
      });
    };
    form.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-check]');
      if (!btn) return;
      boxes(btn.closest('[data-perm-group]') || form).forEach((c) => { c.checked = btn.dataset.check === 'all'; });
      update();
    });
    form.addEventListener('change', update);
  })();
</script>
