-- Update application version to 1.2.0
UPDATE settings
SET setting_value = '1.2.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('043_update_app_version_v1.2.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
