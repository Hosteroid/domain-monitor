-- Add source tracking to DNS records (discovered vs manual vs imported)
ALTER TABLE dns_records
  ADD COLUMN source ENUM('discovered','manual','imported') NOT NULL DEFAULT 'discovered'
  AFTER is_cloudflare;

INSERT INTO migrations (migration) VALUES ('029_add_dns_record_source.sql')
ON DUPLICATE KEY UPDATE migration=migration;
