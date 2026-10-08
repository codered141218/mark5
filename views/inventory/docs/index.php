<?php /** Document list. Variables: $cfg, $slug, $rows, $columns, $from, $to, $status, $statuses, $stats */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1><?= e($cfg['title']) ?></h1>
    <p class="muted"><?= e($cfg['subtitle']) ?></p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="<?= url("/inventory/$slug/new") ?>">+ <?= e($cfg['new']) ?></a></div>
</div>

<div class="stats mb">
  <div class="stat"><div class="stat-label">Posted total</div><div class="stat-value"><?= peso($stats['posted_total']) ?></div>
    <div class="stat-sub"><?= $stats['posted_count'] ?> document<?= $stats['posted_count'] === 1 ? '' : 's' ?> · <?= e(range_label($from, $to)) ?></div></div>
  <div class="stat<?= $stats['draft_count'] ? ' stat-amber' : '' ?>"><div class="stat-label">Drafts (not yet posted)</div><div class="stat-value"><?= $stats['draft_count'] ?></div>
    <div class="stat-sub"><?= $stats['draft_count'] ? peso($stats['draft_total']) : 'none pending' ?></div></div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['status'],
    'extra' => '<label class="field"><span class="field-label">Status</span><select class="input" name="status">' . options($statuses, $status, 'All statuses') . '</select></label>'], null) ?>

<div class="card">
  <?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No documents in this period.', 'link' => fn ($r) => url("/inventory/$slug/{$r['id']}")]) ?>
</div>
