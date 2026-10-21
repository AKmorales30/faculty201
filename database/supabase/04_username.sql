-- =====================================================================
-- Username login (PostgreSQL / Supabase)
--
-- Run in Supabase: SQL Editor > New query, paste this file, Run. Safe to
-- run again. For a new database: after 01_schema.sql (and 02 / 03).
-- The MySQL / MariaDB version is database/migration_username_2fa.sql
-- (applied automatically by the app).
--
--   users.username           what people log in with (set by the Admin);
--                            stored in lowercase, unique ignoring case
--   users.email              an optional contact email (not used to log
--                            in), unique ignoring case
--   login_attempts.username  the username typed (email stays for old rows)
--
-- Existing accounts get a username automatically (first.last, a number
-- added if taken) the first time the app needs one -- a login attempt or
-- the Admin opening Manage Faculty. To see them afterwards:
--   SELECT user_id, username, full_name, role FROM users ORDER BY user_id;
-- =====================================================================

BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username varchar(30) NULL;
ALTER TABLE users ALTER COLUMN email DROP NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username_lower ON users (lower(username));
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_email_lower ON users (lower(email));
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_users_username_format') THEN
        ALTER TABLE users ADD CONSTRAINT ck_users_username_format CHECK (username ~ '^[a-z0-9._]{4,30}$');
    END IF;
END
$$;

ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS username varchar(150) NULL;
ALTER TABLE login_attempts ALTER COLUMN email DROP NOT NULL;
CREATE INDEX IF NOT EXISTS idx_login_attempts_username ON login_attempts (username, attempted_at);

INSERT INTO schema_migrations (name) VALUES ('migration_username_2fa.sql'), ('migration_remove_two_factor.sql') ON CONFLICT (name) DO NOTHING;

COMMIT;
