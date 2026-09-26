-- =====================================================================
-- Migration: add RBAC / suspicious-login-alert tracking
--
-- The capstone paper (Ch.3 3.1) lists "Role-Based Access Control (RBAC)
-- and suspicious login alerts" as one of the system's core modules, but
-- no table existed yet to support it. Added here as a separate,
-- non-destructive migration (same pattern as migration_add_expiration.sql)
-- rather than editing schema.sql directly.
--
-- Run this once against an existing database. New setups can just run
-- schema.sql, then migration_add_expiration.sql, then this file, in
-- that order.
-- =====================================================================

USE faculty201_repository;

-- Every login attempt, successful or not -- the audit trail the
-- suspicious-login screening logic (includes/functions.php,
-- flag_suspicious_login()) reads from.
CREATE TABLE login_attempts (
    attempt_id      INT AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(150) NOT NULL,
    user_id          INT NULL,
    ip_address       VARCHAR(45) NULL,
    was_successful   TINYINT(1) NOT NULL,
    attempted_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Flagged logins surfaced to the Admin (admin/security_alerts.php) and
-- pushed into the existing notifications table.
CREATE TABLE security_alerts (
    alert_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    reason        VARCHAR(255) NOT NULL,
    ip_address    VARCHAR(45) NULL,
    is_reviewed   TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_login_attempts_email ON login_attempts(email, attempted_at);
CREATE INDEX idx_security_alerts_reviewed ON security_alerts(is_reviewed);
