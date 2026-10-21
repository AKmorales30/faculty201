-- =====================================================================
-- Username login (MySQL / MariaDB)
-- Applied automatically by run_pending_migrations() (includes/functions.php).
-- The Supabase version is database/supabase/04_username.sql.
-- (The file name is from when this also added emailed login codes; those
-- were removed again -- database/migration_remove_two_factor.sql.)
--
--   users.username           what people log in with (set by the Admin);
--                            stored in lowercase (the collation ignores case)
--   users.email              an optional contact email, not used to log in
--   login_attempts.username  the username typed (email stays for old rows)
-- Existing accounts get a username automatically (assign_missing_usernames()).
-- =====================================================================

USE faculty201_repository;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(30) NULL AFTER full_name;
ALTER TABLE users MODIFY COLUMN email VARCHAR(150) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username ON users (username);

ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS username VARCHAR(150) NULL AFTER email;
ALTER TABLE login_attempts MODIFY COLUMN email VARCHAR(150) NULL;
CREATE INDEX IF NOT EXISTS idx_login_attempts_username ON login_attempts (username, attempted_at);
