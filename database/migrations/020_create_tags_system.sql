-- Create comprehensive tags system with user isolation support
-- This migration creates the tags table, domain_tags junction table, migrates existing data, and adds user isolation

-- Create tags table for better tag management
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

-- Create domain_tags junction table for many-to-many relationship
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

-- Insert default tags with their colors (global tags), once: they have no owner, so the unique key above does not see that they are there already
INSERT INTO tags (name, color, description, user_id)
SELECT d.name, d.color, d.description, NULL FROM (
    SELECT 'production' AS name, 'bg-green-100 text-green-700 border-green-300' AS color, 'Production environment domains' AS description
    UNION ALL SELECT 'staging', 'bg-yellow-100 text-yellow-700 border-yellow-300', 'Staging environment domains'
    UNION ALL SELECT 'development', 'bg-blue-100 text-blue-700 border-blue-300', 'Development environment domains'
    UNION ALL SELECT 'client', 'bg-purple-100 text-purple-700 border-purple-300', 'Client-related domains'
    UNION ALL SELECT 'personal', 'bg-orange-100 text-orange-700 border-orange-300', 'Personal domains'
    UNION ALL SELECT 'archived', 'bg-gray-100 text-gray-700 border-gray-300', 'Archived or inactive domains'
) AS d
WHERE NOT EXISTS (SELECT 1 FROM tags WHERE user_id IS NULL);

-- Migrate existing comma-separated tags to the new tag system
-- Create a temporary table to store the migration data
CREATE TEMPORARY TABLE temp_domain_tags AS
SELECT 
    d.id as domain_id,
    TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(d.tags, ',', n.n), ',', -1)) as tag_name
FROM domains d
CROSS JOIN (
    SELECT 1 as n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
    UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
) n
WHERE d.tags IS NOT NULL 
  AND d.tags != ''
  AND CHAR_LENGTH(d.tags) - CHAR_LENGTH(REPLACE(d.tags, ',', '')) >= n.n - 1
  AND TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(d.tags, ',', n.n), ',', -1)) != '';

-- Insert new tags that don't exist yet (assign to domain owner)
-- Note: If the same tag name is used by multiple users, only the first one will be created
-- due to the UNIQUE constraint on (user_id, name). This is intentional to avoid conflicts.
INSERT IGNORE INTO tags (name, color, description, user_id)
SELECT DISTINCT 
    tdt.tag_name,
    'bg-gray-100 text-gray-700 border-gray-300' as color,
    CONCAT('Tag: ', tdt.tag_name) as description,
    d.user_id
FROM temp_domain_tags tdt
JOIN domains d ON d.id = tdt.domain_id
WHERE tdt.tag_name NOT IN (SELECT name FROM tags);

-- Insert domain-tag relationships
INSERT IGNORE INTO domain_tags (domain_id, tag_id)
SELECT 
    tdt.domain_id,
    t.id as tag_id
FROM temp_domain_tags tdt
JOIN tags t ON t.name = tdt.tag_name;

-- Update usage counts
UPDATE tags t 
SET usage_count = (
    SELECT COUNT(*) 
    FROM domain_tags dt 
    WHERE dt.tag_id = t.id
);

-- Drop the old tags column from domains table
ALTER TABLE domains DROP COLUMN tags;
