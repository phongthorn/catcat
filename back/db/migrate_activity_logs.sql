-- Run once to add activity_logs to an existing database:
--   docker exec -i catcat-mysql mysql -u root -p"$PW" catcat < back/db/migrate_activity_logs.sql

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
