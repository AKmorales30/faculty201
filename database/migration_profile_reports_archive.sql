-- =====================================================================
-- Migration: profile page, seminar / training report, auto-archive
--
-- Adds only what the system didn't store yet. Reused as they are:
--   date hired         users.date_engaged
--   employment status  users.employment_status (Admin's pause / resume)
--   position           users.role + users.employment_type
--   last login         login_attempts
--   archive            documents.status / archived_at / archived_by / archive_reason
--
-- users
--   profile_picture   random file name of the current picture (NULL = none,
--                     show initials). The image itself is in profile_pictures;
--                     the name changes with every upload, so it's also the
--                     browser cache key.
--   academic_rank     one of academic_ranks.name (NULL = not set). Admin only.
--   specialization    field of specialization. Editable by the user.
--   employee_id       set by the Admin. When empty, the PDS Agency Employee
--                     No. is shown instead.
--   contact_number    editable by the user. Kept in step with the PDS
--                     Mobile No. (each one updates the other when saved).
--
-- academic_ranks      the Academic Rank dropdown. The Admin can add ranks
--                     and hide ones no longer used (admin/academic_ranks.php).
-- profile_pictures    the picture of each account, already cropped and
--                     resized (JPEG). Kept in the database like
--                     document_files, because the hosting server's disk is
--                     wiped on every deploy. Served only through
--                     profile_photo.php, after an access check.
--
-- documents
--   title, date_start, date_end, venue, conducted_by, training_type,
--   training_level, hours
--                     seminar / training details for certificates. Pre-filled
--                     from the OCR text at upload, reviewed by the faculty
--                     member, editable later (document_details.php).
--   date_issued       the document's own date, for documents that aren't
--                     seminars (optional).
--   archive_exempt    1 = the Admin restored this document from the archive
--                     while it was older than ARCHIVE_AFTER_YEARS, so the
--                     auto-archive leaves it alone.
--
-- Existing rows keep working: every new column is NULL or 0 by default, and
-- a document with no dates is dated by its upload date, so nothing already
-- uploaded is archived by this change.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

ALTER TABLE users ADD COLUMN profile_picture VARCHAR(64) NULL AFTER email;
ALTER TABLE users ADD COLUMN employee_id VARCHAR(30) NULL AFTER profile_picture;
ALTER TABLE users ADD COLUMN contact_number VARCHAR(30) NULL AFTER employee_id;
ALTER TABLE users ADD COLUMN academic_rank VARCHAR(60) NULL AFTER employment_type;
ALTER TABLE users ADD COLUMN specialization VARCHAR(150) NULL AFTER academic_rank;

CREATE TABLE academic_ranks (
    rank_id       INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(60) NOT NULL UNIQUE,
    sort_order    INT NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO academic_ranks (name, sort_order) VALUES
    ('N/A', 0),
    ('Instructor I', 10), ('Instructor II', 20), ('Instructor III', 30),
    ('Assistant Professor I', 40), ('Assistant Professor II', 50), ('Assistant Professor III', 60), ('Assistant Professor IV', 70),
    ('Associate Professor I', 80), ('Associate Professor II', 90), ('Associate Professor III', 100), ('Associate Professor IV', 110), ('Associate Professor V', 120),
    ('Professor I', 130), ('Professor II', 140), ('Professor III', 150), ('Professor IV', 160), ('Professor V', 170), ('Professor VI', 180);

CREATE TABLE profile_pictures (
    user_id       INT PRIMARY KEY,
    mime_type     VARCHAR(50) NOT NULL,
    file_size     INT NOT NULL,
    data          MEDIUMBLOB NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE documents ADD COLUMN title VARCHAR(255) NULL AFTER period_year;
ALTER TABLE documents ADD COLUMN date_start DATE NULL AFTER title;
ALTER TABLE documents ADD COLUMN date_end DATE NULL AFTER date_start;
ALTER TABLE documents ADD COLUMN venue VARCHAR(255) NULL AFTER date_end;
ALTER TABLE documents ADD COLUMN conducted_by VARCHAR(255) NULL AFTER venue;
ALTER TABLE documents ADD COLUMN training_type VARCHAR(40) NULL AFTER conducted_by;
ALTER TABLE documents ADD COLUMN training_level VARCHAR(40) NULL AFTER training_type;
ALTER TABLE documents ADD COLUMN hours DECIMAL(6,1) NULL AFTER training_level;
ALTER TABLE documents ADD COLUMN date_issued DATE NULL AFTER hours;
ALTER TABLE documents ADD COLUMN archive_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER archive_reason;

CREATE INDEX idx_documents_training ON documents(document_type, status, date_start);
