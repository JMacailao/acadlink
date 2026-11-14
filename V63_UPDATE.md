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

## v63.1 — Sign-in page on phones
- On phones (narrower than 768 px), the sign-in page has its own design:
  - a dark green top with the school logo and name, a help (?) button, "Your school. Connected." and "One account.
    Every workspace."
  - then a white sheet with **Welcome back**, labelled Username and Password boxes (show/hide password), Remember
    me, Forgot password?, and a full-width **Sign in** button.
  - "Need access? Contact your school" and the ? button open short sign-in help.
- The Student Portal sign-in and Forgot password use the same look.
- Tablets and computers keep the current design.
- Source: `sis/app/pages/login.php`, `css/style.css`.
- **Choose a workspace on phones**:
  - each workspace is a picture tile with a short coloured name under it (Grading, Admission, LMS, Canteen POS,
    Library, Finance, Student Portal), three per row; tap the tile to open it;
  - "Tap a module to open it." under the title;
  - a round account button (top right) with your name, Change password and Sign out.
  - Tablets and computers keep the cards with descriptions and buttons.
  - Source: `sis/app/pages/workspaces.php`, `css/workspace-selector.css`, `sis/assets/workspace-tiles.js`.

## v63.2 — Bottom menu stays put; Library gets it too; no source-map warnings
- **The bottom menu no longer disappears while another page opens.**
  - It is now sent first, at the top of each page, so it shows at once even before the rest of the page arrives.
  - During the page change the browser keeps it in place instead of fading it with the page. This needs Chrome or
    Edge 126+ or Safari 18.2+; other browsers just load the next page.
- **Library** now has the bottom menu on phones and tablets too, in purple: Home · Catalog · **Desk** (middle) ·
  Members · More. It follows the librarian's permissions, and the curve follows the selected item as in the other
  systems.
- The browser console no longer shows "Connecting to …bootstrap.min.css.map violates the Content Security Policy".
  Those were the developer tools asking for Bootstrap's debugging files; the security policy now allows that
  download (`connect-src`) from the same Bootstrap site. Nothing else is loosened.
- Source: `sis/app/views/layout.php`, `portal/views/layout.php`, `sis/assets/bottom-nav.css`, `sis/app/front.php`,
  `portal/index.php`.

## v63.3 — Security fixes
- **No published first password.** A new installation's power.admin has no usable password until **First-time
  setup**. The sign-in page links to it while it is needed.
  - The installer types the **database password from `api/config.php`** (visitors can't read that file), then chooses
    power.admin's password.
  - If the database has no password (local XAMPP/Laragon), setup works only from that computer (`localhost`).
  - Wrong tries are limited (5 per 15 minutes). Setup closes for good once done.
  - `INSTALL.sql` and `START_HERE.md` no longer contain a password.
  - Existing schools: if power.admin still used the old published starting password, it is switched off
    automatically, so nobody can sign in with it; set a new one on First-time setup. A password the school already
    chose is not touched.
- **Direct web access blocked** (Apache): the code and data folders of the LMS, Canteen, Library, Student Portal and
  `shared/` refuse direct opening; only each system's `index.php` and `assets/` are public, as in SIS and Finance.
  Everywhere in AcadLink, `.sql`, `.md`, `.log`, `.bak`, `.gz`, `.zip`, `.ini`, `.sh` and hidden files (`.git`,
  `.env`) are refused.
- **API**: visitors no longer see the PHP or AcadLink version, and the list of administrator IDs needs sign-in.
- Source: `sis/app/lib/first-setup.php`, `sis/app/pages/setup.php` (new); `INSTALL.sql`, `START_HERE.md`, `.htaccess`
  plus new `.htaccess` files in `lms/`, `canteen/`, `library/`, `portal/` (and their `lib`, `pages`, `views`
  folders) and `shared/`; `api/api.php`, `sis/index.php`, `sis/app/bootstrap.php`, `sis/app/pages/login.php`.
  Tests: `sis/tests/v63-security.php` (4 checks); `curriculum-teachers.php` sets its own power.admin password.
