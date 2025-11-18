-- Add 'pushover' to the channel_type ENUM in notification_channels table
-- This enables Pushover push notification support for domain expiration alerts

ALTER TABLE notification_channels 
MODIFY COLUMN channel_type ENUM('email', 'telegram', 'discord', 'slack', 'mattermost', 'webhook', 'pushover') NOT NULL;

