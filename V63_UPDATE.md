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

## v63.4 — Class schedule like the school's sheet
- Every timetable looks like the printed class-schedule sheet. That covers SIS → Class Schedules (section week and
  Print), the registration's schedule, Student Portal → Schedule, My Schedule and LMS → Schedule:
  - a dark blue header row with **TIME** and each day (Monday–Friday, plus Saturday/Sunday when used), each day
    with its own light-blue **Room** column;
  - half-hour rows from 7:30 to 6:00 (longer if a class starts earlier or ends later);
  - each class is **one coloured block as tall as it lasts**, with the subject code and name; laboratory classes
    show "(Lab)". The teacher's name is in bold in a darker band at the bottom. On a teacher's own timetable the
    band shows the section instead. The room is beside it, or **TBA** when no room is set.
  - each subject keeps the same colour all week; the section name is shown on top when known.
  - classes that overlap in the same section are flagged "Clash" inside the block.
  - colours print as they are on screen.
- Source: `sis/app/lib/schedules.php` (`Schedules::grid`), `sis/assets/schedule-grid.css` (new), both layouts, and
  the pages above.

## v63.5 — The school's logo in the LMS and Canteen POS
- The school's logo (Grading System → School settings) now shows wherever the LMS and Canteen POS showed a
  generic icon:
  - **LMS** side menu on computers: the logo in a white circle, instead of the book icon;
  - **Canteen POS** top bar on computers: the logo next to "Canteen POS", instead of the shop icon;
  - **Canteen receipt**: the logo on top of the receipt, on screen and when printed (only when a logo is uploaded).
- Until a school uploads its logo, the AcadLink mark is shown, as everywhere else. Phones already showed the logo in
  the top bar.
- Source: `sis/app/views/layout.php`, `canteen/pages/receipt.php`, `lms/assets/lms.css`, `canteen/assets/canteen.css`.

## v63.6 — The school's logo in the Library
- The Library side menu on computers shows the school's logo and name with "Library" under it, instead of the book
  icon and "AcadLink Library". The AcadLink mark is shown until a logo is uploaded in Grading System → School settings.
- Library fine receipts (Finance → Library fines) already print the school logo in their header.
- Source: `sis/app/views/layout.php`, `library/assets/library.css`.

## v63.7 — Settings in one place
- **Administration** in the side menu now has just **Report Manager**, **Users and Groups**, **Settings** and
  **Activity Log** (Help stays at the bottom).
- **Settings** opens a page with a **Settings menu on the right** listing:
  - Printing (the former Settings page: assessment statement and official receipt)
  - Document Requirements
  - Email Sending
  - Backup & Restore (power.admin only)
  - Bulk Accounts
  - Data Migration
  - Import All
- Each person sees only the pages they may open (for example a registrar who can only use Data Migration sees just
  that). The side menu keeps **Settings** highlighted on all of them, and the breadcrumb reads Home › Administration.
- Screens narrower than 1400px show the Settings menu as a row of tabs above the page instead.
- Links from other pages (Users and Groups → Bulk create accounts, Students → Import records, the admission
  checklist) still open the same pages.
- Source: `sis/app/views/layout.php` (`SETTINGS_HUB`), `sis/assets/settings-hub.css` (new).

## v63.8 — School Profile in the SIS
- New **Administration → Settings → School Profile** (first in the Settings menu). It edits the same school details
  as the Grading System's **School settings**: school name, short name, address, phone, email, website, registrar's
  name and title, the **school logo** and the **sign-in page picture**.
- Saving updates every system at once: SIS, Student Portal, Grading System, LMS, Canteen POS, Library, receipts and
  printouts. Other Grading System settings are kept as they are.
- The logo is made small (160 px) and the sign-in picture compressed in the browser, the same way as the Grading
  System, then checked again on the server. Only people with Settings rights can open it; each save is in the Activity Log.
- The Grading System's School settings still work; its connection, email and Google Sheets parts stay there.
- Source: `sis/app/pages/school.php` (new), `sis/assets/school-profile.js` (new), `sis/index.php`, `sis/app/views/layout.php`.

## v63.9 — The Grading System's settings move into SIS Settings
- Two new pages in **Administration → Settings** (SIS):
  - **Grading Options**: the Grading System's former *Setup and options* page: grading scale, final grade (GWA) and
    conversion table, audit trail retention, combined classes, term deadlines and what happens when a student changes section.
  - **System Settings**: the rest of the former *School settings* page: sign-in security, teacher downtime, the school's
    database, email sending keys (EmailJS) and the Google Sheets copy. School details are in **School Profile** (v63.8).
