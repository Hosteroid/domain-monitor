-- Domain suffixes: the registry part of the Public Suffix List, imported from TLD Registry, so the
-- subdomain check knows endings that take registrations, such as .co.uk or .my.id (#112)
CREATE TABLE IF NOT EXISTS domain_suffixes (
    suffix VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'Rule in lower case, without a leading *. or !',
    rule_type TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = rule, 1 = wildcard (*.suffix), 2 = exception (!suffix)',
    PRIMARY KEY (suffix, rule_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('domain_suffix_list_imported_at', '', 'string', 'When the Public Suffix List was last imported (unix time)')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

INSERT INTO migrations (migration) VALUES ('042_add_domain_suffixes.sql')
ON DUPLICATE KEY UPDATE migration=migration;
