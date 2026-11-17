-- Named pickwaves (warehouse pick batches)

CREATE TABLE IF NOT EXISTS `store_pickwaves` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `reference` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `order_count` int unsigned NOT NULL DEFAULT 0,
  `created_by` int unsigned DEFAULT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_pickwaves_tenant_ref` (`tenant_id`, `reference`),
  KEY `store_pickwaves_tenant` (`tenant_id`),
  KEY `store_pickwaves_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_pickwave_orders` (
  `pickwave_id` int unsigned NOT NULL,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pickwave_id`, `order_id`),
  KEY `store_pickwave_orders_order` (`order_id`),
  KEY `store_pickwave_orders_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
