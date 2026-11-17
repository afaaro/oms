-- Supplier dropship / requisition flags + export templates

ALTER TABLE `store_suppliers` ADD COLUMN `save_cost_to_product` tinyint(1) NOT NULL DEFAULT 1 AFTER `dropship_item_shipping_cost`;
ALTER TABLE `store_suppliers` ADD COLUMN `is_dropship_supplier` tinyint(1) NOT NULL DEFAULT 0 AFTER `save_cost_to_product`;
ALTER TABLE `store_suppliers` ADD COLUMN `include_in_requisitions` tinyint(1) NOT NULL DEFAULT 1 AFTER `is_dropship_supplier`;
ALTER TABLE `store_suppliers` ADD COLUMN `consolidate_dropship_emails` tinyint(1) NOT NULL DEFAULT 0 AFTER `include_in_requisitions`;
ALTER TABLE `store_suppliers` ADD COLUMN `req_include_unpaid_business` tinyint(1) NOT NULL DEFAULT 0 AFTER `consolidate_dropship_emails`;
ALTER TABLE `store_suppliers` ADD COLUMN `req_exclude_zero_supplier_stock` tinyint(1) NOT NULL DEFAULT 0 AFTER `req_include_unpaid_business`;

CREATE TABLE IF NOT EXISTS `store_supplier_export_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `supplier_id` int unsigned NOT NULL,
  `name` varchar(128) NOT NULL DEFAULT '',
  `transfer_method` varchar(32) NOT NULL DEFAULT 'manual',
  `export_method` varchar(32) NOT NULL DEFAULT 'csv',
  `template_type` varchar(64) NOT NULL DEFAULT 'drop_shipment_lines',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `delimiter` varchar(8) NOT NULL DEFAULT ',',
  `export_headers` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sset_supplier` (`supplier_id`),
  KEY `sset_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
