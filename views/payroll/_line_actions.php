<?php /** Change / remove a bonus or deduction line of a draft payslip. Variables: $l */ ?>
<details class="line-edit">
  <summary title="Change or remove">✎</summary>
  <div class="line-edit-pop">
    <form method="post" action="<?= url('/payroll/lines/' . $l['id']) ?>" class="row gap-sm">
      <?= csrf_field() ?>
      <input class="input num" type="number" step="0.01" min="0.01" name="amount" value="<?= e($l['amount']) ?>" style="width:120px">
      <button class="btn btn-sm" type="submit">Save</button>
    </form>
    <form method="post" action="<?= url('/payroll/lines/' . $l['id'] . '/delete') ?>" class="mt-sm">
      <?= csrf_field() ?><button class="btn btn-sm btn-ghost text-red" type="submit">Remove</button>
    </form>
  </div>
</details>
