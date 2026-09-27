-- =====================================================================
-- AI-BASED FACULTY 201-FILE REPOSITORY SYSTEM
-- Database Schema
-- College of Computing Studies, Universidad de Manila
-- =====================================================================

CREATE DATABASE IF NOT EXISTS faculty201_repository
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE faculty201_repository;

-- ---------------------------------------------------------------------
-- USERS: Admin, Faculty, Program Chair, Dean
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id            INT AUTO_INCREMENT PRIMARY KEY,
    role                ENUM('admin','faculty','program_chair','dean') NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    email               VARCHAR(150) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,

    -- Only meaningful for role = 'faculty'
    employment_type     ENUM('full_time','part_time') NULL,
    employment_status    ENUM('active','paused') NULL DEFAULT 'active',
    date_engaged        DATE NULL,

    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- EMPLOYMENT HISTORY: tracks pauses/resumptions for part-time faculty
-- so continuity is preserved within one existing record (Ch.1 requirement)
-- ---------------------------------------------------------------------
CREATE TABLE employment_history (
    history_id          INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id          INT NOT NULL,
    event_type          ENUM('engaged','paused','resumed') NOT NULL,
    event_date          DATE NOT NULL,
    remarks             VARCHAR(255) NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SUBMISSION REQUESTS: upload log. Faculty uploads are filed immediately
-- (status 'uploaded') and the Program Chair + Dean are notified -- there
-- is no approval step. The other statuses and chair/dean/rejection columns
-- are kept only for records created under the old approval workflow.
-- ---------------------------------------------------------------------
CREATE TABLE submission_requests (
    request_id          INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id          INT NOT NULL,
    document_type_hint  ENUM('TOR','Diploma','Certificate') NOT NULL,
    temp_file_path       VARCHAR(500) NOT NULL,

    status               ENUM(
                            'pending',              -- just submitted, awaiting both confirmations
                            'chair_confirmed',       -- program chair confirmed, waiting on dean
                            'dean_confirmed',        -- dean confirmed, waiting on chair
                            'fully_confirmed',       -- both confirmed -> ready for / done with OCR filing
                            'uploaded',              -- OCR filing complete, now in documents table
                            'rejected'
                          ) NOT NULL DEFAULT 'pending',

    chair_id             INT NULL,
    chair_confirmed_at    TIMESTAMP NULL,
    dean_id               INT NULL,
    dean_confirmed_at     TIMESTAMP NULL,

    rejected_by          INT NULL,
    rejection_reason     VARCHAR(500) NULL,

    submitted_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (chair_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (dean_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- DOCUMENTS: the official, filed 201-file repository record.
-- Created at the moment a faculty member uploads a document.
-- ---------------------------------------------------------------------
CREATE TABLE documents (
    document_id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id            INT NOT NULL,
    faculty_id            INT NOT NULL,
    document_type         ENUM('TOR','Diploma','Certificate') NOT NULL,
    file_path              VARCHAR(500) NOT NULL,     -- e.g. uploads/12/Certificate/2026-08-06_cert.jpg
    ocr_extracted_text     TEXT NULL,
    ocr_matched_name       VARCHAR(150) NULL,
    ocr_confidence_note     VARCHAR(255) NULL,
    filed_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (request_id) REFERENCES submission_requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS: submission alerts + confirmation-status updates
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    notification_id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL,
    request_id             INT NULL,
    message                VARCHAR(500) NOT NULL,
    is_read                TINYINT(1) NOT NULL DEFAULT 0,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES submission_requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Helpful indexes
CREATE INDEX idx_requests_status ON submission_requests(status);
CREATE INDEX idx_requests_faculty ON submission_requests(faculty_id);
CREATE INDEX idx_documents_faculty ON documents(faculty_id);
CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);
