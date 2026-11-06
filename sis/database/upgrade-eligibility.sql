-- Select the existing AcadLink database before importing. Safe to repeat.
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
