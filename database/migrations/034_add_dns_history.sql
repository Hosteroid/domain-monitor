-- DNS history: the records a domain had before each change a DNS check or scan found, kept after the domain is deleted (#75)
CREATE TABLE IF NOT EXISTS dns_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NULL COMMENT 'NULL once the domain is deleted',
    domain_name VARCHAR(255) NOT NULL,
    user_id INT NULL COMMENT 'Owner of the domain, used once the domain is deleted',
    source VARCHAR(20) NOT NULL COMMENT 'cron, refresh, quick_scan, deep_scan, deleted',
    added_count INT NOT NULL DEFAULT 0,
    removed_count INT NOT NULL DEFAULT 0,
    changed_count INT NOT NULL DEFAULT 0,
    record_count INT NOT NULL DEFAULT 0 COMMENT 'Records in the saved set',
    changes JSON NULL COMMENT 'Records added, removed and changed',
    records JSON NOT NULL COMMENT 'Every record from before the change, by type',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dns_history_domain (domain_id, created_at),
    INDEX idx_dns_history_created (created_at),
    INDEX idx_dns_history_name (domain_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Save DNS history per domain (the global switch is the dns_history_enabled setting)
ALTER TABLE domains ADD COLUMN dns_history_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=DNS history saved, 0=not saved' AFTER dns_monitoring_enabled;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('dns_history_enabled', '1', 'boolean', 'Save the DNS records from before each change a check or scan finds'),
('dns_history_retention_days', '90', 'string', 'Days DNS history is kept')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('034_add_dns_history.sql')
ON DUPLICATE KEY UPDATE migration=migration;
