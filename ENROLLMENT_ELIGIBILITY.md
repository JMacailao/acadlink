# Enrollment Eligibility Checker — feature 1

This update extends the redesigned AcadLink PHP/MySQL project. It runs on the same hosting and database. It does not implement the other nine proposed modules.

## Installation

1. Back up the full database and application files.
2. Replace the deployed `sis/` folder with this package's `sis/` folder. Keep the site's existing `api/config.php` and database credentials.
3. On the first SIS request, the existing automatic upgrader creates three new tables: `sis_eligibility_holds`, `sis_subject_prerequisites`, and `sis_eligibility_decisions`. Existing records are retained. The database account needs permission to create tables. Alternatively, import `sis/database/upgrade-eligibility.sql` into the existing database before opening the SIS.
4. Open Settings and review the existing balance enrollment policy. The update preserves its current enabled/disabled state. Enable balance enforcement and approval requirements if that is the school's policy.
5. In Settings → Enrollment eligibility, decide whether an INC/missing grade in a finalized previous term should block enrollment. The default remains warning only, matching the previous behavior.
6. Open Enrollment Eligibility to configure actual subject prerequisites and record any explicit enrollment holds.

The older `SIS_UI_UPDATE.md` describes the preceding design-only release. Its no-migration and runtime-testing notes are superseded by this document for the eligibility update.

## Staff workflow

- Registration → New: select the student or admitted applicant, section, term and curriculum.
- The eligibility preview refreshes when the section, term or curriculum changes. Passed and informational checks can be expanded; restrictions remain visible.
- The checker returns Eligible, Needs Approval, or Blocked, with reasons, responsible offices and resolution links.
- Whole-enrollment restrictions prevent draft creation, preserving existing enrollment policy. Subject-only prerequisite restrictions permit an editable draft; remove or resolve those subjects before confirming.
- Confirm Enrollment runs the checks again using the current database records. An earlier preview cannot authorize a later submission.
- A successful creation and confirmation each save a decision snapshot. Confirmed registrations display their saved confirmation decision rather than presenting it as a new check.

## Checks included

- Valid student/admitted applicant and duplicate active registration for the target term.
- Exact previous-term grade-completion tag and existing readmission requirement.
- Enrollment dates from the existing academic calendar. No configured calendar continues the existing open-enrollment policy.
- Prior-term balances and existing student/term-scoped balance approvals. Pending, rejected or expired cases do not authorize enrollment.
- Approved arrangement minimums: the larger of the current policy minimum and requested payment must be supported by posted, non-voided earlier-term receipts after the request and a corresponding net reduction of the reviewed balance. An increase above the reviewed balance requires another review. A fully settled prior balance no longer requires an exception.
- Optional hard blocking for previous-term INC/missing grades.
- Explicit academic, financial, document or other holds. Holds can apply globally, to a school year, or to a specific term, and can expire on an inclusive end date. Releases require a recorded reason.
- Configured prerequisites for actual selected subjects. Only an earlier finalized numeric grade explicitly marked Passed satisfies a mapped prerequisite; INC, dropped, missing, current-term and future-term grades do not count. Passing-scale thresholds are not guessed. Circular prerequisite mappings are rejected.
- Missing selected subject records fail closed.

The new term's unpaid assessment does not itself block confirmation. Existing assessment review and cashier controls still apply.

## Permissions and exceptions

- Staff with registration access can view eligibility checks.
- Registrar/student-edit permission is required to add or release explicit holds.
- Curriculum-edit permission is required to configure prerequisites.
- Settings permission is required to change enrollment policies.
- The existing administrator exception remains limited to untagged previous-term grades, readmission, and enrollment dates. A reason must be entered again if confirmation still needs an exception. It cannot bypass financial restrictions, explicit holds, duplicate registration, hard INC policy, or prerequisites.
- All mutations use the existing CSRF and audit mechanisms. Public/student sessions cannot use the staff eligibility route or preview endpoint.

## Scope limits

Document upload/verification and an automatic clearance checklist belong to feature 3 and are not added here. Staff use DOCUMENT holds for known missing requirements. The panel explicitly states that eligibility does not certify document completeness.

No new academic exception approval process, installment scheduler, grade-correction workflow, graduation evaluator, curriculum equivalency system or accounting posting workflow is added. Existing balance case statuses and approvals are reused. Prerequisite mappings are global per subject; program-specific alternatives and transferred-credit equivalencies require separate school review.

## Validation

- Focused PHP/MariaDB tests: **18 passed, 0 failed**. Run `php sis/tests/eligibility.php` from the project root on a test database server. It creates a disposable `sis_eligibility_test` database, never the configured live database; database-creation privileges are required.
- Signed-in browser checks passed for preview refresh, subject-only draft behavior, document hold blocking, confirmation, saved decision display and a 390px mobile layout. No browser JavaScript errors were observed.
- PHP syntax and JavaScript syntax checks passed.
- Existing regression suite: **24 passed, 2 failed**, identical to the original uploaded project's baseline. The two existing inconsistencies concern an annual completion tag versus an exact term tag, and an expected same-year section warning absent from the original implementation. They were not changed in this feature.

Use a copy of the school's real database to verify its grade conventions, prerequisite mappings and financial policies before rollout.
