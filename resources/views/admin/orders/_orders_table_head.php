<?php
/** @var bool $selectable */
$selectable = !empty($selectable);
?>
        <?php if ($selectable): ?>
          <th class="sf-col-check"><input type="checkbox" id="sf-select-page" title="Select page" aria-label="Select all on page"></th>
        <?php endif; ?>
        <th>Order Flags</th>
        <th>Order ID</th>
        <th>Channel</th>
        <th>Product SKUs</th>
        <th>Order Date</th>
        <th>Postcode</th>
        <th>Email Address</th>
        <th>Total</th>
        <th>Packaging</th>
        <th>Number of Packages</th>
        <th>Shipping Method</th>
        <th>Order Status</th>
        <th>Order Weight</th>
        <th>Pickwave ID</th>
        <th>Notes</th>
