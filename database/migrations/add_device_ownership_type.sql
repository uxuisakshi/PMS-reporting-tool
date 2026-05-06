-- Migration: Add ownership and hardware fields to devices table
-- Date: 2026-04-17
-- Description: Adds ownership_type, lease_owner, storage_capacity, charger_wire columns.

ALTER TABLE `devices`
ADD COLUMN `ownership_type` ENUM('Owned', 'Leased') NOT NULL DEFAULT 'Owned' AFTER `status`,
ADD COLUMN `lease_owner` VARCHAR(255) DEFAULT NULL AFTER `ownership_type`,
ADD COLUMN `storage_capacity` INT(11) DEFAULT NULL AFTER `lease_owner`,
ADD COLUMN `charger_wire` VARCHAR(255) DEFAULT NULL AFTER `storage_capacity`;
