-- =====================================================================
-- Migration: program / college of each account
--
-- Upload notifications are scoped: a Program Chair is notified only about
-- faculty in their program, a Dean only about faculty in their college.
-- The program / college keys are defined in config/config.php
-- (PROGRAMS, COLLEGES) and set per account by the Admin in Manage
-- Accounts. Existing non-admin accounts default to the CCS college; their
-- program still has to be assigned.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- =====================================================================

ALTER TABLE users ADD COLUMN program VARCHAR(20) NULL AFTER employment_type;
ALTER TABLE users ADD COLUMN college VARCHAR(20) NULL AFTER program;

UPDATE users SET college = 'CCS' WHERE college IS NULL AND role <> 'admin';

CREATE INDEX idx_users_program ON users(role, college, program);
