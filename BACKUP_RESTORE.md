# Grading backup and restore

Power Users open **Grading → Backup and restore**. Download a **grading backup** before making changes. The Excel file includes grading records and school settings. It does not contain centralized accounts, passwords, SIS registrations, receipts or accounting tables. Use a full MySQL backup for the complete shared database.

## Merge and Replace

- **Merge** restores records with their original IDs, updating matching records and adding missing ones. Other records stay.
- **Replace** restores the sheets in the file and removes records absent from those sheets, except protected records needed by centralized identities, SIS enrollment/financial records, prerequisites, Professor X, or grading records that remain. Sheets absent from the file stay unchanged. An empty included sheet is replaced with an empty collection unless protected references require records to stay.

Replace no longer deletes records in browser batches. The file is first uploaded in small private chunks. Uploading changes no live grading records. The server checks the upload and permissions, then performs all restore writes and deletions in one transaction. A validation error or SQL failure rolls back the entire restore.

Type **RESTORE** to confirm Replace. The completion message shows restored, removed and retained record counts. Source references whose target records are already missing from both the backup and database are reported for review; the original records are preserved for recovery. Invalid grades, duplicate IDs, invalid sheets and conflicting verified Student/Teacher IDs block restoration.

## Interrupted connections

If the connection drops while applying, use **Check restore result** in the same signed-in browser session. The server records the outcome with the transaction. Rechecking a committed job returns its result without applying it again or overwriting later changes. Uploads expire after two hours of inactivity and are bound to the Power User session. After a session reset, inspect the data and upload again if required.

If an older release stopped halfway through deleting/restoring, use your original backup with **Merge** to recover its missing records. This update cannot recreate records that are absent from both the database and your backup.

## Accounts and installation

Accounts and groups are managed only in **SIS → Users and Groups**. Old Excel `users`, `usernames` and `meta` sheets are ignored. Account passwords/status, verified links, SIS registrations, receipt references and journals are preserved. Verified teacher sign-in pointers stay current even when importing an older teacher sheet.

Passwords have an eight-character minimum, including letters and numbers. This applies to new accounts, bulk temporary passwords, password resets and self-service changes in both systems. Existing valid passwords are not reset.

For an existing installation, upload the updated files while keeping your current **api/config.php**, then refresh the browser. Restore staging tables are created automatically the first time you restore; no second SQL import is needed. For a fresh database, the single root **INSTALL.sql** includes these tables and the initial Power User.
