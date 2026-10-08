<?php /** Users list + add / edit dialogs. Variables: $columns, $rows, $roles, $employees */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Users</h1>
    <p class="muted">Who can sign in, and what role (set of permissions) each person has.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" data-open="dlg-user-new" data-reset>+ Add user</button>
  </div>
</div>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No users.']) ?></div>

<dialog class="modal" id="dlg-user-new">
  <form method="post" action="<?= url('/admin/users') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Add user</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <?= view('admin/users/_fields', ['isNew' => true, 'roles' => $roles, 'employees' => $employees], null) ?>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Create user</button></div>
  </form>
</dialog>

<dialog class="modal" id="dlg-user-edit">
  <form method="post" action="" autocomplete="off">
    <?= csrf_field() ?>
    <div class="modal-head"><h3>Edit user</h3><button class="icon-btn" type="button" data-close>✕</button></div>
    <div class="modal-body">
      <?= view('admin/users/_fields', ['isNew' => false, 'roles' => $roles, 'employees' => $employees], null) ?>
    </div>
    <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>
