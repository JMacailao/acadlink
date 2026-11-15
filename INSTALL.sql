-- AcadLink — ONE-FILE DATABASE INSTALLER
-- PHP/MySQL centralized SIS accounts release, 2026-10-19.
-- In phpMyAdmin: CREATE/SELECT your empty database, Import THIS FILE once.
-- It creates Grading, SIS, accounting, eligibility, notifications and central accounts.
-- Do not import other schema/seed files afterwards.
-- FIRST LOGIN (SIS): open sis/index.php → First-time setup, type the database password from
-- api/config.php and choose the password of power.admin (v63.3: no starting password is published).
-- Reimporting this file does NOT reset passwords, users or existing settings.
-- For an existing production installation use CENTRAL_ACCOUNTS.md migration guidance;
-- this installer does not infer identities, transfer historical journals or grant old users access.
SET NAMES utf8mb4;
SET @acadlink_old_fk_checks=@@FOREIGN_KEY_CHECKS;
SET @acadlink_old_timezone=@@session.time_zone;
SET time_zone='+08:00'; -- Default school timezone: Asia/Manila (matches api/config.php default).


-- 1. GRADING DATABASE
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


-- 2. SIS, ACCOUNTING, ELIGIBILITY AND NOTIFICATIONS
/* ========================================================================== */
/* School Information System (SIS) — tables (MySQL 5.7+/8.0 or MariaDB 10.3+) */
/* */
/* One database for both systems. Students, sections, year levels, teachers, */
/* subjects and curriculum are the GRADING SYSTEM's records (table docs); the */
/* SIS only adds the tables below, all named sis_... */
/* Safe to import again: existing tables and rows are kept. */
/* phpMyAdmin: choose the grading system's database, Import this FILE, then seed.sql. */
/* ========================================================================== */

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/* -------------------------------------------------------------------------- */
/* Sign-in */
/* -------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS sis_users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(64)  NOT NULL,
  full_name            VARCHAR(150) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  role                 ENUM('ADMIN','REGISTRAR','ADMISSION','CASHIER','ACCOUNTING','TEACHER','STUDENT') NOT NULL,
  student_number       VARCHAR(40)  NULL,          /* STUDENT accounts: the grading system's Student ID */
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  failed_logins        INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_users_username (username),
  UNIQUE KEY u_sis_users_student (student_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Document numbers (admission, registration, receipt), locked with FOR UPDATE */
CREATE TABLE IF NOT EXISTS sis_sequences (
  name       VARCHAR(40)     NOT NULL,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,
  entity_type VARCHAR(40)  NOT NULL,
  entity_id   VARCHAR(60)  NULL,
  details     TEXT         NULL,
  ip_address  VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_audit_entity (entity_type, entity_id),
  KEY k_sis_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Assessment: Categories → Labels → Names (what a student pays) */
/* -------------------------------------------------------------------------- */
/* Groups of fees, e.g. Tuition, Miscellaneous, Laboratory */
CREATE TABLE IF NOT EXISTS sis_fee_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 100,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_cat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* The fee lines printed on the assessment, e.g. Tuition Fee, Library Fee, ID */
CREATE TABLE IF NOT EXISTS sis_fee_labels (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  category_id    INT UNSIGNED  NOT NULL,
  name           VARCHAR(100)  NOT NULL,
  description    VARCHAR(255)  NULL,
  charge_type    ENUM('FIXED','PER_UNIT','PER_SUBJECT') NOT NULL DEFAULT 'FIXED',
  default_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_label_name (name),
  CONSTRAINT fk_sis_label_cat FOREIGN KEY (category_id) REFERENCES sis_fee_categories (id),
  CONSTRAINT ck_sis_label_amount CHECK (default_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/* A named assessment (fee template), e.g. "BSIT 1st Year 1st Semester 2026-2027" */
CREATE TABLE IF NOT EXISTS sis_assessment_names (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  program     VARCHAR(40)  NULL,              /* as in the grading system's section names, e.g. BSIT; NULL = any */
  year_level  VARCHAR(20)  NULL,              /* "1st Year" … NULL = any */
  term        VARCHAR(20)  NULL,              /* "1st Semester" … NULL = any */
  school_year VARCHAR(9)   NULL,              /* "2026-2027"; NULL = any */
  notes       VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_aname (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fees of particular subjects, charged per unit of the subject (Assessment → Subject Fees)
CREATE TABLE IF NOT EXISTS sis_subject_fees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject_doc_id VARCHAR(191) NOT NULL,
  assessment_name_id INT UNSIGNED NULL,
  label_id INT UNSIGNED NOT NULL,
  rate DECIMAL(12,2) NOT NULL,
  created_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY u_subject_fee (subject_doc_id, label_id, assessment_name_id),
  CONSTRAINT fk_subject_fee_label FOREIGN KEY (label_id) REFERENCES sis_fee_labels (id),
  CONSTRAINT fk_subject_fee_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_assessment_name_items (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  assessment_name_id INT UNSIGNED  NOT NULL,
  label_id           INT UNSIGNED  NOT NULL,
  charge_type        ENUM('FIXED','PER_UNIT','PER_SUBJECT') NOT NULL,
  amount             DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_aitem (assessment_name_id, label_id),
  CONSTRAINT fk_sis_aitem_name FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id) ON DELETE CASCADE,
  CONSTRAINT fk_sis_aitem_label FOREIGN KEY (label_id) REFERENCES sis_fee_labels (id),
  CONSTRAINT ck_sis_aitem_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* -------------------------------------------------------------------------- */
/* Transaction: Admission → Registration → Cashier */
/* -------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS sis_admissions (
  application_term VARCHAR(20) NULL,
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admission_no    VARCHAR(20)  NOT NULL,
  applicant_type  ENUM('NEW','TRANSFEREE','RETURNEE') NOT NULL DEFAULT 'NEW',
  last_name       VARCHAR(80)  NOT NULL,
  first_name      VARCHAR(80)  NOT NULL,
  middle_name     VARCHAR(80)  NULL,
  birth_date      DATE         NULL,
  sex             ENUM('M','F') NULL,
  email           VARCHAR(191) NULL,
  phone           VARCHAR(30)  NULL,
  address         VARCHAR(255) NULL,
  guardian_name   VARCHAR(150) NULL,
  guardian_phone  VARCHAR(30)  NULL,
  program         VARCHAR(40)  NULL,
  year_level      VARCHAR(20)  NOT NULL DEFAULT '1st Year',
  school_year     VARCHAR(9)   NOT NULL,
  previous_school VARCHAR(150) NULL,
  status          ENUM('PENDING','ADMITTED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  student_number  VARCHAR(40)  NULL,          /* given when admitted (the grading system's Student ID) */
  remarks         VARCHAR(255) NULL,
  created_by      INT UNSIGNED NOT NULL,
  decided_by      INT UNSIGNED NULL,
  decided_at      DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_adm_no (admission_no),
  KEY k_sis_adm_student (student_number),          /* not unique: a returnee's readmission request reuses their Student ID */
  KEY k_sis_adm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* One registration per student per school year and term (cancelled ones are kept) */
CREATE TABLE IF NOT EXISTS sis_registrations (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  reg_no             VARCHAR(20)   NOT NULL,
  record_origin      ENUM('ENROLLMENT','MIGRATION') NOT NULL DEFAULT 'ENROLLMENT',
  student_number     VARCHAR(40)   NOT NULL,
  student_doc_id     VARCHAR(191)  NOT NULL,     /* docs(col='students').id in the grading system */
  student_name       VARCHAR(200)  NOT NULL,
  school_year        VARCHAR(9)    NOT NULL,
  term               VARCHAR(20)   NOT NULL,
  year_level         VARCHAR(20)   NOT NULL,
  section_doc_id     VARCHAR(191)  NOT NULL,
  section_name       VARCHAR(80)   NOT NULL,
  program            VARCHAR(40)   NULL,
  assessment_name_id INT UNSIGNED  NULL,
  total_units        DECIMAL(5,1)  NOT NULL DEFAULT 0,
  total_amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_paid        DECIMAL(12,2) NOT NULL DEFAULT 0,
  status             ENUM('REGISTERED','CANCELLED') NOT NULL DEFAULT 'REGISTERED',
  remarks            VARCHAR(255)  NULL,
  override_reason    VARCHAR(255)  NULL,          /* administrator allowed a registration a rule would block */
  locked_at          DATETIME      NULL,          /* "Done": from then on the registration can only be viewed */
  locked_by          INT UNSIGNED  NULL,
  created_by         INT UNSIGNED  NOT NULL,
  created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_flag        TINYINT GENERATED ALWAYS AS (IF(status = 'CANCELLED', NULL, 1)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_reg_no (reg_no),
  UNIQUE KEY u_sis_reg_active (student_number, school_year, term, active_flag),
  KEY k_sis_reg_term (school_year, term),
  CONSTRAINT fk_sis_reg_aname FOREIGN KEY (assessment_name_id) REFERENCES sis_assessment_names (id),
  CONSTRAINT ck_sis_reg_amounts CHECK (total_amount >= 0 AND amount_paid >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_registration_subjects (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  subject_doc_id  VARCHAR(191) NOT NULL,
  subject_code    VARCHAR(40)  NULL,
  subject_name    VARCHAR(200) NOT NULL,
  units           DECIMAL(4,1) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_rsub (registration_id, subject_doc_id),
  CONSTRAINT fk_sis_rsub_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* The assessment of a registration: a copy of the fee lines at the time it was computed */
/* Settings of the SIS (printing of the assessment form and the official receipt) */
CREATE TABLE IF NOT EXISTS sis_settings (
  skey       VARCHAR(60) NOT NULL,
  svalue     TEXT        NULL,
  updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Scholarship and discount programs: a percentage or a fixed amount off the whole assessment
   or off chosen fee categories (sis_discount_categories), with an optional maximum */
CREATE TABLE IF NOT EXISTS sis_discounts (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)  NOT NULL,
  kind        ENUM('SCHOLARSHIP','DISCOUNT') NOT NULL DEFAULT 'DISCOUNT',
  method      ENUM('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
  value       DECIMAL(12,2) NOT NULL,             /* 50.00 = 50% (PERCENT) or ₱ (FIXED) */
  max_amount  DECIMAL(12,2) NULL,                 /* the most it can take off; NULL = no limit */
  description VARCHAR(255)  NULL,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_discount_name (name),
  CONSTRAINT ck_sis_discount_value CHECK (value > 0 AND (max_amount IS NULL OR max_amount > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* The fee categories a program applies to (none = the whole assessment) */
CREATE TABLE IF NOT EXISTS sis_discount_categories (
  discount_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (discount_id, category_id),
  CONSTRAINT fk_sis_dcat_discount FOREIGN KEY (discount_id) REFERENCES sis_discounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_sis_dcat_category FOREIGN KEY (category_id) REFERENCES sis_fee_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Fee labels chosen for one student: ADD charges a label (unit_amount NULL = its default amount; it replaces
   the template's line for that label), EXCLUDE takes a template line off this student's assessment */
CREATE TABLE IF NOT EXISTS sis_registration_labels (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED  NOT NULL,
  label_id        INT UNSIGNED  NOT NULL,
  mode            ENUM('ADD','EXCLUDE') NOT NULL DEFAULT 'ADD',
  unit_amount     DECIMAL(12,2) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_rlabel (registration_id, label_id),
  CONSTRAINT fk_sis_rlabel_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id) ON DELETE CASCADE,
  CONSTRAINT fk_sis_rlabel_label FOREIGN KEY (label_id) REFERENCES sis_fee_labels (id),
  CONSTRAINT ck_sis_rlabel_amount CHECK (unit_amount IS NULL OR unit_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Scholarships / discounts given to one registration (computed into DISCOUNT lines on every recompute) */
CREATE TABLE IF NOT EXISTS sis_registration_discounts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  discount_id     INT UNSIGNED NOT NULL,
  remarks         VARCHAR(255) NULL,
  added_by        INT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_rdiscount (registration_id, discount_id),
  KEY k_sis_rdiscount_discount (discount_id),
  CONSTRAINT fk_sis_rdiscount_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id) ON DELETE CASCADE,
  CONSTRAINT fk_sis_rdiscount_discount FOREIGN KEY (discount_id) REFERENCES sis_discounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_registration_items (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED  NOT NULL,
  category_name   VARCHAR(80)   NOT NULL,
  label_name      VARCHAR(100)  NOT NULL,
  charge_type     ENUM('FIXED','PER_UNIT','PER_SUBJECT','ADJUSTMENT','DISCOUNT') NOT NULL,
  quantity        DECIMAL(6,1)  NOT NULL DEFAULT 1,
  unit_amount     DECIMAL(12,2) NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,          /* ADJUSTMENT may be negative (less); DISCOUNT is negative */
  sort_order      SMALLINT      NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  KEY k_sis_ritem_reg (registration_id),
  CONSTRAINT fk_sis_ritem_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Official receipt series (Settings → O.R. Series): numbering and the printed template of each kind of receipt */
CREATE TABLE IF NOT EXISTS sis_or_series (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  code        VARCHAR(10)      NOT NULL,
  description VARCHAR(100)     NOT NULL,
  prefix      VARCHAR(8)       NOT NULL DEFAULT '',
  digits      TINYINT UNSIGNED NOT NULL DEFAULT 7,
  next_number BIGINT UNSIGNED  NOT NULL DEFAULT 1,    /* the number suggested next (numbers already used are skipped) */
  template    ENUM('STANDARD','OFFICIAL') NOT NULL DEFAULT 'STANDARD',
  is_active   TINYINT(1)       NOT NULL DEFAULT 1,    /* can be used at the cashier */
  auto_number TINYINT(1)       NOT NULL DEFAULT 1,    /* the system gives the next number (off: type the pre-printed number) */
  allow_edit  TINYINT(1)       NOT NULL DEFAULT 1,    /* the O.R. number can be corrected after posting */
  is_default  TINYINT(1)       NOT NULL DEFAULT 0,    /* chosen first at the cashier */
  created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_or_series_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_payments (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  receipt_no      VARCHAR(30)   NOT NULL,           /* used once; a voided number may be made negative (-OR-0000012) to free it */
  series_id       INT UNSIGNED  NULL,
  idempotency_key CHAR(32)      NOT NULL,           /* one per payment form: a double submit records it once */
  registration_id INT UNSIGNED  NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  method          ENUM('CASH','GCASH','MAYA','BANK','CARD','CHECK') NOT NULL DEFAULT 'CASH',
  reference_no    VARCHAR(60)   NULL,
  remarks         VARCHAR(255)  NULL,
  status          ENUM('POSTED','VOIDED') NOT NULL DEFAULT 'POSTED',
  received_by     INT UNSIGNED  NOT NULL,
  paid_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_by       INT UNSIGNED  NULL,
  voided_at       DATETIME      NULL,
  void_reason     VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_pay_receipt (receipt_no),
  UNIQUE KEY u_sis_pay_idem (idempotency_key),
  KEY k_sis_pay_reg (registration_id),
  KEY k_sis_pay_date (paid_at),
  CONSTRAINT fk_sis_pay_reg FOREIGN KEY (registration_id) REFERENCES sis_registrations (id),
  CONSTRAINT fk_sis_pay_series FOREIGN KEY (series_id) REFERENCES sis_or_series (id),
  CONSTRAINT ck_sis_pay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* v52: document requests from the Student Portal and their fee receipts (O.R. numbers share sis_or_series) */
CREATE TABLE IF NOT EXISTS sis_document_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ref_no VARCHAR(30) NOT NULL,
            student_doc_id VARCHAR(191) NOT NULL, student_number VARCHAR(40) NOT NULL, student_name VARCHAR(200) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            doc_type VARCHAR(40) NOT NULL, school_year VARCHAR(9) NULL, term VARCHAR(20) NULL,
            copies TINYINT UNSIGNED NOT NULL DEFAULT 1, purpose VARCHAR(200) NOT NULL,
            fee_per_copy DECIMAL(12,2) NOT NULL DEFAULT 0, amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('PENDING_PAYMENT','PAID','PROCESSING','READY','RELEASED','CANCELLED') NOT NULL,
            processing_days TINYINT UNSIGNED NOT NULL DEFAULT 0, expected_date DATE NULL,
            document_id INT UNSIGNED NULL,
            paid_at DATETIME NULL, ready_at DATETIME NULL,
            released_at DATETIME NULL, released_to VARCHAR(150) NULL, released_by INT UNSIGNED NULL,
            cancelled_at DATETIME NULL, cancel_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_docreq_ref (ref_no), KEY k_sis_docreq_student (student_doc_id), KEY k_sis_docreq_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_document_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            receipt_no VARCHAR(30) NOT NULL, series_id INT UNSIGNED NULL, idempotency_key CHAR(32) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method ENUM('CASH','GCASH','MAYA','BANK','CARD','CHECK') NOT NULL DEFAULT 'CASH', reference_no VARCHAR(60) NULL,
            status ENUM('POSTED','VOIDED') NOT NULL DEFAULT 'POSTED',
            received_by INT UNSIGNED NOT NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voided_by INT UNSIGNED NULL, voided_at DATETIME NULL, void_reason VARCHAR(255) NULL,
            UNIQUE KEY u_sis_docpay_receipt (receipt_no), UNIQUE KEY u_sis_docpay_idem (idempotency_key), KEY k_sis_docpay_req (request_id),
            CONSTRAINT fk_sis_docpay_req FOREIGN KEY (request_id) REFERENCES sis_document_requests (id),
            CONSTRAINT ck_sis_docpay_amount CHECK (amount > 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* v53: rooms and class schedules */
CREATE TABLE IF NOT EXISTS sis_rooms (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL, building VARCHAR(80) NOT NULL DEFAULT '', capacity SMALLINT UNSIGNED NOT NULL DEFAULT 40,
            kind ENUM('LECTURE','LAB','OTHER') NOT NULL DEFAULT 'LECTURE', is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY u_sis_room_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_class_schedules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(9) NOT NULL, term VARCHAR(20) NOT NULL,
            section_doc_id VARCHAR(191) NOT NULL, subject_doc_id VARCHAR(191) NOT NULL, teacher_doc_id VARCHAR(191) NULL,
            room_id INT UNSIGNED NULL, days VARCHAR(20) NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL,
            kind ENUM('LEC','LAB','OTHER') NOT NULL DEFAULT 'LEC',
            created_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY k_sis_sched_term (school_year, term), KEY k_sis_sched_section (section_doc_id), KEY k_sis_sched_room (room_id), KEY k_sis_sched_teacher (teacher_doc_id),
            CONSTRAINT fk_sis_sched_room FOREIGN KEY (room_id) REFERENCES sis_rooms (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* v54: prerequisite overrides and add/drop history */
CREATE TABLE IF NOT EXISTS sis_prereq_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, subject_doc_id VARCHAR(191) NOT NULL, prerequisite_code VARCHAR(40) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_prereq_override (registration_id, subject_doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_adddrop_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, kind ENUM('ADD_DROP','WITHDRAW') NOT NULL DEFAULT 'ADD_DROP',
            added VARCHAR(500) NOT NULL DEFAULT '', dropped VARCHAR(500) NOT NULL DEFAULT '',
            charge_percent TINYINT UNSIGNED NOT NULL DEFAULT 0, charged DECIMAL(12,2) NOT NULL DEFAULT 0, total_before DECIMAL(12,2) NOT NULL, total_after DECIMAL(12,2) NOT NULL,
            reason VARCHAR(255) NOT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_adddrop_reg (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* v55: online clearance */
CREATE TABLE IF NOT EXISTS sis_clearance_periods (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_year VARCHAR(9) NOT NULL, term VARCHAR(20) NOT NULL, opens_on DATE NOT NULL, closes_on DATE NOT NULL,
            is_open TINYINT(1) NOT NULL DEFAULT 1, updated_by INT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY u_sis_clr_period (school_year, term)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_clearance_tags (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, item_key VARCHAR(220) NOT NULL, item_label VARCHAR(200) NOT NULL DEFAULT '',
            status ENUM('CLEARED','NOT_CLEARED') NOT NULL, note VARCHAR(500) NOT NULL DEFAULT '',
            signer_account_id BIGINT UNSIGNED NOT NULL, signer_name VARCHAR(200) NOT NULL, signer_position VARCHAR(100) NOT NULL DEFAULT '',
            signature_mode ENUM('ESIGN','APPROVED','NONE') NOT NULL DEFAULT 'NONE', signature MEDIUMBLOB NULL, control_no VARCHAR(30) NULL,
            signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            undone_at DATETIME NULL, undone_by BIGINT UNSIGNED NULL, undo_reason VARCHAR(255) NULL,
            active_flag TINYINT GENERATED ALWAYS AS (IF(undone_at IS NULL, 1, NULL)) STORED,
            UNIQUE KEY u_sis_clr_active (registration_id, item_key, active_flag), KEY k_sis_clr_reg (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_clearance_submissions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, item_key VARCHAR(220) NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '',
            file MEDIUMBLOB NULL, file_name VARCHAR(150) NULL, file_mime VARCHAR(60) NULL, file_size INT UNSIGNED NULL,
            status ENUM('NEW','SEEN','REPLIED') NOT NULL DEFAULT 'NEW', reply VARCHAR(1000) NULL, replied_by BIGINT UNSIGNED NULL, replied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_clr_sub (registration_id, item_key), KEY k_sis_clr_sub_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_clearance_overrides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL, cleared_registration_id INT UNSIGNED NOT NULL, pending VARCHAR(1000) NOT NULL DEFAULT '',
            reason VARCHAR(255) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY k_sis_clr_ovr (registration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

/* -------------------------------------------------------------------------- */
/* Accounting (double entry): chart of accounts, account mappings, journal    */
/* -------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS sis_accounts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  type        ENUM('ASSET','LIABILITY','EQUITY','REVENUE','CONTRA_REVENUE','EXPENSE') NOT NULL,
  is_cash     TINYINT(1)   NOT NULL DEFAULT 0,      /* cash on hand or bank: reconciled against statements */
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_account_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Which account each thing posts to: ar, default_revenue, default_discount, adjust_charge, adjust_less,
   method:CASH…, cat:<fee category id>, label:<fee label id>, discount:<discount id> */
CREATE TABLE IF NOT EXISTS sis_account_map (
  map_key    VARCHAR(40)  NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (map_key),
  CONSTRAINT fk_sis_map_account FOREIGN KEY (account_id) REFERENCES sis_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Journal entries: made automatically for confirmed assessments and payments (and reversed when they are
   reopened or voided), or by hand by accounting staff */
CREATE TABLE IF NOT EXISTS sis_journal (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_no        VARCHAR(20)  NOT NULL,
  entry_date      DATE         NOT NULL,
  source          ENUM('ASSESSMENT','PAYMENT','MANUAL','DOCUMENT_FEE') NOT NULL,
  source_id       INT UNSIGNED NULL,                 /* registration id or payment id */
  registration_id INT UNSIGNED NULL,
  memo            VARCHAR(255) NOT NULL,
  reverses_id     INT UNSIGNED NULL,                 /* this entry reverses that one */
  reversed_by_id  INT UNSIGNED NULL,                 /* that entry reverses this one */
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('DRAFT','REVIEW','POSTED','CANCELLED') NOT NULL DEFAULT 'POSTED',
  fund ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL DEFAULT 'OPERATING',
  department VARCHAR(80) NOT NULL DEFAULT 'School',
  reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
  posted_by INT UNSIGNED NULL, posted_at DATETIME NULL,
  review_note VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_journal_no (entry_no),
  KEY k_sis_journal_date (entry_date),
  KEY k_sis_journal_source (source, source_id),
  KEY k_sis_journal_workflow (status,entry_date),
  KEY k_sis_journal_reg (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_journal_lines (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  journal_id        INT UNSIGNED  NOT NULL,
  account_id        INT UNSIGNED  NOT NULL,
  debit             DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit            DECIMAL(12,2) NOT NULL DEFAULT 0,
  memo              VARCHAR(160)  NULL,
  reconciliation_id INT UNSIGNED  NULL,              /* cleared on this bank reconciliation */
  PRIMARY KEY (id),
  KEY k_sis_jline_account (account_id),
  KEY k_sis_jline_journal (journal_id),
  CONSTRAINT fk_sis_jline_journal FOREIGN KEY (journal_id) REFERENCES sis_journal (id) ON DELETE CASCADE,
  CONSTRAINT fk_sis_jline_account FOREIGN KEY (account_id) REFERENCES sis_accounts (id),
  CONSTRAINT ck_sis_jline_amounts CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Cash and bank reconciliation: the statement balance against the cleared ledger lines */
CREATE TABLE IF NOT EXISTS sis_reconciliations (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  account_id        INT UNSIGNED  NOT NULL,
  statement_date    DATE          NOT NULL,
  statement_balance DECIMAL(14,2) NOT NULL,
  cleared_balance   DECIMAL(14,2) NOT NULL,
  book_balance      DECIMAL(14,2) NOT NULL,
  notes             VARCHAR(255)  NULL,
  created_by        INT UNSIGNED  NOT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_recon_account (account_id, statement_date),
  CONSTRAINT fk_sis_recon_account FOREIGN KEY (account_id) REFERENCES sis_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Academic terms and deadlines: when enrollment is open, when classes start, when grades are due */
CREATE TABLE IF NOT EXISTS sis_term_calendar (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_year      VARCHAR(9)   NOT NULL,
  term             VARCHAR(20)  NOT NULL,
  enrollment_start DATE         NOT NULL,
  enrollment_end   DATE         NOT NULL,
  classes_start    DATE         NULL,
  grades_due       DATE         NULL,
  notes            VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_term_cal (school_year, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/* -------------------------------------------------------------------------- */
/* Existing student balance enrollment workflow                               */
/* -------------------------------------------------------------------------- */
/* The actual balance remains derived from registrations minus posted
   payments; this table stores only requests and policy decisions. */
CREATE TABLE IF NOT EXISTS sis_balance_cases (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_number     VARCHAR(40)  NOT NULL,
  student_name       VARCHAR(200) NOT NULL,
  target_school_year VARCHAR(9)   NOT NULL,
  target_term        VARCHAR(20)  NOT NULL,
  option_code        ENUM('ARRANGEMENT','SPECIAL') NOT NULL,
  balance_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
  requested_payment  DECIMAL(12,2) NOT NULL DEFAULT 0,
  arrangement_rules  VARCHAR(1000) NULL,
  request_reason     VARCHAR(1000) NOT NULL,
  status             ENUM('PENDING','APPROVED','REJECTED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  reviewed_by        INT UNSIGNED NULL,
  reviewed_at        DATETIME NULL,
  decision_reason    VARCHAR(1000) NULL,
  created_by         INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_balance_student (student_number, status),
  KEY k_sis_balance_target (target_school_year, target_term, status),
  CONSTRAINT ck_sis_balance_amounts CHECK (balance_amount >= 0 AND requested_payment >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_balance_case_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  case_id     INT UNSIGNED NOT NULL,
  event_type  VARCHAR(40) NOT NULL,
  actor_id    INT UNSIGNED NULL,
  details     TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_balance_event_case (case_id),
  CONSTRAINT fk_sis_balance_event_case FOREIGN KEY (case_id) REFERENCES sis_balance_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


/* -------------------------------------------------------------------------- */
/* SIS notification inbox                                                    */
/* -------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS sis_notifications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_role      VARCHAR(20) NULL,
  recipient_user_id   INT UNSIGNED NULL,
  type                VARCHAR(40) NOT NULL,
  title               VARCHAR(160) NOT NULL,
  message             VARCHAR(1000) NOT NULL,
  entity_type         VARCHAR(40) NULL,
  entity_id           VARCHAR(60) NULL,
  is_read             TINYINT(1) NOT NULL DEFAULT 0,
  read_at             DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_notif_role (recipient_role, is_read, created_at),
  KEY k_sis_notif_user (recipient_user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Enrollment eligibility: explicit holds, subject rules and immutable decision history. */
CREATE TABLE IF NOT EXISTS sis_eligibility_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_number VARCHAR(40) NOT NULL,
  school_year VARCHAR(9) NULL,
  term VARCHAR(20) NULL,
  kind ENUM('ACADEMIC','FINANCIAL','DOCUMENT','OTHER') NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  office VARCHAR(100) NOT NULL,
  expires_on DATE NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by INT UNSIGNED NULL,
  released_at DATETIME NULL,
  release_reason VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY k_sis_eligibility_hold (student_number, released_at, school_year, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_subject_prerequisites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_doc_id VARCHAR(191) NOT NULL,
  prerequisite_doc_id VARCHAR(191) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY u_sis_prerequisite (subject_doc_id, prerequisite_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_eligibility_decisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  stage ENUM('CREATED','CONFIRMED') NOT NULL,
  result VARCHAR(30) NOT NULL,
  policy_version VARCHAR(40) NOT NULL,
  snapshot LONGTEXT NOT NULL,
  checked_by INT UNSIGNED NOT NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY k_sis_eligibility_decision (registration_id, id),
  CONSTRAINT fk_sis_eligibility_decision FOREIGN KEY (registration_id) REFERENCES sis_registrations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. GUARDED COMPATIBILITY COLUMNS
SET @acadlink_ddl=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_users' AND column_name='role' AND column_type NOT LIKE '%TEACHER%'), 'ALTER TABLE sis_users MODIFY role ENUM(''ADMIN'',''REGISTRAR'',''ADMISSION'',''CASHIER'',''ACCOUNTING'',''TEACHER'',''STUDENT'') NOT NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_admissions' AND column_name='application_term'), 'ALTER TABLE sis_admissions ADD COLUMN application_term VARCHAR(20) NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_registrations' AND column_name='locked_at'), 'ALTER TABLE sis_registrations ADD COLUMN locked_at DATETIME NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_registrations' AND column_name='locked_by'), 'ALTER TABLE sis_registrations ADD COLUMN locked_by INT UNSIGNED NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_registrations' AND column_name='override_reason'), 'ALTER TABLE sis_registrations ADD COLUMN override_reason VARCHAR(255) NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(NOT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_payments' AND column_name='series_id'), 'ALTER TABLE sis_payments ADD COLUMN series_id INT UNSIGNED NULL, ADD CONSTRAINT fk_sis_pay_series FOREIGN KEY(series_id) REFERENCES sis_or_series(id)', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_registration_items' AND column_name='charge_type' AND column_type NOT LIKE '%DISCOUNT%'), 'ALTER TABLE sis_registration_items MODIFY charge_type ENUM(''FIXED'',''PER_UNIT'',''PER_SUBJECT'',''ADJUSTMENT'',''DISCOUNT'') NOT NULL', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sis_admissions' AND column_name='status' AND column_type NOT LIKE '%CANCELLED%'), 'ALTER TABLE sis_admissions MODIFY status ENUM(''PENDING'',''ADMITTED'',''REJECTED'',''CANCELLED'') NOT NULL DEFAULT ''PENDING''', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;
SET @acadlink_ddl=IF(EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='sis_admissions' AND index_name='u_sis_adm_student'), 'ALTER TABLE sis_admissions DROP INDEX u_sis_adm_student', 'SELECT 1');
PREPARE acadlink_upgrade FROM @acadlink_ddl;
EXECUTE acadlink_upgrade;
DEALLOCATE PREPARE acadlink_upgrade;


-- 4. STARTING FEES, SEQUENCES AND RECEIPT SERIES
/* ========================================================================== */
/* SIS — starting data (after schema.sql; safe to run again: existing rows are kept) */
/* The only initial sign-in is the central Power User at the end of INSTALL.sql. */
/* ========================================================================== */
SET NAMES utf8mb4;

-- No legacy admin/password is seeded. The central Power User is created below.

INSERT IGNORE INTO sis_sequences (name, next_value) VALUES ('admission', 1), ('registration', 1), ('receipt', 1);

INSERT INTO sis_or_series(code,description,prefix,digits,next_number,template,is_active,auto_number,allow_edit,is_default)
SELECT 'OR1','Official Receipt Series One',
 COALESCE((SELECT svalue FROM sis_settings WHERE skey='or_prefix'),'OR-'),
 CASE WHEN CAST(COALESCE((SELECT svalue FROM sis_settings WHERE skey='or_digits'),'7') AS UNSIGNED) BETWEEN 3 AND 10
 THEN CAST(COALESCE((SELECT svalue FROM sis_settings WHERE skey='or_digits'),'7') AS UNSIGNED) ELSE 7 END,
 GREATEST(1,COALESCE((SELECT next_value FROM sis_sequences WHERE name='receipt'),1)),
 'STANDARD',1,1,1,1
WHERE NOT EXISTS(SELECT 1 FROM sis_or_series);

INSERT IGNORE INTO sis_fee_categories (name, description, sort_order) VALUES
 ('Tuition', 'Tuition fees', 10),
 ('Miscellaneous', 'Registration, library, ID and other school fees', 20),
 ('Laboratory', 'Laboratory and computer fees', 30),
 ('Other Fees', 'Other authorized charges', 40);


-- 5. SAVED SIS DEFAULT SETTINGS — EXISTING VALUES KEPT
INSERT IGNORE INTO sis_settings(skey,svalue) VALUES
 ('print_school_name',''),
 ('print_address',''),
 ('print_contact',''),
 ('print_show_logo','1'),
 ('asm_template','1'),
 ('asm_title','Statement of Account'),
 ('asm_paper','A4'),
 ('asm_show_subjects','1'),
 ('asm_show_breakdown','1'),
 ('asm_show_payments','1'),
 ('asm_note',''),
 ('asm_sign_left','Prepared by (Registrar)'),
 ('asm_sign_right','Conforme (Student / Parent)'),
 ('or_title','Official Receipt'),
 ('or_paper','HALF'),
 ('or_copies','1'),
 ('or_show_words','1'),
 ('or_show_balance','1'),
 ('portal_hide_grades_unpaid','0'),
 ('eligibility_block_incomplete','0'),
 ('balance_enrollment_enabled','0'),
 ('balance_allow_pay','1'),
 ('balance_allow_arrangement','1'),
 ('balance_allow_special','1'),
 ('balance_show_on_latest_assessment','0'),
 ('balance_min_payment','0.00'),
 ('balance_installment_rules','Minimum payment and installment terms are reviewed by Accounting.'),
 ('balance_arrangement_approver_roles','ACCOUNTING,REGISTRAR'),
 ('balance_special_approver_roles','ACCOUNTING,REGISTRAR'),
 ('balance_require_approval','1'),
 ('or_note','Thank you for your payment. Please keep this receipt.'),
 ('or_sign','Cashier');


-- 6. DEFAULT SCHOOL CHART OF ACCOUNTS AND MAPPINGS
-- Default chart of accounts and account mappings (safe to run more than once).
INSERT IGNORE INTO sis_accounts (code, name, type, is_cash, description) VALUES
 ('1010', 'Cash on Hand', 'ASSET', 1, 'Cashier collections in cash and checks not yet deposited'),
 ('1020', 'Cash in Bank', 'ASSET', 1, 'School bank account: deposits, card and bank transfer payments'),
 ('1030', 'E-Wallet Collections (GCash / Maya)', 'ASSET', 1, 'Payments received through GCash or Maya'),
 ('1100', 'Accounts Receivable - Students', 'ASSET', 0, 'What students owe on their assessments'),
 ('2100', 'Student Deposits and Advances', 'LIABILITY', 0, 'Money received before it is earned'),
 ('3000', 'Fund Balance', 'EQUITY', 0, 'Opening balances and accumulated surplus'),
 ('4010', 'Tuition Fees', 'REVENUE', 0, NULL),
 ('4020', 'Miscellaneous Fees', 'REVENUE', 0, NULL),
 ('4030', 'Laboratory Fees', 'REVENUE', 0, NULL),
 ('4090', 'Other School Fees', 'REVENUE', 0, 'Other fees and charges added to an assessment'),
 ('4900', 'Scholarships and Discounts', 'CONTRA_REVENUE', 0, 'Less on the fees: scholarships, discounts and other less'),
 ('5010', 'Operating Expenses', 'EXPENSE', 0, 'Expenses entered by hand in the journal');
INSERT IGNORE INTO sis_account_map (map_key, account_id)
 SELECT k, (SELECT id FROM sis_accounts WHERE code = c) FROM (
   SELECT 'ar' k, '1100' c UNION ALL SELECT 'default_revenue', '4090' UNION ALL SELECT 'default_discount', '4900'
   UNION ALL SELECT 'adjust_charge', '4090' UNION ALL SELECT 'adjust_less', '4900'
   UNION ALL SELECT 'method:CASH', '1010' UNION ALL SELECT 'method:CHECK', '1010' UNION ALL SELECT 'method:CARD', '1020'
   UNION ALL SELECT 'method:BANK', '1020' UNION ALL SELECT 'method:GCASH', '1030' UNION ALL SELECT 'method:MAYA', '1030'
 ) m WHERE (SELECT id FROM sis_accounts WHERE code = c) IS NOT NULL;
INSERT IGNORE INTO sis_account_map (map_key, account_id)
 SELECT CONCAT('cat:', c.id), a.id FROM sis_fee_categories c JOIN sis_accounts a ON a.code = CASE
   WHEN c.name LIKE 'Tuition%' THEN '4010' WHEN c.name LIKE 'Misc%' THEN '4020' WHEN c.name LIKE 'Lab%' THEN '4030' ELSE '4090' END;


-- 7. CENTRAL ACCOUNTS, FIVE GROUPS AND PERMISSIONS
-- AcadLink centralized account FOUNDATION: MySQL 5.7+ / MariaDB 10.3+.
-- Select the EXISTING AcadLink database before import. Additive, safe to repeat.
-- This script creates groups and permissions; it creates no login accounts,
-- changes no existing password, and does not switch either PHP login handler.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS central_schema_migrations (
  migration_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(200) NOT NULL,
  account_kind ENUM('STAFF','STUDENT') NOT NULL,
  status ENUM('PENDING','ACTIVE','SUSPENDED','DISABLED') NOT NULL DEFAULT 'PENDING',
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY k_central_account_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_login_identifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  identifier_key VARCHAR(191) GENERATED ALWAYS AS (LOWER(TRIM(identifier))) STORED,
  identifier_kind ENUM('USERNAME','EMAIL','STUDENT_ID') NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  primary_slot TINYINT GENERATED ALWAYS AS (IF(is_primary=1,1,NULL)) STORED,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY u_central_login_identifier(identifier_key),
  UNIQUE KEY u_central_primary_login(account_id,primary_slot),
  CONSTRAINT fk_central_identifier_account FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_credentials (
  account_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  password_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(account_id),
  CONSTRAINT fk_central_credential_account FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also included in INSTALL.sql. Existing installations create this automatically in SIS.
CREATE TABLE IF NOT EXISTS central_temporary_password_expiry (
  account_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY(account_id),
  CONSTRAINT fk_central_temp_expiry FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS central_groups (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(500) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY(id), UNIQUE KEY u_central_group_code(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_permissions (
  code VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  workspace ENUM('ADMIN','GRADING','SIS','PORTAL') NOT NULL,
  label VARCHAR(160) NOT NULL,
  PRIMARY KEY(code), KEY k_central_permission_workspace(workspace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_group_permissions (
  group_id SMALLINT UNSIGNED NOT NULL,
  permission_code VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  access_scope ENUM('ALL','OWN','ASSIGNED') NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY(group_id,permission_code),
  CONSTRAINT fk_central_gp_group FOREIGN KEY(group_id) REFERENCES central_groups(id),
  CONSTRAINT fk_central_gp_permission FOREIGN KEY(permission_code) REFERENCES central_permissions(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_user_groups (
  account_id BIGINT UNSIGNED NOT NULL,
  group_id SMALLINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  valid_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY(account_id,group_id), KEY k_central_ug_group(group_id),
  CONSTRAINT fk_central_ug_account FOREIGN KEY(account_id) REFERENCES central_accounts(id),
  CONSTRAINT fk_central_ug_group FOREIGN KEY(group_id) REFERENCES central_groups(id),
  CONSTRAINT fk_central_ug_assigner FOREIGN KEY(assigned_by) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_person_links (
  account_id BIGINT UNSIGNED NOT NULL,
  teacher_doc_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
  student_doc_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
  student_number VARCHAR(40) COLLATE utf8mb4_bin NULL,
  verified_at DATETIME NULL,
  PRIMARY KEY(account_id),
  UNIQUE KEY u_central_teacher(teacher_doc_id), UNIQUE KEY u_central_student(student_doc_id),
  UNIQUE KEY u_central_student_number(student_number),
  CONSTRAINT fk_central_person_account FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_legacy_links (
  source_system ENUM('GRADING','SIS') NOT NULL,
  source_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verification_note VARCHAR(1000) NOT NULL,
  PRIMARY KEY(source_system,source_key),
  UNIQUE KEY u_central_legacy_account(account_id,source_system),
  CONSTRAINT fk_central_legacy_account FOREIGN KEY(account_id) REFERENCES central_accounts(id),
  CONSTRAINT fk_central_legacy_reviewer FOREIGN KEY(verified_by) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admission applicants may have a portal account before they have a Student ID.
-- OWN permissions must check this mapping or the verified student link on the server.
CREATE TABLE IF NOT EXISTS central_record_owners (
  workspace ENUM('GRADING','SIS','PORTAL') NOT NULL,
  entity_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(workspace,entity_type,entity_key), KEY k_central_owner(account_id),
  CONSTRAINT fk_central_owner_account FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_security_policy (
  id TINYINT UNSIGNED NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  account_auth_version BIGINT UNSIGNED NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY(id), UNIQUE KEY u_central_session_token(token_hash),
  KEY k_central_session_account(account_id,revoked_at,expires_at),
  CONSTRAINT fk_central_session_account FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS central_account_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_account_id BIGINT UNSIGNED NULL,
  target_account_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY k_central_account_audit(target_account_id,created_at),
  CONSTRAINT fk_central_audit_actor FOREIGN KEY(actor_account_id) REFERENCES central_accounts(id),
  CONSTRAINT fk_central_audit_target FOREIGN KEY(target_account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review inventory only. Staging a source account grants NO access.
CREATE TABLE IF NOT EXISTS central_migration_candidates (
  source_system ENUM('GRADING','SIS') NOT NULL,
  source_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
  source_login VARCHAR(191) NULL,
  display_name VARCHAR(255) NULL,
  legacy_role VARCHAR(32) NULL,
  suggested_group VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  source_active TINYINT(1) NOT NULL DEFAULT 0,
  source_password_present TINYINT(1) NOT NULL DEFAULT 0,
  source_teacher_doc_id VARCHAR(191) COLLATE utf8mb4_bin NULL,
  source_student_number VARCHAR(40) COLLATE utf8mb4_bin NULL,
  review_status ENUM('UNREVIEWED','REVIEWED','LINKED','REJECTED') NOT NULL DEFAULT 'UNREVIEWED',
  review_note VARCHAR(1000) NULL,
  last_scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(source_system,source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO central_security_policy(id,policy_version) VALUES(1,1);
INSERT IGNORE INTO central_groups(code,name,description) VALUES
 ('POWER_USER','Power User — Super Admin','Manage accounts, groups, settings and all staff functions across both systems.'),
 ('ACCOUNTING','Accounting','Manage assessments, discounts, balances, payments, receipts and accounting.'),
 ('REGISTRAR','Registrar','Manage grading records, admission, student records and registration.'),
 ('TEACHER','Teacher','View and enter grades for assigned classes, and submit grades for review.'),
 ('STUDENT','Student','View own grades and assessment; submit own admission application during enrollment.');

INSERT IGNORE INTO central_permissions(code,workspace,label) VALUES
 ('admin.accounts.manage','ADMIN','Manage centralized accounts'),
 ('admin.groups.manage','ADMIN','Manage groups and permissions'),
 ('admin.settings.manage','ADMIN','Manage system settings'),
 ('admin.audit.view','ADMIN','View system access audit'),
 ('admin.backup.manage','ADMIN','Manage backups and recovery'),
 ('grading.workspace.view','GRADING','Open Grading System'),
 ('grading.students.view','GRADING','View academic student records'),
 ('grading.students.manage','GRADING','Manage academic student records'),
 ('grading.sections.manage','GRADING','Manage sections'),
 ('grading.subjects.manage','GRADING','Manage subjects'),
 ('grading.curriculum.manage','GRADING','Manage curriculum'),
 ('grading.teachers.manage','GRADING','Manage teacher records'),
 ('grading.grades.view','GRADING','View grades'),
 ('grading.grades.enter','GRADING','Enter and edit open grades'),
 ('grading.grades.submit','GRADING','Submit grades for registrar review'),
 ('grading.grades.finalize','GRADING','Finalize grades and completion'),
 ('grading.corrections.review','GRADING','Review grade correction requests'),
 ('grading.reports.view','GRADING','View academic reports'),
 ('sis.workspace.view','SIS','Open School Information System'),
 ('sis.students.view','SIS','View student records'),
 ('sis.students.manage','SIS','Manage student records'),
 ('sis.admission.view','SIS','View admission applications'),
 ('sis.admission.manage','SIS','Process admission applications'),
 ('sis.admission.approve','SIS','Approve admission and readmission'),
 ('sis.registration.view','SIS','View registrations'),
 ('sis.registration.manage','SIS','Manage registration drafts and subjects'),
 ('sis.enrollment.confirm','SIS','Confirm enrollment after eligibility checks'),
 ('sis.enrollment.reopen','SIS','Reopen confirmed enrollment with a reason'),
 ('sis.eligibility.view','SIS','View enrollment eligibility'),
 ('sis.eligibility.holds.manage','SIS','Manage explicit enrollment holds'),
 ('sis.assessment.view','SIS','View student assessments'),
 ('sis.assessment.manage','SIS','Manage fee categories, labels and templates'),
 ('sis.discounts.manage','SIS','Manage scholarships and discounts'),
 ('sis.balances.view','SIS','View prior balances and payment arrangements'),
 ('sis.balances.approve','SIS','Review financial enrollment arrangements'),
 ('sis.payments.receive','SIS','Receive cashier payments'),
 ('sis.payments.void','SIS','Void payments with a recorded reason'),
 ('sis.receipts.view','SIS','View official receipts'),
 ('sis.journals.prepare','SIS','Prepare journal entries'),
 ('sis.journals.review','SIS','Review journal entries'),
 ('sis.journals.post','SIS','Post approved journal entries'),
 ('sis.periods.close','SIS','Close accounting periods'),
 ('sis.reconciliation.manage','SIS','Manage bank reconciliation'),
 ('sis.reports.view','SIS','View school and financial reports'),
 ('sis.audit.view','SIS','View SIS audit history'),
 ('portal.workspace.view','PORTAL','Open own Student Portal'),
 ('portal.grades.view','PORTAL','View own finalized grades'),
 ('portal.assessment.view','PORTAL','View own assessment'),
 ('portal.balances.view','PORTAL','View own balances'),
 ('portal.receipts.view','PORTAL','View own receipts'),
 ('portal.enrollment.view','PORTAL','View own enrollment status'),
 ('portal.admission.apply','PORTAL','Submit own admission or enrollment application');

-- Seed default grants once. Later edits/revocations are not silently reset by reimport.
INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,'ALL' FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='POWER_USER' AND p.workspace IN('ADMIN','GRADING','SIS')
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_v1');

INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,'ALL' FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='ACCOUNTING' AND p.code IN(
 'sis.workspace.view','sis.students.view','sis.registration.view','sis.assessment.view','sis.assessment.manage',
 'sis.discounts.manage','sis.balances.view','sis.balances.approve','sis.payments.receive','sis.payments.void',
 'sis.receipts.view','sis.journals.prepare','sis.journals.review','sis.journals.post','sis.periods.close',
 'sis.reconciliation.manage','sis.reports.view','sis.audit.view')
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_v1');

INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,'ALL' FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='REGISTRAR' AND p.code IN(
 'grading.workspace.view','grading.students.view','grading.students.manage','grading.sections.manage',
 'grading.subjects.manage','grading.curriculum.manage','grading.teachers.manage','grading.grades.view',
 'grading.grades.finalize','grading.corrections.review','grading.reports.view',
 'sis.workspace.view','sis.students.view','sis.students.manage','sis.admission.view','sis.admission.manage',
 'sis.admission.approve','sis.registration.view','sis.registration.manage','sis.enrollment.confirm',
 'sis.eligibility.view','sis.eligibility.holds.manage','sis.assessment.view','sis.balances.view',
 'sis.receipts.view','sis.reports.view','sis.audit.view')
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_v1');

INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,IF(p.code='grading.workspace.view','ALL','ASSIGNED')
FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='TEACHER' AND p.code IN('grading.workspace.view','grading.students.view',
 'grading.grades.view','grading.grades.enter','grading.grades.submit')
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_v1');

INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,'OWN' FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='STUDENT' AND p.workspace='PORTAL'
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_v1');

INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES('central_accounts_v1');

-- Runtime integration: preserve the existing registrar academic editing workflow.
INSERT IGNORE INTO central_group_permissions(group_id,permission_code,access_scope)
SELECT g.id,p.code,'ALL' FROM central_groups g CROSS JOIN central_permissions p
WHERE g.code='REGISTRAR' AND p.code IN('grading.grades.enter','grading.grades.submit')
AND NOT EXISTS(SELECT 1 FROM central_schema_migrations WHERE migration_key='central_accounts_runtime_v2');
INSERT IGNORE INTO central_schema_migrations(migration_key) VALUES('central_accounts_runtime_v2');


-- 8. FIRST POWER USER — NO CLI SETUP REQUIRED
-- v63.3: power.admin has NO usable password ('!setup'): the installer sets it on SIS → First-time setup
-- (proving they installed the site with the database password from api/config.php). No password is published.
-- Account seeding is transactional and serialized. It runs ONLY if there are no
-- central accounts and no legacy identity already using this initial username.
START TRANSACTION;
SELECT id FROM central_security_policy WHERE id=1 FOR UPDATE;
SET @acadlink_new_power=(SELECT COUNT(*)=0 FROM central_accounts);
SET @acadlink_new_power=IF(@acadlink_new_power=1
 AND NOT EXISTS(SELECT 1 FROM sis_users WHERE username='power.admin')
 AND NOT EXISTS(SELECT 1 FROM docs WHERE col='users' AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(data,'$.username')))='power.admin'),1,0);
INSERT INTO central_accounts(display_name,account_kind,status,must_change_password)
SELECT 'Power User / Super Admin','STAFF','ACTIVE',1 WHERE @acadlink_new_power=1;
SET @acadlink_power_id=IF(@acadlink_new_power=1,LAST_INSERT_ID(),NULL);
INSERT INTO central_credentials(account_id,password_hash)
SELECT @acadlink_power_id,'!setup' WHERE @acadlink_new_power=1;
INSERT INTO central_login_identifiers(account_id,identifier,identifier_kind,is_primary,verified_at)
SELECT @acadlink_power_id,'power.admin','USERNAME',1,NOW() WHERE @acadlink_new_power=1;
INSERT INTO central_person_links(account_id,verified_at)
SELECT @acadlink_power_id,NOW() WHERE @acadlink_new_power=1;
INSERT INTO central_user_groups(account_id,group_id)
SELECT @acadlink_power_id,id FROM central_groups WHERE code='POWER_USER' AND @acadlink_new_power=1;
INSERT INTO sis_users(username,full_name,password_hash,role,is_active,must_change_password)
SELECT 'power.admin','Power User / Super Admin','!setup','ADMIN',1,1
WHERE @acadlink_new_power=1;
SET @acadlink_sis_id=IF(@acadlink_new_power=1,LAST_INSERT_ID(),NULL);
SET @acadlink_grading_uid=REPLACE(UUID(),'-','');
INSERT INTO central_legacy_links(source_system,source_key,account_id,verification_note)
SELECT 'SIS',CAST(@acadlink_sis_id AS CHAR),@acadlink_power_id,'Created together by INSTALL.sql on a fresh installation' WHERE @acadlink_new_power=1;
INSERT INTO central_legacy_links(source_system,source_key,account_id,verification_note)
SELECT 'GRADING',@acadlink_grading_uid,@acadlink_power_id,'Created together by INSTALL.sql on a fresh installation' WHERE @acadlink_new_power=1;
INSERT INTO docs(col,id,data)
SELECT 'users',@acadlink_grading_uid,JSON_OBJECT('username','power.admin','displayName','Power User / Super Admin',
 'role','admin','canApprove',JSON_EXTRACT('true','$'),'teacherDocId',NULL,'active',JSON_EXTRACT('true','$'),'mustChangePassword',JSON_EXTRACT('true','$'),'authEmail','power.admin@central.invalid')
WHERE @acadlink_new_power=1;
INSERT INTO central_account_audit(actor_account_id,target_account_id,action,details)
SELECT NULL,@acadlink_power_id,'installer_power_user_created',JSON_OBJECT('source','INSTALL.sql','requires_password_change',TRUE) WHERE @acadlink_new_power=1;
INSERT IGNORE INTO central_schema_migrations(migration_key)
SELECT 'one_file_installer_bootstrap_v1' WHERE @acadlink_new_power=1;
COMMIT;
SET FOREIGN_KEY_CHECKS=@acadlink_old_fk_checks;
SET time_zone=@acadlink_old_timezone;
SELECT 'AcadLink database installation complete' AS result,
 (SELECT COUNT(*) FROM central_groups) AS default_groups,
 CASE WHEN @acadlink_new_power=1 THEN 'First Power User created: power.admin. Use the temporary password in INSTALL.sql and change it at sign-in.'
 WHEN EXISTS(SELECT 1 FROM central_accounts) THEN 'Existing central accounts kept. Passwords and access were not reset.'
 ELSE 'No initial user created: a legacy power.admin exists. Review migration links; no identity was claimed automatically.' END AS account_setup;


-- Reserved directory placeholder only. No login, password or central account is created.
INSERT IGNORE INTO docs(col,id,data) VALUES
('teachers','_acadlink_professor_x','{"teacherId":"PROF-X","teacherName":"Professor X","isPlaceholder":true}');


-- Private staging for atomic Grading Excel restore. No live grading rows change during upload.
CREATE TABLE IF NOT EXISTS grading_restore_jobs (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_account_id BIGINT UNSIGNED NOT NULL,
 actor_auth_version BIGINT UNSIGNED NOT NULL,
 policy_version BIGINT UNSIGNED NOT NULL,
 session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 restore_mode ENUM('merge','replace') NOT NULL,
 expected_counts JSON NOT NULL,
 expected_chunks INT UNSIGNED NOT NULL,
 received_chunks JSON NOT NULL,
 total_rows INT UNSIGNED NOT NULL,
 received_rows INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('STAGING','COMMITTED') NOT NULL DEFAULT 'STAGING',
 result JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(token_hash), KEY k_grading_restore_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS grading_restore_records (
 job_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 col VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
 data JSON NOT NULL,
 PRIMARY KEY(job_hash,col,id),
 CONSTRAINT fk_grading_restore_job FOREIGN KEY(job_hash) REFERENCES grading_restore_jobs(token_hash) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Permanent student history and migration staging.
CREATE TABLE IF NOT EXISTS sis_student_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 student_doc_id VARCHAR(191) NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
 snapshot JSON NOT NULL,
 captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY u_student_history(fingerprint), KEY k_student_history(student_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_teacher_student_history (
 teacher_doc_id VARCHAR(191) NOT NULL,
 student_doc_id VARCHAR(191) NOT NULL,
 assignment_id VARCHAR(191) NOT NULL,
 school_year VARCHAR(9) NOT NULL,
 term VARCHAR(20) NOT NULL,
 captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(teacher_doc_id,student_doc_id,assignment_id), KEY k_teacher_history_student(student_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_migration_batches (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 kind ENUM('BALANCES','GRADES') NOT NULL,
 file_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 source_name VARCHAR(191) NOT NULL,
 status ENUM('DRAFT','APPROVED','IMPORTED','REVERSED','DISCARDED') NOT NULL DEFAULT 'DRAFT',
 input_json JSON NOT NULL,
 review_json JSON NOT NULL,
 approved_hash CHAR(64) CHARACTER SET ascii NULL,
 equity_account_id INT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL,
 reviewed_by INT UNSIGNED NULL,
 review_reason VARCHAR(1000) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 imported_at DATETIME NULL,
 PRIMARY KEY(id), UNIQUE KEY u_migration_file(kind,file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_payment_items (
 payment_id INT UNSIGNED NOT NULL,
 item_id INT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(payment_id,item_id),
 CONSTRAINT fk_migration_allocation_payment FOREIGN KEY(payment_id) REFERENCES sis_payments(id),
 CONSTRAINT fk_migration_allocation_item FOREIGN KEY(item_id) REFERENCES sis_registration_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_claims (
 key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 batch_id INT UNSIGNED NOT NULL,
 source_row INT UNSIGNED NOT NULL,
 PRIMARY KEY(key_hash), KEY k_migration_claim_batch(batch_id),
 CONSTRAINT fk_migration_claim_batch FOREIGN KEY(batch_id) REFERENCES sis_migration_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_targets (
 batch_id INT UNSIGNED NOT NULL,
 kind ENUM('REGISTRATION','COMPLETION') NOT NULL,
 target_id VARCHAR(191) NOT NULL,
 PRIMARY KEY(batch_id,kind,target_id),
 CONSTRAINT fk_migration_target_batch FOREIGN KEY(batch_id) REFERENCES sis_migration_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Accounting workflow additions. Existing journals remain posted on upgrade. */
CREATE TABLE IF NOT EXISTS sis_accounting_control (
 id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO sis_accounting_control(id) VALUES(1);
CREATE TABLE IF NOT EXISTS sis_accounting_periods (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 start_date DATE NOT NULL, end_date DATE NOT NULL,
 status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'CLOSED',
 reason VARCHAR(1000) NOT NULL, changed_by INT UNSIGNED NULL, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY u_sis_period (start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_suppliers (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, reference_no VARCHAR(80) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY u_sis_supplier_ref (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_documents (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('BILL','DISBURSEMENT','EXPENSE','REIMBURSEMENT','PAYROLL','DEPRECIATION','ADJUSTMENT','REFUND') NOT NULL,
 reference_no VARCHAR(100) NOT NULL, journal_id INT UNSIGNED NOT NULL,
 supplier_id INT UNSIGNED NULL, bill_id INT UNSIGNED NULL,
 registration_id INT UNSIGNED NULL, payment_id INT UNSIGNED NULL,
 amount DECIMAL(12,2) NOT NULL, direction ENUM('CHARGE','LESS') NULL,
 item_id INT UNSIGNED NULL, reversal_item_id INT UNSIGNED NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY u_sis_document_reference (kind,reference_no), UNIQUE KEY u_sis_document_journal (journal_id),
 KEY k_sis_document_reg (registration_id), KEY k_sis_document_bill (bill_id),
 CONSTRAINT fk_sis_document_journal FOREIGN KEY (journal_id) REFERENCES sis_journal(id),
 CONSTRAINT fk_sis_document_supplier FOREIGN KEY (supplier_id) REFERENCES sis_accounting_suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_budgets (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 start_date DATE NOT NULL, end_date DATE NOT NULL, school_year VARCHAR(9) NOT NULL,
 department VARCHAR(80) NOT NULL, fund ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL,
 account_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL,
 created_by INT UNSIGNED NULL,
 UNIQUE KEY u_sis_budget (start_date,end_date,school_year,department,fund,account_id),
 CONSTRAINT fk_sis_budget_account FOREIGN KEY (account_id) REFERENCES sis_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_assets (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 asset_code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, acquired_date DATE NOT NULL,
 cost DECIMAL(12,2) NOT NULL, residual DECIMAL(12,2) NOT NULL, useful_months INT UNSIGNED NOT NULL,
 asset_account_id INT UNSIGNED NOT NULL, expense_account_id INT UNSIGNED NOT NULL, accumulated_account_id INT UNSIGNED NOT NULL,
 fund ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL, department VARCHAR(80) NOT NULL,
 created_by INT UNSIGNED NULL,
 UNIQUE KEY u_sis_asset_code (asset_code),
 CONSTRAINT fk_sis_asset_account FOREIGN KEY (asset_account_id) REFERENCES sis_accounts(id),
 CONSTRAINT fk_sis_asset_expense FOREIGN KEY (expense_account_id) REFERENCES sis_accounts(id),
 CONSTRAINT fk_sis_asset_accumulated FOREIGN KEY (accumulated_account_id) REFERENCES sis_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO sis_accounts(code,name,type,is_cash,description) VALUES
 ('1200','School Equipment','ASSET',0,'Computers, laboratory and school equipment'),
 ('1290','Accumulated Depreciation','ASSET',0,'Credit balance deducted from equipment'),
 ('2200','Accounts Payable - Suppliers','LIABILITY',0,'Unpaid supplier bills'),
 ('2300','Payroll Payable','LIABILITY',0,'Approved payroll awaiting payment'),
 ('5020','Salaries and Wages','EXPENSE',0,'Teacher and staff payroll summaries'),
 ('5030','Depreciation Expense','EXPENSE',0,'Periodic school asset depreciation');
CREATE TABLE IF NOT EXISTS sis_payment_allocations (
 payment_id INT UNSIGNED NOT NULL, item_id INT UNSIGNED NOT NULL,
 category_name VARCHAR(80) NOT NULL, fee_name VARCHAR(100) NOT NULL, amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(payment_id,item_id), KEY k_sis_allocation_item(item_id),
 CONSTRAINT fk_sis_allocation_payment FOREIGN KEY(payment_id) REFERENCES sis_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_refund_allocations (
 document_id INT UNSIGNED NOT NULL, item_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(document_id,item_id), KEY k_sis_refund_item(item_id),
 CONSTRAINT fk_sis_refund_document FOREIGN KEY(document_id) REFERENCES sis_accounting_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student-owned enrollment applications; admission for new applicants is unchanged.
CREATE TABLE IF NOT EXISTS sis_portal_enrollments (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 account_id BIGINT UNSIGNED NOT NULL,
 student_number VARCHAR(40) NOT NULL,
 student_doc_id VARCHAR(191) NOT NULL,
 student_name VARCHAR(200) NOT NULL,
 school_year VARCHAR(9) NOT NULL,
 term VARCHAR(20) NOT NULL,
 current_enrollment LONGTEXT NULL,
 requested_year_level VARCHAR(30) NULL,
 preferred_section_doc_id VARCHAR(191) NULL,
 preferred_section_name VARCHAR(150) NULL,
 approved_placement LONGTEXT NULL,
 status ENUM('PENDING','RETURNED','ACCEPTED','REJECTED') NOT NULL DEFAULT 'PENDING',
 student_note TEXT NULL,
 review_note TEXT NULL,
 eligibility_snapshot LONGTEXT NOT NULL,
 registration_id INT UNSIGNED NULL,
 reviewed_by INT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 assessment_hash CHAR(64) NULL,
 assessment_published_at DATETIME NULL,
 assessment_published_by INT UNSIGNED NULL,
 minimum_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 active_flag TINYINT GENERATED ALWAYS AS (IF(status='REJECTED',NULL,1)) STORED,
 PRIMARY KEY(id),
 UNIQUE KEY u_portal_enrollment_active(student_number,school_year,term,active_flag),
 UNIQUE KEY u_portal_enrollment_registration(registration_id),
 KEY k_portal_enrollment_owner(account_id,student_number),
 KEY k_portal_enrollment_review(status,school_year,term),
 CONSTRAINT fk_portal_enrollment_registration FOREIGN KEY(registration_id) REFERENCES sis_registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sis_portal_enrollment_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 application_id INT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 message TEXT NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY k_portal_enrollment_event(application_id,id),
 CONSTRAINT fk_portal_enrollment_event FOREIGN KEY(application_id) REFERENCES sis_portal_enrollments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment plan templates, immutable registration terms and auditable earned discounts.
CREATE TABLE IF NOT EXISTS sis_payment_plans (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,kind ENUM('FULL','INSTALLMENT') NOT NULL,rules LONGTEXT NOT NULL,is_active TINYINT NOT NULL DEFAULT 1,created_by BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_registration_plans (registration_id BIGINT UNSIGNED PRIMARY KEY,plan_id BIGINT UNSIGNED NOT NULL,snapshot LONGTEXT NOT NULL,assigned_by BIGINT UNSIGNED NOT NULL,assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,earned_cents BIGINT NOT NULL DEFAULT 0,discount_item_id BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_payment_plan_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,registration_id BIGINT UNSIGNED NOT NULL,delta_cents BIGINT NOT NULL,journal_id BIGINT UNSIGNED NULL,actor_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_plan_registration(registration_id),UNIQUE KEY u_plan_journal(journal_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
