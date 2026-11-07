/*
  AcadLink SIS demo seed — MySQL 5.7+ / MariaDB 10.3+

  Run only against a disposable test database after INSTALL.sql and the SIS
  schema are installed. This deliberately creates synthetic, confirmed,
  zero-fee registrations by SQL; it does not run eligibility checks, audit
  events, admission, assessment, or accounting workflows. Do not use on a live
  school database.

  Contents:
    5,000 students across five sample programs and four year levels
    100 sections (50 students per section)
    3,000 teachers
    10,000 subjects named Subject 1 … Subject 10000
    20 curricula and 500 matching grading-class rosters
    5,000 confirmed SIS registration records with five subjects each
    500 inactive, credential-free Registrar account placeholders
    500 inactive demo fee labels (not attached to assessments)

  Sample programs: BSN, BSIT, BSCS, BSBA, BSA. All registrations use
  2026-2027, 1st Semester. Change these CASE expressions if your test data
  should use different program names.
*/

SET NAMES utf8mb4;
SET @demo_old_group_concat_max_len := @@SESSION.group_concat_max_len;
SET SESSION group_concat_max_len = 1048576;

DROP TEMPORARY TABLE IF EXISTS demo_bulk_numbers;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_section_map;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_student_map;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_curriculum_map;

CREATE TEMPORARY TABLE demo_bulk_numbers (
  n INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO demo_bulk_numbers (n)
SELECT d0.digit + (10 * d1.digit) + (100 * d2.digit) + (1000 * d3.digit) + 1
FROM (SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d0
CROSS JOIN (SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d1
CROSS JOIN (SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d2
CROSS JOIN (SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d3;

CREATE TEMPORARY TABLE demo_bulk_section_map (
  section_no       SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  program_no       TINYINT UNSIGNED NOT NULL,
  year_no          TINYINT UNSIGNED NOT NULL,
  section_in_year  TINYINT UNSIGNED NOT NULL,
  program          VARCHAR(10) NOT NULL,
  year_level       VARCHAR(20) NOT NULL,
  section_id       VARCHAR(191) NOT NULL,
  section_name     VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

INSERT INTO demo_bulk_section_map
  (section_no, program_no, year_no, section_in_year, program, year_level, section_id, section_name)
SELECT x.section_no, x.program_no, x.year_no, x.section_in_year,
       CASE x.program_no WHEN 1 THEN 'BSN' WHEN 2 THEN 'BSIT' WHEN 3 THEN 'BSCS' WHEN 4 THEN 'BSBA' ELSE 'BSA' END,
       CASE x.year_no WHEN 1 THEN '1st Year' WHEN 2 THEN '2nd Year' WHEN 3 THEN '3rd Year' ELSE '4th Year' END,
       CONCAT('demo_bulk_2026_section_', LPAD(x.section_no, 3, '0')),
       CONCAT(CASE x.program_no WHEN 1 THEN 'BSN' WHEN 2 THEN 'BSIT' WHEN 3 THEN 'BSCS' WHEN 4 THEN 'BSBA' ELSE 'BSA' END,
              ' ', x.year_no, CHAR(64 + x.section_in_year), ' DEMO')
FROM (
  SELECT n AS section_no,
         FLOOR((n - 1) / 20) + 1 AS program_no,
         FLOOR(MOD(n - 1, 20) / 5) + 1 AS year_no,
         MOD(n - 1, 5) + 1 AS section_in_year
  FROM demo_bulk_numbers
  WHERE n <= 100
) x;

CREATE TEMPORARY TABLE demo_bulk_student_map (
  student_no       INT UNSIGNED NOT NULL PRIMARY KEY,
  program_no       TINYINT UNSIGNED NOT NULL,
  year_no          TINYINT UNSIGNED NOT NULL,
  section_no       SMALLINT UNSIGNED NOT NULL,
  program          VARCHAR(10) NOT NULL,
  year_level       VARCHAR(20) NOT NULL,
  section_id       VARCHAR(191) NOT NULL,
  section_name     VARCHAR(80) NOT NULL,
  student_id       VARCHAR(40) NOT NULL,
  student_doc_id   VARCHAR(191) NOT NULL,
  registration_no  VARCHAR(20) NOT NULL,
  KEY k_demo_section (section_no)
) ENGINE=InnoDB;

INSERT INTO demo_bulk_student_map
  (student_no, program_no, year_no, section_no, program, year_level, section_id, section_name, student_id, student_doc_id, registration_no)
SELECT y.student_no, y.program_no, y.year_no, s.section_no, s.program, s.year_level, s.section_id, s.section_name,
       CONCAT('DEMO26-', LPAD(y.student_no, 5, '0')),
       CONCAT('demo_bulk_2026_student_', LPAD(y.student_no, 5, '0')),
       CONCAT('REG-DMO-', LPAD(y.student_no, 6, '0'))
FROM (
  SELECT n AS student_no,
         FLOOR((n - 1) / 1000) + 1 AS program_no,
         FLOOR(MOD(n - 1, 1000) / 250) + 1 AS year_no,
         FLOOR(MOD(n - 1, 250) / 50) + 1 AS section_in_year,
         ((FLOOR((n - 1) / 1000)) * 20)
           + (FLOOR(MOD(n - 1, 1000) / 250) * 5)
           + FLOOR(MOD(n - 1, 250) / 50) + 1 AS section_no
  FROM demo_bulk_numbers
  WHERE n <= 5000
) y
JOIN demo_bulk_section_map s ON s.section_no = y.section_no;

CREATE TEMPORARY TABLE demo_bulk_curriculum_map (
  group_no       TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  program_no     TINYINT UNSIGNED NOT NULL,
  year_no        TINYINT UNSIGNED NOT NULL,
  program        VARCHAR(10) NOT NULL,
  year_level     VARCHAR(20) NOT NULL,
  curriculum_id  VARCHAR(191) NOT NULL,
  subject_start  SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO demo_bulk_curriculum_map (group_no, program_no, year_no, program, year_level, curriculum_id, subject_start)
SELECT x.group_no, x.program_no, x.year_no,
       CASE x.program_no WHEN 1 THEN 'BSN' WHEN 2 THEN 'BSIT' WHEN 3 THEN 'BSCS' WHEN 4 THEN 'BSBA' ELSE 'BSA' END,
       CASE x.year_no WHEN 1 THEN '1st Year' WHEN 2 THEN '2nd Year' WHEN 3 THEN '3rd Year' ELSE '4th Year' END,
       CONCAT(LOWER(CASE x.program_no WHEN 1 THEN 'BSN' WHEN 2 THEN 'BSIT' WHEN 3 THEN 'BSCS' WHEN 4 THEN 'BSBA' ELSE 'BSA' END), '-',
              CASE x.year_no WHEN 1 THEN '1st-year' WHEN 2 THEN '2nd-year' WHEN 3 THEN '3rd-year' ELSE '4th-year' END,
              '-1st-semester'),
       ((x.group_no - 1) * 5) + 1
FROM (
  SELECT n AS group_no, FLOOR((n - 1) / 4) + 1 AS program_no, MOD(n - 1, 4) + 1 AS year_no
  FROM demo_bulk_numbers
  WHERE n <= 20
) x;

START TRANSACTION;

/* Sections and student records. */
INSERT INTO docs (col, id, data)
SELECT 'sections', section_id,
       JSON_OBJECT('schoolYear', '2026-2027', 'yearLevel', year_level, 'sectionName', section_name)
FROM demo_bulk_section_map
ON DUPLICATE KEY UPDATE data = VALUES(data);

INSERT INTO docs (col, id, data)
SELECT 'students', m.student_doc_id,
       JSON_OBJECT('studentId', m.student_id,
                   'studentName', CONCAT('Student, Demo ', LPAD(m.student_no, 5, '0')),
                   'email', CONCAT('demo.student', LPAD(m.student_no, 5, '0'), '@example.test'),
                   'schoolYear', '2026-2027', 'yearLevel', m.year_level,
                   'sectionId', m.section_id, 'sectionName', m.section_name,
                   'program', m.program)
FROM demo_bulk_student_map m
ON DUPLICATE KEY UPDATE data = VALUES(data);

/* 3,000 teacher records. */
INSERT INTO docs (col, id, data)
SELECT 'teachers', CONCAT('demo_bulk_2026_teacher_', LPAD(n, 4, '0')),
       JSON_OBJECT('teacherId', CONCAT('DEMO26-T', LPAD(n, 4, '0')),
                   'teacherName', CONCAT('Teacher ', LPAD(n, 4, '0')))
FROM demo_bulk_numbers
WHERE n <= 3000
ON DUPLICATE KEY UPDATE data = VALUES(data);

/* 10,000 subjects named exactly Subject 1 through Subject 10000. */
INSERT INTO docs (col, id, data)
SELECT 'subjects', CONCAT('demo_bulk_2026_subject_', LPAD(n, 5, '0')),
       JSON_OBJECT('subjectCode', CONCAT('DEMO26-S', LPAD(n, 5, '0')),
                   'subjectName', CONCAT('Subject ', n), 'units', 3)
FROM demo_bulk_numbers
WHERE n <= 10000
ON DUPLICATE KEY UPDATE data = VALUES(data);

/* Twenty program/year curricula. The first 100 subjects and teachers provide five
   subjects and assigned teachers for each of the twenty program/year combinations. */
INSERT INTO docs (col, id, data)
SELECT 'curriculum', c.curriculum_id,
       CONCAT('{"program":', JSON_QUOTE(c.program),
              ',"yearLevel":', JSON_QUOTE(c.year_level),
              ',"term":"1st Semester","subjectIds":[',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start, 5, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 1, 5, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 2, 5, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 3, 5, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 4, 5, '0'))),
              '],"subjectTeachers":{',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start, 5, '0'))), ':', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD((c.group_no - 1) * 5 + 1, 4, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 1, 5, '0'))), ':', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD((c.group_no - 1) * 5 + 2, 4, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 2, 5, '0'))), ':', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD((c.group_no - 1) * 5 + 3, 4, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 3, 5, '0'))), ':', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD((c.group_no - 1) * 5 + 4, 4, '0'))), ',',
              JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(c.subject_start + 4, 5, '0'))), ':', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD((c.group_no - 1) * 5 + 5, 4, '0'))),
              '}}')
