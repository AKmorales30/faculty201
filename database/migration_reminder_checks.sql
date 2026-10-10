-- =====================================================================
-- Migration: per-account reminder checks
--
--   ai_reminder_checks  when each account's reminders were last checked
--                       (includes/ai_reminders.php). checked_at is set
--                       only after the check of that account finished, so
--                       an account whose check failed -- or a new account
--                       created after the day's run -- is checked on its
--                       own next dashboard visit instead of waiting for
--                       tomorrow. started_at keeps two page loads from
--                       checking the same account at once. It also tells
--                       the dashboard whether "You're all set" is true.
--
-- Safe to run any number of times. Applied automatically by
-- run_pending_migrations() in includes/functions.php on the next page
-- load. By hand: phpMyAdmin > faculty201_repository > SQL > paste > Go, or
--   mysql -u root faculty201_repository < database/migration_reminder_checks.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS ai_reminder_checks (
    user_id      INT PRIMARY KEY,
    checked_at   DATETIME NULL,
    started_at   DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
