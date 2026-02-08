-- Domain Monitor v1.1.0 - Complete Initial Schema
-- This consolidated migration includes all features for fresh installations

-- =====================================================
-- USER MANAGEMENT & AUTHENTICATION (must be first)
-- =====================================================

-- Users table (must be created first - referenced by other tables)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    email_verified BOOLEAN DEFAULT FALSE,
    email_verification_token VARCHAR(255) NULL,
    email_verification_sent_at TIMESTAMP NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(32) NULL,
    two_factor_backup_codes TEXT NULL,
    two_factor_setup_at TIMESTAMP NULL,
    full_name VARCHAR(255),
    avatar VARCHAR(255) NULL,
    role VARCHAR(50) DEFAULT 'user',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default admin user (credentials will be set during installation)
INSERT INTO users (username, password, email, full_name, is_active, role, email_verified) VALUES
('{{ADMIN_USERNAME}}', '{{ADMIN_PASSWORD_HASH}}', '{{ADMIN_EMAIL}}', 'Administrator', 1, 'admin', 1)
ON DUPLICATE KEY UPDATE username=username;

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions table (database-backed sessions)
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id INT DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    country VARCHAR(100) DEFAULT NULL,
    country_code VARCHAR(2) DEFAULT NULL,
    region VARCHAR(100) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    isp VARCHAR(255) DEFAULT NULL,
    timezone VARCHAR(50) DEFAULT NULL,
    payload MEDIUMTEXT NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remember me tokens table
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(128) DEFAULT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-factor authentication verification attempts table
CREATE TABLE IF NOT EXISTS two_factor_verification_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    success BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_ip_address (ip_address),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Two-factor authentication email codes table
CREATE TABLE IF NOT EXISTS two_factor_email_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_code (code),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used (used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CORE TABLES
-- =====================================================

-- Notification groups table (must be created first - referenced by domains)
CREATE TABLE IF NOT EXISTS notification_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notification_groups_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domains table
CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_name VARCHAR(255) NOT NULL UNIQUE,
    notification_group_id INT NULL,
    registrar VARCHAR(255),
    registrar_url VARCHAR(255),
    expiration_date DATE,
    updated_date DATE,
    abuse_email VARCHAR(255),
    last_checked TIMESTAMP NULL,
    status ENUM('active', 'expiring_soon', 'expired', 'error', 'available', 'redemption_period', 'pending_delete') DEFAULT 'active',
    whois_data JSON,
    notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (notification_group_id) REFERENCES notification_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notification_group_id (notification_group_id),
    INDEX idx_domain_name (domain_name),
    INDEX idx_expiration_date (expiration_date),
    INDEX idx_status (status),
    INDEX idx_is_active (is_active),
    INDEX idx_domains_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User notifications table (in-app notifications)
CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    domain_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TAGS SYSTEM (normalized)
-- =====================================================

-- Tags table
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(50) DEFAULT 'bg-gray-100 text-gray-700 border-gray-300',
    description TEXT NULL,
    usage_count INT DEFAULT 0,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_usage_count (usage_count),
    INDEX idx_user_id (user_id),
    UNIQUE KEY unique_user_tag (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domain tags junction table
CREATE TABLE IF NOT EXISTS domain_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    UNIQUE KEY unique_domain_tag (domain_id, tag_id),
    INDEX idx_domain_id (domain_id),
    INDEX idx_tag_id (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default tags
INSERT INTO tags (name, color, description, user_id) VALUES
('production', 'bg-green-100 text-green-700 border-green-300', 'Production environment domains', NULL),
('staging', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'Staging environment domains', NULL),
('development', 'bg-blue-100 text-blue-700 border-blue-300', 'Development environment domains', NULL),
('client', 'bg-purple-100 text-purple-700 border-purple-300', 'Client-related domains', NULL),
('personal', 'bg-orange-100 text-orange-700 border-orange-300', 'Personal domains', NULL),
('archived', 'bg-gray-100 text-gray-700 border-gray-300', 'Archived or inactive domains', NULL)
ON DUPLICATE KEY UPDATE color = VALUES(color), description = VALUES(description);

-- Notification channels table
CREATE TABLE IF NOT EXISTS notification_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_group_id INT NOT NULL,
    channel_type ENUM('email', 'telegram', 'discord', 'slack', 'mattermost', 'webhook', 'pushover') NOT NULL,
    channel_config JSON NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (notification_group_id) REFERENCES notification_groups(id) ON DELETE CASCADE,
    INDEX idx_group_id (notification_group_id),
    INDEX idx_channel_type (channel_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification logs table
CREATE TABLE IF NOT EXISTS notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    channel_type VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('sent', 'failed') DEFAULT 'sent',
    error_message TEXT,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_id (domain_id),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Error logs table for debugging and error tracking
CREATE TABLE IF NOT EXISTS error_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    error_id VARCHAR(32) UNIQUE NOT NULL COMMENT 'Unique reference ID for user reporting',
    error_type VARCHAR(100) NOT NULL COMMENT 'Exception class name',
    error_message TEXT NOT NULL COMMENT 'Error message',
    error_file VARCHAR(500) NOT NULL COMMENT 'File where error occurred',
    error_line INT NOT NULL COMMENT 'Line number where error occurred',
    stack_trace TEXT COMMENT 'Full stack trace',
    
    -- Request context
    request_method VARCHAR(10) COMMENT 'HTTP method (GET, POST, etc)',
    request_uri VARCHAR(500) COMMENT 'Request URI',
    request_data TEXT COMMENT 'JSON encoded POST/GET data (sanitized)',
    
    -- User context
    user_id INT NULL COMMENT 'User who encountered the error',
    user_agent TEXT COMMENT 'Browser user agent string',
    ip_address VARCHAR(45) COMMENT 'IP address (IPv4 or IPv6)',
    session_data TEXT COMMENT 'Session data (sanitized, no passwords)',
    
    -- System context
    php_version VARCHAR(20) COMMENT 'PHP version at time of error',
    memory_usage BIGINT COMMENT 'Memory usage in bytes',
    
    -- Tracking
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'First occurrence timestamp',
    occurrences INT DEFAULT 1 COMMENT 'Number of times this error occurred',
    last_occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last occurrence timestamp',
    
    -- Management
    is_resolved BOOLEAN DEFAULT FALSE COMMENT 'Admin marked as resolved',
    resolved_at TIMESTAMP NULL COMMENT 'When marked as resolved',
    resolved_by INT NULL COMMENT 'Admin user who resolved it',
    notes TEXT COMMENT 'Admin notes about resolution',
    
    -- Foreign keys
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Indexes for performance
    KEY idx_error_id (error_id),
    KEY idx_error_type (error_type),
    KEY idx_occurred_at (occurred_at),
    KEY idx_user_id (user_id),
    KEY idx_is_resolved (is_resolved),
    KEY idx_occurrences (occurrences)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TLD REGISTRY SYSTEM
-- =====================================================

-- TLD registry table
CREATE TABLE IF NOT EXISTS tld_registry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tld VARCHAR(63) NOT NULL UNIQUE,
    rdap_servers JSON,
    whois_server VARCHAR(255),
    registry_url VARCHAR(500),
    iana_publication_date TIMESTAMP NULL,
    iana_last_updated TIMESTAMP NULL,
    record_last_updated TIMESTAMP NULL,
    registration_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tld (tld),
    INDEX idx_is_active (is_active),
    INDEX idx_iana_publication_date (iana_publication_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TLD import logs table
CREATE TABLE IF NOT EXISTS tld_import_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('tld_list', 'rdap', 'whois', 'manual', 'complete_workflow', 'check_updates') NOT NULL,
    total_tlds INT DEFAULT 0,
    new_tlds INT DEFAULT 0,
    updated_tlds INT DEFAULT 0,
    failed_tlds INT DEFAULT 0,
    iana_publication_date TIMESTAMP NULL,
    version VARCHAR(50) NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    status ENUM('running', 'completed', 'failed') DEFAULT 'running',
    error_message TEXT,
    details JSON,
    INDEX idx_started_at (started_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SYSTEM SETTINGS
-- =====================================================

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT,
    `type` VARCHAR(50) DEFAULT 'string',
    `description` TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
-- Application settings
('app_name', 'Domain Monitor', 'string', 'Application name'),
('app_url', 'http://localhost:8000', 'string', 'Application URL'),
('app_timezone', 'UTC', 'string', 'Application timezone'),
('app_version', '1.1.2', 'string', 'Application version number'),

-- Email settings
('mail_host', 'smtp.mailtrap.io', 'string', 'SMTP server host'),
('mail_port', '2525', 'string', 'SMTP server port'),
('mail_username', '', 'string', 'SMTP username'),
('mail_password', '', 'encrypted', 'SMTP password (encrypted)'),
('mail_encryption', 'tls', 'string', 'SMTP encryption (tls/ssl)'),
('mail_from_address', 'noreply@domainmonitor.com', 'string', 'From email address'),
('mail_from_name', 'Domain Monitor', 'string', 'From name'),

-- Monitoring settings
('notification_days_before', '60,30,21,14,7,5,3,2,1', 'string', 'Notification days before expiration'),
('notification_status_triggers', 'available,registered,expired,redemption_period,pending_delete', 'string', 'Domain status changes that trigger notifications'),
('check_interval_hours', '24', 'string', 'Domain check interval in hours'),
('last_check_run', NULL, 'datetime', 'Last time cron job ran'),

-- Authentication settings
('registration_enabled', '0', 'boolean', 'Enable user registration'),
('require_email_verification', '1', 'boolean', 'Require email verification for new users'),

-- CAPTCHA settings
('captcha_provider', 'disabled', 'string', 'CAPTCHA provider (disabled, recaptcha_v2, recaptcha_v3, turnstile)'),
('captcha_site_key', '', 'string', 'CAPTCHA site/public key'),
('captcha_secret_key', '', 'encrypted', 'CAPTCHA secret key (encrypted)'),
('recaptcha_v3_score_threshold', '0.5', 'string', 'reCAPTCHA v3 minimum score threshold (0.0 to 1.0)'),

-- Two-factor authentication settings
('two_factor_policy', 'optional', 'string', '2FA policy: disabled, optional, or required'),
('two_factor_rate_limit_minutes', '15', 'string', 'Rate limit for 2FA attempts in minutes'),
('two_factor_email_code_expiry_minutes', '10', 'string', 'Email code expiry time in minutes'),

-- User isolation settings
('user_isolation_mode', 'shared', 'string', 'User data visibility mode: shared (all users see all data) or isolated (users see only their own data)')

ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- =====================================================
-- MIGRATION TRACKING
-- =====================================================

-- Migrations tracking table
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark this consolidated migration as executed
INSERT INTO migrations (migration) VALUES ('000_initial_schema_v1.1.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;

