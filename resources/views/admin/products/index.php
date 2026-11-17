<?php
/** @var callable $e */
/** @var callable $url */
?>
<div class="sf-page-head">
  <h1>Product Manager</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/products/new')) ?>">New Product</a>
</div>

<div class="card sf-panel sf-datatable-wrap">
  <table id="products-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/products')) ?>"
         data-sf-keys='["type","image","sku","name","ean","supplier_sku","inventory","allocated","available","supplier","brand","price","warehouse","active"]'
         data-page-length="25"
         data-order='[[2,"asc"]]'
         data-variants-ajax="<?= $e($url('api/datatable/products/variants')) ?>">
    <thead>
      <tr>
        <th>Type</th>
        <th>Image</th>
        <th>SKU</th>
        <th>Name</th>
        <th>EAN</th>
        <th>Supplier SKU</th>
        <th>On hand</th>
        <th>Allocated</th>
        <th>Available</th>
        <th>Supplier</th>
        <th>Brand</th>
        <th>Price</th>
        <th>Warehouse</th>
        <th>Active</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>
