<?php
/** @var \App\Core\App $app */
/** @var string $uiTheme */
use App\Support\Theme;

$uiTheme = $uiTheme ?? Theme::current($app);
foreach (Theme::scriptUrls($app, $uiTheme) as $src): ?>
  <script src="<?= $e($src) ?>"></script>
<?php endforeach; ?>
