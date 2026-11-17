<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $channel */
/** @var array<string,string> $settings */
/** @var string|null $flash */
/** @var string|null $error */
$settings = $settings ?? [];
$on = static fn (string $key, string $default = '0'): bool => ($settings[$key] ?? $default) === '1';
$type = (string) ($channel['type'] ?? 'marketplace');
$linked = ($settings['linked'] ?? '0') === '1';
$isManual = $type === 'manual';
$provider = (string) ($settings['provider'] ?? $type);
$oauthReady = !empty($oauthReady);
$credReady = !empty($credReady);
$oauthProvider = (string) ($oauthProvider ?? '');
$connectorMode = (string) ($connectorMode ?? '');
$appsUrl = (string) ($appsUrl ?? $url('platform/marketplace-apps'));
$hasToken = ($settings['access_token'] ?? '') !== '' || ($settings['refresh_token'] ?? '') !== '';
$channelId = (string) $channel['id'];
$csrf = $app->session->csrfToken();
?>
<div class="sf-page-head">
  <h1><?= $e((string) $channel['name']) ?> - Integration Details</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/channels')) ?>">&lt;&lt; Back To Integrations</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<?php if (!$isManual && $oauthReady): ?>
  <div class="alert alert-info py-2">
    <?= $e($oauthProvider) ?> OAuth is ready — Connect opens the real authorise page.
  </div>
<?php elseif (!$isManual && $credReady): ?>
  <div class="alert alert-info py-2">
    <?= $e($oauthProvider) ?> API credentials are ready — Connect will link with your key/secret.
  </div>
<?php elseif (!$isManual && $oauthProvider !== ''): ?>
  <div class="alert alert-warning py-2">
    <?= $e($oauthProvider) ?> is not configured yet.
    Add credentials under <a href="<?= $e($appsUrl) ?>">Marketplace Apps</a>
    <?= $connectorMode === 'credentials' ? ' or enter API key/secret below' : '' ?>.
  </div>
<?php endif; ?>

