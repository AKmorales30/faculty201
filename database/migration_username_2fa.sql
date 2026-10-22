-- =====================================================================
-- Username login + email two-factor codes (MySQL / MariaDB)
-- Applied automatically by run_pending_migrations() (includes/functions.php).
-- The Supabase version is database/supabase/04_username_2fa.sql.
--
--   users.username           what people log in with (set by the Admin);
--                            stored in lowercase (the collation ignores case)
--   users.email              now the two-factor email: optional, and used
--                            only once email_verified_at is set
--   login_attempts.username  the username typed (email stays for old rows)
--   login_otps               one-time codes: login, setting up / changing
--                            the email, password reset (hashed, never plain)
-- Existing accounts get a username automatically (assign_missing_usernames()).
-- =====================================================================

USE faculty201_repository;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(30) NULL AFTER full_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER email;
ALTER TABLE users MODIFY COLUMN email VARCHAR(150) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_username ON users (username);

ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS username VARCHAR(150) NULL AFTER email;
ALTER TABLE login_attempts MODIFY COLUMN email VARCHAR(150) NULL;
CREATE INDEX IF NOT EXISTS idx_login_attempts_username ON login_attempts (username, attempted_at);

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
