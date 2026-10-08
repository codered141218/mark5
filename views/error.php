<?php /** Error page. Variables: $status, $message */ ?>
<div class="error-page">
  <h1><?= $status === 404 ? 'Page not found' : ($status === 403 ? 'Not allowed' : 'Something went wrong') ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <a class="btn" href="<?= url('/') ?>">Go to the start page</a>
</div>
