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
