<?php
/** @var \App\Core\App $app */
/** @var string $uiTheme */
use App\Support\Theme;

$uiTheme = $uiTheme ?? Theme::current($app);
foreach (Theme::styleUrls($app, $uiTheme) as $href): ?>
  <link rel="stylesheet" href="<?= $e($href) ?>">
<?php endforeach; ?>
