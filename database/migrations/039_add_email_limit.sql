-- Password reset and verification emails per address, counted by the account email limit
CREATE TABLE IF NOT EXISTS email_counts (
    email VARCHAR(255) NOT NULL COMMENT 'Address being mailed. Same collation as users.email, so every spelling of one address is one row',
    kind VARCHAR(20) NOT NULL COMMENT 'reset or verify: each has its own count',
    window_start INT UNSIGNED NOT NULL COMMENT 'Unix time of the first email counted',
    sent INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Emails sent since then',
    PRIMARY KEY (email, kind),
    INDEX idx_email_counts_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('email_limit_per_recipient', '3', 'string', 'Password reset emails, and verification emails, to one address within the time window (0 = off)'),
('email_limit_minutes', '60', 'string', 'Minutes the time window lasts, counted from the first email')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('039_add_email_limit.sql')
ON DUPLICATE KEY UPDATE migration=migration;
