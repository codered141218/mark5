<?php /** Row buttons for a backup file. Variable: $b */ ?>
<div class="actions">
  <a class="btn btn-sm" href="<?= url('/admin/backup/download', ['name' => $b['name']]) ?>">⬇ Download</a>
  <form method="post" action="<?= url('/admin/backup/restore') ?>" class="inline" data-danger data-ok="Yes, restore"
        data-confirm="This replaces ALL current data with the backup from <?= e(fmt_datetime($b['created_at'])) ?> (<?= e($b['name']) ?>). A safety backup of the current data is created first. You will be signed out.">
    <?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-dark" type="submit">Restore</button>
  </form>
  <form method="post" action="<?= url('/admin/backup/delete') ?>" class="inline" data-danger data-ok="Delete"
        data-confirm="Permanently delete <?= e($b['name']) ?>?">
    <?= csrf_field() ?><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-ghost" type="submit">Delete</button>
  </form>
</div>
