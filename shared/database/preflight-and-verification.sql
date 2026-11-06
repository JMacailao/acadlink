-- READ-ONLY checks after staging. No password hashes or session tokens are selected.

-- Normalized login collisions: matching names/logins are NOT proof of identity.
SELECT LOWER(TRIM(source_login)) AS login_key,COUNT(*) AS source_accounts,
 GROUP_CONCAT(CONCAT(source_system,':',source_key) ORDER BY source_system,source_key SEPARATOR ', ') AS sources
FROM central_migration_candidates WHERE source_login IS NOT NULL AND TRIM(source_login)<>''
GROUP BY LOWER(TRIM(source_login)) HAVING COUNT(*)>1;

-- Grading credentials without a grading user profile need manual investigation.
SELECT a.uid AS grading_uid,a.email AS legacy_login
FROM accounts a LEFT JOIN docs u ON u.col='users' AND u.id=a.uid WHERE u.id IS NULL;

-- SIS students without exactly one matching shared student record cannot be auto-linked.
SELECT u.id AS sis_user_id,u.username,u.student_number,COUNT(s.id) AS matching_students
FROM sis_users u LEFT JOIN docs s ON s.col='students'
 AND JSON_UNQUOTE(JSON_EXTRACT(s.data,'$.studentId'))=u.student_number
WHERE u.role='STUDENT' GROUP BY u.id,u.username,u.student_number HAVING COUNT(s.id)<>1;

-- Teachers whose referenced teacher record is missing.
SELECT c.source_key,c.display_name,c.source_teacher_doc_id
FROM central_migration_candidates c LEFT JOIN docs t ON t.col='teachers' AND t.id=c.source_teacher_doc_id
WHERE c.source_system='GRADING' AND c.legacy_role='teacher' AND t.id IS NULL;

-- Default group permission matrix (scope must also be enforced by PHP).
SELECT g.code AS group_code,g.name,p.workspace,p.code AS permission_code,gp.access_scope,gp.is_enabled
FROM central_groups g JOIN central_group_permissions gp ON gp.group_id=g.id
JOIN central_permissions p ON p.code=gp.permission_code ORDER BY g.code,p.workspace,p.code;

-- Accounts are expected to remain EMPTY until reviewed account migration is implemented.
SELECT status,COUNT(*) AS account_count FROM central_accounts GROUP BY status;

-- Incomplete or unsafe activation candidates. Review these before any cutover.
SELECT a.id,a.display_name,a.status,
 IF(c.account_id IS NULL,'MISSING','PRESENT') AS credential,
 IF(NOT EXISTS(SELECT 1 FROM central_login_identifiers i WHERE i.account_id=a.id AND i.is_primary=1 AND i.verified_at IS NOT NULL),'MISSING','PRESENT') AS verified_primary_login,
 IF(NOT EXISTS(SELECT 1 FROM central_user_groups ug JOIN central_groups g ON g.id=ug.group_id
   WHERE ug.account_id=a.id AND ug.revoked_at IS NULL AND ug.valid_from<=NOW()
   AND (ug.expires_at IS NULL OR ug.expires_at>NOW()) AND g.is_active=1),'MISSING','PRESENT') AS active_group
FROM central_accounts a LEFT JOIN central_credentials c ON c.account_id=a.id;

-- Student portal identities requiring a verified shared student link before grade/fee access.
-- An applicant without a Student ID can still own an admission application through central_record_owners.
SELECT a.id,a.display_name,l.student_doc_id,l.student_number,l.verified_at
FROM central_accounts a LEFT JOIN central_person_links l ON l.account_id=a.id
WHERE a.account_kind='STUDENT' AND (l.student_doc_id IS NULL OR l.verified_at IS NULL);
