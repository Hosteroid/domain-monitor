-- API tokens: personal tokens for the JSON API under /api/v1 (#93, #102)
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'The token acts as this user',
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 of the token, the token itself is never stored',
    token_hint CHAR(4) NOT NULL COMMENT 'Last four characters, for the list',
    can_write TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = read only, 1 = read and write',
    expires_at INT UNSIGNED NULL COMMENT 'Unix time, NULL = no expiry',
    last_used_at INT UNSIGNED NULL COMMENT 'Unix time',
    last_used_ip VARCHAR(45) NULL,
    window_start INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Start of the rate limit minute, unix time',
    window_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Requests counted in that minute',
    created_at INT UNSIGNED NOT NULL COMMENT 'Unix time',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_api_token_hash (token_hash),
    INDEX idx_api_token_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('api_access', 'off', 'string', 'API under /api/v1: off, read or read_write'),
('api_users', 'all', 'string', 'Who may use the API: all or admins'),
('api_rate_limit', '120', 'string', 'API requests per minute for each token')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('036_add_api_tokens.sql')
ON DUPLICATE KEY UPDATE migration=migration;
