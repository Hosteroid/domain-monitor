-- Domain renewal alerts: add "renewed" to the status change notifications that are turned on
UPDATE settings
SET setting_value = CONCAT_WS(',', NULLIF(setting_value, ''), 'renewed')
WHERE setting_key = 'notification_status_triggers'
  AND FIND_IN_SET('renewed', setting_value) = 0;

INSERT INTO migrations (migration) VALUES ('032_add_renewal_notifications.sql')
ON DUPLICATE KEY UPDATE migration=migration;
