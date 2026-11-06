/* ========================================================================== */
/* OPTIONAL: removes the tables of the EARLIER SIS version (before the sis_ tables). */
/* Only run it if you installed that earlier version, and only after a backup. */
/* It never touches the grading system (docs, accounts, login_attempts) or the sis_ tables. */
/* ========================================================================== */
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS scholarship_usage, assessment_discounts, student_scholarships, scholarship_documents,
  scholarship_application_history, scholarship_applications, scholarship_types, refunds, receipts,
  payment_allocations, payments, assessment_adjustments, assessment_items, assessment_headers,
  fee_schedule_rules, fee_schedules, fee_types, subject_credit_evaluations, transferred_subjects,
  student_academic_evaluations, grade_corrections, student_grades, enrollment_subjects, enrollments,
  registration_applications, student_documents, students, class_offerings, sections, prerequisites,
  curriculum_subjects, curricula, subjects, year_levels, programs, academic_terms, academic_years,
  sequences, settings, role_permissions, users, permissions, roles, audit_logs;
SET FOREIGN_KEY_CHECKS = 1;
