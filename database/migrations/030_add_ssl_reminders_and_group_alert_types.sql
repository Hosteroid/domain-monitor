-- SSL expiry reminders: configurable reminder days, and the last reminder sent per certificate
-- SSL monitoring default for new domains
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('ssl_notification_days', '14,7,3,1', 'string', 'Days before SSL certificate expiry to send reminders (comma-separated)'),
('ssl_monitoring_new_domains', '0', 'boolean', 'Turn on SSL monitoring for newly added domains')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

ALTER TABLE ssl_certificates
    ADD COLUMN last_reminder_days INT NULL COMMENT 'Reminder day last sent for this certificate' AFTER last_error,
    ADD COLUMN last_reminder_valid_to DATETIME NULL COMMENT 'Expiry of the certificate the last reminder was sent for' AFTER last_reminder_days;

-- Notification groups - choose which alert types the group sends
ALTER TABLE notification_groups
    ADD COLUMN notify_expiration TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=send domain expiration alerts' AFTER description,
    ADD COLUMN notify_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=send domain status change alerts' AFTER notify_expiration,
    ADD COLUMN notify_dns TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=send DNS change alerts' AFTER notify_status,
    ADD COLUMN notify_ssl TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=send SSL certificate alerts' AFTER notify_dns;

INSERT INTO migrations (migration) VALUES ('030_add_ssl_reminders_and_group_alert_types.sql')
ON DUPLICATE KEY UPDATE migration=migration;
