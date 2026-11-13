# AcadLink v63 — Backup & Restore (whole system)

No SQL to import. Copy the files over v62.2 (keep `api/config.php`), sign in as **power.admin** and press Ctrl+F5.

## Who can use it
- Only **power.admin** sees **SIS → Administration → Backup & Restore** and can back up or restore. Other Power Users,
  the Registrar and everyone else get "not allowed". The Grading System's own restore follows the same rule.
- If a school no longer has a power.admin account, the oldest active Power User takes its place.
- **power.admin's username can't be changed.** In Users and Groups the field is read-only, and the server refuses a
  change. power.admin must also stay an active Power User. **Its password can be changed** (My profile → Change
  password, or Users and Groups → Reset password), and so can its display name.

## Backing up
- Tick what to include (everything is ticked = a full backup):
  - Accounts, groups and access
  - Grading System
  - SIS records
  - Cashier and accounting
  - Canteen POS
  - Library
  - LMS
  - Student photos
  - Activity history and notifications
- The page shows each part's size. Add a note if you like, then **Make backup**.
- The backup is one compressed file. It includes photos, book covers, product pictures and LMS files, byte for byte.
- It is saved on the server and listed under **Backups on this server**, with Download, Restore… and Delete.
  **Download it and keep a copy elsewhere** (USB drive, Google Drive): a copy on the same server does not help if
  the server fails.
- Sign-in sessions, password-reset codes, sign-in attempts and restore staging are never included.

## Restoring
1. Choose **Restore…** on a backup in the list, or **upload** a backup file you downloaded before.
2. **Tick what to restore.** Ticked parts are replaced by the backup; unticked parts stay as they are now. Parts that
   aren't in that backup can't be ticked. Restore related parts together (for example SIS records with the Grading
   System and Cashier and accounting) so students, registrations and payments match.
3. Type **RESTORE** and press **Restore ticked parts**.

How the restore protects you:
- A **safety copy** of the ticked parts is saved on the server first, so a restore can be undone by restoring the
  safety copy.
- Everything happens in **one transaction**: if the file is damaged, cut off, or anything fails, **nothing is changed**.
- Restoring **Accounts** never changes power.admin's sign-in. Its current password, active status and Power User
  access are kept.
- Each backup, download, upload, restore and deletion is in the Activity Log.

## Where the files are kept
- By default: the `backups/` folder inside AcadLink. Web access is blocked by its `.htaccess` (Apache), and the file
  names are random.
- **Better:** a folder outside the website. In `api/config.php` add, for example,
  `"backup_dir" => "/home/youraccount/acadlink-backups",` (see `api/config.sample.php`).
- Uploading a backup is limited by the hosting's `upload_max_filesize` and `post_max_size`, shown on the page. For a
  bigger file, copy it into the backups folder; it then appears in the list.
- On Nginx (not Apache), make sure the backups folder is not served, or use `backup_dir`.

## Source code
- New: `sis/app/lib/backup.php`, `sis/app/pages/backup.php`, `backups/.htaccess`, `backups/index.html`,
  `sis/tests/v63-backup.php` (7 checks).
- Changed: `shared/central-accounts.php` (power.admin username and access locked), `sis/app/pages/users.php`,
  `api/grading-restore.php`, `sis/index.php`, `sis/app/views/layout.php`, `sis/app/bootstrap.php`,
  `sis/assets/people.css`, `api/config.sample.php`, `.gitignore`.
