-- =====================================================================
-- Account names in parts, and deleting / restoring accounts (Manage Faculty)
--
-- Safe to run more than once (IF NOT EXISTS). Applied automatically by
-- run_pending_migrations() (includes/functions.php); to apply by hand:
--   mysql -u root faculty201_repository < database/migration_account_names_delete.sql
--
-- Names: users.full_name stays the one name every page shows (all pages
-- join users by user_id). It is now built from these parts by
-- person_full_name() whenever the Admin creates or edits an account, e.g.
-- "Dr. Juan S. Dela Cruz Jr.". Older accounts keep their full_name until
-- edited; the edit form fills the parts in from it.
--
-- Delete: a deleted account is deactivated at once (can't log in, its
-- sessions end) and listed under Manage Faculty > Deleted accounts, where
-- the Admin can restore it for DELETED_RETENTION_DAYS days. After that the
-- daily cleanup deletes it permanently: the login is removed for good
-- (email released, password cleared, picture and contact number removed,
-- notifications and reminders deleted) and its 201-file documents move to
-- Archived Docs under the former faculty member's name. The row itself is
-- kept (purged_at), because documents, the PDS and the activity log refer
-- to it -- removing it would delete those official records with it.
-- =====================================================================

USE faculty201_repository;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS name_title  VARCHAR(20) NULL COMMENT 'Dr., Engr., Prof. ...' AFTER full_name,
    ADD COLUMN IF NOT EXISTS first_name  VARCHAR(60) NULL AFTER name_title,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS last_name   VARCHAR(60) NULL AFTER middle_name,
    ADD COLUMN IF NOT EXISTS name_suffix VARCHAR(10) NULL COMMENT 'Jr., Sr., III ...' AFTER last_name,
    ADD COLUMN IF NOT EXISTS deleted_at  DATETIME NULL COMMENT 'Deleted from Manage Faculty, restorable for DELETED_RETENTION_DAYS',
    ADD COLUMN IF NOT EXISTS deleted_by  INT NULL,
    ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS active_before_delete TINYINT(1) NULL COMMENT 'is_active to restore',
    ADD COLUMN IF NOT EXISTS purged_at   DATETIME NULL COMMENT 'Deleted permanently by the daily cleanup';

CREATE INDEX IF NOT EXISTS idx_users_deleted ON users (deleted_at, purged_at);
