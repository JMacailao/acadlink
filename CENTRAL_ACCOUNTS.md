# Centralized SIS accounts

SIS is the only account administration surface. Grading uses the same authoritative central password, status and permissions. All earlier SIS design, eligibility and balance/notification work remains included.

## What is implemented

- Five default groups: Power User / Super Admin, Accounting, Registrar, Teacher and Student.
- SIS → Users and Groups: create, edit, enable/disable, reset passwords, review migration links and preview access. Only an active Power User can administer accounts; the server enforces this.
- Group access: view fixed default permissions and enable/disable the four non-administrator groups. Permission boundaries stay fixed in this release; custom groups and individual grant editing are not exposed. Power User access cannot be disabled through group management.
- Create a teacher directory record and linked account in one transaction. A failed account save rolls back the teacher record. A person can have only one central teacher/student account link, including disabled accounts; reuse the existing account rather than creating another.
- Teacher class assignments stay in Grading. Teachers may read their assigned students/classes, enter open grades and submit them. They cannot finalize grades or use accounting.
- Accounting includes assessment/discount setup, payments and voids, balances, existing journals and reconciliation. Registrar retains academic record editing/finalization, admissions and registration. Accounting does not open Grading or manage users.
- Student accounts can exist before a Student ID. Their admission application is bound to their central account, never a browser-supplied owner ID. Applications require an explicitly configured open term in SIS Settings → Academic terms and deadlines. Students cannot approve admissions or confirm enrollment.
- Power User links an applicant's approved student record later in Users and Groups, after the student record actually exists. Existing academic/financial records are not inferred or merged by matching a name.
- Existing student portal pages continue to show only the linked student's finalized grades, assessments, registrations and receipts under the existing school visibility policy.
- One shared secure, HTTP-only sign-in token connects the existing SIS and Grading sessions on the same host. My workspaces shows permitted destinations. Sign-out revokes the current shared token. Disabling an account, editing its group/link or resetting its password invalidates all previous tokens for that identity. Group access changes invalidate outdated sessions globally.
- Passwords require at least 8 characters with letters and numbers. Self-service password change works through the central credential service. Temporary passwords must be changed in SIS before any academic/financial action is permitted.
- Old Grading create-account, reset-password, temporary-password and SIS-install endpoints are refused. Generic document writes cannot create/delete users, change roles or activate accounts; only a user's own device/heartbeat metadata can change there. Grading’s Users and Roles module and navigation have been removed; old Users bookmarks redirect to SIS. Grading Excel backups do not export or import accounts. Teacher and first-run account forms have been removed.
- Original Grading UIDs and SIS user IDs remain distinct in verified compatibility links. Audit logs, grades, registrations and receipt operator IDs continue referencing their original records.
- Central security audit records account changes, verified links, password resets and sign-ins without storing plaintext passwords.

## Fresh database — use the one-file installer

Read **START_HERE.md**. Import **INSTALL.sql** into your selected empty database, configure **api/config.php**, and sign in to SIS with the Power User credentials printed in START_HERE.md and the SQL header. The initial Power User is already included and must change its password at sign-in. **Do not run the CLI bootstrap or import the component SQL files on this fresh-install path.**

The guarded CLI bootstrap below is retained for existing-database migrations that need explicit identity verification. It is not part of fresh installation.

## Existing database migration — advanced operator path

This archive includes a complete one-file fresh installer; it is not a completed migration of your production database. The application code intentionally has **no legacy login fallback**. Unlinked legacy users cannot sign in. Test and pilot on a database copy before replacing production code, then schedule the production cutover and account review.

1. Back up the entire database and the previous application files. Keep `api/config.php` for your environment. Both systems must remain in the same application host and use the same database. No new server is required.
2. These instructions apply to an existing SIS installation. Keep its tables and records; do not use `--fresh`. For a fresh database use START_HERE.md instead.
3. Install the additive central schema and compatibility columns:

   ```bash
   php sis/bin/central-accounts.php install
   ```

   This also adds the Teacher compatibility role and optional admission application term column. It does not migrate anyone or create passwords.
4. Create the first Power User through the local operator command. Select an existing, active administrator explicitly:

   ```bash
   php sis/bin/central-accounts.php bootstrap-sis --source=1 --username=your.admin
   ```

   The command reads that administrator's current password from standard input. Run interactively in a private terminal; terminal input may be echoed. Do not pass a password as a command argument or put it in shell history. The existing hash is adopted centrally and a password change is required at next sign-in. The selected SIS ID is retained. Substitute the real ID; `1` is only an example.

   For a Grading administrator instead:

   ```bash
   php sis/bin/central-accounts.php bootstrap-grading --source=EXISTING_GRADING_UID --username=your.admin
   ```

   This verifies the selected Grading administrator's existing password and preserves their UID. If that same person already has a SIS account, explicitly add `--sis-id=EXISTING_SIS_ID` after verifying both identities. With a SIS bootstrap, the optional `--grading-uid=EXISTING_GRADING_UID` similarly preserves both records. These flags are trusted local-operator decisions, never automatic matches.

   For a genuinely new installation with no administrator to retain, use `bootstrap-new --username=your.admin --name="Full Name"` and supply a strong temporary password on standard input. A legacy username collision must be resolved with an explicit source link rather than silently claimed. Bootstrap is available only while there are no central accounts; it is never an HTTP setup endpoint.
