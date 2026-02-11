-- Migration: Add application update system settings
-- Version: 1.1.3
-- This migration adds settings for the GitHub-based update system:
-- update_channel (stable/latest), installed_commit_sha for hotfix tracking

-- 1. Add update channel setting (stable = releases only, latest = releases + hotfixes)
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('update_channel', 'stable', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 2. Add update badge in menu setting (1 = show when update available, 0 = hide)
INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
VALUES ('update_badge_enabled', '1', NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 3. Update application version to 1.1.3
UPDATE settings 
SET setting_value = '1.1.3' 
WHERE setting_key = 'app_version';
