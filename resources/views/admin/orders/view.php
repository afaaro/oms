<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $order */
/** @var list<array<string,mixed>> $items */
/** @var array<string,mixed> $address */
/** @var array<string,mixed> $billing */
/** @var array<string,mixed>|null $shipment */
/** @var array<string,mixed>|null $pickwave */
/** @var list<array<string,mixed>> $packagingSizes */
/** @var string|null $flash */
/** @var string|null $error */

$status = (string) ($order['status'] ?? '');
$address = $address ?? [];
$billing = $billing ?? [];
$shipment = $shipment ?? null;
$pickwave = $pickwave ?? null;
$packagingSizes = $packagingSizes ?? [];
$currency = strtoupper((string) ($order['currency'] ?? 'GBP'));
$symbol = $currency === 'GBP' ? '£' : ($currency === 'EUR' ? '€' : $currency . ' ');
$externalId = (string) ($order['external_order_id'] ?? '');
$buyerName = trim((string) ($order['shipping_name'] ?? ''));
$buyerUser = trim((string) ($order['buyer_username'] ?? ''));
$buyerEmail = trim((string) ($order['buyer_email'] ?? ''));
$channelName = trim((string) ($order['channel_name'] ?? ''));
$channelCode = strtolower((string) ($order['channel_code'] ?? ''));
$channelType = strtolower((string) ($order['channel_type'] ?? ''));

$statusOptions = [
    'awaiting_payment'  => 'Awaiting Payment',
    'awaiting_despatch' => 'Complete (Ready To Pick)',
    'printed'           => 'Complete (Ready To Pick)',
    'processing'        => 'In Progress',
    'despatched'        => 'Despatched',
    'on_hold'           => 'On Hold',
    'cancelled'         => 'Cancelled',
    'refunded'          => 'Refunded',
];

$statusLabel = static function (string $s) use ($statusOptions): string {
    return $statusOptions[$s] ?? ($s === '' ? '—' : ucwords(str_replace('_', ' ', $s)));
};

$fmtDt = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return 'Not Yet Set';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y @ H:i', $ts) : $value;
};

$money = static function (float $n) use ($symbol, $e): string {
    return $e($symbol . number_format($n, 2));
};

