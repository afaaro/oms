<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
use App\Support\Theme;

$themes = Theme::all($app);
$currentTheme = Theme::current($app);
$returnPath = trim((string) $app->request->route(), '/');
if ($returnPath === '') {
    $returnPath = 'admin';
}
?>
<details class="sf-theme-picker">
  <summary class="sf-icon-btn" title="Theme" aria-label="Choose theme">◐</summary>
  <div class="sf-theme-menu" role="menu">
    <div class="sf-theme-menu-title">Theme</div>
    <?php foreach ($themes as $key => $theme): ?>
      <form method="post" action="<?= $e($url('admin/settings/appearance')) ?>" role="none">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="theme" value="<?= $e($key) ?>">
        <input type="hidden" name="return" value="<?= $e($returnPath) ?>">
        <button type="submit" class="sf-theme-option<?= $currentTheme === $key ? ' is-active' : '' ?>" role="menuitem">
          <span class="sf-theme-dots" aria-hidden="true">
            <i style="background:<?= $e($theme['swatch'][0]) ?>"></i>
            <i style="background:<?= $e($theme['swatch'][1]) ?>"></i>
            <i style="background:<?= $e($theme['swatch'][2]) ?>"></i>
          </span>
          <?= $e($theme['label']) ?>
        </button>
      </form>
    <?php endforeach; ?>
    <a class="sf-theme-more" href="<?= $e($url('admin/settings/appearance')) ?>">Appearance settings</a>
  </div>
</details>