- The Grading System's side menu no longer has *School settings* or *Setup and options*. Opening those old addresses goes
  to the SIS pages. Its links to them (Audit trail, Backup and restore) point to SIS Settings too.
- Nothing about how these settings work has changed: the same Grading System page runs inside SIS (its own menus
  hidden), saves the same records, and only Grading System administrators can change them.
- Source: `sis/app/pages/gsframe.php` (new), `sis/assets/gs-frame.js` (new), `sis/index.php`, `sis/app/views/layout.php`,
  `pages/options.html`, `pages/settings.html` and the side menu of every page in `pages/`.

## v63.10 — Grading Audit trail and Backup in SIS Settings; one device at a time everywhere; the V4 logo
- **Grading Audit Trail** and **Grading Backup** move into SIS → Administration → Settings (the Grading System's
  former *Audit trail* and *Backup and restore* pages, unchanged). The Grading System's side menu no longer has an
  Administration group; opening the old addresses goes to the SIS pages.
- **One device at a time** (Settings → System Settings → Sign-in security) now covers **every system**: SIS, Student
  Portal, Grading System, LMS, Canteen POS, Library and Finance. Signing in on a new device ends the sign-in on the
  other device in all of them; that device shows "You were signed out because your account was opened on another
  device." Turn the setting off to allow several devices again. (The Grading System sign-in still asks first.)
- **The ACADLINK V4 logo** is now the AcadLink mark wherever the school has not uploaded its own logo, and the browser
  tab icon, phone home-screen icon and app icons (`icons/acadlink-mark.png`, `favicon-32.png`, `apple-touch-icon.png`,
  `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`).
- Source: `shared/central-accounts.php` (`singleDevice`, `endedElsewhere`), `sis/app/pages/login.php`,
  `sis/app/pages/gsframe.php`, `sis/index.php`, `sis/app/views/layout.php`, `pages/*.html`, `icons/`.

## v63.11 — Grading Backup removed
- **Grading Backup** is gone from SIS Settings. **Backup & Restore** (power.admin) already makes a copy of all of AcadLink,
  the Grading System's records included (its "Grading System" part), and can restore just that part.
- The Grading System's old backup page now opens SIS Backup & Restore; its script `js/backup.js` was deleted (it can
  also be deleted from the server).
- Source: `sis/index.php`, `sis/app/views/layout.php`, `sis/app/pages/gsframe.php`, `pages/backup.html`.

## v63.12 — Sign-in security in every system; no more {"scope": …} page
- **Sign out automatically after no activity** (Settings → System Settings → Sign-in security) now works in **every system**:
  SIS, Student Portal, LMS, Canteen POS, Library and Finance, as it did in the Grading System. A "Still there?" warning with
  **Stay signed in** shows shortly before; activity in any tab counts for all tabs; while someone is working (for example
  typing a long form) the server is told now and then so nothing expires under them. The server also ends a sign-in after
  that many minutes (plus one) without any request. When the setting is Off, the server keeps its 30 minutes as before.
- **One device at a time asks first, everywhere.** Signing in while the account is open on another device shows
  "Open on another device" with **Sign out the other device and continue** or **Cancel**. Nothing changes until the
  person chooses: Cancel leaves the other device signed in. The SIS, Student Portal and Grading System ask the same way
  (the Grading System used to sign the other device out before asking).
- **Fixed:** after a sign-in had ended (timed out or opened on another device), signing in again could open a page that
  showed only `{"scope":"…"}`. Background checks are no longer remembered as "the page to return to"; signing in now
  goes to the workspaces.
- Source: `shared/central-accounts.php`, `sis/app/lib/auth.php`, `sis/app/pages/login.php`, `sis/app/front.php`,
  `sis/app/pages/navstate.php`, `sis/app/pages/keepalive.php` (new), `sis/assets/idle-signout.js`/`.css` (new), both
  layouts, `api/api.php`, `api/lib.php`, `js/php-auth.js`, `js/auth.js`.

