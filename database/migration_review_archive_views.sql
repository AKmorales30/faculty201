-- =====================================================================
-- Migration: classification review (For Review -> Posted) and the
-- "not opened in 3 years" archive rule
--
--   documents.status  gains 'for_review'. A new upload is saved as
--                     for_review and listed in the Admin's Manage Document
--                     queue (admin/manage_documents.php). There the Admin
--                     checks the detected document type and extracted
--                     details, corrects them if needed, and posts it:
--                     status becomes 'active' (= Posted). Only posted
--                     documents appear in Public Docs, searches, reports,
--                     analytics and expiration alerts, which all filter
--                     on status = 'active'. This is a classification
--                     review only -- not the old approval workflow.
--   posted_at / posted_by   when and by whom it was posted. Documents
--                     filed before this migration count as posted at
--                     their upload time (posted_by stays NULL).
--   entry_method      'ocr' (uploaded and auto-detected) or 'manual'
--                     (details typed in by the uploader); NULL = uploaded
--                     before this migration.
--   last_viewed_at    the last time anyone opened the document
--                     (document.php, including the preview pop-up).
--                     The daily auto-archive moves a posted document that
--                     hasn't been opened in ARCHIVE_UNVIEWED_YEARS (3)
--                     years to its owner's archive. Existing documents
--                     start from their upload date.
--
-- Safe to run any number of times: MODIFY COLUMN can be repeated, every
-- ADD uses IF NOT EXISTS (MariaDB 10.0.2+, e.g. XAMPP and SkySQL), and the
-- UPDATEs only fill values that are still empty.
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php on the next page load. By hand: phpMyAdmin >
-- faculty201_repository > SQL > paste > Go, or
--   mysql -u root faculty201_repository < database/migration_review_archive_views.sql
-- =====================================================================

-- 'for_review' is added at the end so the existing values keep their order
ALTER TABLE documents MODIFY COLUMN status ENUM('active','archived','deleted','for_review') NOT NULL DEFAULT 'active';

ALTER TABLE documents ADD COLUMN IF NOT EXISTS posted_at DATETIME NULL AFTER reviewed_by;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS posted_by INT NULL AFTER posted_at;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS entry_method VARCHAR(10) NULL AFTER posted_by;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS last_viewed_at DATETIME NULL AFTER entry_method;

-- Existing documents: viewed "at upload", and posted when they were filed
UPDATE documents SET last_viewed_at = filed_at WHERE last_viewed_at IS NULL;
UPDATE documents SET posted_at = filed_at WHERE posted_at IS NULL AND status <> 'for_review';

CREATE INDEX IF NOT EXISTS idx_documents_last_viewed ON documents(status, last_viewed_at);
CREATE INDEX IF NOT EXISTS idx_documents_review_queue ON documents(status, filed_at);
