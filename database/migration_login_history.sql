-- =====================================================================
-- Login activity: login history, trusted devices, IP location cache (MySQL / MariaDB)
-- Applied automatically by run_pending_migrations() (includes/functions.php).
-- See database/supabase/08_login_history.sql for what each table holds.
-- TIMESTAMP columns are kept in UTC by MySQL and shown in the session's +08:00.
-- =====================================================================

USE faculty201_repository;

CREATE TABLE IF NOT EXISTS login_history (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NULL,
    ip_address        VARCHAR(45) NULL,
    device_label      VARCHAR(100) NULL,
    location          VARCHAR(100) NULL,
    status            ENUM('success', 'failed_password', 'failed_2fa', 'blocked_locked', 'blocked_deactivated') NOT NULL,
    new_device        TINYINT(1) NOT NULL DEFAULT 0,
    location_checked  TINYINT(1) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_history_user (user_id, created_at),
    INDEX idx_login_history_ip (ip_address, created_at),
    INDEX idx_login_history_unlocated (location_checked, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS trusted_devices (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    device_label  VARCHAR(100) NULL,
    first_seen    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trusted_devices_token (user_id, token_hash),
    INDEX idx_trusted_devices_seen (last_seen),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_locations (
    ip_address    VARCHAR(45) PRIMARY KEY,
    location      VARCHAR(100) NULL,
    looked_up_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