FROM demo_bulk_curriculum_map c
ON DUPLICATE KEY UPDATE data = VALUES(data);

/* Demo fee labels are inactive and are not attached to an assessment. */
INSERT IGNORE INTO sis_fee_categories (name, description, sort_order, is_active)
VALUES ('DEMO Bulk Fees', 'Inactive fee-label fixtures created by demo_bulk_seed.sql.', 999, 0);
SET @demo_bulk_fee_category_id := (SELECT id FROM sis_fee_categories WHERE name = 'DEMO Bulk Fees' LIMIT 1);

INSERT IGNORE INTO sis_fee_labels (category_id, name, description, charge_type, default_amount, is_active)
SELECT @demo_bulk_fee_category_id,
       CONCAT('DEMO Fee Item ', LPAD(n, 4, '0')),
       'Inactive test fixture; not assigned to any student assessment.',
       'FIXED', 0.00, 0
FROM demo_bulk_numbers
WHERE n <= 500;

/* The placeholders appear in Central Accounts under Registrar, but cannot sign in:
   they are disabled, their identifier is unverified, and no password credential is made. */
INSERT INTO central_accounts (display_name, account_kind, status, must_change_password)
SELECT CONCAT('DEMO Registrar ', LPAD(n, 4, '0'), ' [Bulk Seed]'),
       'STAFF', 'DISABLED', 1
