-- Migration: Add client dashboard access
-- Description: Adds client_id to users table and creates client role for dashboard access
-- Date: 2026-03-03

-- Add client_id column to users table if it doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'client_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` INT(11) NULL DEFAULT NULL AFTER `role`')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add index on client_id if it doesn't exist
SET @indexname = 'idx_users_client_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD KEY `', @indexname, '` (`client_id`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add foreign key constraint if it doesn't exist
SET @fkname = 'fk_users_client_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (constraint_name = @fkname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD CONSTRAINT `', @fkname, '` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update role enum to include 'client' role (only if not already present)
-- Check current enum values
SET @current_enum = (
  SELECT COLUMN_TYPE 
  FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = @dbname 
    AND TABLE_NAME = @tablename 
    AND COLUMN_NAME = 'role'
);

-- Only modify if 'client' is not in the enum
SET @preparedStatement = (SELECT IF(
  LOCATE('client', @current_enum) > 0,
  'SELECT 1',
  'ALTER TABLE `users` MODIFY COLUMN `role` ENUM(''admin'', ''admin'', ''project_lead'', ''qa'', ''at_tester'', ''ft_tester'', ''client'') NOT NULL DEFAULT ''at_tester'''
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update notifications type enum to include 'client_update' (only if not already present)
SET @tablename = 'notifications';
SET @current_enum = (
  SELECT COLUMN_TYPE 
  FROM INFORMATION_SCHEMA.COLUMNS 
  WHERE TABLE_SCHEMA = @dbname 
    AND TABLE_NAME = @tablename 
    AND COLUMN_NAME = 'type'
);

-- Only modify if 'client_update' is not in the enum
SET @preparedStatement = (SELECT IF(
  LOCATE('client_update', @current_enum) > 0,
  'SELECT 1',
  'ALTER TABLE `notifications` MODIFY COLUMN `type` ENUM(''mention'', ''assignment'', ''system'', ''permission_update'', ''client_update'') NOT NULL'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
