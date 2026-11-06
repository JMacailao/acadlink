-- ==========================================================
-- College Grading System: MySQL tables (run once)
--
-- phpMyAdmin → select your database → SQL → paste this file → Go.
-- Works on MySQL 5.7+ and MariaDB 10.3+. Running it again is safe:
-- it only creates what is missing and never deletes data.
-- ==========================================================

-- Every record of the system: students, grades, sections, users, settings…
-- col = the list ("grades"), id = the record id, data = the record (JSON).
CREATE TABLE IF NOT EXISTS docs (
  col  VARCHAR(64)  NOT NULL,
  id   VARCHAR(191) NOT NULL,
  data JSON         NOT NULL,
  -- Copies of the fields the pages search on most, so those searches use an index
  f_assignmentId VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.assignmentId'))) STORED,
  f_teacherDocId VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.teacherDocId'))) STORED,
  f_studentId    VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.studentId'))) STORED,
  f_schoolYear   VARCHAR(64)  GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.schoolYear'))) STORED,
  f_yearLevel    VARCHAR(64)  GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.yearLevel'))) STORED,
  f_sectionId    VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.sectionId'))) STORED,
  f_toUid        VARCHAR(191) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.toUid'))) STORED,
  PRIMARY KEY (col, id),
  KEY k_assignment (col, f_assignmentId),
  KEY k_teacher    (col, f_teacherDocId),
  KEY k_student    (col, f_studentId),
  KEY k_year       (col, f_schoolYear, f_yearLevel),
  KEY k_section    (col, f_sectionId),
  KEY k_to         (col, f_toUid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Sign-in: one row per login. Passwords are stored only as password_hash() (bcrypt),
-- never in docs, never in a backup, never sent to the website.
CREATE TABLE IF NOT EXISTS accounts (
  uid           VARCHAR(64)  NOT NULL,
  email         VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  UNIQUE KEY u_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Wrong-password attempts, to slow down password guessing
CREATE TABLE IF NOT EXISTS login_attempts (
  id      BIGINT       NOT NULL AUTO_INCREMENT,
  ip      VARCHAR(64)  NOT NULL,
  email   VARCHAR(191) NOT NULL,
  at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_ip (ip, at),
  KEY k_email (email, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
