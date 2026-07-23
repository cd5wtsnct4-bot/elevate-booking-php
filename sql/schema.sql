-- Elevate SJC Booking Portal — MySQL schema
-- Import this via cPanel > phpMyAdmin (or `mysql < schema.sql` locally).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('admin', 'client') NOT NULL DEFAULT 'client',
    phone VARCHAR(40) NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_users_email (email),
    KEY idx_users_role (role),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- One-time links so admin-provisioned accounts can set their own password
-- (there is no public registration form, so plaintext passwords are never
-- assigned directly by the admin).
CREATE TABLE IF NOT EXISTS password_setup_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pst_token_hash (token_hash),
    KEY idx_pst_user (user_id),
    CONSTRAINT fk_pst_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- DB-backed login lockout (no external rate-limiting middleware available).
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_email_time (email, created_at),
    KEY idx_login_attempts_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Connected Microsoft 365 calendars. Exactly two rows are expected in
-- practice (admin@ and info@ as a shared mailbox), but the schema doesn't
-- hardcode that limit.
CREATE TABLE IF NOT EXISTS calendar_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(60) NOT NULL,
    mailbox_email VARCHAR(190) NOT NULL,
    is_shared_mailbox TINYINT(1) NOT NULL DEFAULT 0,
    access_token_enc TEXT NULL,
    refresh_token_enc TEXT NULL,
    token_expires_at DATETIME NULL,
    connected_by INT UNSIGNED NULL,
    last_sync_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_calendar_mailbox (mailbox_email),
    CONSTRAINT fk_calendar_connected_by FOREIGN KEY (connected_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    calendar_account_id INT UNSIGNED NULL,
    type ENUM('meeting', 'training') NOT NULL,
    booking_date DATE NOT NULL,
    title VARCHAR(190) NOT NULL,
    notes TEXT NULL,
    status ENUM('pending', 'approved', 'declined', 'cancelled') NOT NULL DEFAULT 'pending',
    ms_event_id VARCHAR(255) NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_bookings_date (booking_date),
    KEY idx_bookings_status (status),
    KEY idx_bookings_user (user_id),
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_calendar FOREIGN KEY (calendar_account_id) REFERENCES calendar_accounts (id) ON DELETE SET NULL,
    CONSTRAINT fk_bookings_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Manually blocked dates (holidays, admin unavailability, etc).
-- calendar_account_id NULL = blocks the date across all calendars.
CREATE TABLE IF NOT EXISTS blocked_dates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_account_id INT UNSIGNED NULL,
    blocked_date DATE NOT NULL,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_blocked_date (blocked_date),
    CONSTRAINT fk_blocked_calendar FOREIGN KEY (calendar_account_id) REFERENCES calendar_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_blocked_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
