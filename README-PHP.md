# AcadLink SIS and Grading — PHP/MySQL

SIS manages all accounts and groups. Grading uses the same centralized identity for academic work. Grading has no Users and Roles module; old Users bookmarks redirect to SIS.

## Fresh installation

1. Upload the project to a PHP/MySQL host. PHP 8.1+ with PDO MySQL and mbstring is required.
2. Create an empty MySQL/MariaDB database and select it in phpMyAdmin.
3. Import the root **INSTALL.sql** once. It includes Grading, SIS, Accounting, centralized accounts, the first Power User, Professor X and restore staging tables. No second SQL script or account-creation wizard is needed.
4. Set the database connection in **api/config.php**. Both systems use this file and the same database.
5. Follow **START_HERE.md** for the initial Power User sign-in and mandatory password change. Keep that file private; the supplied Apache configuration blocks it and SQL files from web access.
6. Use HTTPS and open **sis/index.php**. SIS Users and Groups is available only to Power Users. The Grading workspace is available according to the centralized group.

Opening **api/api.php** directly checks PHP and the Grading database connection. Configuration files must not be served as plain text.

## Updating an existing installation

Keep a full database backup and your current **api/config.php**, then upload the updated application files. Preserve your host's database credentials. Refresh the browser after updating. Do not initialize a new database to fix an existing installation.

New restore staging tables are created automatically on first restore. Fresh setup still uses only root INSTALL.sql. Earlier component installers in api/ and sis/database/ are retained for development and advanced migration; they are not the fresh-install entry point.

## Accounts and passwords

Power Users create staff, teacher and student accounts in **SIS → Users and Groups**. Teacher directory shortcuts and bulk creation also run there. Passwords require at least eight characters, including letters and numbers. Temporary passwords require a first-sign-in change. Reset passwords in SIS; users can change their own password through the shared service.

For verified legacy identities, see **CENTRAL_ACCOUNTS.md**. For outdated teacher account references, see **TEACHER_ACCOUNT_LINKS.md**. Never merge identities solely because names match.

## Backup and restore

See **BACKUP_RESTORE.md**. Grading exports Excel grading records and settings. Account/setup sheets in old workbooks are ignored. Accounts, registrations, receipts and accounting remain protected. Use a full MySQL backup when moving or restoring the entire shared system.

Merge updates matching IDs and adds missing rows. Replace stages the entire upload, then applies writes and deletions in one transaction. Protected identity and SIS references are retained. A failed restore rolls back completely; a lost response can be checked without applying the job twice.

## Other guides

- **V57_UPDATE.md** — v57: the same New · Edit · Void · Post · Select All · Filters · Apply · Search · Refresh · More toolbar on every SIS and Grading module; lists open with default filters (school year, today).
- **V56_UPDATE.md** — v56 checklist update: subject prices, fee template ticks, SOA payment plan box, payment plan before Cashier, semesters, curriculum teacher sync, enrollment documents, Import All.

- **BULK_ACCOUNTS.md** — teacher/student selection, shared temporary passwords and expiry.
- **CURRICULUM_TEACHERS.md** — subject teachers, Professor X and automatic class assignments.
- **sis/README.md** — SIS modules and developer details.
# Student migration and permanent history

See **MIGRATION_HISTORY.md** for Excel templates, reviewed itemized opening balances, historical academic imports, promotion/graduation and permission-scoped grade/assessment history. Existing databases upgrade automatically when opening SIS; fresh databases still use the single root `INSTALL.sql`.


## Reviewed Accounting

See [ACCOUNTING_WORKFLOW.md](ACCOUNTING_WORKFLOW.md). New assessments and receipts await Accounting review and posting; manual entries begin as drafts. Existing posted entries remain posted. Accounting must reverse a posted receipt before Cashier can void it. All fresh-install additions are included in root INSTALL.sql, with automatic additive upgrades for existing installations.

## Standard record lists

See [RECORD_LIST_TOOLBAR.md](RECORD_LIST_TOOLBAR.md). Record lists remain empty until Apply Filters is clicked. Clear empties results; Refresh uses the applied filters. This interface update needs no SQL changes.