$scalarPhone = static function (mixed $raw): string {
    if (is_array($raw)) {
        foreach (['number', 'phoneNumber', 'PhoneNumber', 'phone', 'value'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $nested = is_array($raw[$key])
                ? trim((string) ($raw[$key]['number'] ?? $raw[$key]['value'] ?? ''))
                : trim((string) $raw[$key]);
            if ($nested !== '' && strcasecmp($nested, 'Array') !== 0) {
                return $nested;
            }
        }
        return '';
    }
    $value = trim((string) ($raw ?? ''));
    return ($value === '' || strcasecmp($value, 'Array') === 0) ? '' : $value;
};

$addrVal = static function (array $addr, string $key, string $fallback = '') use ($scalarPhone): string {
    $aliases = [
        'name'     => ['name', 'fullName'],
        'line1'    => ['line1', 'addressLine1'],
        'line2'    => ['line2', 'addressLine2'],
        'city'     => ['city'],
        'county'   => ['county', 'stateOrProvince'],
        'postcode' => ['postcode', 'postalCode'],
        'country'  => ['country', 'countryCode'],
        'phone'    => ['phone'],
    ];
    foreach ($aliases[$key] ?? [$key] as $alias) {
        $raw = $addr[$alias] ?? null;
        if ($key === 'phone') {
            $value = $scalarPhone($raw);
        } else {
            if (is_array($raw)) {
                $raw = $raw['number'] ?? $raw['value'] ?? '';
            }
            $value = trim((string) $raw);
            if (strcasecmp($value, 'Array') === 0) {
                $value = '';
            }
        }
        if ($value !== '') {
            return $value;
        }
    }
    $fallback = trim($fallback);
    return ($fallback === '' || strcasecmp($fallback, 'Array') === 0) ? '' : $fallback;
};

$shipName = $addrVal($address, 'name', $buyerName);
$shipPhone = $addrVal($address, 'phone', $scalarPhone($order['shipping_phone'] ?? null));
$billName = $addrVal($billing, 'name', $shipName);
$billPhone = $addrVal($billing, 'phone', $shipPhone);

$itemsSubtotalGross = 0.0;
foreach ($items as $it) {
    $itemsSubtotalGross += (float) ($it['line_total'] ?? 0);
}
$shippingCost = (float) ($order['shipping_cost'] ?? 0);
$shippingTax = (float) ($order['shipping_tax'] ?? 0);
$orderTotal = (float) ($order['total'] ?? 0);
// Assume UK VAT-inclusive prices for display split.
$vatRate = 20.0;
$itemsNet = $itemsSubtotalGross > 0 ? round($itemsSubtotalGross / (1 + $vatRate / 100), 2) : 0.0;
$itemsTax = round($itemsSubtotalGross - $itemsNet, 2);

$dot = 'channel-dot';
$letter = '•';
if (str_contains($channelCode, 'amazon') || str_contains($channelType, 'amazon')) {
    $letter = 'a';
} elseif (str_contains($channelCode, 'ebay') || str_contains($channelType, 'ebay')) {
    $dot .= ' ebay';
    $letter = 'e';
} elseif (str_contains($channelCode, 'shop') || str_contains($channelType, 'shop')) {
    $dot .= ' shopify';
    $letter = 'S';
}

$handlingDays = 1;
$settingsRaw = $order['channel_settings'] ?? null;
if (is_string($settingsRaw) && $settingsRaw !== '') {
    $settings = json_decode($settingsRaw, true);
    if (is_array($settings) && isset($settings['handling_days']) && ctype_digit((string) $settings['handling_days'])) {
        $handlingDays = max(0, (int) $settings['handling_days']);
    }
}
$createdAt = (string) ($order['created_at'] ?? '');
$createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
$shipBy = ($createdTs !== false)
    ? date('d/m/Y @ 23:59', strtotime('+' . $handlingDays . ' days', $createdTs))
    : 'Not Yet Set';

$pickwaveId = (int) ($pickwave['pickwave_id'] ?? 0);
$despatchedOn = is_array($shipment) ? (string) ($shipment['despatched_at'] ?? '') : (string) ($order['shipped_at'] ?? '');
$labelledOn = is_array($shipment) ? (string) ($shipment['labelled_at'] ?? '') : '';
$fulfillmentStatus = is_array($shipment) ? (string) ($shipment['fulfillment_status'] ?? '') : '';
$manifestId = is_array($shipment) ? (int) ($shipment['manifest_id'] ?? 0) : 0;
$allocated = (int) ($order['pre_despatched'] ?? 0) > 0
    || in_array($status, ['awaiting_despatch', 'printed', 'processing', 'despatched'], true);

$processRows = [
    'Order Date' => $fmtDt($createdAt),
    'Order Latest Ship By Date' => $shipBy,
    'Despatched on' => $despatchedOn !== '' ? $fmtDt($despatchedOn) : 'Not Yet Set',
    'Updated as shipped on Channel' => $fulfillmentStatus === 'uploaded' ? 'Validated' : 'Not Yet Validated',
    'Manifested on' => $manifestId > 0 ? ('Manifest #' . $manifestId) : 'Not Yet Set',
    'Labelled on' => $labelledOn !== '' ? $fmtDt($labelledOn) : 'Not Yet Set',
    'Pickwave ID' => $pickwaveId > 0
        ? '<a href="' . $e($url('admin/warehouse/pickwaves/view', ['id' => $pickwaveId])) . '">' . $e((string) $pickwaveId) . '</a>'
        : '0',
];
?>
<form method="post" action="<?= $e($url('admin/orders/status')) ?>" class="sf-oe">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $order['id']) ?>">

  <div class="sf-page-head sf-oe-head">
    <h1>Edit Order</h1>
    <div class="spacer"></div>
    <div class="sf-oe-actions">
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/orders/moto')) ?>">Clone Order</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/documents/invoice', ['order_id' => $order['id'], 'print' => '1'])) ?>" target="_blank" rel="noopener">Reprint Invoice</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/documents/label', ['order_id' => $order['id'], 'print' => '1'])) ?>" target="_blank" rel="noopener">Reprint Label</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/documents/packing', ['order_id' => $order['id'], 'print' => '1'])) ?>" target="_blank" rel="noopener">Packing Slip</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/returns/new', ['order_id' => $order['id']])) ?>">Return items</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/orders')) ?>">&lt;&lt; Orders</a>
      <button class="btn btn-primary" type="submit">Save</button>
    </div>
  </div>

  <?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

  <div class="sf-oe-grid">
    <section class="sf-oe-card">
      <h2>Order Information</h2>
      <dl class="sf-oe-dl">
        <div>
          <dt>Order Number</dt>
          <dd><strong><?= $e((string) $order['id']) ?></strong><?php if ($externalId !== ''): ?> <span class="text-muted">/ <?= $e($externalId) ?></span><?php endif; ?></dd>
        </div>
        <div>
          <dt>Channel</dt>
          <dd>
            <span class="channel-pill">
              <span class="<?= $e($dot) ?>"><?= $e($letter) ?></span>
              <?= $e($channelName !== '' ? $channelName : '—') ?>
            </span>
          </dd>
        </div>
        <div>
          <dt>Order Status</dt>
          <dd>
            <select name="status" class="sf-oe-select">
              <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= $e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </dd>
        </div>
        <div>
          <dt>Flags</dt>
          <dd class="sf-oe-flags">
            <label><input type="checkbox" name="is_priority" value="1" <?= !empty($order['is_priority']) ? 'checked' : '' ?>> Priority</label>
            <label><input type="checkbox" name="attention_required" value="1" <?= !empty($order['attention_required']) ? 'checked' : '' ?>> Attention Required</label>
            <label><input type="checkbox" name="on_hold" value="1" <?= !empty($order['on_hold']) || $status === 'on_hold' ? 'checked' : '' ?>> On Hold</label>
          </dd>
        </div>
      </dl>
    </section>

    <section class="sf-oe-card">
      <h2>Order Process Details</h2>
      <dl class="sf-oe-dl sf-oe-dl-process">
        <?php foreach ($processRows as $label => $value): ?>
          <div>
            <dt><?= $e($label) ?></dt>
            <dd><?= $value ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </section>

    <section class="sf-oe-card">
      <h2>Customer</h2>
      <div class="sf-oe-fields sf-oe-customer-fields">
        <label>
          <span>Email</span>
          <input name="buyer_email" type="email" value="<?= $e($buyerEmail) ?>">
        </label>
        <?php if ($buyerUser !== ''): ?>
          <p class="text-muted mb-0">Username: <strong><?= $e($buyerUser) ?></strong></p>
        <?php endif; ?>
      </div>
      <div class="sf-oe-addr-grid">
        <div>
          <h3>Billing Address</h3>
          <div class="sf-oe-fields">
            <label><span>Name</span><input name="bill_name" value="<?= $e($billName) ?>"></label>
            <label><span>Phone</span><input name="bill_phone" value="<?= $e($billPhone) ?>"></label>
            <label><span>Address line 1</span><input name="bill_line1" value="<?= $e($addrVal($billing, 'line1')) ?>"></label>
            <label><span>Address line 2</span><input name="bill_line2" value="<?= $e($addrVal($billing, 'line2')) ?>"></label>
            <label><span>City</span><input name="bill_city" value="<?= $e($addrVal($billing, 'city')) ?>"></label>
            <label><span>County</span><input name="bill_county" value="<?= $e($addrVal($billing, 'county')) ?>"></label>
            <div class="sf-oe-addr-row">
              <label><span>Postcode</span><input name="bill_postcode" value="<?= $e($addrVal($billing, 'postcode')) ?>"></label>
              <label><span>Country</span><input name="bill_country" value="<?= $e($addrVal($billing, 'country', 'GB')) ?>"></label>
            </div>
          </div>
        </div>
        <div>
          <h3>Shipping Address</h3>
          <div class="sf-oe-fields">
            <label><span>Name</span><input name="ship_name" value="<?= $e($shipName) ?>"></label>
            <label><span>Phone</span><input name="ship_phone" value="<?= $e($shipPhone) ?>"></label>
            <label><span>Address line 1</span><input name="ship_line1" value="<?= $e($addrVal($address, 'line1')) ?>"></label>
            <label><span>Address line 2</span><input name="ship_line2" value="<?= $e($addrVal($address, 'line2')) ?>"></label>
            <label><span>City</span><input name="ship_city" value="<?= $e($addrVal($address, 'city')) ?>"></label>
            <label><span>County</span><input name="ship_county" value="<?= $e($addrVal($address, 'county')) ?>"></label>
            <div class="sf-oe-addr-row">
              <label><span>Postcode</span><input name="ship_postcode" value="<?= $e($addrVal($address, 'postcode')) ?>"></label>
              <label><span>Country</span><input name="ship_country" value="<?= $e($addrVal($address, 'country', 'GB')) ?>"></label>
            </div>
            <label class="sf-oe-check">
              <input type="checkbox" name="bill_same_as_ship" value="1" id="sf-bill-same">
              Copy shipping to billing on save
            </label>
          </div>
        </div>
      </div>
    </section>

    <section class="sf-oe-card">
      <h2>Shipping &amp; Handling</h2>
      <div class="sf-oe-fields">
        <label>
          <span>Shipping Method</span>
          <input name="shipping_method" value="<?= $e((string) (($order['mapped_shipping_method'] ?? '') !== '' ? $order['mapped_shipping_method'] : ($order['shipping_method'] ?? ''))) ?>">
        </label>
        <label>
          <span>Tracking Number</span>
          <input name="tracking_number" value="<?= $e((string) ($order['tracking_number'] ?? '')) ?>">
        </label>
        <label>
          <span>Carrier</span>
          <input name="carrier_code" value="<?= $e((string) ($order['carrier_code'] ?? '')) ?>" placeholder="e.g. royal_mail">
        </label>
        <label>
          <span>Order Weight (kg)</span>
          <input name="order_weight_kg" type="number" step="0.001" min="0" value="<?= $e(number_format((float) ($order['order_weight_kg'] ?? 0), 3, '.', '')) ?>">
        </label>
        <label>
          <span>Package Size</span>
          <select name="packaging_size_id" class="sf-oe-select">
            <option value="0">—</option>
            <?php foreach ($packagingSizes as $ps): ?>
              <option value="<?= $e((string) (int) $ps['id']) ?>" <?= (int) ($order['packaging_size_id'] ?? 0) === (int) $ps['id'] ? 'selected' : '' ?>>
                <?= $e((string) $ps['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Number of Packages</span>
          <input name="package_count" type="number" min="1" value="<?= $e((string) max(1, (int) ($order['package_count'] ?? 1))) ?>">
        </label>
      </div>
      <?php if (is_array($shipment) && $fulfillmentStatus !== '' && $fulfillmentStatus !== 'uploaded'): ?>
        <div class="sf-oe-fulfillment">
          <span class="badge badge-warn"><?= $e($fulfillmentStatus) ?></span>
          <?php if (!empty($shipment['fulfillment_error'])): ?>
            <span class="text-muted small"><?= $e((string) $shipment['fulfillment_error']) ?></span>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-secondary" type="submit" formaction="<?= $e($url('admin/orders/retry-fulfillment')) ?>" name="order_id" value="<?= $e((string) $order['id']) ?>">
            Retry channel upload
          </button>
          <input type="hidden" name="shipment_id" value="<?= $e((string) ((int) ($shipment['id'] ?? 0))) ?>">
        </div>
      <?php endif; ?>
    </section>
  </div>

  <section class="sf-oe-card sf-oe-items">
    <div class="sf-oe-items-head">
      <h2>Items Ordered</h2>
      <button class="btn btn-sm btn-success" type="button" id="sf-oe-add-item">+ Add line</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle sf-grid mb-0" id="sf-oe-items-table">
        <thead>
          <tr>
            <th>SKU</th>
            <th>Product Name</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Unit price</th>
            <th class="text-end">Line total</th>
            <th class="text-end">Allocated</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="sf-oe-items-body">
          <?php
            $rows = $items !== [] ? $items : [[
                'id' => 0,
                'sku' => '',
                'name' => '',
                'product_name' => '',
                'qty' => 1,
                'unit_price' => 0,
                'line_total' => 0,
                'product_id' => 0,
                'linked_product_id' => 0,
            ]];
            foreach ($rows as $it):
              $sku = trim((string) ($it['sku'] ?? ''));
              $name = trim((string) ($it['product_name'] ?? $it['name'] ?? ''));
              $qty = max(1, (int) ($it['qty'] ?? 1));
              $unitGross = (float) ($it['unit_price'] ?? 0);
              $lineTotal = (float) ($it['line_total'] ?? ($unitGross * $qty));
              if ($unitGross <= 0 && $qty > 0 && $lineTotal > 0) {
                  $unitGross = $lineTotal / $qty;
              }
              $productId = (int) ($it['linked_product_id'] ?? $it['product_id'] ?? 0);
              $qtyAlloc = $allocated ? $qty : (int) ($it['qty_shipped'] ?? 0);
          ?>
            <tr class="sf-oe-item-row">
              <td>
                <input type="hidden" name="item_id[]" value="<?= $e((string) ((int) ($it['id'] ?? 0))) ?>">
                <input type="hidden" name="item_product_id[]" value="<?= $e((string) $productId) ?>">
                <input name="item_sku[]" value="<?= $e($sku) ?>" placeholder="SKU">
              </td>
              <td><input name="item_name[]" value="<?= $e($name) ?>" placeholder="Product name"></td>
              <td class="text-end"><input class="sf-oe-qty text-end" name="item_qty[]" type="number" min="1" step="1" value="<?= $e((string) $qty) ?>"></td>
              <td class="text-end"><input class="sf-oe-unit text-end" name="item_unit_price[]" type="number" min="0" step="0.01" value="<?= $e(number_format($unitGross, 2, '.', '')) ?>"></td>
              <td class="text-end"><span class="sf-oe-line-total"><?= $e(number_format($lineTotal, 2)) ?></span></td>
              <td class="text-end"><?= $e((string) $qtyAlloc) ?></td>
              <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger sf-oe-remove-item" title="Remove line">Remove</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small mt-2 mb-0">Edit SKU, name, qty or unit price, then Save. Empty SKU + name rows are dropped. Order total is recalculated from line totals + shipping.</p>
  </section>

  <div class="sf-oe-bottom">
    <section class="sf-oe-card">
      <h2>Notes</h2>
      <textarea name="notes" rows="5" placeholder="Internal order notes"><?= $e((string) ($order['notes'] ?? '')) ?></textarea>
    </section>
    <section class="sf-oe-card sf-oe-totals">
      <h2>Order Totals</h2>
      <dl class="sf-oe-dl">
        <div><dt>Items total</dt><dd id="sf-oe-items-sum"><?= $money($itemsSubtotalGross) ?></dd></div>
        <div>
          <dt>Shipping Costs</dt>
          <dd>
            <input class="sf-oe-ship-cost text-end" name="shipping_cost" type="number" min="0" step="0.01"
                   value="<?= $e(number_format($shippingCost, 2, '.', '')) ?>">
          </dd>
        </div>
        <div><dt>Shipping Tax</dt><dd><?= $money($shippingTax) ?></dd></div>
        <div class="sf-oe-grand"><dt>Grand Total</dt><dd><strong id="sf-oe-grand-total"><?= $money($orderTotal > 0 ? $orderTotal : ($itemsSubtotalGross + $shippingCost)) ?></strong></dd></div>
      </dl>
    </section>
  </div>
</form>

<template id="sf-oe-item-template">
  <tr class="sf-oe-item-row">
    <td>
      <input type="hidden" name="item_id[]" value="0">
      <input type="hidden" name="item_product_id[]" value="0">
      <input name="item_sku[]" value="" placeholder="SKU">
    </td>
    <td><input name="item_name[]" value="" placeholder="Product name"></td>
    <td class="text-end"><input class="sf-oe-qty text-end" name="item_qty[]" type="number" min="1" step="1" value="1"></td>
    <td class="text-end"><input class="sf-oe-unit text-end" name="item_unit_price[]" type="number" min="0" step="0.01" value="0.00"></td>
    <td class="text-end"><span class="sf-oe-line-total">0.00</span></td>
    <td class="text-end">0</td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger sf-oe-remove-item" title="Remove line">Remove</button></td>
  </tr>
</template>
<script>
(function () {
  var body = document.getElementById('sf-oe-items-body');
  var tpl = document.getElementById('sf-oe-item-template');
  var addBtn = document.getElementById('sf-oe-add-item');
  var shipInput = document.querySelector('.sf-oe-ship-cost');
  var itemsSum = document.getElementById('sf-oe-items-sum');
  var grand = document.getElementById('sf-oe-grand-total');
  if (!body || !tpl) return;

  function money(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function recalcRow(row) {
    var qty = parseFloat((row.querySelector('.sf-oe-qty') || {}).value || '0') || 0;
    var unit = parseFloat((row.querySelector('.sf-oe-unit') || {}).value || '0') || 0;
    var total = qty * unit;
    var cell = row.querySelector('.sf-oe-line-total');
    if (cell) cell.textContent = money(total);
    return total;
  }

  function recalcAll() {
    var sum = 0;
    body.querySelectorAll('.sf-oe-item-row').forEach(function (row) {
      sum += recalcRow(row);
    });
    var ship = parseFloat((shipInput && shipInput.value) || '0') || 0;
    if (itemsSum) itemsSum.textContent = money(sum);
    if (grand) grand.textContent = money(sum + ship);
  }

  body.addEventListener('input', function (ev) {
    if (ev.target.matches('.sf-oe-qty, .sf-oe-unit')) recalcAll();
  });
  body.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.sf-oe-remove-item');
    if (!btn) return;
    var row = btn.closest('tr');
    if (!row) return;
    if (body.querySelectorAll('.sf-oe-item-row').length <= 1) {
      row.querySelectorAll('input').forEach(function (input) {
        if (input.type === 'hidden') {
          if (input.name === 'item_id[]' || input.name === 'item_product_id[]') input.value = '0';
          return;
        }
        if (input.name === 'item_qty[]') input.value = '1';
        else if (input.name === 'item_unit_price[]') input.value = '0.00';
        else input.value = '';
      });
    } else {
      row.remove();
    }
    recalcAll();
  });
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      body.appendChild(tpl.content.cloneNode(true));
      recalcAll();
    });
  }
  if (shipInput) shipInput.addEventListener('input', recalcAll);
  recalcAll();
})();
</script>

