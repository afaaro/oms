<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $content */
/** @var string $title */
/** @var string $navSection */

use App\Support\Theme;

$nav = require dirname(__DIR__, 2) . '/nav/platform.php';
$section = $navSection ?? 'home';
$user = $user ?? $app->auth->user();
$assetBase = $app->assetBaseUrl() . '/assets';
$display = (string) ($user['display_name'] ?? $user['email'] ?? 'Staff');
$initial = strtoupper(substr($display, 0, 1));
$requestPath = trim($app->request->route(), '/');
$uiTheme = Theme::current($app);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $e($uiTheme) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e($app->session->csrfToken()) ?>">
  <title><?= $e(($title ?? 'Platform') . ' - StoreFeeder Platform') ?></title>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="<?= $e($assetBase . '/vendor/bootstrap/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $e($assetBase . '/css/app.css?v=' . filemtime(dirname(__DIR__, 3) . '/assets/css/app.css')) ?>">
  <?php include dirname(__DIR__) . '/partials/theme_assets.php'; ?>
</head>
<body class="sf-platform">
  <div class="sf-nav-backdrop" data-sf-nav-backdrop></div>

  <header class="sf-topbar">
    <button type="button" class="sf-menu-toggle" data-sf-nav-toggle aria-label="Open menu" aria-expanded="false" aria-controls="sf-side-nav">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <div class="sf-brand-wrap">
      <a class="sf-logo" href="<?= $e($url('platform')) ?>"><span>afaaro</span></a>
      <span class="sf-tenant">Platform <span class="sf-pill">Staff</span></span>
    </div>
    <form class="sf-search" action="<?= $e($url('platform/tenants')) ?>" method="get">
      <input type="search" name="q" placeholder="Search tenants, tickets…">
    </form>
    <div class="sf-top-actions">
      <?php include dirname(__DIR__) . '/partials/theme_picker.php'; ?>
      <a class="sf-icon-btn" href="<?= $e($url('platform/support')) ?>" title="Support">✉</a>
      <a class="sf-icon-btn" href="<?= $e($url('platform/billing')) ?>" title="Billing">£</a>
      <form method="post" action="<?= $e($url('logout')) ?>" style="display:inline;margin:0">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <button type="submit" class="sf-avatar" title="Sign out <?= $e($display) ?>" style="border:0;cursor:pointer"><?= $e($initial) ?></button>
      </form>
    </div>
  </header>

  <div class="sf-shell">
    <aside class="sf-side" id="sf-side-nav">
      <?php
      $icons = [
          'home' => 'home',
          'tenants' => 'customers',
          'support' => 'orders',
          'billing' => 'insights',
          'marketplace-apps' => 'products',
          'health' => 'insights',
          'database' => 'warehouse',
          'logs' => 'messages',
      ];
      foreach ($nav['primary'] as $item):
          $active = $section === $item['key'];
          $icon = $icons[$item['key']] ?? 'home';
          $children = $nav['sections'][$item['key']] ?? [];
      ?>
        <div class="sf-nav-item<?= $children === [] ? ' no-children' : '' ?>">
          <a class="sf-nav-link <?= $active ? 'is-active' : '' ?>" href="<?= $e($url($item['url'])) ?>">
            <?php include dirname(__DIR__) . '/partials/nav_icon.php'; ?>
            <?= $e($item['label']) ?>
          </a>
          <?php if ($children !== []): ?>
            <div class="sf-flyout" role="menu" aria-label="<?= $e($item['label']) ?>">
              <div class="sf-flyout-title"><?= $e($item['label']) ?></div>
              <?php foreach ($children as $sub): ?>
                <a href="<?= $e($url($sub['url'], $sub['query'] ?? [])) ?>"><?= $e($sub['label']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </aside>
    <main class="sf-main">
      <?= $content ?>
    </main>
  </div>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="<?= $e($assetBase . '/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
  <script src="<?= $e($assetBase . '/js/datatables-init.js?v=' . filemtime(dirname(__DIR__, 3) . '/assets/js/datatables-init.js')) ?>"></script>
  <script src="<?= $e($assetBase . '/js/nav-mobile.js') ?>"></script>
  <?php include dirname(__DIR__) . '/partials/theme_scripts.php'; ?>
</body>
</html>
