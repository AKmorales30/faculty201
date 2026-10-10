-- =====================================================================
-- Migration: AI features (Google Gemini)
--
--   ai_reports         each AI summary generated on the Seminar & Training
--                      Report: who, the filters (JSON), and the summary
--                      (JSON: overview, key findings, faculty needing
--                      attention, recommendations). filters_hash finds the
--                      saved summary for the same filters and viewer, so
--                      Gemini is called only on Generate / Regenerate.
--   ai_chat_messages   the chatbot conversation of each user. Users clear
--                      their own history (DELETE).
--   ai_usage_log       one row per AI call: user, feature (report /
--                      reminder / chatbot), success, short error code.
--                      Never the API key, the prompt or the answer.
--   ai_reminders       reminders sent to faculty (rule-based -- who and
--                      why is decided by SQL; Gemini only words the
--                      message). condition_key fingerprints the reason, so
--                      the same reminder is repeated only after
--                      REMINDER_REPEAT_DAYS or when the reason changes.
--                      resolved_at is set once the condition clears (or a
--                      newer reminder of the same type replaces it);
--                      dismissed_at hides it from the dashboard card.
--   notifications.link optional page a notification points to (e.g. the
--                      upload page), shown as a link on the Notifications page.
--
-- Safe to run any number of times (IF NOT EXISTS everywhere; nothing is
-- dropped or changed). Needs MariaDB 10.0.2+ (XAMPP and SkySQL are) for
-- ADD COLUMN / CREATE INDEX ... IF NOT EXISTS.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php on the next page load. To run it by hand instead:
-- phpMyAdmin > select the database (faculty201_repository) > SQL > paste
-- this file > Go. Or: mysql -u root faculty201_repository < database/migration_ai_features.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_reports (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    generated_by   INT NULL,
    report_type    VARCHAR(30) NOT NULL DEFAULT 'training',
    filters        JSON NULL,
    filters_hash   CHAR(64) NOT NULL,
    summary_text   MEDIUMTEXT NOT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_ai_reports_lookup ON ai_reports(filters_hash, created_at);

CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    role         ENUM('user','assistant') NOT NULL,
    message      TEXT NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_ai_chat_user ON ai_chat_messages(user_id, created_at);

CREATE TABLE IF NOT EXISTS ai_usage_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    feature         VARCHAR(30) NOT NULL,
    success         TINYINT(1) NOT NULL,
    error_message   VARCHAR(255) NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_ai_usage_created ON ai_usage_log(created_at);
CREATE INDEX IF NOT EXISTS idx_ai_usage_user ON ai_usage_log(user_id, feature, created_at);

CREATE TABLE IF NOT EXISTS ai_reminders (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    reminder_type    VARCHAR(30) NOT NULL,
    condition_key    VARCHAR(190) NOT NULL,
    reason           VARCHAR(500) NOT NULL,
    message          VARCHAR(500) NOT NULL,
    link             VARCHAR(255) NULL,
    ai_generated     TINYINT(1) NOT NULL DEFAULT 0,
    notification_id  INT NULL,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    dismissed_at     DATETIME NULL,
    resolved_at      DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_ai_reminders_user ON ai_reminders(user_id, reminder_type, resolved_at);
CREATE INDEX IF NOT EXISTS idx_ai_reminders_open ON ai_reminders(reminder_type, resolved_at);

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS link VARCHAR(255) NULL AFTER message;
