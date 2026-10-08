<?php
/**
 * Account picker grouped by type.
 * Variables: $name, $groups (Accounts::grouped()), $selected, $required, $placeholder (all optional except the first two)
 * $combo = true makes it type-to-search (full pages only: the search list cannot appear above an open <dialog>).
 */
?>
<select class="input" name="<?= e($name) ?>"<?= !empty($combo) ? ' data-combo data-placeholder="' . e($placeholder ?? 'Type code or name…') . '"' : '' ?><?= !empty($required) ? ' required' : '' ?>>
  <option value=""><?= empty($combo) ? e($placeholder ?? 'Select account…') : '' ?></option>
  <?php foreach ($groups as $group => $accounts): ?>
    <optgroup label="<?= e($group) ?>"><?= options($accounts, $selected ?? null) ?></optgroup>
  <?php endforeach; ?>
</select>
