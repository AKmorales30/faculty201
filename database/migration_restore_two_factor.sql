-- =====================================================================
-- Bring back the emailed login codes (MySQL / MariaDB)
-- Applied automatically by run_pending_migrations() (includes/functions.php).
-- For a database where the removal (an earlier migration_remove_two_factor.sql)
-- ran: adds back login_otps and users.email_verified_at. Does nothing where
-- migration_username_2fa.sql already has them. Every account verifies its
-- email again at the next login. Supabase: database/supabase/05_restore_two_factor.sql.
-- =====================================================================

USE faculty201_repository;

ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;

CREATE TABLE IF NOT EXISTS login_otps (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    purpose       ENUM('login', 'setup_email', 'change_email', 'reset_password') NOT NULL,
    email         VARCHAR(150) NOT NULL,
    code_hash     VARCHAR(255) NOT NULL,
    attempts      SMALLINT NOT NULL DEFAULT 0,
    sent_count    SMALLINT NOT NULL DEFAULT 1,
    ip_address    VARCHAR(45) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_sent_at  DATETIME NOT NULL,
    expires_at    DATETIME NOT NULL,
    used_at       DATETIME NULL,
    INDEX idx_login_otps_user (user_id, purpose, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
