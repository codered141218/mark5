<?php
/**
 * Small "Void" button form that asks for a reason (and a manager PIN when $pin is true).
 * Variables: $action (url path), $title (confirm text), $pin (bool, optional), $label (optional), $class (optional)
 */
?>
<form method="post" action="<?= url($action) ?>" class="inline" data-confirm="<?= e($title) ?>" data-prompt="Reason" data-danger data-ok="Void"<?= !empty($pin) ? ' data-pin' : '' ?>>
  <?= csrf_field() ?><button class="btn btn-sm <?= e($class ?? 'btn-ghost') ?>" type="submit"><?= e($label ?? 'Void') ?></button>
</form>
