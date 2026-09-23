-- ==========================================================================
--  Migration 002 — sign-in log and user administration
--      mysql -u root -p marking < db/migrate-002.sql
--  A fresh installation does not need it — schema.sql already contains it all.
-- ==========================================================================

USE marking;

ALTER TABLE users
  ADD COLUMN last_login DATETIME DEFAULT NULL AFTER active;

CREATE TABLE IF NOT EXISTS login_log (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  user_id  INT          DEFAULT NULL,
  username VARCHAR(50)  NOT NULL,
  ok       TINYINT(1)   NOT NULL DEFAULT 0,
  ip       VARCHAR(45)  NOT NULL DEFAULT '',
  agent    VARCHAR(255) NOT NULL DEFAULT '',
  at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_at   (at),
  KEY ix_user (user_id, at),
  KEY ix_fail (ok, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
