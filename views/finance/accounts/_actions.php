<?php /** Row buttons for an account. Variable: $r */ ?>
<div class="actions">
  <a class="btn btn-sm btn-ghost" href="<?= url('/reports/finance', ['report' => 'gl', 'account_id' => $r['id']]) ?>">View ledger</a>
  <?php if (can('finance.accounts')): ?>
    <button class="btn btn-sm" type="button" data-open="dlg-account" data-title="Edit account <?= e($r['code']) ?>"
            data-fill='<?= e(json_encode(['id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'], 'subtype' => $r['subtype'],
                'description' => $r['description'], 'active' => $r['active'], 'locked' => $r['is_system'] ? 'system' : ($r['has_tx'] ? 'tx' : '')])) ?>'>Edit</button>
    <?php if (!$r['is_system'] && !$r['has_tx']): ?>
      <form method="post" action="<?= url('/finance/accounts/' . $r['id'] . '/delete') ?>" class="inline" data-confirm="Delete <?= e($r['code'] . ' · ' . $r['name']) ?>? This cannot be undone." data-danger data-ok="Delete">
        <?= csrf_field() ?><button class="btn btn-sm btn-ghost" type="submit">Delete</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
