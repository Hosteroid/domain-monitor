-- Add avatar field to users table
-- This field will store the filename of the uploaded avatar image
ALTER TABLE users 
ADD COLUMN avatar VARCHAR(255) NULL AFTER full_name;
