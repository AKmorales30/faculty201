-- =====================================================================
-- Migration: password management
--
-- Users can change their own password (change_password.php) and the
-- Admin can reset one (Manage Faculty & Accounts). A password the Admin
-- sets -- when creating an account or resetting it -- is temporary: the
-- user is sent to Change Password after logging in and can't open any
-- other page until they choose their own.
--
--   must_change_password   1 = temporary password, must be changed
--   password_changed_at    last time the user set their own password
--
-- Existing accounts are NOT flagged (default 0): their users keep their
-- current passwords. To make everyone choose a new password at their next
-- login, run once:  UPDATE users SET must_change_password = 1;
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password;
