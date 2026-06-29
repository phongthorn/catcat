-- Panda Portal — MySQL schema (customer-facing rental layer)
-- Runs inside the `panda` database created by docker-compose (MYSQL_DATABASE=panda).
--   docker exec -i panda-mysql mysql -u root -p"$PW" panda < portal/db/schema.sql
--
-- Design notes:
--  * The Rust server stays the source of truth for "which devices physically exist"
--    and "which stream sessions are live".
--  * MySQL adds the BUSINESS layer the Rust server has no concept of:
--    who a user is, which serials they may control, and which browser session
--    owns which Rust stream (for the nginx auth_request ownership check).

-- Customers and admins who can log in to the portal
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,                       -- password_hash() output
  role          ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  credits       DECIMAL(10,2) NOT NULL DEFAULT 0.00,         -- prepaid balance topped up by admin
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Known devices (label is a human-friendly name; serial matches adb)
CREATE TABLE IF NOT EXISTS devices (
  serial      VARCHAR(128) PRIMARY KEY,
  label       VARCHAR(128),
  is_rentable TINYINT(1) NOT NULL DEFAULT 1,                 -- shown in the rental catalog
  tier        ENUM('VIP','KVIP','SVIP','XVIP') NULL,         -- rental tier assigned by admin
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Ownership / lease: which user may use which device, until when.
-- The portal filters on this for EVERY page and thumbnail.
-- One active lease per device is enforced in app logic (a device can be re-rented
-- after its previous lease expires).
CREATE TABLE IF NOT EXISTS leases (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  serial     VARCHAR(128) NOT NULL,
  tier       VARCHAR(16) NULL,                               -- tier that was rented (VIP/KVIP/SVIP/XVIP)
  expires_at DATETIME NULL,                                  -- NULL = no expiry
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)       ON DELETE CASCADE,
  FOREIGN KEY (serial)  REFERENCES devices(serial) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_serial (user_id, serial)
);

-- Credit top-up requests submitted by users (manual slip verification).
-- Admin reviews each request and approves or rejects.
CREATE TABLE IF NOT EXISTS topup_requests (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  slip_path   VARCHAR(255) NOT NULL,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  note        VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL,
  reviewed_by INT NULL,
  FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Maps a Rust stream session_id to the user who opened it.
-- Written when PHP provisions a session via Rust /api/session/:serial.
-- Read by the nginx auth_request endpoint to authorise /ws/{session_id}.
CREATE TABLE IF NOT EXISTS sessions (
  session_id VARCHAR(64) PRIMARY KEY,
  user_id    INT NOT NULL,
  serial     VARCHAR(128) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Audit trail: login/logout, stream start, admin actions, etc.
-- username is stored denormalized so logs survive user deletion.
CREATE TABLE IF NOT EXISTS activity_logs (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  username   VARCHAR(64) NULL,
  action     VARCHAR(64) NOT NULL,
  serial     VARCHAR(128) NULL,
  detail     TEXT NULL,
  ip         VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_user    (user_id),
  INDEX idx_al_created (created_at DESC)
);
