CREATE TABLE IF NOT EXISTS `store_shipping_methods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `name` varchar(128) NOT NULL,
  `carrier` varchar(64) NOT NULL DEFAULT '',
  `service_code` varchar(64) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ssm_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_shipping_regions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `name` varchar(128) NOT NULL,
  `countries` varchar(255) NOT NULL DEFAULT 'GB',
  `postcode_prefixes` varchar(255) NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ssr_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_shipping_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `name` varchar(128) NOT NULL,
  `priority` int NOT NULL DEFAULT 100,
  `channel_id` int unsigned DEFAULT NULL,
  `region_id` int unsigned DEFAULT NULL,
  `method_id` int unsigned DEFAULT NULL,
  `match_shipping_method` varchar(128) NOT NULL DEFAULT '',
  `weight_min_g` int unsigned NOT NULL DEFAULT 0,
  `weight_max_g` int unsigned NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ssrule_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_listings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `channel_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `external_id` varchar(128) NOT NULL DEFAULT '',
  `sku` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `qty` int NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `controlled_by_sf` tinyint(1) NOT NULL DEFAULT 1,
  `mapped` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sl_tenant` (`tenant_id`),
  KEY `sl_channel` (`channel_id`),
  KEY `sl_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