FROM demo_bulk_numbers n
JOIN central_groups g ON g.code = 'REGISTRAR' AND g.is_active = 1
WHERE n.n <= 500
  AND NOT EXISTS (
    SELECT 1 FROM central_login_identifiers i
    WHERE i.identifier_key = LOWER(CONCAT('demo.registrar.bulk.', LPAD(n.n, 4, '0')))
  )
  AND NOT EXISTS (
    SELECT 1 FROM central_accounts a
    WHERE a.display_name = CONCAT('DEMO Registrar ', LPAD(n.n, 4, '0'), ' [Bulk Seed]')
  );

INSERT INTO central_login_identifiers (account_id, identifier, identifier_kind, is_primary, verified_at)
SELECT a.id, CONCAT('demo.registrar.bulk.', LPAD(n.n, 4, '0')), 'USERNAME', 1, NULL
FROM demo_bulk_numbers n
JOIN central_accounts a
  ON a.display_name = CONCAT('DEMO Registrar ', LPAD(n.n, 4, '0'), ' [Bulk Seed]')
 AND a.account_kind = 'STAFF' AND a.status = 'DISABLED'
WHERE n.n <= 500
  AND NOT EXISTS (
    SELECT 1 FROM central_login_identifiers i
    WHERE i.identifier_key = LOWER(CONCAT('demo.registrar.bulk.', LPAD(n.n, 4, '0')))
  )
  AND NOT EXISTS (
    SELECT 1 FROM central_login_identifiers i
    WHERE i.account_id = a.id AND i.is_primary = 1
  );

INSERT IGNORE INTO central_user_groups (account_id, group_id, assigned_by)
SELECT a.id, g.id, NULL
FROM demo_bulk_numbers n
JOIN central_accounts a
  ON a.display_name = CONCAT('DEMO Registrar ', LPAD(n.n, 4, '0'), ' [Bulk Seed]')
 AND a.account_kind = 'STAFF' AND a.status = 'DISABLED'
