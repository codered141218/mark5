<?php /** Row buttons for a user. Variables: $r, $self (true for the signed-in user) */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-user-edit" data-action="/admin/users/<?= (int) $r['id'] ?>" data-title="Edit user — <?= e($r['username']) ?>"
          data-fill='<?= e(json_encode(['username' => $r['username'], 'full_name' => $r['full_name'], 'role_id' => $r['role_id'] ?? '',
              'employee_id' => $r['employee_id'] ?? '', 'active' => $r['active'], 'password' => '', 'pin' => ''])) ?>'>Edit</button>
  <?php if ($r['active'] && !$self): ?>
    <form method="post" action="<?= url('/admin/users/' . $r['id'] . '/disable') ?>" class="inline" data-danger data-ok="Disable"
          data-confirm="Disable “<?= e($r['username']) ?>”? They can no longer sign in. Their past transactions stay in the records.">
      <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Disable</button>
    </form>
  <?php endif; ?>
</div>
