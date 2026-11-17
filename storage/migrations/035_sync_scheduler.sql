CREATE TABLE IF NOT EXISTS `store_sync_settings` (
  `tenant_id` int unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `interval_minutes` smallint unsigned NOT NULL DEFAULT 10,
  `order_days` smallint unsigned NOT NULL DEFAULT 2,
  `sync_orders` tinyint(1) NOT NULL DEFAULT 1,
  `sync_mail` tinyint(1) NOT NULL DEFAULT 1,
  `sync_conversations` tinyint(1) NOT NULL DEFAULT 1,
  `last_started_at` datetime DEFAULT NULL,
  `last_finished_at` datetime DEFAULT NULL,
  `last_status` varchar(16) NOT NULL DEFAULT '',
  `last_summary` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_sync_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `trigger` varchar(16) NOT NULL DEFAULT 'cron',
  `status` varchar(16) NOT NULL DEFAULT '',
  `summary` varchar(500) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sync_runs_tenant` (`tenant_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_sync_cron` (
  `id` tinyint unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
