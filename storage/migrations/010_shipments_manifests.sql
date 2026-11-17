-- Shipments (courier labels), manifests, and print audit fields

CREATE TABLE IF NOT EXISTS `store_manifests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `reference` varchar(64) NOT NULL DEFAULT '',
  `carrier_code` varchar(64) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `shipment_count` int unsigned NOT NULL DEFAULT 0,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `store_manifests_tenant` (`tenant_id`),
  KEY `store_manifests_carrier` (`carrier_code`),
  KEY `store_manifests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_shipments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `order_id` int unsigned NOT NULL,
  `manifest_id` int unsigned DEFAULT NULL,
  `carrier_code` varchar(64) NOT NULL DEFAULT '',
  `service_code` varchar(128) NOT NULL DEFAULT '',
  `tracking_number` varchar(128) NOT NULL DEFAULT '',
  `label_format` varchar(16) NOT NULL DEFAULT 'html',
  `label_path` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'created',
  `weight_kg` decimal(10,3) NOT NULL DEFAULT 0.000,
  `package_count` int unsigned NOT NULL DEFAULT 1,
  `external_consignment_id` varchar(128) NOT NULL DEFAULT '',
  `meta` json DEFAULT NULL,
  `labelled_at` datetime DEFAULT NULL,
  `despatched_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `store_shipments_tenant` (`tenant_id`),
  KEY `store_shipments_order` (`order_id`),
  KEY `store_shipments_manifest` (`manifest_id`),
  KEY `store_shipments_carrier` (`carrier_code`),
  KEY `store_shipments_tracking` (`tracking_number`),
  KEY `store_shipments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
