<?php
/** Backup & Restore. Variables: $columns, $rows, $totalSize, $autoOn, $retention, $uploadLimit */
use App\Core\Table;

$latest = $rows[0] ?? null;
$restoreMsg = 'This replaces ALL current data with the uploaded backup. A safety backup of the current data is created first. You will be signed out.';
?>
<div class="page-header">
  <div>
    <h1>Backup &amp; Restore</h1>
    <p class="muted">Protect your sales, inventory and accounting data.</p>
  </div>
  <div class="page-actions">
    <form method="post" action="<?= url('/admin/backup') ?>" class="inline"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Create backup now</button></form>
  </div>
</div>

<div class="stack">
  <div class="alert alert-info" style="margin-bottom:0">
    <?php if ($autoOn): ?>
      A backup is made <b>automatically every day</b> and the last <?= $retention ?> automatic copies are kept.
    <?php else: ?>
      Daily automatic backup is currently <b>turned off</b>; turn it on in <a href="<?= url('/admin/settings', ['tab' => 'backup']) ?>">Settings → Backups</a>.
    <?php endif; ?>
    Backups stored on the same computer won't survive a broken disk, theft or fire: regularly <b>download a backup and keep it off-site</b>
    (USB drive or cloud storage like Google Drive).
  </div>

  <div class="stats">
    <div class="stat"><div class="stat-label">Backups on server</div><div class="stat-value"><?= count($rows) ?></div></div>
    <div class="stat"><div class="stat-label">Latest backup</div><div class="stat-value"><?= $latest ? e(fmt_datetime($latest['created_at'])) : '—' ?></div>
      <div class="stat-sub"><?= $latest ? e((App\Services\Backup::KINDS[$latest['kind']] ?? [$latest['kind']])[0]) : 'None yet' ?></div></div>
    <div class="stat"><div class="stat-label">Total size</div><div class="stat-value"><?= e($totalSize) ?></div></div>
  </div>

  <div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No backups yet. Click “Create backup now”.']) ?></div>

  <div class="card">
    <div class="card-head"><h3>Restore from a file</h3></div>
    <form class="card-body row gap wrap" method="post" action="<?= url('/admin/backup/upload') ?>" enctype="multipart/form-data"
          data-danger data-ok="Yes, restore" data-confirm="<?= e($restoreMsg) ?>">
      <?= csrf_field() ?>
      <input class="input" type="file" name="backup" accept=".sql" required style="max-width:360px">
      <button class="btn" type="submit">⬆ Restore from file</button>
      <span class="muted small">A .sql file downloaded from this page (largest upload allowed by the server: <?= e($uploadLimit) ?>).</span>
    </form>
  </div>
</div>
