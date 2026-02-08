-- Migration: Add status-based notifications and new domain lifecycle statuses
-- Version: 1.1.2
-- This migration adds support for notifications based on domain status changes:
-- available, registered, expired, redemption_period, pending_delete

-- 1. Expand domain status ENUM to include redemption_period and pending_delete
ALTER TABLE domains MODIFY COLUMN status 
    ENUM('active', 'expiring_soon', 'expired', 'error', 'available', 'redemption_period', 'pending_delete') 
    DEFAULT 'active';

-- 2. Add setting for notification status triggers (which status changes trigger notifications)
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('notification_status_triggers', 'available,registered,expired,redemption_period,pending_delete', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 3. Update application version to 1.1.2
UPDATE settings 
SET setting_value = '1.1.2' 
WHERE setting_key = 'app_version';

