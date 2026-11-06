/*
  Existing Balance Enrollment repair
  Replace the three variables below before running this script.
  This does not create a duplicate balance or payment record.
*/
SET @student_number = 'REPLACE-STUDENT-ID';
SET @target_school_year = '2027-2028';
SET @target_term = '1st Semester';
SET @reviewer_id = NULL; /* optional sis_users.id */
SET @decision_reason = 'Approved by Accounting/Registrar after policy review';

START TRANSACTION;

/* Required settings. Keep balance_require_approval = 1 if approval is mandatory. */
INSERT INTO sis_settings (skey, svalue) VALUES
  ('balance_enrollment_enabled', '1'),
  ('balance_show_on_latest_assessment', '1'),
  ('balance_require_approval', '1')
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);

/* Approve only the selected pending SPECIAL request. */
UPDATE sis_balance_cases
SET status = 'APPROVED',
    reviewed_by = @reviewer_id,
    reviewed_at = NOW(),
    decision_reason = @decision_reason
WHERE student_number = @student_number
  AND target_school_year = @target_school_year
  AND target_term = @target_term
  AND option_code = 'SPECIAL'
  AND status = 'PENDING';

/* Record the approval in the workflow history. */
INSERT INTO sis_balance_case_events (case_id, event_type, actor_id, details)
SELECT id, 'APPROVED', @reviewer_id,
       JSON_OBJECT('reason', @decision_reason, 'source', 'SQL repair script')
FROM sis_balance_cases
WHERE student_number = @student_number
  AND target_school_year = @target_school_year
  AND target_term = @target_term
  AND option_code = 'SPECIAL'
  AND status = 'APPROVED'
ORDER BY id DESC
LIMIT 1;

COMMIT;

/* Verify the setting, approved case, and original previous-semester balance. */
SELECT skey, svalue
FROM sis_settings
WHERE skey IN ('balance_enrollment_enabled', 'balance_show_on_latest_assessment', 'balance_require_approval');

SELECT id, student_number, target_school_year, target_term, option_code,
       balance_amount, status, reviewed_by, reviewed_at, decision_reason
FROM sis_balance_cases
WHERE student_number = @student_number
  AND target_school_year = @target_school_year
  AND target_term = @target_term
ORDER BY id DESC;

SELECT r.id, r.reg_no, r.school_year, r.term,
       GREATEST(r.total_amount - r.amount_paid, 0) AS current_balance
FROM sis_registrations r
WHERE r.student_number = @student_number
  AND r.status = 'REGISTERED'
  AND (CAST(LEFT(r.school_year, 4) AS UNSIGNED) < CAST(LEFT(@target_school_year, 4) AS UNSIGNED)
       OR (r.school_year = @target_school_year AND r.term <> @target_term));
