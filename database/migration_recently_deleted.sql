-- =====================================================================
-- Migration: Recently Deleted (30-day restore window, then permanent deletion)
--
-- A deleted document (documents.status 'deleted', deleted_at / _by /
-- delete_reason as before) stays in Recently Deleted for
-- DELETED_RETENTION_DAYS (config/config.php) from deleted_at. It can be
-- restored until then; afterwards the daily check removes it for good --
-- file, database copy (document_files), record, its upload log row and
-- the notifications linked to it. Only the activity-log entries remain.
--
--   status_before_delete  where it was when deleted ('active' = Public
--                         Docs, 'archived' = Archived Docs, 'for_review'),
--                         so Restore puts it back there. NULL for documents
--                         deleted before this migration: they go back to
--                         Public Docs (or For Review if never posted).
--   purge_warned_at       when the owner was told it will be permanently
--                         deleted in DELETED_PURGE_WARNING_DAYS days (sent once)
--
-- Safe to run any number of times (ADD ... IF NOT EXISTS, MariaDB 10.0.2+).
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php on the next page load. By hand: phpMyAdmin >
-- faculty201_repository > SQL > paste > Go, or
--   mysql -u root faculty201_repository < database/migration_recently_deleted.sql
-- =====================================================================

ALTER TABLE documents ADD COLUMN IF NOT EXISTS status_before_delete VARCHAR(20) NULL AFTER delete_reason;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS purge_warned_at DATETIME NULL AFTER status_before_delete;

-- A deleted document always has a deletion time to count the 30 days from
UPDATE documents SET deleted_at = NOW() WHERE status = 'deleted' AND deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_documents_deleted ON documents(status, deleted_at);
