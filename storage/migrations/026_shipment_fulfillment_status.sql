ALTER TABLE `store_shipments`
  ADD COLUMN `fulfillment_status` varchar(32) NOT NULL DEFAULT '' AFTER `status`,
  ADD COLUMN `fulfillment_error` varchar(512) NOT NULL DEFAULT '' AFTER `fulfillment_status`;
