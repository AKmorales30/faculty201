-- =====================================================================
-- Repair: add every table, column and index the system uses, if missing.
--
-- One script with everything from schema.sql and all database/migration_*.sql
-- files. Safe to run any number of times, on any database of this system
-- (new, partly migrated, or complete): every statement uses IF NOT EXISTS,
-- nothing is dropped, and no existing data is changed. It then records all
-- migrations as applied, so the automatic runner (run_pending_migrations()
-- in includes/functions.php) doesn't try them again.
--
-- Needs MariaDB 10.1.4 or newer (XAMPP and SkySQL MariaDB both are) for
-- ADD COLUMN / CREATE INDEX ... IF NOT EXISTS.
--
-- How to run: select the system's database (e.g. faculty201_repository)
-- in phpMyAdmin > SQL, paste this file, Go. Or from a terminal:
--   mysql -u root faculty201_repository < database/repair_schema.sql
--
-- The app also applies all of this by itself on the next page load; use
-- this script when that keeps failing (the PHP error log then says
-- "Database migration ... failed" with the reason).
-- =====================================================================

-- ------------------------------------------------------------- base tables (schema.sql)
CREATE TABLE IF NOT EXISTS users (
    user_id            INT AUTO_INCREMENT PRIMARY KEY,
    role               ENUM('admin','faculty','program_chair','dean') NOT NULL,
    full_name          VARCHAR(150) NOT NULL,
    email              VARCHAR(150) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NOT NULL,
    employment_type    ENUM('full_time','part_time') NULL,
    employment_status  ENUM('active','paused') NULL DEFAULT 'active',
    date_engaged       DATE NULL,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employment_history (
    history_id   INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id   INT NOT NULL,
    event_type   ENUM('engaged','paused','resumed') NOT NULL,
    event_date   DATE NOT NULL,
    remarks      VARCHAR(255) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS submission_requests (
    request_id          INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id          INT NOT NULL,
    document_type_hint  VARCHAR(40) NOT NULL,
    temp_file_path      VARCHAR(500) NOT NULL,
    status              ENUM('pending','chair_confirmed','dean_confirmed','fully_confirmed','uploaded','rejected') NOT NULL DEFAULT 'pending',
    chair_id            INT NULL,
    chair_confirmed_at  TIMESTAMP NULL,
    dean_id             INT NULL,
    dean_confirmed_at   TIMESTAMP NULL,
    rejected_by         INT NULL,
    rejection_reason    VARCHAR(500) NULL,
    submitted_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (chair_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (dean_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
    document_id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id           INT NOT NULL,
    faculty_id           INT NOT NULL,
    document_type        VARCHAR(40) NOT NULL,
    file_path            VARCHAR(500) NOT NULL,
    ocr_extracted_text   TEXT NULL,
    ocr_matched_name     VARCHAR(150) NULL,
    ocr_confidence_note  VARCHAR(255) NULL,
    filed_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES submission_requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    request_id       INT NULL,
    message          VARCHAR(500) NOT NULL,
    is_read          TINYINT(1) NOT NULL DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES submission_requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_requests_status ON submission_requests(status);
CREATE INDEX IF NOT EXISTS idx_requests_faculty ON submission_requests(faculty_id);
CREATE INDEX IF NOT EXISTS idx_documents_faculty ON documents(faculty_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, is_read);

-- ------------------------------------------------------------- users
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER must_change_password;
ALTER TABLE users ADD COLUMN IF NOT EXISTS program VARCHAR(20) NULL AFTER employment_type;
ALTER TABLE users ADD COLUMN IF NOT EXISTS college VARCHAR(20) NULL AFTER program;
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(64) NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS employee_id VARCHAR(30) NULL AFTER profile_picture;
ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(30) NULL AFTER employee_id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS academic_rank VARCHAR(60) NULL AFTER employment_type;
ALTER TABLE users ADD COLUMN IF NOT EXISTS specialization VARCHAR(150) NULL AFTER academic_rank;
CREATE INDEX IF NOT EXISTS idx_users_program ON users(role, college, program);

-- ------------------------------------------------------------- submission requests / documents
ALTER TABLE submission_requests MODIFY COLUMN document_type_hint VARCHAR(40) NOT NULL;
ALTER TABLE submission_requests ADD COLUMN IF NOT EXISTS expiration_date_hint DATE NULL AFTER document_type_hint;

ALTER TABLE documents MODIFY COLUMN document_type VARCHAR(40) NOT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS document_subtype VARCHAR(40) NULL AFTER document_type;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS academic_year VARCHAR(9) NULL AFTER document_subtype;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS semester TINYINT NULL AFTER academic_year;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS period_year SMALLINT NULL AFTER semester;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS title VARCHAR(255) NULL AFTER period_year;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS date_start DATE NULL AFTER title;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS date_end DATE NULL AFTER date_start;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS venue VARCHAR(255) NULL AFTER date_end;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS conducted_by VARCHAR(255) NULL AFTER venue;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS training_type VARCHAR(40) NULL AFTER conducted_by;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS training_level VARCHAR(40) NULL AFTER training_type;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS hours DECIMAL(6,1) NULL AFTER training_level;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS date_issued DATE NULL AFTER hours;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS expiration_date DATE NULL AFTER file_path;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active' AFTER file_path;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER status;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS archived_by INT NULL AFTER archived_at;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) NULL AFTER archived_by;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS archive_exempt TINYINT(1) NOT NULL DEFAULT 0 AFTER archive_reason;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER archive_exempt;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS deleted_by INT NULL AFTER deleted_at;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(255) NULL AFTER deleted_by;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS confidence_score DECIMAL(4,3) NULL AFTER ocr_confidence_note;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS predicted_category VARCHAR(40) NULL AFTER confidence_score;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS chosen_category VARCHAR(40) NULL AFTER predicted_category;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_low_confidence TINYINT(1) NOT NULL DEFAULT 0 AFTER chosen_category;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER is_low_confidence;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS reviewed_by INT NULL AFTER reviewed_at;
CREATE INDEX IF NOT EXISTS idx_documents_expiration ON documents(expiration_date);
CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(faculty_id, document_type, document_subtype);
CREATE INDEX IF NOT EXISTS idx_documents_low_confidence ON documents(is_low_confidence, reviewed_at, confidence_score);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents(status, faculty_id);
CREATE INDEX IF NOT EXISTS idx_documents_status_filed ON documents(status, filed_at);
CREATE INDEX IF NOT EXISTS idx_documents_training ON documents(document_type, status, date_start);

CREATE TABLE IF NOT EXISTS document_files (
    document_id  INT PRIMARY KEY,
    mime_type    VARCHAR(100) NOT NULL,
    file_size    INT NOT NULL,
    data         LONGBLOB NOT NULL,
    stored_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------- logins, alerts, activity
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id      INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL,
    user_id         INT NULL,
    ip_address      VARCHAR(45) NULL,
    was_successful  TINYINT(1) NOT NULL,
    attempted_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS was_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER was_successful;
CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email, attempted_at);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address, attempted_at);

CREATE TABLE IF NOT EXISTS login_lockouts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    lock_type     ENUM('account','ip') NOT NULL,
    lock_key      VARCHAR(190) NOT NULL,
    failed_count  INT NOT NULL,
    locked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until  DATETIME NOT NULL,
    cleared_at    DATETIME NULL,
    cleared_by    INT NULL,
    INDEX idx_login_lockouts_key (lock_type, lock_key, locked_until)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_alerts (
    alert_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    reason       VARCHAR(255) NOT NULL,
    ip_address   VARCHAR(45) NULL,
    is_reviewed  TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE INDEX IF NOT EXISTS idx_security_alerts_reviewed ON security_alerts(is_reviewed);

CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    user_role   VARCHAR(20) NULL,
    action      VARCHAR(40) NOT NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX IF NOT EXISTS idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at);

-- ------------------------------------------------------------- expiration alerts, settings
CREATE TABLE IF NOT EXISTS expiration_alerts_sent (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    document_id      INT NOT NULL,
    milestone        ENUM('60','30','7','expired') NOT NULL,
    expiration_date  DATE NOT NULL,
    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expiration_alert (document_id, milestone, expiration_date),
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key    VARCHAR(64) PRIMARY KEY,
    setting_value  VARCHAR(255) NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------- digital PDS
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
CREATE INDEX IF NOT EXISTS idx_pds_ld_faculty ON pds_learning_development(faculty_id);

CREATE TABLE IF NOT EXISTS pds_snapshots (
    snapshot_id  INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id   INT NOT NULL,
    data         LONGTEXT NOT NULL,
    reason       VARCHAR(150) NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE INDEX IF NOT EXISTS idx_pds_snapshots_faculty ON pds_snapshots(faculty_id, created_at);

-- ------------------------------------------------------------- profiles
CREATE TABLE IF NOT EXISTS academic_ranks (
    rank_id     INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60) NOT NULL UNIQUE,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- The standard ranks (INSERT IGNORE: ranks already there are left as they are)
INSERT IGNORE INTO academic_ranks (name, sort_order) VALUES
    ('N/A', 0),
    ('Instructor I', 10), ('Instructor II', 20), ('Instructor III', 30),
    ('Assistant Professor I', 40), ('Assistant Professor II', 50), ('Assistant Professor III', 60), ('Assistant Professor IV', 70),
    ('Associate Professor I', 80), ('Associate Professor II', 90), ('Associate Professor III', 100), ('Associate Professor IV', 110), ('Associate Professor V', 120),
    ('Professor I', 130), ('Professor II', 140), ('Professor III', 150), ('Professor IV', 160), ('Professor V', 170), ('Professor VI', 180);

CREATE TABLE IF NOT EXISTS profile_pictures (
    user_id     INT PRIMARY KEY,
    mime_type   VARCHAR(50) NOT NULL,
    file_size   INT NOT NULL,
    data        MEDIUMBLOB NOT NULL,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------- record the migrations as applied
CREATE TABLE IF NOT EXISTS schema_migrations (
    name        VARCHAR(190) PRIMARY KEY,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations (name) VALUES
    ('migration_add_expiration.sql'), ('migration_add_security_alerts.sql'), ('migration_201_contents_pds.sql'),
    ('migration_programs_colleges.sql'), ('migration_document_files.sql'), ('migration_classification_confidence.sql'),
    ('migration_activity_logs.sql'), ('migration_expiration_alerts.sql'), ('migration_document_removal.sql'),
    ('migration_password_management.sql'), ('migration_login_lockout.sql'), ('migration_search_indexes.sql'),
    ('migration_profile_reports_archive.sql');
