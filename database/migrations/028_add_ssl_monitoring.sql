-- SSL Monitoring - Add ssl_certificates table for tracking monitored TLS endpoints
CREATE TABLE IF NOT EXISTS ssl_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 443,
    status ENUM('valid', 'expiring', 'expired', 'invalid') NOT NULL DEFAULT 'invalid',
    is_trusted TINYINT(1) NOT NULL DEFAULT 0,
    is_self_signed TINYINT(1) NOT NULL DEFAULT 0,
    valid_from DATETIME NULL,
    valid_to DATETIME NULL,
    days_remaining INT NULL,
    issuer_name VARCHAR(255) NULL,
    subject_name VARCHAR(255) NULL,
    serial_number VARCHAR(255) NULL,
    signature_algorithm VARCHAR(100) NULL,
    key_bits INT NULL,
    key_type VARCHAR(20) NULL,
    certificate_version VARCHAR(20) NULL,
    san_list JSON NULL,
    last_checked DATETIME NULL,
    last_error TEXT NULL,
    raw_data JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_domain_host_port (domain_id, hostname, port),
    INDEX idx_ssl_domain_id (domain_id),
    INDEX idx_ssl_status (status),
    INDEX idx_ssl_valid_to (valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SSL Monitoring - Add per-domain toggle, timestamps, and cron settings
ALTER TABLE domains
    ADD COLUMN ssl_last_checked TIMESTAMP NULL AFTER dns_last_checked,
    ADD COLUMN ssl_monitoring_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=SSL monitoring active, 0=disabled' AFTER dns_monitoring_enabled;

-- Preserve existing monitored SSL domains when upgrading
UPDATE domains d
SET d.ssl_monitoring_enabled = 1
WHERE EXISTS (
    SELECT 1
    FROM ssl_certificates s
    WHERE s.domain_id = d.id
);

-- Carry forward the latest stored SSL check time
UPDATE domains d
JOIN (
    SELECT domain_id, MAX(last_checked) AS max_checked
    FROM ssl_certificates
    GROUP BY domain_id
) s ON s.domain_id = d.id
SET d.ssl_last_checked = s.max_checked;

-- Add SSL monitoring cron settings
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('ssl_check_interval_hours', '12', 'string', 'SSL certificate check interval in hours'),
('last_ssl_check_run', NULL, 'datetime', 'Last time SSL cron job ran')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Update application version to 1.1.5
UPDATE settings
SET setting_value = '1.1.5'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('028_add_ssl_monitoring.sql')
ON DUPLICATE KEY UPDATE migration=migration;
