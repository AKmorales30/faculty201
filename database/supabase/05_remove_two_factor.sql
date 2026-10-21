-- =====================================================================
-- Remove the emailed login codes (PostgreSQL / Supabase)
--
-- Only for a database that ran the earlier version of 04 (with two-factor
-- login codes): drops the codes table and the email-verified column, which
-- the system no longer uses. Safe to run again, and on a database that
-- never had them. The MySQL / MariaDB version is
-- database/migration_remove_two_factor.sql (applied automatically).
-- =====================================================================

BEGIN;

DROP TABLE IF EXISTS login_otps;
ALTER TABLE users DROP COLUMN IF EXISTS email_verified_at;

INSERT INTO schema_migrations (name) VALUES ('migration_remove_two_factor.sql') ON CONFLICT (name) DO NOTHING;

COMMIT;
