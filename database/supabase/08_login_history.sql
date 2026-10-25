-- =====================================================================
-- Login activity: login history, trusted devices, IP location cache
-- (PostgreSQL / Supabase)
--
-- Run in Supabase: SQL Editor > New query, paste this file, Run. Safe to
-- run again. The MySQL / MariaDB version is
-- database/migration_login_history.sql (applied automatically by the app).
--
--   login_history     every login attempt (includes/login_activity.php).
--                     user_id is NULL for a username that doesn't exist --
--                     the typed text is never stored (people sometimes type
--                     their password there). Times are UTC (timestamptz),
--                     shown in Asia/Manila. Deleted after 90 days.
--   trusted_devices   browsers that logged in before (the SHA-256 of a
--                     random token kept in an HttpOnly cookie). Only for
--                     new-device alerts -- a trusted device does NOT skip
--                     two-factor authentication. Deleted after 90 days unused.
--   ip_locations      approximate locations from ipinfo.io, cached per IP.
--
-- login_attempts stays as it is: the brute-force lockout counts it by the
-- username typed (also for usernames that don't exist, so a lock never
-- reveals whether an account exists), which login_history must not store.
-- =====================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS login_history (
    id                bigserial    PRIMARY KEY,
    user_id           integer      NULL REFERENCES users (user_id) ON DELETE CASCADE,
    ip_address        inet         NULL,
    device_label      varchar(100) NULL,          -- "Windows · Chrome", "iOS 17 · Safari"
    location          varchar(100) NULL,          -- approximate, e.g. "Manila, Metro Manila, PH"
    status            varchar(30)  NOT NULL CHECK (status IN ('success', 'failed_password', 'failed_2fa', 'blocked_locked', 'blocked_deactivated')),
    new_device        boolean      NOT NULL DEFAULT false,   -- right password from a browser without a device cookie
    location_checked  boolean      NOT NULL DEFAULT false,   -- the location lookup ran (found or not)
    created_at        timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_login_history_user ON login_history (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_history_ip ON login_history (ip_address, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_history_unlocated ON login_history (created_at) WHERE location_checked = false;

CREATE TABLE IF NOT EXISTS trusted_devices (
    id            bigserial    PRIMARY KEY,
    user_id       integer      NOT NULL REFERENCES users (user_id) ON DELETE CASCADE,
    token_hash    char(64)     NOT NULL,          -- SHA-256 (hex) of the cookie's token; the token itself is never stored
    device_label  varchar(100) NULL,
    first_seen    timestamptz  NOT NULL DEFAULT now(),
    last_seen     timestamptz  NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_trusted_devices_token ON trusted_devices (user_id, token_hash);
CREATE INDEX IF NOT EXISTS idx_trusted_devices_seen ON trusted_devices (last_seen);

CREATE TABLE IF NOT EXISTS ip_locations (
    ip_address    inet         PRIMARY KEY,
    location      varchar(100) NULL,              -- NULL: looked up, nothing found
    looked_up_at  timestamptz  NOT NULL DEFAULT now()
);

-- Same API lockout as every other table (01_schema.sql)
ALTER TABLE login_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE trusted_devices ENABLE ROW LEVEL SECURITY;
ALTER TABLE ip_locations ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE login_history, trusted_devices, ip_locations FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;

INSERT INTO schema_migrations (name) VALUES ('migration_login_history.sql') ON CONFLICT (name) DO NOTHING;

COMMIT;
