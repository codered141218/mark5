<?php /** Preview of an item import. Variables: $rows, $count, $file, $update */
use App\Services\Items;
$badge = ['create' => ['new', 'green'], 'update' => ['update', 'blue'], 'skip' => ['skip', 'gray'], 'error' => ['error', 'red']];
$ok = ($count['create'] ?? 0) + ($count['update'] ?? 0);
?>
<div class="page-header">
  <div>
    <h1>Check before importing</h1>
    <p class="muted"><?= e($file) ?> · <?= count($rows) ?> row(s)</p>
  </div>
  <div class="page-actions"><a class="btn" href="<?= url('/inventory/items/import') ?>">← Choose another file</a></div>
</div>

<div class="stats mb">
  <div class="stat stat-green"><div class="stat-label">New items</div><div class="stat-value"><?= (int) ($count['create'] ?? 0) ?></div></div>
  <div class="stat"><div class="stat-label">Updates</div><div class="stat-value"><?= (int) ($count['update'] ?? 0) ?></div></div>
  <div class="stat"><div class="stat-label">Skipped</div><div class="stat-value"><?= (int) ($count['skip'] ?? 0) ?></div><div class="stat-sub">already exist</div></div>
  <div class="stat <?= !empty($count['error']) ? 'stat-red' : '' ?>"><div class="stat-label">Rows with errors</div><div class="stat-value"><?= (int) ($count['error'] ?? 0) ?></div><div class="stat-sub">not imported</div></div>
</div>

<div class="card">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Line</th><th></th><th>Name</th><th>Type</th><th>Category</th><th>Unit</th><th class="right">Price</th><th class="right">Cost</th><th class="right">Stock</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$label, $tone] = $badge[$r['_action']]; ?>
      <tr class="<?= $r['_action'] === 'error' ? 'import-error' : '' ?>">
        <td class="muted"><?= (int) $r['_line'] ?></td>
        <td><?= badge($label, $tone) ?></td>
        <td class="bold"><?= e($r['name']) ?><?= ($r['sku'] ?? '') !== '' ? ' <span class="muted small">' . e($r['sku']) . '</span>' : '' ?></td>
        <td><?= e(Items::TYPE_SHORT[$r['_type']] ?? '') ?></td>
        <td><?= e($r['category'] ?? '') ?></td>
        <td><?= e($r['unit'] ?? '') ?></td>
        <td class="right"><?= ($r['_price'] ?? null) !== null ? money($r['_price']) : '' ?></td>
        <td class="right"><?= ($r['_cost'] ?? null) !== null ? money($r['_cost']) : '' ?></td>
        <td class="right"><?= ($r['_stock'] ?? null) !== null ? qty($r['_stock']) : '' ?></td>
        <td class="small"><?php foreach ($r['_errors'] as $er): ?><div class="text-red bold">✕ <?= e($er) ?></div><?php endforeach; ?>
          <?php foreach ($r['_notes'] as $n): ?><div class="muted"><?= e($n) ?></div><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<form class="row gap mt-lg" method="post" action="<?= url('/inventory/items/import/confirm') ?>">
  <?= csrf_field() ?>
  <a class="btn btn-lg" href="<?= url('/inventory/items/import') ?>">Cancel</a>
  <button class="btn btn-primary btn-lg" type="submit" <?= $ok ? '' : 'disabled' ?>>Import <?= $ok ?> item(s)</button>
  <?php if (!empty($count['error'])): ?><span class="muted">Rows with errors are left out — fix them in the file and import again.</span><?php endif; ?>
</form>
