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
