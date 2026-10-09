<?php /** Row buttons for a discount preset. Variable: $r */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-discount" data-title="Edit discount"
          data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'value' => $r['value'], 'scope' => $r['scope'],
              'requires_approval' => $r['requires_approval'], 'active' => $r['active']])) ?>'>Edit</button>
  <form method="post" action="<?= url('/admin/discounts/' . $r['id'] . '/delete') ?>" class="inline" data-confirm="Remove the discount “<?= e($r['name']) ?>”?" data-danger>
    <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Remove</button>
  </form>
</div>
