-- =====================================================================
-- Post every document still marked For Review.
--
-- Uploads no longer wait for the Admin: they go to 201-Files right away,
-- and the system flags the ones worth checking (Manage Document). This
-- posts the documents left in the old For Review queue, flagged when there
-- is a reason to check them (low confidence, details entered manually, no
-- text read). Safe to run more than once -- it only touches documents still
-- For Review. migration_flags_pds_versions.sql (applied automatically)
-- does the same; run this by hand on a database where it hasn't run yet.
--
--   mysql -u root faculty201_repository < database/post_for_review_documents.sql
-- =====================================================================

USE faculty201_repository;

UPDATE documents
   SET flag_reason = COALESCE(flag_reason, NULLIF(CONCAT_WS(',',
           IF(is_low_confidence = 1, 'low_confidence', NULL),
           IF(entry_method = 'manual', 'manual_entry', NULL),
           IF(entry_method <> 'manual' AND COALESCE(TRIM(ocr_extracted_text), '') = '', 'no_text', NULL)), '')),
       status = 'active',
       posted_at = COALESCE(posted_at, filed_at)
 WHERE status = 'for_review';

-- Deleted ones restore to 201-Files, not to the old queue
UPDATE documents SET status_before_delete = 'active' WHERE status = 'deleted' AND status_before_delete = 'for_review';
UPDATE documents SET posted_at = filed_at WHERE status = 'deleted' AND posted_at IS NULL;
