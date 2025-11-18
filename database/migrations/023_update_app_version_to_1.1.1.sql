-- Update application version to 1.1.1
-- This version includes Pushover notifications, security fixes, and improved domain status detection

UPDATE settings 
SET setting_value = '1.1.1' 
WHERE setting_key = 'app_version';