JOIN central_login_identifiers i
  ON i.account_id = a.id
 AND i.identifier = CONCAT('demo.registrar.bulk.', LPAD(n.n, 4, '0'))
JOIN central_groups g ON g.code = 'REGISTRAR' AND g.is_active = 1
WHERE n.n <= 500;

/* Confirmed zero-fee SIS registrations. The demo actor is only a provenance value;
   this SQL does not perform the application's confirmation or accounting workflow. */
SET @demo_bulk_actor_id := COALESCE((SELECT MIN(id) FROM sis_users), 0);
SET @demo_bulk_now := NOW();

INSERT INTO sis_registrations
  (reg_no, record_origin, student_number, student_doc_id, student_name,
   school_year, term, year_level, section_doc_id, section_name, program,
   assessment_name_id, total_units, total_amount, amount_paid, status,
   locked_at, locked_by, created_by, created_at, updated_at)
SELECT m.registration_no, 'ENROLLMENT', m.student_id, m.student_doc_id,
       CONCAT('Student, Demo ', LPAD(m.student_no, 5, '0')),
       '2026-2027', '1st Semester', m.year_level, m.section_id, m.section_name, m.program,
       NULL, 15.0, 0.00, 0.00, 'REGISTERED',
       @demo_bulk_now, IF(@demo_bulk_actor_id = 0, NULL, @demo_bulk_actor_id),
       @demo_bulk_actor_id, @demo_bulk_now, @demo_bulk_now
FROM demo_bulk_student_map m
WHERE NOT EXISTS (
  SELECT 1 FROM sis_registrations r
  WHERE r.reg_no = m.registration_no
     OR (r.student_number = m.student_id AND r.school_year = '2026-2027'
         AND r.term = '1st Semester' AND r.status <> 'CANCELLED')
);

/* Five subjects from the student's program/year curriculum. */
INSERT INTO sis_registration_subjects (registration_id, subject_doc_id, subject_code, subject_name, units)
SELECT r.id,
       CONCAT('demo_bulk_2026_subject_', LPAD(((m.program_no - 1) * 4 + m.year_no - 1) * 5 + slot.n, 5, '0')),
       CONCAT('DEMO26-S', LPAD(((m.program_no - 1) * 4 + m.year_no - 1) * 5 + slot.n, 5, '0')),
       CONCAT('Subject ', ((m.program_no - 1) * 4 + m.year_no - 1) * 5 + slot.n),
       3.0
FROM demo_bulk_student_map m
JOIN demo_bulk_numbers slot ON slot.n <= 5
JOIN sis_registrations r ON r.reg_no = m.registration_no
                         AND r.status = 'REGISTERED' AND r.locked_at IS NOT NULL
WHERE NOT EXISTS (
  SELECT 1 FROM sis_registration_subjects rs
  WHERE rs.registration_id = r.id
    AND rs.subject_doc_id = CONCAT('demo_bulk_2026_subject_', LPAD(((m.program_no - 1) * 4 + m.year_no - 1) * 5 + slot.n, 5, '0'))
);

/* Class rosters mirror the confirmed registrations so these students also appear
   in the Grading System's classes for their course, year, section, and semester. */
