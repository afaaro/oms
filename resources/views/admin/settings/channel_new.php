<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array{key:string,label:string,type:string,group:string}> $providers */
/** @var array{key:string,label:string,type:string,group:string}|null $provider */
/** @var string|null $error */
$providers = $providers ?? [];
$provider = $provider ?? null;
$groups = [];
foreach ($providers as $p) {
    $groups[$p['group']][] = $p;
}
?>
<div class="sf-page-head">
  <h1>New Integration</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/channels')) ?>">&lt;&lt; Back To Integrations</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<?php if ($provider === null): ?>
  <p class="text-muted">Step 1 — choose a provider</p>
  <?php foreach ($groups as $group => $items): ?>
    <section class="sf-int-section" style="margin-bottom:1rem">
      <header class="sf-int-section-head"><?= $e($group) ?></header>
      <div class="sf-wizard-grid">
        <?php foreach ($items as $item): ?>
          <a class="sf-wizard-card" href="<?= $e($url('admin/settings/channels/new', ['provider' => $item['key']])) ?>">
            <strong><?= $e($item['label']) ?></strong>
            <span class="text-muted"><?= $e($item['type']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php else: ?>
  <p class="text-muted">Step 2 — profile for <strong><?= $e($provider['label']) ?></strong>
    · <a href="<?= $e($url('admin/settings/channels/new')) ?>">Change provider</a>
  </p>
  <form method="post" action="<?= $e($url('admin/settings/channels')) ?>" class="card sf-panel sf-panel-solo" style="padding:1.1rem;max-width:640px">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="provider" value="<?= $e($provider['key']) ?>">
    <input type="hidden" name="type" value="<?= $e($provider['type']) ?>">
    <div class="sf-field">
      <label>Profile name</label>
      <input name="name" required placeholder="Duzky (<?= $e($provider['label']) ?>)" value="Duzky (<?= $e($provider['label']) ?>)">
    </div>
    <div class="sf-field">
      <label>Code <span class="text-muted">(optional — auto-generated if blank)</span></label>
      <input name="code" placeholder="<?= $e($provider['key']) ?>_duzky">
    </div>
    <button class="btn btn-green" type="submit">+ Add Integration</button>
  </form>
<?php endif; ?>
