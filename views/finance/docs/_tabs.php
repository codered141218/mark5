<?php /** Page header and Documents / Aging tabs for AP and AR. Variables: $cfg, $tab */ ?>
<div class="page-header">
  <div>
    <h1><?= e($cfg['title']) ?></h1>
    <p class="muted"><?= e($cfg['subtitle']) ?></p>
  </div>
  <?php if ($tab === 'docs'): ?>
    <div class="page-actions"><button class="btn btn-primary" data-open="dlg-doc" data-reset>+ New <?= e($cfg['docLower']) ?></button></div>
  <?php endif; ?>
</div>
<div class="tabs">
  <a class="tab<?= $tab === 'docs' ? ' active' : '' ?>" href="<?= url($cfg['base']) ?>"><?= e(ucfirst($cfg['docs'])) ?></a>
  <a class="tab<?= $tab === 'aging' ? ' active' : '' ?>" href="<?= url($cfg['base'], ['tab' => 'aging']) ?>">Aging</a>
</div>
