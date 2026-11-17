<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $content */
/** @var string $title */
/** @var string $navSection */

use App\Support\Theme;

$nav = require dirname(__DIR__, 2) . '/nav/section.php';
$section = $navSection ?? 'home';
if ($section === 'reports') {
    $section = 'insights';
}
$user = $user ?? $app->auth->user();
$tenant = $app->tenant->tenant();
$impersonating = $app->tenant->isImpersonating();
$company = $tenant
    ? (string) $tenant['name']
    : (string) ($app->config->get('app.name', 'StoreFeeder'));
$assetBase = $app->assetBaseUrl() . '/assets';
$display = (string) ($user['display_name'] ?? $user['email'] ?? 'U');
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
  <title><?= $e(($title ?? 'Admin') . ' - StoreFeeder') ?></title>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="<?= $e($assetBase . '/vendor/bootstrap/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= $e($assetBase . '/css/app.css?v=' . filemtime(dirname(__DIR__, 3) . '/assets/css/app.css')) ?>">
  <?php include dirname(__DIR__) . '/partials/theme_assets.php'; ?>
</head>
<body class="sf-app<?= $section === 'settings' ? ' sf-settings' : '' ?>">
  <?php if ($impersonating && $app->auth->isPlatform()): ?>
  <div class="sf-impersonate-bar">
    <span>StoreFeeder support — viewing <strong><?= $e($company) ?></strong> as tenant</span>
    <a href="<?= $e($url('platform/tenants/stop-impersonate')) ?>">Exit to Platform</a>
  </div>
  <?php endif; ?>

  <div class="sf-nav-backdrop" data-sf-nav-backdrop></div>

  <header class="sf-topbar">
    <button type="button" class="sf-menu-toggle" data-sf-nav-toggle aria-label="Open menu" aria-expanded="false" aria-controls="sf-side-nav">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <div class="sf-brand-wrap">
      <a class="sf-logo" href="<?= $e($url('admin')) ?>">Store<span>Feeder</span></a>
      <span class="sf-tenant"><?= $e($company) ?></span>
    </div>
    <form class="sf-search" action="<?= $e($url('admin/orders')) ?>" method="get">
      <input type="search" name="q" placeholder="Search...">
    </form>
    <div class="sf-top-actions">
      <?php include dirname(__DIR__) . '/partials/theme_picker.php'; ?>
      <?php if ($app->auth->isPlatform() && !$impersonating): ?>
        <a class="sf-icon-btn" href="<?= $e($url('platform')) ?>" title="Platform">P</a>
      <?php endif; ?>
      <a class="sf-icon-btn" href="<?= $e($url('admin/settings/users')) ?>" title="Settings">⚙</a>
      <form method="post" action="<?= $e($url('logout')) ?>" style="display:inline;margin:0">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <button type="submit" class="sf-avatar" title="Sign out <?= $e($display) ?>" style="border:0;cursor:pointer"><?= $e($initial) ?></button>
      </form>
    </div>
  </header>

  <nav class="sf-menubar" id="sf-side-nav" aria-label="Main">
    <?php foreach ($nav['primary'] as $item): ?>
      <?php
        $active = $section === $item['key'];
        $children = $nav['sections'][$item['key']] ?? [];
      ?>
      <div class="sf-nav-item<?= $children === [] ? ' no-children' : '' ?>">
        <a class="sf-nav-link <?= $active ? 'is-active' : '' ?>" href="<?= $e($url($item['url'])) ?>">
          <?php $icon = $item['icon'] ?? 'home'; include dirname(__DIR__) . '/partials/nav_icon.php'; ?>
          <?= $e($item['label']) ?>
        </a>
        <?php if ($children !== []): ?>
          <div class="sf-flyout" role="menu" aria-label="<?= $e($item['label']) ?>">
            <div class="sf-flyout-title"><?= $e($item['label']) ?></div>
            <?php foreach ($children as $sub): ?>
              <?php
                $subPath = trim($sub['url'], '/');
                $subQuery = $sub['query'] ?? [];
                $subActive = $requestPath === $subPath
                    || ($subQuery === [] && $subPath !== '' && str_starts_with($requestPath, $subPath));
                foreach ($subQuery as $qk => $qv) {
                    if ((string) $app->request->get((string) $qk, 'message') !== (string) $qv) {
                        $subActive = false;
                    }
                }
              ?>
              <a href="<?= $e($url($sub['url'], $sub['query'] ?? [])) ?>" class="<?= $subActive ? 'is-active' : '' ?>"><?= $e($sub['label']) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  </nav>

  <div class="sf-shell">
    <?php if ($section === 'settings'): ?>
      <aside class="sf-settings-nav" aria-label="Settings">
        <?php foreach (($nav['sections']['settings'] ?? []) as $group): ?>
          <?php
            $items = $group['items'] ?? [];
            $groupOpen = false;
            foreach ($items as $sub) {
                $subPath = trim((string) ($sub['url'] ?? ''), '/');
                if ($requestPath === $subPath || ($subPath !== '' && str_starts_with($requestPath, $subPath . '/'))) {
                    $groupOpen = true;
                    break;
                }
            }
          ?>
          <details class="sf-settings-group"<?= $groupOpen ? ' open' : '' ?>>
            <summary><?= $e((string) ($group['label'] ?? '')) ?></summary>
            <?php foreach ($items as $sub): ?>
              <?php
                $subPath = trim((string) ($sub['url'] ?? ''), '/');
                $subActive = $requestPath === $subPath
                    || ($subPath !== '' && str_starts_with($requestPath, $subPath . '/'));
              ?>
              <a href="<?= $e($url((string) $sub['url'])) ?>" class="<?= $subActive ? 'is-active' : '' ?>"><?= $e((string) $sub['label']) ?></a>
            <?php endforeach; ?>
          </details>
        <?php endforeach; ?>
      </aside>
    <?php endif; ?>
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
