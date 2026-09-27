-- =====================================================================
-- Migration: full 201-file contents + digital PDS
--
-- Expands the repository from 3 document categories (TOR / Diploma /
-- Certificate) to the full Faculty 201 File agreed in the interview:
-- PDS, Certificates, Diploma, TOR, FTA, IPCR, Contract of Service,
-- Affidavit of Undertaking, and Other Documents -- with subtypes
-- (e.g. Certificates > Seminar) and the semester / year a document
-- covers (FTA and IPCR are per semester, PDS is per year).
--
-- Also adds the editable digital PDS (CS Form No. 212):
--   pds_records               Parts I-VI, VIII, Q34-40, references, ID
--   pds_learning_development  Part VII rows (auto-filled from uploaded
--                             seminar / training certificates)
--   pds_snapshots             earlier versions, kept whenever the PDS
--                             is changed so nothing is ever lost
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

-- Categories are defined in PHP (document_categories()), so store the
-- key as text instead of a fixed ENUM. Existing TOR / Diploma /
-- Certificate values are kept as-is.
ALTER TABLE submission_requests MODIFY COLUMN document_type_hint VARCHAR(40) NOT NULL;
ALTER TABLE documents MODIFY COLUMN document_type VARCHAR(40) NOT NULL;

ALTER TABLE documents ADD COLUMN document_subtype VARCHAR(40) NULL AFTER document_type;
ALTER TABLE documents ADD COLUMN academic_year VARCHAR(9) NULL AFTER document_subtype;
ALTER TABLE documents ADD COLUMN semester TINYINT NULL AFTER academic_year;
ALTER TABLE documents ADD COLUMN period_year SMALLINT NULL AFTER semester;

CREATE INDEX idx_documents_type ON documents(faculty_id, document_type, document_subtype);

CREATE TABLE IF NOT EXISTS pds_records (
    faculty_id          INT PRIMARY KEY,
    data                LONGTEXT NOT NULL,
    source_document_id  INT NULL,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (source_document_id) REFERENCES documents(document_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pds_learning_development (
    ld_id               INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id          INT NOT NULL,
    title               VARCHAR(255) NOT NULL,
    date_from           DATE NULL,
    date_to             DATE NULL,
    hours               DECIMAL(6,1) NULL,
    ld_type             VARCHAR(40) NULL,
    conducted_by        VARCHAR(255) NULL,
    source_document_id  INT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (source_document_id) REFERENCES documents(document_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_pds_ld_faculty ON pds_learning_development(faculty_id);

CREATE TABLE IF NOT EXISTS pds_snapshots (
    snapshot_id   INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id    INT NOT NULL,
    data          LONGTEXT NOT NULL,
    reason        VARCHAR(150) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_pds_snapshots_faculty ON pds_snapshots(faculty_id, created_at);
