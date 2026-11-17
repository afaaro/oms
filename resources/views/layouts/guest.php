<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var string $content */
/** @var string $title */

use App\Support\Theme;

$company = (string) ($app->config->get('app.name', 'StoreFeeder'));
$assetBase = $app->assetBaseUrl() . '/assets';
$uiTheme = Theme::current($app);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $e($uiTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e($app->session->csrfToken()) ?>">
  <title><?= $e(($title ?? 'Sign in') . ' | ' . $company) ?></title>
  <link rel="stylesheet" href="<?= $e($assetBase . '/vendor/bootstrap/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $e($assetBase . '/css/app.css?v=' . filemtime(dirname(__DIR__, 3) . '/assets/css/app.css')) ?>">
  <?php include dirname(__DIR__) . '/partials/theme_assets.php'; ?>
</head>
<body>
  <?= $content ?>
  <script src="<?= $e($assetBase . '/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
