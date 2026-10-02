-- =====================================================================
-- Migration: activity logs
--
-- Objective 3c / section 3.5.4 / Fig. 4 "View Activity / Login Logs".
-- One row per user action -- login, logout, upload, document view,
-- search, report generation, account and PDS changes -- written by
-- log_activity() in includes/functions.php and shown read-only to the
-- Admin in admin/activity_logs.php. Login attempts (including failed
-- ones) stay in the existing login_attempts table, which is unchanged.
--
--   user_id    NULL when no one is signed in
--   user_role  role at the time of the action (kept even if it changes later)
--   action     short code, e.g. LOGIN, UPLOAD, VIEW_DOCUMENT (activity_actions())
--   details    human-readable description with the relevant IDs
--
-- Applied automatically by run_pending_migrations() in
-- includes/functions.php (recorded in schema_migrations), which skips
-- "already exists" errors so a partly-applied run can simply be retried.
-- To run it by hand instead: select the database first, then run this
-- file once.
-- =====================================================================

CREATE TABLE activity_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NULL,
    user_role     VARCHAR(20) NULL,
    action        VARCHAR(40) NOT NULL,
    details       TEXT NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_action ON activity_logs(action);
CREATE INDEX idx_activity_logs_created ON activity_logs(created_at);
