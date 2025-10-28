-- Add user isolation support
-- This migration adds user_id fields to domains and notification_groups tables
-- and adds the user_isolation_mode setting

-- Add user_id field to domains table
ALTER TABLE domains 
ADD COLUMN user_id INT NULL 
AFTER is_active;

-- Add foreign key constraint for domains
ALTER TABLE domains 
ADD CONSTRAINT fk_domains_user_id 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add index for user_id in domains
ALTER TABLE domains 
ADD INDEX idx_domains_user_id (user_id);

-- Add user_id field to notification_groups table
ALTER TABLE notification_groups 
ADD COLUMN user_id INT NULL 
AFTER description;

-- Add foreign key constraint for notification_groups
ALTER TABLE notification_groups 
ADD CONSTRAINT fk_notification_groups_user_id 
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add index for user_id in notification_groups
ALTER TABLE notification_groups 
ADD INDEX idx_notification_groups_user_id (user_id);

-- Add an optional 'description' column to the settings table
-- This column stores a human-readable explanation of each setting,
-- used in newer versions for improved configuration clarity.
ALTER TABLE settings ADD COLUMN description TEXT NULL AFTER setting_value;

-- Add user isolation mode setting
INSERT INTO settings (setting_key, setting_value, description) VALUES 
('user_isolation_mode', 'shared', 'User data visibility mode: shared (all users see all data) or isolated (users see only their own data)')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