<?php if (!in_array($status, ['cancelled', 'refunded'], true)): ?>
<div class="sf-oe-card sf-oe-channel mt-3">
  <h2>Channel actions</h2>
  <div class="sf-od-channel-actions">
    <form method="post" action="<?= $e($url('admin/orders/cancel')) ?>" class="sf-od-action-panel" onsubmit="return confirm('Cancel this order on the channel and locally?');">
      <h3>Cancel</h3>
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="order_id" value="<?= $e((string) $order['id']) ?>">
      <div class="sf-field">
        <label>Reason</label>
        <input name="reason" value="seller_cancel">
      </div>
      <button class="btn btn-outline-danger" type="submit">Cancel on channel</button>
    </form>
    <form method="post" action="<?= $e($url('admin/orders/refund')) ?>" class="sf-od-action-panel" onsubmit="return confirm('Refund this order on the channel and mark refunded locally?');">
      <h3>Refund</h3>
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="order_id" value="<?= $e((string) $order['id']) ?>">
      <div class="sf-field">
        <label>Amount (blank = full)</label>
        <input name="amount" type="number" step="0.01" min="0" placeholder="<?= $e(number_format($orderTotal, 2)) ?>">
      </div>
      <div class="sf-field">
        <label>Comments</label>
        <input name="comments" value="" placeholder="Optional note to channel">
      </div>
      <button class="btn btn-outline-danger" type="submit">Refund on channel</button>
    </form>
  </div>
</div>
<?php endif; ?>
