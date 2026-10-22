-- =====================================================================
-- Optional two-factor authentication (PostgreSQL / Supabase)
--
-- Run in Supabase: SQL Editor > New query, paste this file, Run. Safe to
-- run again. Needs 04_username_2fa.sql or 05_restore_two_factor.sql first
-- (users.email_verified_at and login_otps).
--
--   users.two_factor_enabled         1 = a code is emailed at every login.
--                                    Accounts that already verified an
--                                    email start with it on (they were
--                                    using codes); everyone else off.
--   users.email_prompt_dismissed_at  the last "Not now" on the "Set up your
--                                    email" step; asked again after
--                                    EMAIL_PROMPT_REMIND_DAYS (config.php)
--   login_otps.purpose               + 'enable_2fa' (the code that turns
--                                    two-factor authentication on)
-- The MySQL / MariaDB version is database/migration_optional_two_factor.sql
-- (applied automatically by the app).
-- =====================================================================

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'two_factor_enabled') THEN
        ALTER TABLE users ADD COLUMN two_factor_enabled smallint NOT NULL DEFAULT 0;
        -- Only when the column is first added, so a second run never turns 2FA back on for someone who turned it off
        UPDATE users SET two_factor_enabled = 1 WHERE email IS NOT NULL AND email_verified_at IS NOT NULL;
    END IF;
END $$;

ALTER TABLE users ADD COLUMN IF NOT EXISTS email_prompt_dismissed_at timestamp(0) NULL;

-- Allow the new code purpose: drop the old CHECK on purpose (whatever its name), add the full one
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname FROM pg_constraint
             WHERE conrelid = 'public.login_otps'::regclass AND contype = 'c'
               AND pg_get_constraintdef(oid) ILIKE '%purpose%' LOOP
        EXECUTE format('ALTER TABLE login_otps DROP CONSTRAINT %I', c.conname);
    END LOOP;
END $$;
ALTER TABLE login_otps ADD CONSTRAINT login_otps_purpose_check
    CHECK (purpose IN ('login', 'setup_email', 'change_email', 'reset_password', 'enable_2fa'));

INSERT INTO schema_migrations (name) VALUES ('migration_optional_two_factor.sql') ON CONFLICT (name) DO NOTHING;

COMMIT;
