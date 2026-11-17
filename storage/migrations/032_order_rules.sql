CREATE TABLE IF NOT EXISTS `store_order_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `name` varchar(128) NOT NULL DEFAULT '',
  `rule_type` varchar(32) NOT NULL DEFAULT 'unpaid',
  `action` varchar(32) NOT NULL DEFAULT 'hold',
  `threshold` decimal(12,2) NOT NULL DEFAULT 0.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `store_order_rules_tenant` (`tenant_id`),
  KEY `store_order_rules_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
