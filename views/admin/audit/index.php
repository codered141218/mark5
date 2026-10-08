<?php /** Audit trail. Variables: $columns, $rows, $from, $to, $action, $actions, $userId, $users, $limited */ use App\Core\Table; ?>
<div class="page-header">
  <div>
    <h1>Audit Trail</h1>
    <p class="muted">Who did what, and when. Up to 2,000 most recent entries for the selected period.</p>
  </div>
</div>

<?= view('partials/daterange', ['from' => $from, 'to' => $to, 'skip' => ['action', 'user'], 'extra' =>
    '<label class="field"><span class="field-label">Action</span><select class="input" name="action">' . options($actions, $action, 'All actions') . '</select></label>'
    . '<label class="field"><span class="field-label">User</span><select class="input" name="user">' . options($users, $userId, 'All users') . '</select></label>'], null) ?>

<?php if ($limited): ?><div class="alert alert-warn">Only the 2,000 most recent entries are shown. Narrow the period or filter by action / user to see older ones.</div><?php endif; ?>

<div class="card"><?= Table::html($columns, $rows, ['export' => true, 'empty' => 'No activity in this period.']) ?></div>
