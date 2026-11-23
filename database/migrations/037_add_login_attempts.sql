-- Failed logins, counted by the login attempt limit
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_name VARCHAR(255) NOT NULL COMMENT 'Username or email as typed. Same collation as users, so every spelling that reaches one account is one name here too',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IPv6 as its /64 network',
    attempted_at INT UNSIGNED NOT NULL COMMENT 'Unix time',
    INDEX idx_login_attempts_name (login_name, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('login_limit_per_address', '5', 'string', 'Failed logins for one name from one address before it is refused (0 = off)'),
('login_limit_per_account', '20', 'string', 'Failed logins for one name from all addresses before it is refused (0 = off)'),
('login_limit_minutes', '15', 'string', 'Minutes a failed login counts')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('037_add_login_attempts.sql')
ON DUPLICATE KEY UPDATE migration=migration;
