<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string, array{title:string, items:list<array<string,mixed>>}> $groups */
/** @var string $callback */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Marketplace Apps</h1>
  <p class="text-muted mb-0">
    Global developer credentials for this StoreFeeder install (shared by every tenant).
    OAuth callback:
  </p>
  <p class="mb-0"><code><?= $e($callback) ?></code></p>
  <div class="spacer"></div>
  <a href="<?= $e($url('platform')) ?>">&lt;&lt; Platform</a>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('platform/marketplace-apps')) ?>">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">

  <?php foreach ($groups as $groupKey => $group): ?>
    <?php if ($group['items'] === []) continue; ?>
    <?php $accordionId = 'sf-apps-' . preg_replace('/[^a-z0-9_\-]+/i', '-', (string) $groupKey); ?>
    <div class="sf-card">
      <h2><?= $e($group['title']) ?></h2>
      <div class="accordion sf-apps-accordion" id="<?= $e($accordionId) ?>">
        <?php foreach ($group['items'] as $item): ?>
          <?php
            $key = (string) $item['key'];
            $cfg = (array) ($item['config'] ?? []);
            $ready = !empty($item['configured']);
            $modes = [];
            if (!empty($item['oauth'])) {
                $modes[] = 'OAuth';
            }
            if (!empty($item['credentials'])) {
                $modes[] = 'API key';
            }
            $panelId = $accordionId . '-' . preg_replace('/[^a-z0-9_\-]+/i', '-', $key);
            $headingId = $panelId . '-h';
          ?>
          <div class="accordion-item">
            <h3 class="accordion-header" id="<?= $e($headingId) ?>">
              <button
                class="accordion-button collapsed"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#<?= $e($panelId) ?>"
                aria-expanded="false"
                aria-controls="<?= $e($panelId) ?>"
              >
                <span class="sf-apps-acc-title">
                  <strong><?= $e((string) $item['label']) ?></strong>
                  <code><?= $e($key) ?></code>
                </span>
                <span class="sf-apps-acc-meta">
                  <span class="badge <?= $ready ? 'badge-ok' : 'badge-warn' ?>"><?= $ready ? 'Ready' : 'Not configured' ?></span>
                  <?php if ($modes !== []): ?>
                    <span class="text-muted"><?= $e(implode(' / ', $modes)) ?></span>
                  <?php endif; ?>
                </span>
              </button>
            </h3>
            <div
              id="<?= $e($panelId) ?>"
              class="accordion-collapse collapse"
              aria-labelledby="<?= $e($headingId) ?>"
              data-bs-parent="#<?= $e($accordionId) ?>"
            >
              <div class="accordion-body">
                <div class="sf-int-grid">
                  <div class="sf-field">
                    <label>Client ID / API key</label>
                    <input name="<?= $e($key) ?>_client_id" value="" placeholder="<?= !empty($cfg['client_id']) || !empty($cfg['api_key']) ? '•••• saved — leave blank to keep' : '' ?>" autocomplete="off">
                  </div>
                  <div class="sf-field">
                    <label>Client secret / API secret</label>
                    <input name="<?= $e($key) ?>_client_secret" type="password" value="" placeholder="<?= !empty($cfg['client_secret']) || !empty($cfg['api_secret']) ? '•••• saved — leave blank to keep' : '' ?>" autocomplete="new-password">
                  </div>

                  <?php if ($key === 'ebay'): ?>
                    <div class="sf-field" style="grid-column:1/-1">
                      <label>RuName (eBay “Your auth accepted URL” must equal the callback above)</label>
                      <input name="ebay_ru_name" value="<?= $e((string) ($cfg['ru_name'] ?? '')) ?>" placeholder="StoreFeeder-StoreFeeder-PRD-xxx or sandbox RuName">
                    </div>
                    <div class="sf-field"><label class="sf-check"><input type="checkbox" name="ebay_sandbox" value="1" <?= !isset($cfg['sandbox']) || !empty($cfg['sandbox']) ? 'checked' : '' ?>> Sandbox</label></div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">
                      In the eBay Developer Portal → User Tokens → Get a Token from eBay via Your Application,
                      set <strong>Auth Accepted URL</strong> to <code><?= $e($callback) ?></code> (no <code>/public</code>, no <code>index.php</code>).
                      Sandbox apps need a sandbox RuName; production apps need a production RuName.
                    </p>
                    <div class="sf-field" style="grid-column:1/-1;margin-top:.75rem">
                      <label>Account deletion endpoint (paste into eBay Developer Portal → Notifications)</label>
                      <input readonly value="<?= $e((string) ($ebayDeletionEndpoint ?? $url('integrations/ebay/account-deletion'))) ?>" onclick="this.select()">
                    </div>
                    <div class="sf-field" style="grid-column:1/-1">
                      <label>Account deletion verification token (32–80 chars: letters, numbers, _ -)</label>
                      <input name="ebay_deletion_verification_token" value="<?= $e((string) ($cfg['deletion_verification_token'] ?? '')) ?>" placeholder="Leave blank on first save to auto-generate" autocomplete="off">
                    </div>
                    <div class="sf-field" style="grid-column:1/-1">
                      <label>Endpoint URL override <span class="text-muted">(only if the public HTTPS URL differs from above — must match eBay exactly for challenge hash)</span></label>
                      <input name="ebay_deletion_endpoint" value="<?= $e((string) ($cfg['deletion_endpoint'] ?? '')) ?>" placeholder="https://your-domain/.../integrations/ebay/account-deletion">
                    </div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">
                      eBay Alerts &amp; Notifications → Marketplace Account Deletion: set the endpoint URL and the same verification token, then Save so eBay can challenge-verify.
                      Processed deletions appear under <strong>Platform → Logs</strong>.
                    </p>
                  <?php elseif ($key === 'amazon'): ?>
                    <div class="sf-field"><label>Application ID</label><input name="amazon_application_id" value="<?= $e((string) ($cfg['application_id'] ?? '')) ?>"></div>
                    <div class="sf-field"><label>Seller Central</label><input name="amazon_seller_central" value="<?= $e((string) ($cfg['seller_central'] ?? 'sellercentral.amazon.co.uk')) ?>"></div>
                    <div class="sf-field"><label>Marketplace ID</label><input name="amazon_marketplace_id" value="<?= $e((string) ($cfg['marketplace_id'] ?? 'A1F83G8C2ARO7P')) ?>"></div>
                    <div class="sf-field">
                      <label>Region</label>
                      <select name="amazon_region">
                        <?php foreach (['eu' => 'Europe', 'na' => 'North America', 'fe' => 'Far East'] as $rk => $rl): ?>
                          <option value="<?= $e($rk) ?>" <?= (($cfg['region'] ?? 'eu') === $rk) ? 'selected' : '' ?>><?= $e($rl) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="sf-field"><label class="sf-check"><input type="checkbox" name="amazon_draft" value="1" <?= !isset($cfg['draft']) || !empty($cfg['draft']) ? 'checked' : '' ?>> Draft (version=beta)</label></div>
                  <?php elseif ($key === 'royal_mail'): ?>
                    <div class="sf-field"><label>Account number</label><input name="royal_mail_account_number" value="<?= $e((string) ($cfg['account_number'] ?? '')) ?>"></div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">
                      Auth API: <code>POST /api/v1/couriers/royal-mail/auth</code>
                      → <code>https://authentication.proshipping.net/connect/token</code> (client_credentials).
                    </p>
                  <?php elseif ($key === 'parcelforce'): ?>
                    <div class="sf-field"><label>Account number</label><input name="parcelforce_account_number" value="<?= $e((string) ($cfg['account_number'] ?? '')) ?>"></div>
                    <div class="sf-field"><label>Department ID</label><input name="parcelforce_department_id" value="<?= $e((string) ($cfg['department_id'] ?? '')) ?>"></div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">Auth API: <code>POST /api/v1/couriers/parcelforce/auth</code> (ExpressLink username/password).</p>
                  <?php elseif ($key === 'dpd'): ?>
                    <div class="sf-field"><label>Account number</label><input name="dpd_account_number" value="<?= $e((string) ($cfg['account_number'] ?? '')) ?>"></div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">Auth API: <code>POST /api/v1/couriers/dpd/auth</code> → <code>api.dpd.co.uk/user/?action=login</code>.</p>
                  <?php elseif ($key === 'evri'): ?>
                    <div class="sf-field"><label>Client name</label><input name="evri_client_name" value="<?= $e((string) ($cfg['client_name'] ?? '')) ?>"></div>
                    <p class="text-muted" style="grid-column:1/-1;margin:.25rem 0 0">Auth API: <code>POST /api/v1/couriers/evri/auth</code> → <code>api.business.evri.com/oauth/token</code>.</p>
                  <?php elseif ($key === 'bandq'): ?>
                    <div class="sf-field"><label>Supplier ID</label><input name="bandq_supplier_id" value="<?= $e((string) ($cfg['supplier_id'] ?? '')) ?>"></div>
                  <?php elseif ($key === 'bigcommerce'): ?>
                    <div class="sf-field"><label>Store hash</label><input name="bigcommerce_store_hash" value="<?= $e((string) ($cfg['store_hash'] ?? '')) ?>"></div>
                  <?php elseif ($key === 'onbuy'): ?>
                    <div class="sf-field"><label>Site ID</label><input name="onbuy_site_id" value="<?= $e((string) ($cfg['site_id'] ?? '2000')) ?>"></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div style="margin:1rem 0 2rem;display:flex;gap:.75rem;flex-wrap:wrap">
    <button class="btn btn-primary" type="submit">Save all credentials</button>
    <a class="btn btn-outline-secondary" href="<?= $e($url('platform/logs')) ?>">System logs</a>
  </div>
</form>
