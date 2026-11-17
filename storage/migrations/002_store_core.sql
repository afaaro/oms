-- Core store tables needed for Home / Orders / Products / Settings

CREATE TABLE IF NOT EXISTS `store_tenants` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_tenants_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `store_tenants` (`id`, `name`, `slug`)
VALUES (1, 'Default', 'default')
ON DUPLICATE KEY UPDATE name=VALUES(name);

CREATE TABLE IF NOT EXISTS `store_tenant_users` (
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `role` varchar(32) NOT NULL DEFAULT 'member',
  `page_perms` json DEFAULT NULL,
  `action_perms` json DEFAULT NULL,
  `warehouse_ids` json DEFAULT NULL,
  `channel_ids` json DEFAULT NULL,
  `flags` json DEFAULT NULL,
  PRIMARY KEY (`tenant_id`,`user_id`),
  KEY `stu_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_channels` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `code` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'marketplace',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `settings` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_channels_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `sku` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `brand` varchar(128) NOT NULL DEFAULT '',
  `image` varchar(255) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `barcode` varchar(64) NOT NULL DEFAULT '',
  `upc` varchar(64) NOT NULL DEFAULT '',
  `mpn` varchar(64) NOT NULL DEFAULT '',
  `supplier_sku` varchar(64) NOT NULL DEFAULT '',
  `supplier_name` varchar(128) NOT NULL DEFAULT '',
  `warehouse` varchar(128) NOT NULL DEFAULT 'Default',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rrp` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_class` varchar(16) NOT NULL DEFAULT '20',
  `price_includes_vat` tinyint(1) NOT NULL DEFAULT 0,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `weight_grams` int unsigned NOT NULL DEFAULT 0,
  `length_mm` int unsigned NOT NULL DEFAULT 0,
  `width_mm` int unsigned NOT NULL DEFAULT 0,
  `height_mm` int unsigned NOT NULL DEFAULT 0,
  `shipping_json` json NOT NULL DEFAULT (JSON_OBJECT()),
  `notes` text NOT NULL,
  `regulatory_json` json NOT NULL DEFAULT (JSON_OBJECT()),
  `ebay_aspects_json` json NOT NULL DEFAULT (JSON_OBJECT()),
  `ebay_category_id` varchar(32) NOT NULL DEFAULT '',
  `ebay_category_name` varchar(255) NOT NULL DEFAULT '',
  `ebay_condition` varchar(64) NOT NULL DEFAULT '',
  `kit_display_on_order` tinyint(1) NOT NULL DEFAULT 1,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_products_tenant_sku` (`tenant_id`,`sku`),
  KEY `store_products_tenant` (`tenant_id`),
  KEY `store_products_active` (`active`),
  KEY `store_products_brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `channel_id` int unsigned NOT NULL,
  `external_order_id` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'unknown',
  `buyer_username` varchar(128) NOT NULL DEFAULT '',
  `buyer_email` varchar(255) NOT NULL DEFAULT '',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `channel_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `shipping_tax` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) NOT NULL DEFAULT 'GBP',
  `shipping_name` varchar(255) NOT NULL DEFAULT '',
  `shipping_address` json NOT NULL DEFAULT (JSON_OBJECT()),
  `tracking_number` varchar(128) NOT NULL DEFAULT '',
  `carrier_code` varchar(64) NOT NULL DEFAULT '',
  `shipping_method` varchar(128) NOT NULL DEFAULT '',
  `warehouse` varchar(128) NOT NULL DEFAULT 'Default',
  `mapped_shipping_method` varchar(128) NOT NULL DEFAULT '',
  `preferred_courier` varchar(32) NOT NULL DEFAULT '',
  `special_instructions` varchar(500) NOT NULL DEFAULT '',
  `billing_address` json DEFAULT NULL,
  `packaging_size_id` int unsigned DEFAULT NULL,
  `package_count` int unsigned NOT NULL DEFAULT 1,
  `order_weight_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `on_hold` tinyint(1) NOT NULL DEFAULT 0,
  `is_priority` tinyint(1) NOT NULL DEFAULT 0,
  `attention_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `pre_despatched` tinyint(1) NOT NULL DEFAULT 0,
  `shipped_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_orders_tenant` (`tenant_id`),
  KEY `store_orders_channel` (`channel_id`),
  KEY `store_orders_status` (`status`),
  KEY `store_orders_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
