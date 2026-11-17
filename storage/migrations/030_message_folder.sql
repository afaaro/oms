ALTER TABLE `store_buyer_messages`
  ADD COLUMN `folder` varchar(16) NOT NULL DEFAULT 'message' AFTER `provider`,
  ADD KEY `sbm_folder` (`tenant_id`, `folder`, `status`);

UPDATE `store_buyer_messages`
SET `folder` = 'mail'
WHERE LOWER(`buyer_name`) IN ('ebay', 'ebay buyer')
   OR `buyer_name` LIKE '%eBay Customer Service%'
   OR `subject` LIKE '%eBay Customer Service%'
   OR `body` LIKE '%eBay Customer Service%';