5. Sign in to SIS with the chosen username, change the temporary password and open Users and Groups.
6. Review the Migration review inventory. Optional SQL inventory/preflight files are in `shared/database/`. Inspect duplicate normalized usernames, absent teacher/student records and accounts present in only one system.
7. For each person, use Add account. Select their default group and verified teacher/student link. In Verified legacy account links, select **both** original SIS and Grading accounts if both exist, and record the identity verification note. Select these links **before the first save**; the service creates compatibility records when no source is supplied. It will not overwrite those links later. An unrelated user with the same name stays separate and must have a unique username.
8. Supply a new temporary password for each migrated user and deliver it securely. Migration deliberately does not guess which of two old passwords should win. The user changes the temporary password at first sign-in. Suspended/disabled legacy users should remain Disabled unless an administrator deliberately reactivates them.
9. Review the broader rights of old Cashier → Accounting and Admission staff → Registrar before choosing those groups. The old roles are not automatically promoted.
10. Pilot each group against the checks below, reconcile user/record counts and then switch production to this release. Keep the backed-up old code/database together for a rollback; this runtime will never silently fall back to the old password stores.

Compatibility `sis_users` rows and Grading `docs/users` profiles are not deleted. New compatibility rows contain random, unusable legacy password hashes; existing legacy hashes may remain in historical rows but neither login reads them. Do not maintain users by directly editing those rows. Central tables must be included in database backups; the old Grading spreadsheet backup is not an authentication backup.

## Staff workflows

**Teacher shortcut:** SIS → Teachers → teacher row **⋯** → **Create account**. Power Users can automatically generate a username (first initial + full surname, e.g. Juan Dela Cruz → jdelacruz; duplicates use jdelacruz2, jdelacruz3) and temporary password, assign the Teacher group and link that exact teacher record. Use First name → optional Middle name/initial → Last name → optional suffix/degree. For example, ALLAN L. BALBACAL, RRT produces abalbacal and Juan M. Dela Cruz, LPT produces jdelacruz. Review the suggested username; unusual surnames can be entered manually in Users and Groups. Existing account usernames remain unchanged. Credentials are shown once after creation. Existing linked accounts show Manage account; disabled accounts are not duplicated or reactivated. Legacy teacher links require migration review. No additional SQL is needed.

**Teacher (manual):** SIS Users and Groups → select Teacher → choose existing directory record or Create teacher record and account together → give temporary password → assign classes in Grading → teacher signs in and changes password → enters/submits only assigned class grades.

**Student:** create Student account in SIS → link existing Student ID, or leave blank for applicant → applicant submits their own application while enrollment is open → Registrar reviews/admits → student record is created through the existing registration process → Power User verifies and links Student ID → own academic and financial data becomes available. Application approval never automatically claims an existing record based on a name or posted Student ID.

**Account changes:** Manage → change group/status/verified person link → Save account. A self-edit retains the verified administrator session; other old sessions must sign in again. Manage → Reset password → enter temporary password → Reset password. Password resets force every session for that user to sign in again.

**Group changes:** Groups and access → review permissions → change Group enabled → Save group access. Users, including the current administrator, must sign in again after this global policy change.

## Verification

`php sis/tests/central-accounts.php` creates a new randomly named disposable database using the configured database server credentials. It never modifies the configured database. Requires CREATE DATABASE permission on the test server. Existing `sis/tests/eligibility.php` and `sis/tests/workspaces.php` remain integration suites for their respective workflows.

Verified with PHP 8.3 and MariaDB 10.11: 21 central account integration cases, 14 Chromium browser/API checks, 18 eligibility cases, 13 balance/notification cases and one CLI bootstrap check group passed (67 total). PHP syntax checks passed for all 70 PHP files. Checks include:

- Create/link teacher in SIS, assign a class through Grading, enter and submit own grade; deny unassigned class writes and finalization.
- Shared sign-in, forced initial password change and cross-workspace password reset revocation.
- All five group boundaries, duplicate person links, failed combined teacher/account rollback and verified migration preserving both legacy IDs.
- Applicant ownership, closed enrollment rejection and prevention of a posted Student ID taking another student's record.
- Server rejection of old account endpoints, role/account document writes and missing SIS CSRF tokens.
- Users/Groups page rendering, mobile account actions and absence of old account forms/JavaScript errors.

The earlier full accounting UI simplification is a separate request and is not part of this account-centralization release. Existing accounting business functions are retained.
# Bulk account creation

Power Users can open SIS **Users and Groups → Bulk create accounts** to create linked Teacher or Student accounts with one administrator-provided or system-generated temporary password per batch. The preview skips existing accounts and flags legacy identities for review. First sign-in requires a personal password. See [BULK_ACCOUNTS.md](BULK_ACCOUNTS.md) for selection, expiry, pause/resume and results export.

