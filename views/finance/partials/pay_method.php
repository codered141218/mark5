<?php
/**
 * Payment / funding source: a method select plus a bank select that shows only when the method is "bank".
 * Variables: $methods ([value => label]), $banks (Banks::options()), $label, $name (default "method"), $selected (optional)
 */
$name = $name ?? 'method';
?>
<label class="field"><span class="field-label"><?= e($label) ?></span>
  <select class="input" name="<?= e($name) ?>" data-pay-method><?= options($methods, $selected ?? array_key_first($methods)) ?></select>
</label>
<?php if (isset($methods['bank'])): ?>
  <label class="field hidden" data-bank-field><span class="field-label">Bank account</span>
    <select class="input" name="bank_account_id"><?= options($banks, null, 'Select bank…') ?></select>
    <?php if (!$banks): ?><span class="field-hint">No bank accounts yet — add one under Banks.</span><?php endif; ?>
  </label>
<?php endif; ?>
<script>
  // Show the bank select only when "bank" is chosen (registered once per page).
  if (!window.payMethodToggle) {
    window.payMethodToggle = (sel) => sel.form.querySelectorAll('[data-bank-field]').forEach((el) => el.classList.toggle('hidden', sel.value !== 'bank'));
    document.addEventListener('change', (e) => { if (e.target.matches('[data-pay-method]')) window.payMethodToggle(e.target); });
    document.addEventListener('reset', (e) => setTimeout(() => e.target.querySelectorAll('[data-pay-method]').forEach(window.payMethodToggle)));
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-pay-method]').forEach(window.payMethodToggle));
  }
</script>
