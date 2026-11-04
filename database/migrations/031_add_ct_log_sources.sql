-- DNS deep scan: certificate transparency sources and an optional Cert Spotter API key
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('ct_log_sources', 'crtsh,certspotter', 'string', 'Certificate transparency sources a DNS deep scan asks (comma-separated)'),
('certspotter_api_key', '', 'encrypted', 'Cert Spotter API key (encrypted, optional)')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Update application version to 1.1.6
UPDATE settings
SET setting_value = '1.1.6'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('031_add_ct_log_sources.sql')
ON DUPLICATE KEY UPDATE migration=migration;
