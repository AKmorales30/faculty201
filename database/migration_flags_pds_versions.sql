-- =====================================================================
-- 1. Uploads are posted right away; some are flagged for checking.
-- 2. PDS versions (version number, what changed, how, by whom, a snapshot).
--
-- Safe to run more than once. Applied automatically by
-- run_pending_migrations() (includes/functions.php); by hand:
--   mysql -u root faculty201_repository < database/migration_flags_pds_versions.sql
-- =====================================================================

USE faculty201_repository;

-- ---------------------------------------------------------------------
-- 1. Flagged for checking. A new upload is posted to 201-Files at once.
--    The system flags it for the Admin to check (Manage Document) when its
--    type was detected with low confidence, no text could be read, or the
--    uploader entered or picked the type themselves -- comma-separated
--    codes: low_confidence, no_text, manual_entry, type_changed. NULL =
--    not flagged. It stays flagged until the Admin marks it checked
--    (documents.reviewed_at). A flag never hides the document.
-- ---------------------------------------------------------------------
ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS flag_reason VARCHAR(160) NULL COMMENT 'Flagged for checking: low_confidence, no_text, manual_entry, type_changed' AFTER is_low_confidence;
CREATE INDEX IF NOT EXISTS idx_documents_flagged ON documents (reviewed_at, flag_reason);

-- Documents still waiting For Review (the old review step) are posted now,
-- flagged when there's a reason to check them. Also in
-- database/post_for_review_documents.sql.
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

-- ---------------------------------------------------------------------
-- 2. PDS versions. One row per change that actually changed something:
--    source 'upload' (automatic update from an uploaded PDS, or a
--    certificate added to Section VI), 'manual edit' (the PDS page, or the
--    mobile number from Profile Preference), 'admin edit' (the Admin's
--    import), 'undo' (an automatic update undone -- it never deletes
--    versions, it adds one), 'initial' (version 1 of a PDS that existed
--    before versions were kept). snapshot: the whole PDS after the change,
--    {"data": {...}, "ld": [...]}, for viewing that version read-only.
--    changes (uploads): what the update changed, for Undo, and the values
--    that need checking.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pds_versions (
    version_id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT NOT NULL,
    version_number     INT NOT NULL,
    changed_sections   VARCHAR(255) NULL COMMENT 'Section keys, comma-separated (I, II, III, IV, V, LD, VII, VIII, Q, R, ID)',
    source             ENUM('initial', 'upload', 'manual edit', 'admin edit', 'undo') NOT NULL,
    source_document_id INT NULL,
    changed_by         INT NULL,
    snapshot           LONGTEXT NOT NULL,
    changes            LONGTEXT NULL,
    undo_of            INT NULL COMMENT 'The version an Undo reverted',
    undone_at          DATETIME NULL,
    undone_by          INT NULL,
    created_at         DATETIME NOT NULL COMMENT 'Asia/Manila',
    UNIQUE KEY uq_pds_versions_number (user_id, version_number),
    KEY idx_pds_versions_document (source_document_id),
    CONSTRAINT fk_pds_versions_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_pds_versions_document FOREIGN KEY (source_document_id) REFERENCES documents (document_id) ON DELETE SET NULL,
    CONSTRAINT fk_pds_versions_changed_by FOREIGN KEY (changed_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Version 1 of every PDS that exists already: its current data, dated when it
-- was last saved (or when the account was created, if unknown)
SET SESSION group_concat_max_len = 16777216;
INSERT INTO pds_versions (user_id, version_number, changed_sections, source, snapshot, created_at)
SELECT f.uid, 1, NULL, 'initial',
       CONCAT('{"data":', COALESCE(NULLIF(r.data, ''), '{}'), ',"ld":[',
              COALESCE((SELECT GROUP_CONCAT(JSON_OBJECT('title', l.title, 'date_from', l.date_from, 'date_to', l.date_to, 'hours', l.hours,
                                                        'ld_type', l.ld_type, 'conducted_by', l.conducted_by, 'source_document_id', l.source_document_id)
                                            ORDER BY l.ld_id SEPARATOR ',')
                        FROM pds_learning_development l WHERE l.faculty_id = f.uid), ''), ']}'),
       COALESCE(r.updated_at, u.created_at)
  FROM (SELECT faculty_id AS uid FROM pds_records UNION SELECT faculty_id FROM pds_learning_development) f
  JOIN users u ON u.user_id = f.uid
  LEFT JOIN pds_records r ON r.faculty_id = f.uid
 WHERE NOT EXISTS (SELECT 1 FROM pds_versions v WHERE v.user_id = f.uid);
