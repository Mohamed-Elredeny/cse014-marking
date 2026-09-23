-- ==========================================================================
--  Migration 001 — email notifications
--  For installations created before this release:
--      mysql -u root -p marking < db/migrate-001.sql
--  A fresh installation does not need it — schema.sql already contains it all.
-- ==========================================================================

USE marking;

-- The account email address, used for notifications.
ALTER TABLE users
  ADD COLUMN email VARCHAR(160) NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN notify TINYINT(1) NOT NULL DEFAULT 1 AFTER email;

-- Mail queue — delivery happens in tools/cron.php, not inside the request.
CREATE TABLE IF NOT EXISTS mail_queue (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  to_email   VARCHAR(160) NOT NULL,
  to_name    VARCHAR(120) NOT NULL DEFAULT '',
  subject    VARCHAR(200) NOT NULL,
  body       MEDIUMTEXT   NOT NULL,
  state      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  tries      INT          NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at    DATETIME     DEFAULT NULL,
  KEY ix_state (state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
