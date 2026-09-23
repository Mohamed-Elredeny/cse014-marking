-- ==========================================================================
--  Migration 003 — administrative audit trail and sign-in throttle indexes
--
--  For installations created before this release:
--      mysql -u root -p marking < db/migrate-003.sql
--
--  A fresh installation does not need it — schema.sql already contains
--  everything below.
-- ==========================================================================

USE marking;

-- --------------------------------------------------------------------------
-- Interface language per account.
--
-- It is recorded at each sign-in from the language the person is actually
-- reading, and is used to compose their notification emails. No
-- administration is needed — the preference follows the interface.
-- --------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN lang ENUM('ar','en') NOT NULL DEFAULT 'ar' AFTER notify;

-- --------------------------------------------------------------------------
-- Administrative actions.
--
-- Grade changes already leave a trail in `marks.reason`. This table records
-- everything else a Main TA can do: opening and closing labs, editing
-- rubrics, changing the schedule, moving students between assistants, and
-- account administration.
--
-- `actor_name` is denormalised so an entry stays readable after the account
-- is removed. `target` holds the machine identifier and `target_type` lets
-- the interface render it in the reader's language.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  actor_id     INT          NOT NULL DEFAULT 0,
  actor_name   VARCHAR(120) NOT NULL DEFAULT '',
  action       VARCHAR(40)  NOT NULL,              -- lab.open, rubric.save, user.create, ...
  target       VARCHAR(60)  NOT NULL DEFAULT '',   -- machine identifier
  target_type  VARCHAR(20)  NOT NULL DEFAULT '',   -- lab | user | student | section
  target_label VARCHAR(120) NOT NULL DEFAULT '',   -- readable name, when there is one
  detail       VARCHAR(255) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_at     (at),
  KEY ix_actor  (actor_id, at),
  KEY ix_action (action, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- The sign-in throttle counts recent failures in `login_log`, so the limits
-- survive a cleared cookie or a fresh session. These indexes keep both
-- counting queries away from a table scan.
--
-- MySQL has no CREATE INDEX IF NOT EXISTS; re-running this file reports
-- "Duplicate key name", which is safe to ignore.
-- --------------------------------------------------------------------------
ALTER TABLE login_log
  ADD KEY ix_throttle_account (username, ip, ok, at),
  ADD KEY ix_throttle_ip      (ip, ok, at);
