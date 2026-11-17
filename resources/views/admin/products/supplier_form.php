<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed>|null $supplier */
/** @var list<array<string,mixed>> $templates */
/** @var string|null $flash */
/** @var string|null $error */
$editing = is_array($supplier);
$s = $supplier ?? [];
$templates = $templates ?? [];
$action = $editing ? $url('admin/suppliers/save') : $url('admin/suppliers/new');
$val = static fn(string $key, string $default = ''): string => (string) ($s[$key] ?? $default);
$on = static fn(string $key): bool => !empty($s[$key]);
// Prefer ?template_id= (controller sorts selected first), else default, else first.
$activeTemplate = $templates[0] ?? null;
$preferDefault = (int) $app->request->get('template_id', 0) <= 0;
if ($preferDefault) {
    foreach ($templates as $tpl) {
        if (!empty($tpl['is_default'])) {
            $activeTemplate = $tpl;
            break;
        }
    }
}
$countries = [
    'GB' => 'United Kingdom',
    'IE' => 'Ireland',
    'US' => 'United States',
    'DE' => 'Germany',
    'FR' => 'France',
    'NL' => 'Netherlands',
    'ES' => 'Spain',
    'IT' => 'Italy',
    'AU' => 'Australia',
    'CA' => 'Canada',
];
$currencies = [
    'GBP' => 'British Pound',
    'EUR' => 'Euro',
    'USD' => 'US Dollar',
    'AUD' => 'Australian Dollar',
    'CAD' => 'Canadian Dollar',
];
$poStatuses = ['', 'draft', 'ordered', 'partial', 'received', 'cancelled'];
$dsStatuses = ['', 'pending', 'sent', 'shipped', 'cancelled'];
?>
<div class="sf-page-head">
  <h1><?= $editing ? 'Supplier' : 'Create supplier' ?></h1>
  <div class="spacer"></div>
  <?php if ($editing): ?>
    <form method="post" action="<?= $e($url('admin/suppliers/delete')) ?>" style="display:inline" onsubmit="return confirm('Delete this supplier permanently?');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
      <button class="btn" type="submit" style="background:#c62828;color:#fff;border-color:#c62828">Delete</button>
    </form>
  <?php endif; ?>
  <button class="btn btn-primary" type="submit" form="supplier-form">Save</button>
  <a href="<?= $e($url('admin/suppliers')) ?>">&lt;&lt; Back to Search</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($action) ?>" id="supplier-form">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <?php if ($editing): ?><input type="hidden" name="id" value="<?= $e($val('id')) ?>"><?php endif; ?>

  <section class="sf-int-block">
    <header>Supplier Information</header>
    <div class="sf-int-block-body">
      <div class="sf-supplier-grid">
        <div class="sf-field"><label>Supplier Name</label><input name="name" required value="<?= $e($val('name')) ?>"></div>
        <div class="sf-field"><label>Contact Name</label><input name="contact_name" value="<?= $e($val('contact_name')) ?>"></div>

        <div class="sf-field"><label>Address</label><input name="address_line1" value="<?= $e($val('address_line1', $val('address'))) ?>"></div>
        <div class="sf-field"><label>Address 2</label><input name="address_line2" value="<?= $e($val('address_line2')) ?>"></div>
        <div class="sf-field"><label>City</label><input name="city" value="<?= $e($val('city')) ?>"></div>
        <div class="sf-field"><label>County / State</label><input name="county" value="<?= $e($val('county')) ?>"></div>
        <div class="sf-field">
          <label>Country</label>
          <select name="country">
            <?php foreach ($countries as $code => $label): ?>
              <option value="<?= $e($code) ?>" <?= $val('country', 'GB') === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field"><label>Postcode / Zip Code</label><input name="postcode" value="<?= $e($val('postcode')) ?>"></div>

        <div class="sf-field"><label>Phone</label><input name="phone" value="<?= $e($val('phone')) ?>"></div>
        <div class="sf-field"><label>Supplier Code</label><input name="code" value="<?= $e($val('code')) ?>"></div>
        <div class="sf-field"><label>Suppliers Reference</label><input name="supplier_reference" value="<?= $e($val('supplier_reference')) ?>"></div>
        <div class="sf-field"><label>US Customs MID Code</label><input name="customs_mid" value="<?= $e($val('customs_mid')) ?>"></div>

        <div class="sf-field">
          <label>Supplier Currency</label>
          <select name="currency">
            <?php foreach ($currencies as $code => $label): ?>
              <option value="<?= $e($code) ?>" <?= $val('currency', 'GBP') === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field"><label>Supplier E-mail Address</label><input name="email" type="email" value="<?= $e($val('email')) ?>"></div>
        <div class="sf-field" style="grid-column:1/-1"><label>Comma Delimited Email Addresses To Send To</label><input name="notify_emails" value="<?= $e($val('notify_emails')) ?>"></div>
        <div class="sf-field"><label>Contact Email</label><input name="contact_email" type="email" value="<?= $e($val('contact_email', $val('email'))) ?>"></div>
        <div class="sf-field"><label>Minimum Order Value</label><input name="min_order_value" type="number" step="0.01" min="0" value="<?= $e($val('min_order_value', '0.00')) ?>"></div>
        <div class="sf-field"><label>Lead Time (days)</label><input name="lead_time_days" type="number" min="0" value="<?= $e($val('lead_time_days', '0')) ?>"></div>
        <div class="sf-field">
          <label>Purchase Order Mode</label>
          <select name="po_mode">
            <?php foreach (['on_demand' => 'On Demand', 'scheduled' => 'Scheduled', 'manual' => 'Manual'] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $val('po_mode', 'on_demand') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="sf-supplier-switches" style="margin-top:1rem">
        <label class="sf-switch-row"><span>Send Email When Product Below Reorder Level</span><input type="checkbox" name="email_below_reorder" value="1" <?= $on('email_below_reorder') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Send Email When Product Below Out of Stock Threshold</span><input type="checkbox" name="email_below_oos" value="1" <?= $on('email_below_oos') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Include Products Equal To Reorder Level</span><input type="checkbox" name="include_equal_reorder" value="1" <?= $on('include_equal_reorder') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Exclude Out of Stock Products With Zero Manual Reorder Level</span><input type="checkbox" name="exclude_oos_zero_reorder" value="1" <?= $on('exclude_oos_zero_reorder') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Accepts Back Orders</span><input type="checkbox" name="accepts_backorders" value="1" <?= $on('accepts_backorders') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Is Manufacturer</span><input type="checkbox" name="is_manufacturer" value="1" <?= $on('is_manufacturer') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Active</span><input type="checkbox" name="active" value="1" <?= !$editing || $on('active') ? 'checked' : '' ?>></label>
      </div>

      <div class="sf-field" style="margin-top:1rem"><label>Notes</label><textarea name="notes" rows="2"><?= $e($val('notes')) ?></textarea></div>
    </div>
  </section>

  <section class="sf-int-block" style="margin-top:1rem">
    <header>Purchase Order / Drop Shipment Information</header>
    <div class="sf-int-block-body">
      <div class="sf-supplier-grid">
        <div class="sf-field">
          <label>Purchase Order Shipping Cost Type</label>
          <select name="po_shipping_cost_type">
            <?php foreach (['per_order' => 'Set Per Order', 'per_item' => 'Set Per Item', 'fixed' => 'Fixed', 'none' => 'None'] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $val('po_shipping_cost_type', 'per_order') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field">
          <label>Drop Shipment Shipping Cost Type</label>
          <select name="dropship_shipping_cost_type">
            <?php foreach (['per_order' => 'Set Per Order', 'per_item' => 'Set Per Item', 'fixed' => 'Fixed', 'none' => 'None'] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $val('dropship_shipping_cost_type', 'per_item') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field">
          <label>Purchase Order Change to Status</label>
          <select name="po_change_status">
            <?php foreach ($poStatuses as $st): ?>
              <option value="<?= $e($st) ?>" <?= $val('po_change_status') === $st ? 'selected' : '' ?>><?= $st === '' ? '(Please Select)' : $e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field">
          <label>Drop Shipment Change to Status</label>
          <select name="dropship_change_status">
            <?php foreach ($dsStatuses as $st): ?>
              <option value="<?= $e($st) ?>" <?= $val('dropship_change_status') === $st ? 'selected' : '' ?>><?= $st === '' ? '(Please Select)' : $e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field"><label>Total Purchase Order Shipping Cost</label><input name="po_shipping_cost" type="number" step="0.01" min="0" value="<?= $e($val('po_shipping_cost', '0.00')) ?>"></div>
        <div class="sf-field"><label>Total Drop Shipment Item Shipping Cost</label><input name="dropship_item_shipping_cost" type="number" step="0.01" min="0" value="<?= $e($val('dropship_item_shipping_cost', '0.00')) ?>"></div>
      </div>
      <div class="sf-supplier-switches" style="margin-top:1rem">
        <label class="sf-switch-row"><span>Save Cost Price To Product</span><input type="checkbox" name="save_cost_to_product" value="1" <?= !$editing || $on('save_cost_to_product') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Is Dropship Supplier</span><input type="checkbox" name="is_dropship_supplier" value="1" <?= $on('is_dropship_supplier') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Include Supplier in Requisitions</span><input type="checkbox" name="include_in_requisitions" value="1" <?= !$editing || $on('include_in_requisitions') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Consolidate Dropship Supplier Emails</span><input type="checkbox" name="consolidate_dropship_emails" value="1" <?= $on('consolidate_dropship_emails') ? 'checked' : '' ?>></label>
      </div>
    </div>
  </section>

  <section class="sf-int-block" style="margin-top:1rem">
    <header>Requisitions</header>
    <div class="sf-int-block-body">
      <div class="sf-supplier-switches">
        <label class="sf-switch-row"><span>Include Unpaid Business Orders</span><input type="checkbox" name="req_include_unpaid_business" value="1" <?= $on('req_include_unpaid_business') ? 'checked' : '' ?>></label>
        <label class="sf-switch-row"><span>Exclude Products With Zero Supplier Stock</span><input type="checkbox" name="req_exclude_zero_supplier_stock" value="1" <?= $on('req_exclude_zero_supplier_stock') ? 'checked' : '' ?>></label>
      </div>
    </div>
  </section>
</form>

<?php if ($editing): ?>
  <?php
    $t = $activeTemplate ?? [];
    $tVal = static fn(string $key, string $default = ''): string => (string) ($t[$key] ?? $default);
    $tOn = static fn(string $key): bool => !empty($t[$key]);
  ?>
  <form method="post" action="<?= $e($url('admin/suppliers/template/save')) ?>" class="sf-int-block" style="margin-top:1rem" id="export-template-form">
    <header>Export Templates</header>
    <div class="sf-int-block-body">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="supplier_id" value="<?= $e($val('id')) ?>">
      <input type="hidden" name="template_id" value="<?= $e($tVal('id')) ?>">
      <div class="sf-supplier-grid">
        <div class="sf-field">
          <label>Transfer Method</label>
          <select name="transfer_method">
            <?php foreach (['manual' => 'Manual', 'email' => 'Email', 'ftp' => 'FTP', 'sftp' => 'SFTP'] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $tVal('transfer_method', 'manual') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field">
          <label>Export Method</label>
          <select name="export_method" id="export-method">
            <?php foreach (['csv' => 'CSV', 'xml' => 'XML', 'api' => 'API'] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $tVal('export_method', 'csv') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field">
          <label>Template Type</label>
          <select name="template_type">
            <?php foreach ([
              'drop_shipment_lines' => 'Drop Shipment Lines',
              'purchase_order_lines' => 'Purchase Order Lines',
              'products' => 'Products',
            ] as $k => $lab): ?>
              <option value="<?= $e($k) ?>" <?= $tVal('template_type', 'drop_shipment_lines') === $k ? 'selected' : '' ?>><?= $e($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-field"><label>Template Name</label><input name="template_name" value="<?= $e($tVal('name')) ?>" placeholder="Optional"></div>
      </div>

      <?php if (count($templates) > 1): ?>
        <p class="text-muted" style="margin:1rem 0 .35rem">Saved templates</p>
        <ul style="margin:0;padding-left:1.1rem">
          <?php foreach ($templates as $tpl): ?>
            <li>
              <?= $e((string) ($tpl['name'] ?: $tpl['template_type'])) ?>
              · <?= $e((string) $tpl['export_method']) ?>
              <?= !empty($tpl['is_default']) ? ' (default)' : '' ?>
              <?php if ((int) ($tpl['id'] ?? 0) !== (int) ($t['id'] ?? 0)): ?>
                — <a href="<?= $e($url('admin/suppliers/edit', ['id' => $val('id'), 'template_id' => $tpl['id']])) ?>">Edit</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </form>

  <section class="sf-int-block" style="margin-top:1rem" id="csv-export-config">
    <header>CSV Export Configuration</header>
    <div class="sf-int-block-body">
      <div class="sf-supplier-grid">
        <label class="sf-switch-row" style="align-items:center"><span>Default Export Method</span>
          <input form="export-template-form" type="checkbox" name="is_default" value="1" <?= empty($t) || $tOn('is_default') ? 'checked' : '' ?>>
        </label>
        <div class="sf-field"><label>Export Delimiter</label><input form="export-template-form" name="delimiter" value="<?= $e($tVal('delimiter', ',')) ?>" style="max-width:4rem"></div>
        <label class="sf-switch-row" style="align-items:center"><span>Export Headers</span>
          <input form="export-template-form" type="checkbox" name="export_headers" value="1" <?= empty($t) || $tOn('export_headers') ? 'checked' : '' ?>>
        </label>
      </div>
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;justify-content:flex-end">
        <?php if (!empty($t['id'])): ?>
          <button class="btn" form="export-template-delete" type="submit" style="background:#c62828;color:#fff;border-color:#c62828" onclick="return confirm('Delete entire template?');">Delete Entire Template</button>
        <?php endif; ?>
        <button class="btn btn-outline-secondary" form="export-template-form" type="submit" name="as_new" value="1" onclick="document.querySelector('#export-template-form [name=template_id]').value='';">Add New</button>
        <button class="btn btn-primary" form="export-template-form" type="submit">Save Template</button>
      </div>
    </div>
  </section>

  <?php if (!empty($t['id'])): ?>
  <form method="post" action="<?= $e($url('admin/suppliers/template/delete')) ?>" id="export-template-delete" style="display:none">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="supplier_id" value="<?= $e($val('id')) ?>">
    <input type="hidden" name="template_id" value="<?= $e($tVal('id')) ?>">
  </form>
  <?php endif; ?>

  <script>
  (function () {
    var method = document.getElementById('export-method');
    var csv = document.getElementById('csv-export-config');
    if (!method || !csv) return;
    function sync() {
      csv.style.display = method.value === 'csv' ? '' : 'none';
    }
    method.addEventListener('change', sync);
    sync();
  })();
  </script>
<?php else: ?>
  <div class="sf-card" style="margin-top:1rem">
    <p class="text-muted" style="margin:0">Save the supplier first to configure export templates.</p>
  </div>
<?php endif; ?>

<style>
.sf-supplier-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .85rem 1.25rem;
}
.sf-supplier-switches {
  display: grid;
  gap: .35rem;
}
@media (max-width: 800px) {
  .sf-supplier-grid { grid-template-columns: 1fr; }
}
</style>
