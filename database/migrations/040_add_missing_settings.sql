-- Settings rows of migrations 018 to 035, for databases where those inserts were skipped
-- A database that began with v1.0.0 had no `type` and `description` columns in settings, so the inserts
-- failed there and the rows are missing wherever such an update was finished by hand. The updater adds the
-- two columns before it runs any migration. On every other database the rows exist and nothing changes.
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
-- 018
('user_isolation_mode', 'shared', 'string', 'User data visibility mode: shared (all users see all data) or isolated (users see only their own data)'),
-- 027
('dns_check_interval_hours', '24', 'string', 'DNS record check interval in hours'),
('last_dns_check_run', NULL, 'datetime', 'Last time DNS cron job ran'),
-- 028
('ssl_check_interval_hours', '12', 'string', 'SSL certificate check interval in hours'),
('last_ssl_check_run', NULL, 'datetime', 'Last time SSL cron job ran'),
-- 030
('ssl_notification_days', '14,7,3,1', 'string', 'Days before SSL certificate expiry to send reminders (comma-separated)'),
('ssl_monitoring_new_domains', '0', 'boolean', 'Turn on SSL monitoring for newly added domains'),
-- 031
('ct_log_sources', 'crtsh,certspotter', 'string', 'Certificate transparency sources a DNS deep scan asks (comma-separated)'),
('certspotter_api_key', '', 'encrypted', 'Cert Spotter API key (encrypted, optional)'),
-- 033
('post_expiry_notification_days', '7,14,30', 'string', 'Days after a domain expired to send a reminder (comma-separated)'),
-- 034
('dns_history_enabled', '1', 'boolean', 'Save the DNS records from before each change a check or scan finds'),
('dns_history_retention_days', '90', 'string', 'Days DNS history is kept'),
-- 035
('billing_enabled', '1', 'boolean', 'Billing: payments, price list and renewal forecasts'),
('billing_currency', 'USD', 'string', 'Base currency of all billing totals'),
('billing_auto_log_renewals', '1', 'boolean', 'Log a payment when a renewal is detected'),
('billing_cost_in_reminders', '1', 'boolean', 'Show the renewal cost in expiry reminders')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('040_add_missing_settings.sql')
ON DUPLICATE KEY UPDATE migration=migration;
