<?php /** Row buttons for a prep station. Variable: $r */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-station" data-title="Edit station"
          data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'active' => $r['active']])) ?>'>Edit</button>
  <form method="post" action="<?= url('/admin/stations/' . $r['id'] . '/delete') ?>" class="inline" data-confirm="Delete the station “<?= e($r['name']) ?>”? Its items become “no station”." data-danger>
    <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Delete</button>
  </form>
</div>
