<?php $combo = $combo ?? true;
/**
 * Account picker grouped by type.
 * Variables: $name, $groups (Accounts::grouped()), $selected, $required, $placeholder (all optional except the first two)
 * Type-to-search by default (also inside dialogs); $combo = false keeps a plain dropdown.
 */
?>
<select class="input" name="<?= e($name) ?>"<?= !empty($combo) ? ' data-combo data-placeholder="' . e($placeholder ?? 'Type code or name…') . '"' : '' ?><?= !empty($required) ? ' required' : '' ?>>
  <option value=""><?= empty($combo) ? e($placeholder ?? 'Select account…') : '' ?></option>
  <?php foreach ($groups as $group => $accounts): ?>
    <optgroup label="<?= e($group) ?>"><?= options($accounts, $selected ?? null) ?></optgroup>
  <?php endforeach; ?>
</select>
