ALTER TABLE `store_order_items`
  ADD COLUMN `qty_shipped` int unsigned NOT NULL DEFAULT 0 AFTER `qty`;

ALTER TABLE `store_conversations`
  ADD COLUMN `assigned_user_id` int unsigned DEFAULT NULL AFTER `unread_count`;

ALTER TABLE `store_conversations`
  ADD COLUMN `due_at` datetime DEFAULT NULL AFTER `assigned_user_id`;

ALTER TABLE `store_conversations`
  ADD COLUMN `priority` tinyint unsigned NOT NULL DEFAULT 0 AFTER `due_at`;
