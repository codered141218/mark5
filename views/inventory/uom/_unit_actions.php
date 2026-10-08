<?php /** Row buttons for a unit. Variable: $r */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-uom" data-title="Edit unit"
          data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'abbr' => $r['abbr']])) ?>'>Edit</button>
  <form method="post" action="<?= url('/inventory/uom/' . $r['id'] . '/delete') ?>" class="inline" data-danger data-ok="Delete"
        data-confirm="Delete unit “<?= e($r['name']) ?> (<?= e($r['abbr']) ?>)”? Its conversions are removed too.">
    <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Delete</button>
  </form>
</div>
