-- Richer purchase order header + warehouse delivery address

ALTER TABLE `store_warehouses` ADD COLUMN `company` varchar(255) NOT NULL DEFAULT '' AFTER `name`;
ALTER TABLE `store_warehouses` ADD COLUMN `address_line1` varchar(255) NOT NULL DEFAULT '' AFTER `company`;
ALTER TABLE `store_warehouses` ADD COLUMN `address_line2` varchar(255) NOT NULL DEFAULT '' AFTER `address_line1`;
ALTER TABLE `store_warehouses` ADD COLUMN `city` varchar(128) NOT NULL DEFAULT '' AFTER `address_line2`;
ALTER TABLE `store_warehouses` ADD COLUMN `county` varchar(128) NOT NULL DEFAULT '' AFTER `city`;
ALTER TABLE `store_warehouses` ADD COLUMN `country` varchar(64) NOT NULL DEFAULT 'GB' AFTER `county`;
ALTER TABLE `store_warehouses` ADD COLUMN `postcode` varchar(32) NOT NULL DEFAULT '' AFTER `country`;

ALTER TABLE `store_purchase_orders` ADD COLUMN `warehouse_id` int unsigned DEFAULT NULL AFTER `supplier_id`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_cost` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `currency`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `include_delivery_cost` tinyint(1) NOT NULL DEFAULT 0 AFTER `delivery_cost`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `date_sent` date DEFAULT NULL AFTER `expected_at`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `paid_on` date DEFAULT NULL AFTER `date_sent`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `payment_reference` varchar(128) NOT NULL DEFAULT '' AFTER `paid_on`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `payment_confirmed_by` varchar(128) NOT NULL DEFAULT '' AFTER `payment_reference`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `change_to_status` varchar(64) NOT NULL DEFAULT '' AFTER `payment_confirmed_by`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `palletized` tinyint(1) NOT NULL DEFAULT 0 AFTER `change_to_status`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `estimated_pallets` int unsigned NOT NULL DEFAULT 0 AFTER `palletized`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_company` varchar(255) NOT NULL DEFAULT '' AFTER `estimated_pallets`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_line1` varchar(255) NOT NULL DEFAULT '' AFTER `delivery_company`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_line2` varchar(255) NOT NULL DEFAULT '' AFTER `delivery_line1`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_city` varchar(128) NOT NULL DEFAULT '' AFTER `delivery_line2`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_country` varchar(64) NOT NULL DEFAULT 'GB' AFTER `delivery_city`;
ALTER TABLE `store_purchase_orders` ADD COLUMN `delivery_postcode` varchar(32) NOT NULL DEFAULT '' AFTER `delivery_country`;
