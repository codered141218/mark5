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

  <div class="card danger-zone">
    <div class="card-head"><h3>Start fresh — delete all data</h3></div>
    <form class="card-body stack" method="post" action="<?= url('/admin/backup/reset') ?>" autocomplete="off"
          data-danger data-ok="Delete everything" data-confirm="Delete ALL data now? Only your own administrator login is kept.">
      <?= csrf_field() ?>
      <p class="mt-0">Deletes <b>everything</b> so you can enter your own records one by one: sales and receipts, items and recipes, categories,
        units of measure, inventory documents, the <b>chart of accounts</b> and journal entries, banks, payables and receivables,
        suppliers, customers, employees, cash advances, discounts, prep stations, and <b>all other users and roles</b>.
        Document numbers start again from 1.</p>
      <p class="muted small" style="margin:0"><b>Kept:</b> your login (<?= e(App\Core\Auth::user()['username'] ?? '') ?>) with the full-access Administrator role,
        and the business settings (name, TIN, receipt text, tax rates). A backup of the current data is saved first, so you can undo this with Restore.</p>
      <div class="form-grid">
        <label class="field"><span class="field-label">Type <b>DELETE ALL</b> to confirm</span><input class="input" name="confirm" required placeholder="DELETE ALL"></label>
        <label class="field"><span class="field-label">Your password</span><input class="input" type="password" name="password" required autocomplete="current-password"></label>
      </div>
      <div><button class="btn btn-danger" type="submit">Delete all data and start fresh</button></div>
    </form>
  </div>
</div>
