-- Notification groups for domains you want to acquire (watchlist) and reminders after a domain expired (#95)
ALTER TABLE notification_groups
    ADD COLUMN purpose ENUM('own','watch') NOT NULL DEFAULT 'own' COMMENT 'own = domains I own, watch = domains I want to acquire' AFTER description;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('post_expiry_notification_days', '7,14,30', 'string', 'Days after a domain expired to send a reminder (comma-separated)')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('033_add_watchlist_groups.sql')
ON DUPLICATE KEY UPDATE migration=migration;
