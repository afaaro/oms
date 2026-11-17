-- Purchase order line items + richer supplier fields + receive tracking

ALTER TABLE `store_suppliers` ADD COLUMN `contact_name` varchar(128) NOT NULL DEFAULT '' AFTER `name`;
ALTER TABLE `store_suppliers` ADD COLUMN `address` varchar(500) NOT NULL DEFAULT '' AFTER `phone`;
ALTER TABLE `store_suppliers` ADD COLUMN `notes` varchar(500) NOT NULL DEFAULT '' AFTER `address`;
ALTER TABLE `store_suppliers` ADD COLUMN `currency` varchar(8) NOT NULL DEFAULT 'GBP' AFTER `notes`;

ALTER TABLE `store_purchase_orders` ADD COLUMN `ordered_at` datetime DEFAULT NULL AFTER `expected_at`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `received_at` datetime DEFAULT NULL AFTER `ordered_at`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `currency` varchar(8) NOT NULL DEFAULT 'GBP' AFTER `total`;

CREATE TABLE IF NOT EXISTS `store_purchase_order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `purchase_order_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `sku` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(255) NOT NULL DEFAULT '',
  `qty_ordered` int unsigned NOT NULL DEFAULT 1,
  `qty_received` int unsigned NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `spoi_po` (`purchase_order_id`),
  KEY `spoi_tenant` (`tenant_id`),
  KEY `spoi_product` (`product_id`),
  KEY `spoi_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
