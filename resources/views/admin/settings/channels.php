<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string, array{title:string, rows:list<array<string,mixed>>}> $groups */
/** @var string|null $flash */
/** @var string|null $error */

$providerLabel = static function (array $row): string {
    $code = strtolower((string) ($row['code'] ?? ''));
    $name = strtolower((string) ($row['name'] ?? ''));
    $type = (string) ($row['type'] ?? '');
    if (str_contains($code, 'amazon') || str_contains($name, 'amazon')) {
        return 'Amazon';
    }
    if (str_contains($code, 'ebay') || str_contains($name, 'ebay')) {
        return 'eBay';
    }
    if (str_contains($code, 'shopify') || str_contains($name, 'shopify')) {
        return 'Shopify';
    }
    if (str_contains($code, 'woo') || str_contains($name, 'woo')) {
        return 'WooCommerce';
    }
    if (str_contains($code, 'etsy') || str_contains($name, 'etsy')) {
        return 'Etsy';
    }
    if ($type === 'courier') {
        return 'Courier';
    }
    if ($type === 'manual') {
        return 'Internal';
    }
    return ucfirst($type ?: 'Channel');
};
?>
<div class="sf-page-head">
  <h1>Integration Manager</h1>
  <div class="spacer"></div>
  <a class="btn btn-green" href="<?= $e($url('admin/settings/channels/new')) ?>">+ New Integration</a>
</div>
<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
  <?php if ($group['rows'] === []) {
      continue;
  } ?>
  <section class="sf-int-section">
    <header class="sf-int-section-head"><?= $e($group['title']) ?></header>
    <table class="sf-int-table">
      <thead>
        <tr>
          <th style="width:140px"></th>
          <th>Profile Name</th>
          <th style="width:110px">Active</th>
          <th style="width:130px">Connection</th>
          <th style="width:180px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($group['rows'] as $row): ?>
          <?php
            $on = !empty($row['active']);
            $settingsRaw = $row['settings'] ?? null;
            $settings = [];
            if (is_string($settingsRaw) && $settingsRaw !== '') {
                $decoded = json_decode($settingsRaw, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            } elseif (is_array($settingsRaw)) {
                $settings = $settingsRaw;
            }
            $linked = ($settings['linked'] ?? '0') === '1'
                || ($settings['linked'] ?? '') === 1
                || ($settings['linked'] ?? '') === true;
            $isManual = ((string) ($row['type'] ?? '')) === 'manual';
          ?>
          <tr>
            <td>
              <span class="sf-int-badge sf-int-badge-<?= $e((string) $row['type']) ?>"><?= $e($providerLabel($row)) ?></span>
            </td>
            <td>
              <a href="<?= $e($url('admin/settings/channels/edit', ['id' => $row['id']])) ?>">
                <strong><?= $e((string) $row['name']) ?></strong>
              </a>
              <div class="text-muted"><?= $e((string) $row['code']) ?></div>
            </td>
            <td>
              <form method="post" action="<?= $e($url('admin/settings/channels/toggle')) ?>" style="display:inline">
                <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $e((string) $row['id']) ?>">
                <button type="submit" class="sf-toggle <?= $on ? 'is-on' : '' ?>" title="<?= $on ? 'Turn off' : 'Turn on' ?>">
                  <span><?= $on ? 'On' : 'Off' ?></span>
                </button>
              </form>
            </td>
            <td>
              <?php if ($isManual): ?>
                <span class="badge <?= $on ? 'badge-ok' : 'badge-warn' ?>"><?= $on ? 'Ready' : 'Inactive' ?></span>
              <?php elseif ($linked): ?>
                <span class="badge badge-ok">Connected</span>
              <?php else: ?>
                <span class="badge badge-warn">Disconnected</span>
              <?php endif; ?>
            </td>
            <td class="sf-int-actions">
              <a href="<?= $e($url('admin/settings/channels/edit', ['id' => $row['id']])) ?>">Edit/View Integration</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>
