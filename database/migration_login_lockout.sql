-- =====================================================================
-- Migration: brute-force login protection
--
-- After MAX_FAILED_ATTEMPTS failures for one email, or
-- MAX_IP_FAILED_ATTEMPTS from one IP address, within
-- FAILED_ATTEMPT_WINDOW_MINUTES, further logins are refused for
-- LOCKOUT_MINUTES (config/config.php; login_process.php). Failures are
-- counted with time-window queries on login_attempts.
--
--   login_attempts.was_blocked  1 = refused because of a lock, password
--                               not checked. Shown in Activity Logs >
--                               Login Attempts; not counted as a failure,
--                               so retrying during a lock doesn't extend it.
--   idx_login_attempts_ip       for the per-IP count (the per-email index
--                               idx_login_attempts_email already exists)
--   login_lockouts              one row per lock: when it ends, and if the
--                               Admin cleared it early. Lets each lock
--                               notify the Admins once, and the Admin unlock.
--
-- Nothing existing is changed or deleted.
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

ALTER TABLE login_attempts ADD COLUMN was_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER was_successful;

CREATE INDEX idx_login_attempts_ip ON login_attempts(ip_address, attempted_at);

CREATE TABLE login_lockouts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    lock_type       ENUM('account','ip') NOT NULL,
    lock_key        VARCHAR(190) NOT NULL,          -- the email entered (lowercase), or the IP address
    failed_count    INT NOT NULL,
    locked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until    DATETIME NOT NULL,
    cleared_at      DATETIME NULL,                  -- set when the Admin unlocks early
    cleared_by      INT NULL,
    INDEX idx_login_lockouts_key (lock_type, lock_key, locked_until)
) ENGINE=InnoDB;
