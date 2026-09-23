-- ==========================================================================
--  Marking — database schema
--  MySQL 5.7+ / MariaDB 10.3+
--  Run with:  mysql -u root -p < db/schema.sql
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS marking
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE marking;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log, login_log, mail_queue, change_requests, marks,
                     lab_assignments, lab_rubric, labs,
                     students, section_tas, sections, settings, users;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------- users
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL DEFAULT '',   -- for notifications
  notify        TINYINT(1)   NOT NULL DEFAULT 1,
  -- Interface language, recorded at each sign-in and used to compose the
  -- person's notification emails.
  lang          ENUM('ar','en') NOT NULL DEFAULT 'ar',
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('ta','main') NOT NULL DEFAULT 'ta',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  last_login    DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Who signed in, when, and from where — failed attempts included.
-- The sign-in throttle counts recent failures here, so its limits survive a
-- cleared cookie or a new session.
CREATE TABLE login_log (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  user_id  INT          DEFAULT NULL,
  username VARCHAR(50)  NOT NULL,
  ok       TINYINT(1)   NOT NULL DEFAULT 0,
  ip       VARCHAR(45)  NOT NULL DEFAULT '',
  agent    VARCHAR(255) NOT NULL DEFAULT '',
  at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_at                (at),
  KEY ix_user              (user_id, at),
  KEY ix_fail              (ok, at),
  KEY ix_throttle_account  (username, ip, ok, at),
  KEY ix_throttle_ip       (ip, ok, at)
) ENGINE=InnoDB;

