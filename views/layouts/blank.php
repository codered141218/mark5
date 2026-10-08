<?php /** Layout without sidebar (login, installer, POS). Variables: $content, $title, $bodyClass */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="base-url" content="<?= e(url('/')) ?>">
  <meta name="theme-color" content="#111827">
  <title><?= e($title ?? 'Mark5 Restaurant Suite') ?></title>
  <link rel="icon" href="<?= asset('img/icon.svg') ?>">
  <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
  <?php foreach ($styles ?? [] as $css): ?><link rel="stylesheet" href="<?= asset($css) ?>"><?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($scripts ?? [] as $js): ?><script src="<?= asset($js) ?>"></script><?php endforeach; ?>
</body>
</html>
