-- Ensure allocated stock column exists (Product Manager + InventoryService).
-- Safe to skip if column already present from 006_oms_complete.sql.
ALTER TABLE `store_inventory`
  ADD COLUMN `qty_allocated` int NOT NULL DEFAULT 0 AFTER `qty_on_hand`;
