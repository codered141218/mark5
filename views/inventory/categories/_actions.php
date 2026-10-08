<?php /** Row buttons for a category. Variable: $r */ ?>
<div class="actions">
  <button class="btn btn-sm" type="button" data-open="dlg-category" data-title="Edit category"
          data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'color' => $r['color'] ?: '#9ca3af', 'sort_order' => $r['sort_order'], 'active' => $r['active']])) ?>'>Edit</button>
  <form method="post" action="<?= url('/inventory/categories/' . $r['id'] . '/delete') ?>" class="inline" data-confirm="Delete category “<?= e($r['name']) ?>”?" data-danger>
    <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Delete</button>
  </form>
</div>
