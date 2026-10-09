<?php /** Layout without sidebar (login, installer, POS). Variables: $content, $title, $bodyClass */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, interactive-widget=resizes-content">
  <link rel="manifest" href="<?= url('/manifest.webmanifest') ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <meta name="theme-color" content="#0a0f1f">
  <title><?= e($title ?? 'Mark5 Restaurant Suite') ?></title>
  <link rel="icon" href="<?= asset('img/icon.svg') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <?php foreach ($styles ?? [] as $css): ?><link rel="stylesheet" href="<?= asset($css) ?>"><?php endforeach; ?>
  <link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($scripts ?? [] as $js): ?><script src="<?= asset($js) ?>"></script><?php endforeach; ?>
</body>
</html>
