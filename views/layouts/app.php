<?php
/** Main back-office layout: sidebar + page content. Variables: $content, $title */
$nav = require BASE_PATH . '/app/nav.php';
$path = $GLOBALS['__request']->path ?? '/';
$u = user();
$business = App\Services\Settings::get('business_name', 'Mark5');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e($business) ?></title>
  <link rel="icon" href="<?= asset('img/icon.svg') ?>">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>
<div class="app">
  <header class="topbar">
    <button class="icon-btn" type="button" data-toggle-sidebar aria-label="Menu">☰</button>
    <b><?= e($business) ?></b>
  </header>
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-logo">M5</div>
      <div>
        <div class="brand-name"><?= e($business) ?></div>
        <div class="brand-sub">Restaurant Suite</div>
      </div>
    </div>
    <?php if (can('pos.access')): ?>
      <div class="pos-link"><a href="<?= url('/pos') ?>">▶ Open POS / Cashier</a></div>
    <?php endif; ?>
    <nav class="nav">
      <?php foreach ($nav as $group => $items):
          $visible = array_filter($items, fn ($i) => can(...$i[3]));
          if (!$visible) continue; ?>
        <div class="nav-group">
          <div class="nav-group-title"><?= e($group) ?></div>
          <?php foreach ($visible as [$href, $label, $icon]):
              $active = $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/')); ?>
            <a href="<?= url($href) ?>" class="<?= $active ? 'active' : '' ?>"><span class="ico"><?= $icon ?></span><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <b><?= e($u['full_name']) ?></b>
      <span><?= e($u['role_name']) ?></span>
      <div class="row gap-sm mt">
        <a href="<?= url('/account') ?>">My account</a>
        <span>·</span>
        <form method="post" action="<?= url('/logout') ?>" class="inline"><?= csrf_field() ?><button class="link-btn" type="submit">Log out</button></form>
      </div>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-toggle-sidebar></div>
  <main class="main">
    <div class="content">
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
