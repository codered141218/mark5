<?php /** Row buttons for a supplier / customer. Variables: $r, $kind */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-partner" data-title="Edit <?= e($r['name']) ?>"
          data-fill='<?= e(json_encode(array_intersect_key($r, array_flip(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'tin', 'terms_days', 'credit_limit', 'notes'])))) ?>'>Edit</button>
  <form method="post" action="<?= url("/finance/$kind/{$r['id']}/active") ?>" class="inline"
        <?= $r['active'] ? 'data-confirm="Deactivate ' . e($r['name']) . '? It will be hidden from selections but its history is kept." data-danger data-ok="Deactivate"' : '' ?>>
    <?= csrf_field() ?><input type="hidden" name="active" value="<?= $r['active'] ? 0 : 1 ?>">
    <button class="btn btn-sm btn-ghost" type="submit"><?= $r['active'] ? 'Deactivate' : 'Reactivate' ?></button>
  </form>
</div>