## v63.13 — Automatic tests, HTTPS everywhere, PHP 8.2+
- **Automatic tests on GitHub** (`.github/workflows/tests.yml`): every pull request and every change to `main` is checked on
  PHP 8.2 and 8.3 with a fresh MariaDB: the syntax of every PHP file and browser script, then all 19 maintained test
  suites (`sis/tests/all.sh`; run it yourself with `bash sis/tests/all.sh`). The result shows on the pull request.
  Not included yet: `run.php` (the original v1 suite, from before central accounts and permission groups; it needs
  rewriting) and `http_test.php` (needs a running server and the school's own records).
- **HTTPS is always used on a real domain**: the root `.htaccess` and each system folder (sis, portal, lms, canteen,
  library, finance) send `http://` visitors to `https://`, and browsers are told to keep using https (HSTS, 1 year).
  Local installs keep working without SSL: localhost, IP addresses and names ending in .local / .lan / .test are not
  redirected. Turn SSL on in the host's panel before uploading (Hostinger: hPanel → Security → SSL).
- **PHP 8.2 or newer** is now required (8.3 recommended; PHP 8.1 no longer gets security fixes). power.admin sees a
  warning on the SIS dashboard while the server runs an older PHP. Hostinger: hPanel → Advanced → PHP Configuration.
- Source: `.github/workflows/tests.yml`, `sis/tests/all.sh` (new), `.htaccess` (root and each system folder),
  `sis/app/pages/dashboard.php`, `api/api.php`, `README-PHP.md`, `sis/tests/run.php` (two checks brought up to date).

## v63.14 — Bootstrap on the server; automatic backups
- **Bootstrap and its icons are now part of AcadLink** (`vendor/bootstrap-5.3.8`, `vendor/bootstrap-icons-1.13.1`) instead
  of loading from cdn.jsdelivr.net. Every system still looks and works the same when the school network blocks or slows
  the CDN, and the CDN is no longer allowed by the security policy. Updated from Bootstrap 5.3.3 and Icons 1.11.3.
- **Automatic backups** (Settings → Backup & Restore → Automatic backups, power.admin):
  - every day (the default), every week, or off; keeps the newest 14 automatic backups (3–90). Your own backups and the
    safety copies made before a restore are never deleted.
  - **Scheduled job (recommended)**: Hostinger hPanel → Advanced → Cron Jobs → Custom, every hour:
    `php /home/<account>/public_html/sis/bin/auto-backup.php` (the page shows the exact command). It does nothing until a
    backup is due; `--now` makes one right away.
  - **Without a scheduled job**, the backup is made after someone opens a page once it is due (the page is sent first,
    so nobody waits). This needs PHP-FPM or LiteSpeed, which Hostinger has.
  - The page shows the last automatic backup, the next one, whether a scheduled job runs it, and the last problem.
    **Make the automatic backup now** tests it on the server.
  - **Dashboard reminders for power.admin**: backups off, the last one failed, none for too long, or no backup
    downloaded in 14 days ("keep a copy off this server": a USB drive or Google Drive).
- Source: `vendor/` (new), both layouts, `pages/*.html`, `js/final-grades.js`, `sis/app/front.php`, `portal/index.php`,
  `sis/app/lib/backup.php`, `sis/app/pages/backup.php`, `sis/app/pages/dashboard.php`, `sis/bin/auto-backup.php` (new),
  `sis/tests/v63-backup.php`.

## v63.15 — No automatic sign-out when doing nothing
- **Removed** "Sign out automatically after no activity" from Settings → System Settings → Sign-in security, together
  with the idle timer and its "Still there?" warning in every system (SIS, Student Portal, Grading System, LMS,
  Canteen POS, Library, Finance). A value saved before is ignored and cleared the next time Sign-in security is saved.
- The server no longer ends a sign-in after 30 minutes without activity either. A sign-in now ends only when the person
  presses **Sign out**, after **8 hours**, or when the account signs in on another device (if **One device at a time** is on).
- Source: `pages/settings.html`, `js/settings.js`, `js/app.js`, `shared/central-accounts.php`, both layouts, `sis/index.php`,
  `sis/app/front.php`, `sis/app/pages/login.php`; removed `sis/assets/idle-signout.js`, `idle-signout.css` and
  `sis/app/pages/keepalive.php` (they can be deleted from the server too).

## v63.19 — Works on Hostinger databases ("Illegal mix of collations")
- **Fixed:** on Hostinger, My profile (and so every page for a Power User without an email) answered
  **500 / a blank page**. The log said `SQLSTATE[HY000]: General error: 1267 Illegal mix of collations
  (utf8mb4_general_ci,COERCIBLE) and (utf8mb4_unicode_ci,COERCIBLE)`. Hostinger's database server sets the
  connection's text-comparison rule (collation) to `utf8mb4_unicode_ci`, while values sent with a query get
  `utf8mb4_general_ci`, so a comparison like `? = 'STUDENT'` is refused.
- Every connection (SIS, Student Portal, LMS, Canteen, Library, Finance and the Grading System API) now starts with
  `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`, the rule AcadLink's tables use. Nothing to change in `api/config.php`
  or the database.
- Source: `sis/app/lib/core.php`, `api/lib.php`.