INSERT INTO docs (col, id, data)
SELECT 'gradingAssignments',
       CONCAT('demo_bulk_2026_assignment_', LPAD((s.section_no - 1) * 5 + slot.n, 4, '0')),
       CONCAT('{"teacherDocId":', JSON_QUOTE(CONCAT('demo_bulk_2026_teacher_', LPAD(((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n, 4, '0'))),
              ',"teacherId":', JSON_QUOTE(CONCAT('DEMO26-T', LPAD(((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n, 4, '0'))),
              ',"teacherName":', JSON_QUOTE(CONCAT('Teacher ', LPAD(((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n, 4, '0'))),
              ',"subjectId":', JSON_QUOTE(CONCAT('demo_bulk_2026_subject_', LPAD(((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n, 5, '0'))),
              ',"subjectCode":', JSON_QUOTE(CONCAT('DEMO26-S', LPAD(((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n, 5, '0'))),
              ',"subjectName":', JSON_QUOTE(CONCAT('Subject ', ((s.program_no - 1) * 4 + s.year_no - 1) * 5 + slot.n)),
              ',"units":3,"schoolYear":"2026-2027","term":"1st Semester"',
              ',"yearLevel":', JSON_QUOTE(s.year_level),
              ',"sectionId":', JSON_QUOTE(s.section_id),
              ',"sectionName":', JSON_QUOTE(s.section_name),
              ',"studentIds":[', GROUP_CONCAT(JSON_QUOTE(st.student_doc_id) ORDER BY st.student_no SEPARATOR ','), ']',
              ',"curriculumId":', JSON_QUOTE(CONCAT(LOWER(s.program), '-',
                 CASE s.year_no WHEN 1 THEN '1st-year' WHEN 2 THEN '2nd-year' WHEN 3 THEN '3rd-year' ELSE '4th-year' END,
                 '-1st-semester')),
              ',"autoCreated":true}')
FROM demo_bulk_section_map s
JOIN demo_bulk_numbers slot ON slot.n <= 5
JOIN demo_bulk_student_map st ON st.section_no = s.section_no
GROUP BY s.section_no, s.program_no, s.year_no, s.year_level, s.section_id, s.section_name, s.program, slot.n
ON DUPLICATE KEY UPDATE data = VALUES(data);

COMMIT;

DROP TEMPORARY TABLE IF EXISTS demo_bulk_curriculum_map;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_student_map;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_section_map;
DROP TEMPORARY TABLE IF EXISTS demo_bulk_numbers;
SET SESSION group_concat_max_len = @demo_old_group_concat_max_len;

/* Verification counts and distribution. */
SELECT 'Students' AS demo_data, COUNT(*) AS rows_created
FROM docs WHERE col = 'students' AND id LIKE 'demo_bulk_2026_student_%'
UNION ALL
SELECT 'Teachers', COUNT(*) FROM docs WHERE col = 'teachers' AND id LIKE 'demo_bulk_2026_teacher_%'
UNION ALL
SELECT 'Subjects', COUNT(*) FROM docs WHERE col = 'subjects' AND id LIKE 'demo_bulk_2026_subject_%'
UNION ALL
SELECT 'Sections', COUNT(*) FROM docs WHERE col = 'sections' AND id LIKE 'demo_bulk_2026_section_%'
UNION ALL
SELECT 'Curricula', COUNT(*) FROM docs WHERE col = 'curriculum' AND id IN (
  'bsn-1st-year-1st-semester','bsn-2nd-year-1st-semester','bsn-3rd-year-1st-semester','bsn-4th-year-1st-semester',
  'bsit-1st-year-1st-semester','bsit-2nd-year-1st-semester','bsit-3rd-year-1st-semester','bsit-4th-year-1st-semester',
  'bscs-1st-year-1st-semester','bscs-2nd-year-1st-semester','bscs-3rd-year-1st-semester','bscs-4th-year-1st-semester',
  'bsba-1st-year-1st-semester','bsba-2nd-year-1st-semester','bsba-3rd-year-1st-semester','bsba-4th-year-1st-semester',
  'bsa-1st-year-1st-semester','bsa-2nd-year-1st-semester','bsa-3rd-year-1st-semester','bsa-4th-year-1st-semester')
UNION ALL
SELECT 'Grading classes', COUNT(*) FROM docs WHERE col = 'gradingAssignments' AND id LIKE 'demo_bulk_2026_assignment_%'
UNION ALL
SELECT 'Registrations', COUNT(*) FROM sis_registrations WHERE reg_no LIKE 'REG-DMO-%' AND school_year = '2026-2027' AND term = '1st Semester'
UNION ALL
SELECT 'Registration subject rows', COUNT(*) FROM sis_registration_subjects rs JOIN sis_registrations r ON r.id = rs.registration_id WHERE r.reg_no LIKE 'REG-DMO-%'
UNION ALL
SELECT 'Fee labels', COUNT(*) FROM sis_fee_labels WHERE name LIKE 'DEMO Fee Item %'
UNION ALL
SELECT 'Registrar placeholders', COUNT(DISTINCT a.id)
FROM central_accounts a JOIN central_login_identifiers i ON i.account_id = a.id
JOIN central_user_groups ug ON ug.account_id = a.id AND ug.revoked_at IS NULL
JOIN central_groups g ON g.id = ug.group_id AND g.code = 'REGISTRAR'
WHERE a.display_name LIKE 'DEMO Registrar % [Bulk Seed]' AND a.status = 'DISABLED';

SELECT program, year_level, COUNT(*) AS students
FROM sis_registrations
WHERE reg_no LIKE 'REG-DMO-%' AND school_year = '2026-2027' AND term = '1st Semester' AND status = 'REGISTERED'
GROUP BY program, year_level
ORDER BY FIELD(program, 'BSN', 'BSIT', 'BSCS', 'BSBA', 'BSA'),
         FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year');
