<?php /** Login page */ ?>
<div class="login-page">
  <form class="login-card" method="post" action="<?= url('/login') ?>">
    <?= csrf_field() ?>
    <div class="brand-logo">M5</div>
    <h1><?= e(App\Services\Settings::get('business_name', 'Mark5 Restaurant Suite')) ?></h1>
    <p class="muted">Sign in to continue</p>
    <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
    <label class="field"><span class="field-label">Username</span>
      <input class="input" name="username" value="<?= e(old('username')) ?>" autocomplete="username" autofocus required></label>
    <label class="field mt"><span class="field-label">Password</span>
      <input class="input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn btn-primary btn-lg btn-block mt-lg" type="submit">Sign in</button>
  </form>
</div>
