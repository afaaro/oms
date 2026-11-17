-- Full supplier profile (address, PO / dropship settings, alert toggles)

ALTER TABLE `store_suppliers` ADD COLUMN `address_line1` varchar(255) NOT NULL DEFAULT '' AFTER `phone`;
ALTER TABLE `store_suppliers` ADD COLUMN `address_line2` varchar(255) NOT NULL DEFAULT '' AFTER `address_line1`;
ALTER TABLE `store_suppliers` ADD COLUMN `city` varchar(128) NOT NULL DEFAULT '' AFTER `address_line2`;
ALTER TABLE `store_suppliers` ADD COLUMN `county` varchar(128) NOT NULL DEFAULT '' AFTER `city`;
ALTER TABLE `store_suppliers` ADD COLUMN `country` varchar(64) NOT NULL DEFAULT 'GB' AFTER `county`;
ALTER TABLE `store_suppliers` ADD COLUMN `postcode` varchar(32) NOT NULL DEFAULT '' AFTER `country`;
ALTER TABLE `store_suppliers` ADD COLUMN `supplier_reference` varchar(64) NOT NULL DEFAULT '' AFTER `code`;
ALTER TABLE `store_suppliers` ADD COLUMN `customs_mid` varchar(64) NOT NULL DEFAULT '' AFTER `supplier_reference`;
ALTER TABLE `store_suppliers` ADD COLUMN `notify_emails` varchar(500) NOT NULL DEFAULT '' AFTER `email`;
ALTER TABLE `store_suppliers` ADD COLUMN `contact_email` varchar(255) NOT NULL DEFAULT '' AFTER `notify_emails`;
ALTER TABLE `store_suppliers` ADD COLUMN `min_order_value` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `currency`;
ALTER TABLE `store_suppliers` ADD COLUMN `lead_time_days` int unsigned NOT NULL DEFAULT 0 AFTER `min_order_value`;
ALTER TABLE `store_suppliers` ADD COLUMN `po_mode` varchar(32) NOT NULL DEFAULT 'on_demand' AFTER `lead_time_days`;
ALTER TABLE `store_suppliers` ADD COLUMN `email_below_reorder` tinyint(1) NOT NULL DEFAULT 0 AFTER `po_mode`;
ALTER TABLE `store_suppliers` ADD COLUMN `email_below_oos` tinyint(1) NOT NULL DEFAULT 0 AFTER `email_below_reorder`;
ALTER TABLE `store_suppliers` ADD COLUMN `include_equal_reorder` tinyint(1) NOT NULL DEFAULT 0 AFTER `email_below_oos`;
ALTER TABLE `store_suppliers` ADD COLUMN `exclude_oos_zero_reorder` tinyint(1) NOT NULL DEFAULT 0 AFTER `include_equal_reorder`;
ALTER TABLE `store_suppliers` ADD COLUMN `accepts_backorders` tinyint(1) NOT NULL DEFAULT 0 AFTER `exclude_oos_zero_reorder`;
ALTER TABLE `store_suppliers` ADD COLUMN `is_manufacturer` tinyint(1) NOT NULL DEFAULT 0 AFTER `accepts_backorders`;
ALTER TABLE `store_suppliers` ADD COLUMN `po_shipping_cost_type` varchar(32) NOT NULL DEFAULT 'per_order' AFTER `is_manufacturer`;
ALTER TABLE `store_suppliers` ADD COLUMN `dropship_shipping_cost_type` varchar(32) NOT NULL DEFAULT 'per_item' AFTER `po_shipping_cost_type`;
ALTER TABLE `store_suppliers` ADD COLUMN `po_change_status` varchar(64) NOT NULL DEFAULT '' AFTER `dropship_shipping_cost_type`;
ALTER TABLE `store_suppliers` ADD COLUMN `dropship_change_status` varchar(64) NOT NULL DEFAULT '' AFTER `po_change_status`;
ALTER TABLE `store_suppliers` ADD COLUMN `po_shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `dropship_change_status`;
ALTER TABLE `store_suppliers` ADD COLUMN `dropship_item_shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `po_shipping_cost`;
