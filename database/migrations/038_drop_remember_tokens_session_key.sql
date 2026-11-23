-- "Remember me": drop the foreign key from remember_tokens.session_id to sessions.id
-- Only installs made from the consolidated schema have it. A session row is written when the
-- request ends, after the token is saved, so with the key no token could be saved at all.
-- Installs upgraded through migration 012 never had it. Profile removes the token of a session it ends.

-- The key has a generated name, and MySQL has no DROP FOREIGN KEY IF EXISTS: look the name up
SET @fk_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'remember_tokens'
      AND COLUMN_NAME = 'session_id'
      AND REFERENCED_TABLE_NAME = 'sessions'
    LIMIT 1
);

SET @drop_fk = IF(@fk_name IS NULL,
    'DO 0',
    CONCAT('ALTER TABLE remember_tokens DROP FOREIGN KEY `', @fk_name, '`'));

PREPARE drop_fk_stmt FROM @drop_fk;
EXECUTE drop_fk_stmt;
DEALLOCATE PREPARE drop_fk_stmt;

INSERT INTO migrations (migration) VALUES ('038_drop_remember_tokens_session_key.sql')
ON DUPLICATE KEY UPDATE migration=migration;
