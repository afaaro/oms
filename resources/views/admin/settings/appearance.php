<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string, array{label:string, description:string, swatch:array{0:string,1:string,2:string}}> $themes */
/** @var string $currentTheme */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Appearance</h1>
</div>
<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>

<p class="text-muted" style="margin-top:0">Choose a colour theme for StoreFeeder. Your choice is saved in this browser.</p>

<form method="post" action="<?= $e($url('admin/settings/appearance')) ?>" class="sf-theme-grid">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <?php foreach ($themes as $key => $theme): ?>
    <?php
      $swatch = $theme['swatch'];
      $checked = $currentTheme === $key;
    ?>
    <label class="sf-theme-card<?= $checked ? ' is-selected' : '' ?>">
      <input type="radio" name="theme" value="<?= $e($key) ?>" <?= $checked ? 'checked' : '' ?>>
      <span class="sf-theme-swatches" aria-hidden="true">
        <span style="background:<?= $e($swatch[0]) ?>"></span>
        <span style="background:<?= $e($swatch[1]) ?>"></span>
        <span style="background:<?= $e($swatch[2]) ?>"></span>
      </span>
      <strong><?= $e($theme['label']) ?></strong>
      <span class="text-muted"><?= $e($theme['description']) ?></span>
    </label>
  <?php endforeach; ?>
  <div style="grid-column:1/-1;margin-top:.5rem">
    <button class="btn btn-primary" type="submit">Save theme</button>
  </div>
</form>