<section class="sf-int-block">
  <header>Link your <?= $e($isManual ? 'channel' : ($provider !== '' ? $provider : $type)) ?> account</header>
  <div class="sf-int-block-body">
    <div class="sf-connect-row">
      <div>
        <div><strong><?= $isManual ? 'Activate channel' : 'Link to StoreFeeder' ?></strong></div>
        <?php if ($linked): ?>
          <div class="sf-connect-ok">
            <?= $isManual ? 'Active' : 'Channel linked' ?><?= !empty($settings['linked_user']) ? ' · ' . $e((string) $settings['linked_user']) : '' ?>
          </div>
          <?php if (!empty($settings['last_connected_at'])): ?>
            <div class="text-muted">Last connected successfully: <?= $e((string) $settings['last_connected_at']) ?></div>
          <?php endif; ?>
          <?php if (($settings['auth_mode'] ?? '') === 'simulated'): ?>
            <div class="sf-connect-warn">Linked in simulated mode — re-connect after Marketplace Apps are configured.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="sf-connect-warn"><?= $isManual ? 'Not active' : 'Not linked — connect to authorise this channel.' ?></div>
        <?php endif; ?>
      </div>
      <div class="sf-connect-actions">
        <?php if ($linked): ?>
          <?php if (!$isManual): ?>
            <form method="post" action="<?= $e($url('admin/settings/channels/test')) ?>" style="display:inline">
              <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= $e($channelId) ?>">
              <button class="btn btn-primary" type="submit">Test Connection</button>
            </form>
            <?php if ($hasToken || ($settings['api_key'] ?? '') !== '' || ($settings['api_secret'] ?? '') !== ''): ?>
              <form method="post" action="<?= $e($url('admin/settings/channels/sync')) ?>" style="display:inline">
                <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
                <input type="hidden" name="id" value="<?= $e($channelId) ?>">
                <input type="hidden" name="days" value="all">
                <button class="btn btn-primary" type="submit">Sync all orders</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" action="<?= $e($url('admin/settings/channels/unlink')) ?>" style="display:inline">
            <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
            <input type="hidden" name="id" value="<?= $e($channelId) ?>">
            <button class="btn btn-outline-secondary" type="submit"><?= $isManual ? 'Deactivate' : 'Unlink' ?></button>
          </form>
        <?php else: ?>
          <?php if (!$isManual && ($oauthReady || $connectorMode === 'oauth')): ?>
            <a class="btn btn-green" href="<?= $e($url('admin/settings/channels/oauth', ['id' => $channelId])) ?>">
              Connect / Link account
            </a>
          <?php else: ?>
            <form method="post" action="<?= $e($url('admin/settings/channels/connect')) ?>" style="display:inline">
              <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
              <input type="hidden" name="id" value="<?= $e($channelId) ?>">
              <input type="hidden" name="provider" value="<?= $e((string) ($settings['provider'] ?? '')) ?>">
              <button class="btn btn-green" type="submit">Connect / Link account</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<form method="post" action="<?= $e($url('admin/settings/channels/save')) ?>">
  <input type="hidden" name="_token" value="<?= $e($csrf) ?>">
  <input type="hidden" name="id" value="<?= $e($channelId) ?>">

  <section class="sf-int-block">
    <header>Integration Profile</header>
    <div class="sf-int-block-body">
      <div class="sf-int-grid">
        <div class="sf-field">
          <label>Integration type</label>
          <div class="sf-int-type"><?= $e(ucfirst($type)) ?> · <code><?= $e((string) $channel['code']) ?></code></div>
        </div>
        <div class="sf-field">
          <label>Active</label>
          <label class="sf-check" style="margin-top:.35rem">
            <input type="checkbox" name="active" value="1" <?= !empty($channel['active']) ? 'checked' : '' ?>> Channel active
          </label>
        </div>
        <div class="sf-field" style="grid-column:1/-1">
          <label>Profile name</label>
          <input name="name" required value="<?= $e((string) $channel['name']) ?>">
        </div>
      </div>
    </div>
  </section>

  <section class="sf-int-block">
    <header>Account details</header>
    <div class="sf-int-block-body">
      <div class="sf-int-grid">
        <div class="sf-field">
          <label>Seller / user ID</label>
          <input name="linked_user" value="<?= $e((string) ($settings['linked_user'] ?? '')) ?>" placeholder="e.g. duzky_uk">
        </div>
        <div class="sf-field"><label>Provider / platform</label><input name="provider" value="<?= $e((string) ($settings['provider'] ?? '')) ?>" placeholder="ebay / amazon / shopify"></div>
        <div class="sf-field"><label>Store URL</label><input name="store_url" value="<?= $e((string) ($settings['store_url'] ?? '')) ?>" placeholder="https://"></div>
        <div class="sf-field"><label>Merchant ID</label><input name="merchant_id" value="<?= $e((string) ($settings['merchant_id'] ?? '')) ?>"></div>
      </div>

      <details class="sf-int-advanced" <?= ($isManual || ($settings['api_key'] ?? '') !== '' || ($settings['api_secret'] ?? '') !== '') ? 'open' : '' ?>>
        <summary>Advanced credentials <span class="text-muted">(optional — not required when using Connect)</span></summary>
        <div class="sf-int-grid" style="margin-top:.85rem">
          <div class="sf-field">
            <label>API key / client ID</label>
            <input name="api_key" value="" placeholder="<?= ($settings['api_key'] ?? '') !== '' ? '•••• saved — leave blank to keep' : 'Optional' ?>" autocomplete="off">
          </div>
          <div class="sf-field">
            <label>API secret</label>
            <input name="api_secret" type="password" value="" placeholder="<?= ($settings['api_secret'] ?? '') !== '' ? '•••• saved — leave blank to keep' : 'Optional' ?>" autocomplete="new-password">
          </div>
        </div>
        <p class="text-muted" style="margin:.5rem 0 0">Leave blank to keep any existing values. Marketplace/webstore channels normally link via Connect instead.</p>
      </details>

      <?php
        $prov = strtolower(trim((string) ($settings['provider'] ?? '')));
        $courierKind = '';
        if ($type === 'courier') {
            $courierKind = match (true) {
                in_array($prov, ['evri', 'hermes', 'myhermes'], true) => 'evri',
                in_array($prov, ['dpd', 'dpd_uk', 'dpduk'], true) => 'dpd',
                in_array($prov, ['parcelforce', 'parcel_force', 'pf'], true) => 'parcelforce',
                in_array($prov, ['royal_mail', 'royalmail', 'rm', ''], true) => 'royal_mail',
                default => 'other',
            };
        }
      ?>
      <?php if ($courierKind === 'royal_mail'): ?>
        <div class="sf-int-grid" style="margin-top:1rem">
          <div class="sf-field" style="grid-column:1/-1">
            <label>Royal Mail Shipping Account ID</label>
            <input name="shipping_account_id" value="<?= $e((string) ($settings['shipping_account_id'] ?? '')) ?>" placeholder="Pro Shipping account GUID">
          </div>
          <div class="sf-field">
            <label>Shipping Location ID <span class="text-muted">(optional)</span></label>
            <input name="shipping_location_id" value="<?= $e((string) ($settings['shipping_location_id'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Default service code</label>
            <input name="default_service_code" value="<?= $e((string) ($settings['default_service_code'] ?? 'CRL1')) ?>" placeholder="CRL1">
          </div>
        </div>
        <p class="text-muted" style="margin:.5rem 0 0">Required for live labels at despatch. Service codes come from shipping methods when rules match; otherwise the default above is used.</p>
      <?php elseif ($courierKind === 'evri'): ?>
        <div class="sf-int-grid" style="margin-top:1rem">
          <div class="sf-field">
            <label>Client UID prefix <span class="text-muted">(optional)</span></label>
            <input name="client_uid" value="<?= $e((string) ($settings['client_uid'] ?? '')) ?>" placeholder="Optional prefix">
          </div>
          <div class="sf-field">
            <label>Default service code</label>
            <input name="default_service_code" value="<?= $e((string) ($settings['default_service_code'] ?? 'STANDARD')) ?>" placeholder="STANDARD, POSTABLE, or NEXT_DAY">
          </div>
        </div>
        <p class="text-muted" style="margin:.5rem 0 0">Live labels use STANDARD, POSTABLE, or NEXT_DAY when shipping rules do not supply a code.</p>
      <?php elseif ($courierKind === 'dpd'): ?>
        <div class="sf-int-grid" style="margin-top:1rem">
          <div class="sf-field">
            <label>DPD account number</label>
            <input name="account_number" value="<?= $e((string) ($settings['account_number'] ?? '')) ?>" placeholder="GEOClient account">
          </div>
          <div class="sf-field">
            <label>Default network code</label>
            <input name="default_service_code" value="<?= $e((string) ($settings['default_service_code'] ?? '')) ?>" placeholder="1^12">
          </div>
          <div class="sf-field">
            <label>Collection contact</label>
            <input name="collection_name" value="<?= $e((string) ($settings['collection_name'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Collection phone</label>
            <input name="collection_phone" value="<?= $e((string) ($settings['collection_phone'] ?? '')) ?>">
          </div>
          <div class="sf-field" style="grid-column:1/-1">
            <label>Collection address line 1</label>
            <input name="collection_line1" value="<?= $e((string) ($settings['collection_line1'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Collection city</label>
            <input name="collection_city" value="<?= $e((string) ($settings['collection_city'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Collection postcode</label>
            <input name="collection_postcode" value="<?= $e((string) ($settings['collection_postcode'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Collection country</label>
            <input name="collection_country" value="<?= $e((string) ($settings['collection_country'] ?? 'GB')) ?>" placeholder="GB">
          </div>
        </div>
        <p class="text-muted" style="margin:.5rem 0 0">Account number, network code, and collection address are required before despatch can buy a DPD label.</p>
      <?php elseif ($courierKind === 'parcelforce'): ?>
        <div class="sf-int-grid" style="margin-top:1rem">
          <div class="sf-field">
            <label>Contract number</label>
            <input name="account_number" value="<?= $e((string) ($settings['account_number'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Department ID <span class="text-muted">(optional)</span></label>
            <input name="department_id" value="<?= $e((string) ($settings['department_id'] ?? '')) ?>">
          </div>
          <div class="sf-field">
            <label>Default service code</label>
            <input name="default_service_code" value="<?= $e((string) ($settings['default_service_code'] ?? 'SND')) ?>" placeholder="SND">
          </div>
          <div class="sf-field" style="grid-column:1/-1">
            <label>ExpressLink URL <span class="text-muted">(optional)</span></label>
            <input name="expresslink_url" value="<?= $e((string) ($settings['expresslink_url'] ?? '')) ?>" placeholder="https://expresslink.parcelforce.net/ws/">
          </div>
        </div>
        <p class="text-muted" style="margin:.5rem 0 0">Contract number and service code are required for live labels. Leave the URL blank to use the production ExpressLink endpoint.</p>
      <?php elseif ($courierKind === 'other'): ?>
        <div class="sf-int-grid" style="margin-top:1rem">
          <div class="sf-field">
            <label>Default service code</label>
            <input name="default_service_code" value="<?= $e((string) ($settings['default_service_code'] ?? '')) ?>">
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="sf-int-block">
    <header>Channel Settings</header>
    <div class="sf-int-block-body">
      <p class="text-muted" style="margin-top:0">StoreFeeder is the master for inventory, listings, prices and stock levels for this channel when the options below are enabled.</p>
      <div class="sf-int-settings">
        <div>
          <h3>Order Processing</h3>
          <label class="sf-switch-row"><span>Import Orders</span><input type="checkbox" name="import_orders" value="1" <?= $on('import_orders', '1') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Send Despatch Notifications</span><input type="checkbox" name="send_despatch" value="1" <?= $on('send_despatch', '1') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Ignore Channel Tax</span><input type="checkbox" name="ignore_channel_tax" value="1" <?= $on('ignore_channel_tax') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Merge Orders</span><input type="checkbox" name="merge_orders" value="1" <?= $on('merge_orders') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Disable Invoice Printing</span><input type="checkbox" name="disable_invoice" value="1" <?= $on('disable_invoice') ? 'checked' : '' ?>></label>
          <div class="sf-field" style="margin:.75rem 0 0"><label>Handling Time in Days</label><input name="handling_days" value="<?= $e((string) ($settings['handling_days'] ?? '')) ?>" style="max-width:8rem"></div>
          <label class="sf-switch-row"><span>Enforce Channel Warehouse</span><input type="checkbox" name="enforce_warehouse" value="1" <?= $on('enforce_warehouse') ? 'checked' : '' ?>></label>
        </div>
        <div>
          <h3>Stock Management</h3>
          <label class="sf-switch-row"><span>Automatic Inventory Updates</span><input type="checkbox" name="auto_inventory" value="1" <?= $on('auto_inventory', '1') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Automatic Price Updates</span><input type="checkbox" name="auto_price" value="1" <?= $on('auto_price') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Automatic Listings Download</span><input type="checkbox" name="auto_listing_download" value="1" <?= $on('auto_listing_download', '1') ? 'checked' : '' ?>></label>
          <label class="sf-switch-row"><span>Upload Listings</span><input type="checkbox" name="upload_listings" value="1" <?= $on('upload_listings') ? 'checked' : '' ?>></label>
          <?php
            $listingProvider = strtolower(str_replace(['-', ' '], '_', (string) ($settings['provider'] ?? $channel['code'] ?? '')));
            $canDownload = in_array($listingProvider, ['ebay', 'ebay_uk', 'ebay_us', 'shopify', 'shopify_store', 'amazon', 'amazon_uk', 'amazon_us', 'amazon_eu', 'amazon_sp'], true);
          ?>
          <?php if ($canDownload): ?>
            <button class="btn" type="submit" formmethod="post" formaction="<?= $e($url('admin/settings/channels/listings')) ?>">Download Listings Now</button>
          <?php else: ?>
            <button class="btn" type="button" disabled title="eBay, Shopify, and Amazon only">Download Listings Now</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="sf-int-block">
    <header>Misc</header>
    <div class="sf-int-block-body">
      <div class="sf-int-grid">
        <div class="sf-field">
          <label>Currency</label>
          <select name="currency">
            <?php foreach (['GBP', 'EUR', 'USD', 'AUD'] as $cur): ?>
              <option value="<?= $e($cur) ?>" <?= ($settings['currency'] ?? 'GBP') === $cur ? 'selected' : '' ?>><?= $e($cur) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field" style="grid-column:1/-1">
          <label>Notes</label>
          <textarea name="notes" rows="3"><?= $e((string) ($settings['notes'] ?? '')) ?></textarea>
        </div>
      </div>
    </div>
  </section>

  <div style="margin:1rem 0 2rem">
    <button class="btn btn-primary" type="submit">Save</button>
  </div>
</form>
