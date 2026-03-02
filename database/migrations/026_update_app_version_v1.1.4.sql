-- Update application version to 1.1.4
-- This version adds TLD Registry import/export/create, IANA dropdown UI, and standardized logging

-- Update application version to 1.1.4
UPDATE settings 
SET setting_value = '1.1.4' 
WHERE setting_key = 'app_version';
