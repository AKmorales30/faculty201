-- =====================================================================
-- Migration: delete and archive documents (soft delete)
--
-- A faculty member may delete their own upload within
-- FACULTY_DELETE_WINDOW_HOURS (config/config.php); the Admin may archive,
-- delete or restore any document (document_action.php,
-- admin/archived_documents.php). Rows are never removed:
--
--   status          active   -- normal; the only status shown anywhere
--                               except the Admin's Archived Documents page
--                   archived -- hidden from everyone but the Admin; file
--                               and record kept as they are
--                   deleted  -- removed from the 201 file; the local file
--                               copy moves to uploads/_deleted/ and the
--                               database copy (document_files) is kept,
--                               so the Admin can still restore it
--   archived_at / archived_by / archive_reason
--   deleted_at  / deleted_by  / delete_reason
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

ALTER TABLE documents ADD COLUMN status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active' AFTER file_path;
ALTER TABLE documents ADD COLUMN archived_at DATETIME NULL AFTER status;
ALTER TABLE documents ADD COLUMN archived_by INT NULL AFTER archived_at;
ALTER TABLE documents ADD COLUMN archive_reason VARCHAR(255) NULL AFTER archived_by;
ALTER TABLE documents ADD COLUMN deleted_at DATETIME NULL AFTER archive_reason;
ALTER TABLE documents ADD COLUMN deleted_by INT NULL AFTER deleted_at;
ALTER TABLE documents ADD COLUMN delete_reason VARCHAR(255) NULL AFTER deleted_by;

CREATE INDEX idx_documents_status ON documents(status, faculty_id);
