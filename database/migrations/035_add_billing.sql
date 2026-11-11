-- Billing: payments per domain, a price list and currencies (#69)
CREATE TABLE IF NOT EXISTS billing_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NULL COMMENT 'NULL once the domain is deleted, or for a cost without a domain',
    domain_name VARCHAR(255) NOT NULL DEFAULT '',
    user_id INT NULL COMMENT 'Owner of the payment',
    type VARCHAR(20) NOT NULL COMMENT 'purchase, renewal, transfer, other, sale, income',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Always positive, the type decides the sign',
    currency CHAR(3) NOT NULL,
    amount_base DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Value in the base currency, used by every total',
    transaction_date DATE NOT NULL,
    vendor VARCHAR(255) NULL,
    invoice_number VARCHAR(100) NULL,
    invoice_url VARCHAR(500) NULL,
    payment_method VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'paid' COMMENT 'paid, pending (pending is not counted)',
    source VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'manual, auto, import',
    needs_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Logged automatically and not confirmed yet',
    term_years TINYINT UNSIGNED NULL COMMENT 'Years a renewal paid for',
    expires_on DATE NULL COMMENT 'Expiry date a renewal reached',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_billing_tx_domain (domain_id, type, expires_on),
    INDEX idx_billing_tx_user_date (user_id, transaction_date),
    INDEX idx_billing_tx_date (transaction_date),
    INDEX idx_billing_tx_name (domain_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'Who added the row',
    tld VARCHAR(63) NOT NULL COMMENT 'With leading dot, lower case',
    registrar VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Empty: any registrar',
    registrar_key VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Registrar in lower case without punctuation',
    registration_price DECIMAL(12,2) NULL,
    renewal_price DECIMAL(12,2) NULL,
    transfer_price DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_billing_price (user_id, tld, registrar_key),
    INDEX idx_billing_price_tld (tld)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_currencies (
    code CHAR(3) NOT NULL PRIMARY KEY,
    symbol VARCHAR(8) NULL,
    rate DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 COMMENT 'Value of 1 unit in the base currency',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE domains ADD COLUMN renewal_price DECIMAL(12,2) NULL COMMENT 'Own yearly renewal price, used before the price list' AFTER notes;
ALTER TABLE domains ADD COLUMN renewal_currency CHAR(3) NULL AFTER renewal_price;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('billing_enabled', '1', 'boolean', 'Billing: payments, price list and renewal forecasts'),
('billing_currency', 'USD', 'string', 'Base currency of all billing totals'),
('billing_auto_log_renewals', '1', 'boolean', 'Log a payment when a renewal is detected'),
('billing_cost_in_reminders', '1', 'boolean', 'Show the renewal cost in expiry reminders')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO billing_currencies (code, symbol, rate) VALUES ('USD', '$', 1)
ON DUPLICATE KEY UPDATE code=code;

INSERT INTO migrations (migration) VALUES ('035_add_billing.sql')
ON DUPLICATE KEY UPDATE migration=migration;