-- Administrative actions.
--
-- Grade changes leave their trail in `marks.reason`; this table records
-- everything else a Main TA can do — lab state, rubrics, the schedule,
-- reassignment, and account administration.
--
-- `actor_name` is denormalised so an entry stays readable after the account is
-- removed. `target` holds the machine identifier and `target_type` lets the
-- interface render it in the reader's language.
CREATE TABLE audit_log (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  actor_id     INT          NOT NULL DEFAULT 0,
  actor_name   VARCHAR(120) NOT NULL DEFAULT '',
  action       VARCHAR(40)  NOT NULL,              -- lab.open, rubric.save, user.create, ...
  target       VARCHAR(60)  NOT NULL DEFAULT '',
  target_type  VARCHAR(20)  NOT NULL DEFAULT '',   -- lab | user | student | section
  target_label VARCHAR(120) NOT NULL DEFAULT '',
  detail       VARCHAR(255) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_at     (at),
  KEY ix_actor  (actor_id, at),
  KEY ix_action (action, at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- sections
CREATE TABLE sections (
  id    VARCHAR(10)  PRIMARY KEY,
  name  VARCHAR(60)  NOT NULL,
  day   VARCHAR(20)  NOT NULL DEFAULT '',
  time  VARCHAR(10)  NOT NULL DEFAULT '',
  room  VARCHAR(40)  NOT NULL DEFAULT '',
  sort  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- The assistants responsible for each section.
-- Order matters: the student distribution follows it.
CREATE TABLE section_tas (
  section_id VARCHAR(10) NOT NULL,
  ta_id      INT         NOT NULL,
  sort       INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (section_id, ta_id),
  KEY ix_ta (ta_id),
  CONSTRAINT fk_st_sec FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_st_ta  FOREIGN KEY (ta_id)      REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- students
CREATE TABLE students (
  id         VARCHAR(20)  PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  -- Normalised copy of the name (أ إ آ→ا, ة→ه, ى→ي) so search matches
  -- regardless of hamza and final-letter spelling.
  name_norm  VARCHAR(120) NOT NULL DEFAULT '',
  email      VARCHAR(120) NOT NULL DEFAULT '',
  section_id VARCHAR(10)  NOT NULL,
  sort       INT          NOT NULL DEFAULT 0,   -- position within the section
  KEY ix_sec  (section_id, sort),
  KEY ix_name (name_norm),
  KEY ix_mail (email),
  CONSTRAINT fk_stu_sec FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -------------------------------------------------------------------- labs
CREATE TABLE labs (
  id             INT         PRIMARY KEY,
  title          VARCHAR(60) NOT NULL,
  -- NULL = follow the weekly schedule; otherwise a manual override.
  state_override ENUM('open','closed') DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE lab_rubric (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  lab_id INT          NOT NULL,
  code   VARCHAR(10)  NOT NULL,      -- r1, r2, ...
  text   VARCHAR(255) NOT NULL,
  points INT          NOT NULL DEFAULT 1,
  sort   INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_item (lab_id, code),
  CONSTRAINT fk_rub_lab FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Distribution overrides — used when an assistant is absent.
CREATE TABLE lab_assignments (
  lab_id     INT         NOT NULL,
  student_id VARCHAR(20) NOT NULL,
  ta_id      INT         NOT NULL,
  PRIMARY KEY (lab_id, student_id),
  KEY ix_ta (lab_id, ta_id),
  CONSTRAINT fk_asg_lab FOREIGN KEY (lab_id)     REFERENCES labs(id)     ON DELETE CASCADE,
  CONSTRAINT fk_asg_stu FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_asg_ta  FOREIGN KEY (ta_id)      REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------ grades
CREATE TABLE marks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  student_id  VARCHAR(20) NOT NULL,
  lab_id      INT         NOT NULL,
  section_id  VARCHAR(10) NOT NULL,
  status      ENUM('present','late','absent') NOT NULL DEFAULT 'present',
  grade       INT         NOT NULL DEFAULT 0,
  items       TEXT        NOT NULL,              -- JSON: ["r1","r2"]
  note        VARCHAR(500) NOT NULL DEFAULT '',
  reason      VARCHAR(500) NOT NULL DEFAULT '',  -- reason for an amendment
  after_close TINYINT(1)  NOT NULL DEFAULT 0,
  by_main     TINYINT(1)  NOT NULL DEFAULT 0,
  via_request INT         DEFAULT NULL,
  ta_id       INT         NOT NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- This is what keeps a replayed offline queue from duplicating entries.
  UNIQUE KEY uq_mark (student_id, lab_id),
  KEY ix_lab      (lab_id),
  KEY ix_sec_lab  (section_id, lab_id),
  KEY ix_ta_lab   (ta_id, lab_id),
  KEY ix_updated  (updated_at),
  CONSTRAINT fk_mk_stu FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_mk_lab FOREIGN KEY (lab_id)     REFERENCES labs(id)     ON DELETE CASCADE,
  CONSTRAINT fk_mk_ta  FOREIGN KEY (ta_id)      REFERENCES users(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------- change requests
CREATE TABLE change_requests (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  student_id    VARCHAR(20) NOT NULL,
  lab_id        INT         NOT NULL,
  section_id    VARCHAR(10) NOT NULL,
  status        ENUM('present','late','absent') NOT NULL DEFAULT 'present',
  grade         INT         NOT NULL DEFAULT 0,
  items         TEXT        NOT NULL,
  reason        VARCHAR(500) NOT NULL,
  ta_id         INT         NOT NULL,
  prev_grade    INT         DEFAULT NULL,
  prev_ta_id    INT         DEFAULT NULL,
  state         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  decided_by    INT         DEFAULT NULL,
  decided_at    DATETIME    DEFAULT NULL,
  decision_note VARCHAR(500) NOT NULL DEFAULT '',
  created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_state (state, created_at),
  KEY ix_ta    (ta_id, created_at),
  KEY ix_stu   (student_id, lab_id, state),
  CONSTRAINT fk_rq_stu FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_rq_lab FOREIGN KEY (lab_id)     REFERENCES labs(id)     ON DELETE CASCADE,
  CONSTRAINT fk_rq_ta  FOREIGN KEY (ta_id)      REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------- mail queue
-- Messages are sent by tools/cron.php rather than from inside the request,
-- so saving a grade never waits on a remote mail server.
CREATE TABLE mail_queue (
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
) ENGINE=InnoDB;

-- ---------------------------------------------------------------- settings
CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB;

-- The weekly lab schedule.
INSERT INTO settings (k, v) VALUES
  ('schedule', '{"startDate":"2026-08-31","weekDays":7,"auto":true}')
ON DUPLICATE KEY UPDATE v = VALUES(v);
