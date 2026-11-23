-- Authenticator app codes work once: the time step of the last code accepted for an account.
-- A code of that step or of an earlier one is refused from then on.
ALTER TABLE users ADD COLUMN two_factor_last_step INT UNSIGNED NULL COMMENT 'Time step (Unix time / 30) of the last authenticator app code accepted' AFTER two_factor_setup_at;

INSERT INTO migrations (migration) VALUES ('041_add_two_factor_last_step.sql')
ON DUPLICATE KEY UPDATE migration=migration;
