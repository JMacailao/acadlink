# Student Portal enrollment applications

Existing students use **Apply for Enrollment** in the Student Portal. Unlinked new applicants retain **My admission application**. Account creation remains exclusively in SIS.

## Workflow

1. Open a school year / term in SIS Settings → Academic terms and deadlines. Set Enrollment opens and Enrollment closes to include today's school date and save the term. See ENROLLMENT_PLACEMENT_UPDATE.md for the current/requested placement form.
2. Finalize the student's prior-term grades and tag them Complete in Grading. INC / missing grades block portal submissions even when staff registration policy permits incomplete grades. No automatic passing or promotion is inferred from Complete.
3. The student reads their current enrollment, chooses an open school year, semester, eligible year level and optional preferred section, checks requirements and submits. Only the authenticated, verified student link is used; posted Student IDs cannot change ownership. Duplicate active applications for a student/period are rejected. Changing a semester does not promote a student.
4. Registrar opens **Enrollment Applications**, applies filters, and reviews the request, grade history, requirements, previous balances and holds. Return/reject needs a reason. A returned request can be resubmitted in place. A rejected request stays in history; a new application may be submitted.
5. **Accept and prepare assessment** requires both admission and registration permissions and an explicit review confirmation. Select the target section/year, curriculum and assessment template. Acceptance creates an OPEN registration draft in one transaction. It does not confirm enrollment. The existing registration service preserves Student IDs, captures history, checks calendar/balance restrictions, and evaluates subject prerequisites. Advancement to the next year requires the same all-Passed second-semester evidence used by student history promotion.
6. In Registration, review subjects, tuition/books/other fees, discounts and previous balances. Use **Publish assessment to Student Portal**. Required payment before confirmation defaults to zero to preserve the existing school's unpaid-enrollment policy; staff can specify a positive amount up to the current assessment total. Publishing requires explicit review and no unresolved eligibility or registration errors.
7. Student sees the published itemized assessment, actual payments, current balance and earlier balances (displayed separately, never duplicated as new charges). The Updates list records submission, return/rejection, acceptance and publication. Cashier collects payments using existing receipt and accounting workflows. Unpublished or changed portal assessments cannot receive payments.
8. Registrar uses the existing **Confirm Enrollment** action after eligibility and the published payment requirement are met. Only then does the approved section/year replace current placement; submission and acceptance leave it unchanged. Promotion requirements are checked again. Only a confirmed registration displays **Enrolled**. Paying alone never confirms enrollment.

## Assessment changes and records

Publication fingerprints the saved subjects, fee lines and assessment total. Changed amounts/subjects disappear from student financial views until reviewed and published again. Confirmation recomputes the assessment and rechecks the fingerprint and payment requirement. No older grades, registration, receipt, assessment or accounting references are deleted by an application or decision.

The existing grade/assessment history pages continue to enforce student ownership and staff/teacher permissions. Teachers do not receive registrar decisions or financial access. Accounting cannot accept enrollment requests by default. Existing non-portal registrations keep their previous cashier and confirmation behavior.

## Install and upgrade

- Fresh database: import root **INSTALL.sql** once, as before. It includes the two new tables and the initial Power User.
- Existing database: replace application files, preserve `api/config.php`, then open SIS. The existing idempotent SIS upgrade creates the application tables when missing and adds nullable placement snapshots automatically (schema version 15). No separate SQL query is needed.
- The new workflow uses the existing `portal.admission.apply`, `portal.assessment.view`, `sis.admission.manage` and `sis.registration.manage` permissions; it does not broaden any default group.
- Create/select appropriate curricula, fee templates and enrollment periods before accepting applications. Missing promotion evidence or prerequisites must be resolved by the registrar; the student cannot override them.

## Validation

`php sis/tests/portal-enrollment.php` creates a uniquely named disposable test database on `127.0.0.1:3369` (override `PORTAL_TEST_PORT`). It never imports into the configured live database. It exercises single-file installation, applications, identity ownership, duplicates, registrar review, assessment publication/invalidation, payment gates, confirmation, history preservation and repeatable upgrades. Use a development MySQL account with CREATE DATABASE permission.
