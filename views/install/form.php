<?php /** First-run installer. Variables: $db, $zip */ ?>
<div class="login-page">
  <form class="login-card wide" method="post" action="<?= url('/install') ?>">
    <?= csrf_field() ?>
    <div class="brand-logo">M5</div>
    <h1>Install Mark5 Restaurant Suite</h1>
    <p class="muted">Enter your MySQL / MariaDB details. The database is created automatically if it does not exist.</p>
    <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
    <?php if (!$zip): ?><div class="alert alert-warn">PHP <b>zip</b> extension is off: Excel exports will use the older .xls format. Enable <code>extension=zip</code> in php.ini for .xlsx.</div><?php endif; ?>
    <div class="form-grid">
      <label class="field"><span class="field-label">MySQL host</span><input class="input" name="host" value="<?= e(old('host', $db['host'] ?? '127.0.0.1')) ?>"></label>
      <label class="field"><span class="field-label">Port</span><input class="input" name="port" value="<?= e(old('port', $db['port'] ?? 3306)) ?>"></label>
      <label class="field"><span class="field-label">Database name</span><input class="input" name="database" value="<?= e(old('database', $db['database'] ?? 'mark5')) ?>"></label>
      <label class="field"><span class="field-label">MySQL username</span><input class="input" name="username" value="<?= e(old('username', $db['username'] ?? 'root')) ?>"></label>
      <label class="field"><span class="field-label">MySQL password</span><input class="input" type="password" name="password" value=""></label>
    </div>
    <h3 class="mt-lg">Administrator account</h3>
    <p class="muted small">Username is <b>admin</b>.</p>
    <div class="form-grid">
      <label class="field"><span class="field-label">Admin password</span><input class="input" type="password" name="admin_password" required minlength="6"></label>
      <label class="field"><span class="field-label">Confirm password</span><input class="input" type="password" name="admin_password_confirm" required></label>
      <label class="field"><span class="field-label">Manager PIN (4-8 digits)</span><input class="input" name="admin_pin" value="<?= e(old('admin_pin', '1234')) ?>" inputmode="numeric"></label>
    </div>
    <label class="checkbox mt"><input type="checkbox" name="sample" value="1" checked> Load a sample Filipino menu with recipes (you can delete it later)</label>
    <button class="btn btn-primary btn-lg btn-block mt-lg" type="submit">Install</button>
  </form>
</div>
