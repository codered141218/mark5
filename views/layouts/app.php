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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
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
          if (!$visible) continue;
          $isActive = fn ($href) => $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/'));
          $hasActive = (bool) array_filter($visible, fn ($i) => $isActive($i[0]));
          $links = '';
          foreach ($visible as [$href, $label, $icon]) {
              $links .= '<a href="' . url($href) . '" class="' . ($isActive($href) ? 'active' : '') . '"><span class="ico">' . $icon . '</span>' . e($label) . '</a>';
          }
          if ($group === 'Overview'): ?>
        <div class="nav-group">
          <div class="nav-group-title"><?= e($group) ?></div>
          <?= $links ?>
        </div>
          <?php else: /* every other section is a drop-down; the one holding the current page starts open */ ?>
        <details class="nav-group nav-dd" data-nav-group="<?= e($group) ?>" <?= $hasActive ? 'open data-current' : '' ?>>
          <summary class="nav-group-title"><span><?= e($group) ?></span><span class="nav-caret" aria-hidden="true">▾</span></summary>
          <div class="nav-dd-items"><?= $links ?></div>
        </details>
          <?php endif; ?>
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
      <?php if (can('admin.settings') && App\Services\Admin\SetupWizard::pending() && !str_starts_with($path, '/setup')): ?>
        <div class="alert alert-info setup-banner"><span class="grow">Your system is not set up yet — the setup wizard takes a few minutes.</span>
          <a class="btn btn-primary btn-sm" href="<?= url('/setup') ?>">Start setup wizard</a></div>
      <?php endif; ?>
      <?php if ($m = flash('success')): ?><div class="alert alert-success" data-autohide><?= e($m) ?></div><?php endif; ?>
      <?php if ($m = flash('error')): ?><div class="alert alert-error"><?= e($m) ?></div><?php endif; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($scripts ?? [] as $js): ?><script src="<?= asset($js) ?>"></script><?php endforeach; ?>
</body>
</html>
