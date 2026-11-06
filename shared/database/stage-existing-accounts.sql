-- Run AFTER 01, in the same existing AcadLink database.
-- Inventories legacy users without copying any password hashes or granting roles.
-- Manual review is required. Nothing is merged or activated by this script.
-- Re-running refreshes source observations, retaining review_status and review_note.
SET NAMES utf8mb4;

INSERT INTO central_migration_candidates
(source_system,source_key,source_login,display_name,legacy_role,suggested_group,
 source_active,source_password_present,source_teacher_doc_id,source_student_number,last_scanned_at)
SELECT 'GRADING',a.uid,
 COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.username')),'null'),''),a.email),
 COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.displayName')),'null'),''),a.email),
 JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.role')),
 CASE JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.role')) WHEN 'admin' THEN 'POWER_USER'
   WHEN 'registrar' THEN 'REGISTRAR' WHEN 'teacher' THEN 'TEACHER' ELSE NULL END,
 IF(JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.active'))='true',1,0),
 IF(a.password_hash IS NOT NULL AND LENGTH(a.password_hash)>0,1,0),
 NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(u.data,'$.teacherDocId')),''),'null'),
 NULL,NOW()
FROM accounts a LEFT JOIN docs u ON u.col='users' AND u.id=a.uid
ON DUPLICATE KEY UPDATE source_login=VALUES(source_login),display_name=VALUES(display_name),
 legacy_role=VALUES(legacy_role),suggested_group=VALUES(suggested_group),
 source_active=VALUES(source_active),source_password_present=VALUES(source_password_present),
 source_teacher_doc_id=VALUES(source_teacher_doc_id),last_scanned_at=VALUES(last_scanned_at);

INSERT INTO central_migration_candidates
(source_system,source_key,source_login,display_name,legacy_role,suggested_group,
 source_active,source_password_present,source_teacher_doc_id,source_student_number,last_scanned_at)
SELECT 'SIS',CAST(u.id AS CHAR),u.username,u.full_name,u.role,
 CASE u.role WHEN 'ADMIN' THEN 'POWER_USER' WHEN 'ACCOUNTING' THEN 'ACCOUNTING'
   WHEN 'CASHIER' THEN 'ACCOUNTING' WHEN 'REGISTRAR' THEN 'REGISTRAR'
   WHEN 'ADMISSION' THEN 'REGISTRAR' WHEN 'STUDENT' THEN 'STUDENT' ELSE NULL END,
 u.is_active,IF(LENGTH(u.password_hash)>0,1,0),NULL,u.student_number,NOW()
FROM sis_users u
ON DUPLICATE KEY UPDATE source_login=VALUES(source_login),display_name=VALUES(display_name),
 legacy_role=VALUES(legacy_role),suggested_group=VALUES(suggested_group),
 source_active=VALUES(source_active),source_password_present=VALUES(source_password_present),
 source_student_number=VALUES(source_student_number),last_scanned_at=VALUES(last_scanned_at);

-- Review inventory: suggestions are NOT role assignments.
SELECT source_system,source_key,source_login,display_name,legacy_role,suggested_group,
 source_active,source_password_present,review_status,
 CASE WHEN legacy_role IN('CASHIER','ADMISSION')
 THEN 'Broader default group: explicitly review each additional permission'
 ELSE 'Verify identity, record links and group before migration' END AS review_requirement
FROM central_migration_candidates ORDER BY source_system,display_name,source_key;
