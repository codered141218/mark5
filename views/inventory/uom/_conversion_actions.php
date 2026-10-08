<?php /** Delete button for a conversion. Variable: $r */ ?>
<div class="actions">
  <form method="post" action="<?= url('/inventory/uom/conversions/' . $r['id'] . '/delete') ?>" class="inline" data-danger data-ok="Delete"
        data-confirm="Remove “<?= e($r['text']) ?>”?">
    <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Remove</button>
  </form>
</div>
