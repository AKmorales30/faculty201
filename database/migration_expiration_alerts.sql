-- =====================================================================
-- Migration: expiration alerts
--
-- Objective 3a / section 1.5 / Fig. 2: the faculty member receives an
-- expiration alert. check_expiration_alerts() (includes/functions.php)
-- notifies the document's owner and every Admin, through the existing
-- notifications table, 60, 30 and 7 days before a document expires and
-- on the day it expires.
--
--   expiration_alerts_sent  one row per alert sent. The UNIQUE key makes
--                           each milestone go out once per document and
--                           expiration date: a new expiration date (a
--                           re-upload is a new document) starts fresh.
--   system_settings         small key/value store; holds the date the
--                           check last ran so it runs at most once a day.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

CREATE TABLE expiration_alerts_sent (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    document_id       INT NOT NULL,
    milestone         ENUM('60','30','7','expired') NOT NULL,
    expiration_date   DATE NOT NULL,
    sent_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expiration_alert (document_id, milestone, expiration_date),
    FOREIGN KEY (document_id) REFERENCES documents(document_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key     VARCHAR(64) PRIMARY KEY,
    setting_value   VARCHAR(255) NULL,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
