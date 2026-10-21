-- =====================================================================
-- Remove the emailed login codes (MySQL / MariaDB)
-- Applied automatically by run_pending_migrations() (includes/functions.php).
-- Drops what an earlier version of migration_username_2fa.sql added for
-- two-factor login codes; the system no longer uses them. Safe on a
-- database that never had them. Supabase: database/supabase/05_remove_two_factor.sql.
-- =====================================================================

USE faculty201_repository;

DROP TABLE IF EXISTS login_otps;
ALTER TABLE users DROP COLUMN IF EXISTS email_verified_at;
