-- DNS Monitoring - Add dns_records table for tracking DNS record changes
CREATE TABLE IF NOT EXISTS dns_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    record_type VARCHAR(10) NOT NULL COMMENT 'A, AAAA, MX, TXT, NS, CNAME, SOA',
    host VARCHAR(255) NOT NULL DEFAULT '@',
    value TEXT NOT NULL,
    ttl INT NULL,
    priority INT NULL COMMENT 'MX priority',
    is_cloudflare BOOLEAN DEFAULT FALSE,
    raw_data JSON NULL COMMENT 'Full record data from dns_get_record()',
    first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_id (domain_id),
    INDEX idx_record_type (record_type),
    INDEX idx_domain_type (domain_id, record_type),
    INDEX idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track when DNS was last checked per domain
ALTER TABLE domains ADD COLUMN dns_last_checked TIMESTAMP NULL AFTER last_checked;

-- crt.sh subdomain fetch tracking
ALTER TABLE domains ADD COLUMN crtsh_last_fetched DATETIME NULL DEFAULT NULL COMMENT 'Last time crt.sh subdomains were fetched for this domain';

-- Toggle DNS monitoring per domain (WHOIS and DNS are separate)
ALTER TABLE domains ADD COLUMN dns_monitoring_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=DNS monitoring active, 0=disabled' AFTER is_active;

-- Add DNS check interval setting
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('dns_check_interval_hours', '24', 'string', 'DNS record check interval in hours'),
('last_dns_check_run', NULL, 'datetime', 'Last time DNS cron job ran')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('027_add_dns_monitoring.sql')
ON DUPLICATE KEY UPDATE migration=migration;
