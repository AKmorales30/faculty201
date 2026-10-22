-- =====================================================================
-- Optional two-factor authentication (MySQL / MariaDB)
-- Applied automatically, once, by run_pending_migrations() (includes/functions.php).
--   users.two_factor_enabled         1 = a code is emailed at every login;
--                                    on for accounts that already verified an email
--   users.email_prompt_dismissed_at  the last "Not now" on "Set up your email"
--   login_otps.purpose               + 'enable_2fa'
-- Supabase: database/supabase/06_optional_two_factor.sql.
-- =====================================================================

USE faculty201_repository;

ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER email_verified_at;
UPDATE users SET two_factor_enabled = 1 WHERE email IS NOT NULL AND email_verified_at IS NOT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_prompt_dismissed_at DATETIME NULL AFTER two_factor_enabled;

ALTER TABLE login_otps MODIFY COLUMN purpose ENUM('login', 'setup_email', 'change_email', 'reset_password', 'enable_2fa') NOT NULL;
