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

## v63.16 — System Check
- New **Settings → System Check** (power.admin only, first in the Settings menu). It checks this server and the setup:
  - **Server**: PHP version (8.3 recommended) and extensions, HTTPS, debug mode, memory, largest upload, time for long
    jobs, database server and size.
  - **Files uploaded**: all 12 `.htaccess` files (and that the HTTPS ones are this version), the `vendor` folder, and
    files from older versions that can be deleted.
  - **Backups**: kept outside the website folder or not, free disk space, automatic backups, the scheduled job, and when
    a copy was last downloaded.
  - **Accounts & security**: power.admin password, One device at a time, email for "Forgot password".
  - **School**: name and logo.
  Each line says OK, Look at, or Problem, with how to fix it. The page also has the **checklist for uploading a new
  version**. power.admin's dashboard shows a red warning while the System Check finds a problem.
- Email setup messages now point to Settings → System Settings / Email Sending.
- Source: `sis/app/lib/system-check.php` (new), `sis/app/pages/syscheck.php` (new), `sis/index.php`, `sis/app/views/layout.php`,
  `sis/app/bootstrap.php`, `sis/app/pages/dashboard.php`, `sis/app/lib/account-email.php`, `sis/app/pages/emailsetup.php`.

## v63.17 — Two-step sign-in
- **My profile → Two-step sign-in**: after the password, AcadLink asks for the 6-digit code from an authenticator app
  (Google Authenticator, Microsoft Authenticator, Authy…). Turn it on by scanning the QR code (or typing the key) and
  typing the first code; **10 recovery codes** are shown once, for a lost phone (each works once). New recovery codes and
  Turn off need the password and a current code.
- Works for every system (SIS, Student Portal, Grading System, LMS, Canteen POS, Library, Finance): the code is asked on
  the AcadLink sign-in page, right after the password and before anything changes (the "another device" question comes
  after it). 5 wrong codes start the sign-in again; wrong codes count toward the usual sign-in limits; each code works once.
- **power.admin**: System Check marks it a Problem until it is on (and warns when fewer than 3 recovery codes are left).
- **Lost phone**: a Power User can turn it off for someone (Users and Groups → the person → profile). For power.admin, use
  a recovery code, or on the server: `php sis/bin/reset-two-step.php power.admin`.
- The secret is stored encrypted (AES-256-GCM with a key from `api/config.php`); recovery codes are stored as hashes.
  If the database password in `api/config.php` changes, turn two-step sign-in on again (recovery codes still work).
- Source: `shared/two-step.php` (new), `shared/central-accounts.php`, `sis/app/lib/auth.php`, `sis/app/pages/login.php`,
  `sis/app/pages/profile.php`, `sis/assets/two-step.js` (new), `vendor/qrcode-generator-2.0.4` (new, MIT), `api/lib.php`,
  `sis/app/lib/system-check.php`, `sis/bin/reset-two-step.php` (new), `sis/tests/v63-security.php`.

## v63.18 — Browser checks in the automatic tests
- Every pull request and change to `main` now also builds a throwaway AcadLink on GitHub (`sis/tests/ci-seed.php`:
  INSTALL.sql, every module, the sample data, and one account per role) and opens it in a real browser
  (`sis/tests/browser-smoke.mjs`, Playwright/Chromium): power.admin (SIS pages, every Settings page including the
  Grading System pages inside them, Finance, Canteen POS, Library, LMS, the Grading System), a registrar, a cashier,
  canteen staff, a librarian, a teacher and a student (Student Portal) — 47 pages. A page fails on a server error, a
  JavaScript error, a security-policy refusal, PHP error text, being signed out, or landing on another page.
  Screenshots of failing pages are attached to the run.
- `ci-seed.php` refuses to run unless `ACADLINK_CI=1` and the database is empty, so it cannot touch real data.
- Known: the sample data (`php sis/bin/install.php --sample`) stops at the first payment, because payment plans became
  required after it was written; registrations and students are still created.
- Source: `.github/workflows/tests.yml` (new browser job), `sis/tests/ci-seed.php` and `sis/tests/browser-smoke.mjs` (new).
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

## v63.20 — "Illegal mix of collations" can't come back
- **One text rule everywhere.** On upgrade (the first page after an update, once per session), AcadLink sets the
  database's default and every table to `utf8mb4_unicode_ci`. Tables a host or an older version created with another
  rule (e.g. `utf8mb4_general_ci`, or `utf8mb4_uca1400_ai_ci` on newer MariaDB) are converted; their data is kept.
  Binary tables (the Grading System's `docs`, `accounts`, `login_attempts`, restore jobs) and ascii token columns are
  left as they are on purpose. Anything that could not be converted is written to the PHP error log.
- **Every table names its rule.** The few `CREATE TABLE` statements that relied on the server's default
  (`sis_payment_plans`, `sis_registration_plans`, `sis_payment_plan_events`, `sis_document_counters`, `sis_form_once`)
  now say `COLLATE=utf8mb4_unicode_ci`, in `INSTALL.sql` too.
- **New test suite `v63-db-collation`** (20 suites now), run on every pull request:
  - builds a database the way Hostinger does: a `utf8mb4_general_ci` database, a user without SUPER, and a server
    that forces `collation_connection = utf8mb4_unicode_ci`. It first proves that this setting reproduces the error;
  - on it: setup password, sign-in, permissions, and accounts of every group;
  - fails if any `CREATE TABLE` in the code doesn't name its collation, or any database connection doesn't start with
    `SET NAMES utf8mb4 COLLATE …`.
  Checked: removing either fix makes the suite fail.
- Source: `sis/app/lib/core.php` (`Db::alignCollation`, `Db::COLLATION`), `sis/app/bootstrap.php`,
  `sis/app/lib/documents.php`, `INSTALL.sql`, `sis/database/schema.sql`, `sis/database/demo_bulk_seed.sql`,
  `sis/tests/v63-db-collation.php` (new), `sis/tests/all.sh`.

## v63.21 — Faster on Hostinger (measured on a school of 3,000 students)
- **Product photos, student photos, book covers and LMS files** (`BinaryResponse`, `sis/app/lib/binary-response.php`):
  - the session lock is released before the file is read, so the photos of a list download **side by side** instead of
    one after another (Canteen Products took **73 s** for 1,000 photos);
  - the after-page jobs (notification scan, email queue, automatic backup) no longer run for every photo;
  - photos are kept by the browser for a year when the address names that exact picture (a student photo id or LMS file
    id never changes; product photos and covers carry a `?v=` tag of the picture), and a known copy gets "304 Not
    Modified"; no session cookie or 1981 "Expires" header on files;
  - lists load photos only when they scroll into view (`loading="lazy"`): Canteen Products, POS, Library, student photos.
- **Admission list** (was **10 s, 20,159 queries** for 3,000 applicants → **1.2 s, 164 queries** on the same data):
  - the document checklists of all applicants are read in one query (it re-read the requirement list and 8 counts for
    every row); the requirement list is read once per request;
  - the counters count every matching applicant in the database. The list still shows the newest 2,000 and now says
    so ("Showing the newest 2,000 of 3,000 matching applicants…"), with search and filters to find the others;
  - new index `k_sis_reg_student` on `sis_registrations (student_number, id)` for "already registered?" per row.
- **Every sign-in and every new visitor**: `sis_upgrade()` stores `schema_version` (now **35**) in `sis_settings`; a new
  session reads that one value instead of ~50 information_schema checks (**173 → 11 queries** for a new visitor).
  Raise `SIS_SCHEMA_VERSION` (sis/app/bootstrap.php) whenever the upgrade gains a step.
- **Every page**: the table checks that ran on each request (`central_sessions`, temporary passwords, `docs`, Library
  fines, subject fees) ask the table itself (`Db::tableExists`, a zero-row read) instead of information_schema, which is
  slow on shared hosting; the Grading System home went from 72 such checks to 0.
- New test suite `v63-speed` (21 suites).
- Source: `sis/app/lib/binary-response.php` (new), `sis/app/bootstrap.php`, `sis/app/lib/core.php`,
  `shared/central-accounts.php`, `sis/app/lib/enrollment-docs.php`, `sis/app/pages/admission.php`, `sis/app/pages/photo.php`,
  `canteen/pages/image.php`, `canteen/pages/products.php`, `canteen/pages/pos.php`, `canteen/assets/pos.js`,
  `library/pages/cover.php`, `library/lib/library.php`, `lms/pages/file.php`, `sis/app/lib/student-photo.php`,
  `sis/app/lib/grading.php`, `sis/app/lib/sis.php`, `sis/app/lib/subject-fees.php`, `sis/tests/v63-speed.php` (new).

## v63.22 — Caching: each page asks the database ~85% less
- **Request cache** (`Db::memo`): a value read again in the same page view is remembered, and **any write drops every
  remembered value**, so a page never shows something older than what it just saved. Used for the signed-in user,
  the Grading System lists (`GS::all`, `GS::get`, school years) and the automatic-backup settings.
- **The signed-in person is looked up once per page** (`Auth::user()`, `CentralAccounts::legacyId()`): it was looked up
  again for every permission check, about 80 times per page, two queries each.
- **Sign-in check on every request** stays complete (revoked, expired, idle, signed in elsewhere, password or policy
  changed), but: the temporary-password expiry is checked only for accounts that still have a temporary password, and
  "last seen" is written at most once a minute instead of on every request.
- Measured (queries per page view): SIS dashboard **195 → 30**, Students **169 → 27**, Admission **170 → 33**,
  LMS **106 → 30**, Library **89 → 32**, Canteen POS **113 → 35**, Grading System home **346 → 274**.
- Not cached across requests on purpose: sign-in, permissions, balances and grades take effect immediately.
- Tests: `v63-speed` (7 checks; a repeated read is remembered, a write drops it, the user is rebuilt after a change,
  revocation still checked on every request).
- Source: `sis/app/lib/core.php` (`Db::memo`, `Db::changed`, `Db::writes`), `sis/app/lib/auth.php`,
  `shared/central-accounts.php`, `shared/curriculum-assignments.php`, `sis/app/lib/grading.php`, `sis/app/lib/backup.php`,
  `sis/tests/v63-speed.php`.

## v63.23 — Canteen POS loads only the photos on screen
- The POS set each product photo's address before marking it "lazy", so the browser downloaded **all** photos at once
  (measured on the live site: 1,000 photos, 51 s). The photo is now marked lazy first, so only the products on screen
  load, as you scroll.
- `v63-speed` checks the order in `canteen/assets/pos.js`.
- Source: `canteen/assets/pos.js`, `canteen/pages/pos.php`, `sis/tests/v63-speed.php`.

## v63.24 — Fixes found while filling the live test site
- **A "saved" message showed in another tab** of the same account (e.g. "Teacher saved" on the Sections tab). A message
  now belongs to the page its save goes to (`redirect()` addresses it) and waits for that page; a message nobody sees
  is dropped after two minutes. Messages without a page (pages that redirect themselves) behave as before.
- **Tuition charged twice: now flagged.** A fee template that charges a fee **per unit** and also the subjects' own
  prices in the same category (e.g. "Tuition Fee per unit" plus each subject's tuition price) shows a warning on the
  template: students would pay both. Untick one of them in Manage fees.
- **Subjects tab** counter said "of 10 curriculums"; it now says "subjects".
- **Teachers list** showed two "Actions" columns; it keeps its own menu only.
- **Payment plan** line said "Overdue: ₱0.00", easy to read as "overdue"; it now says "Nothing overdue" (or shows the
  overdue amount in red).
- Checked and not a bug: Canteen and Library staff with a temporary password ARE asked to change it (tested on the live
  site with a new Canteen cashier account).
- New test suite `v63-fixes` (22 suites).
- Source: `sis/app/lib/core.php` (`flash`, `redirect`, `take_flashes`), `sis/app/lib/subject-fees.php`
  (`TemplateFees::doubleCharge`), `sis/app/views/assessment-fee-templates.php`, `sis/assets/theme.js`,
  `sis/app/views/layout.php`, `sis/app/pages/teachers.php`, `sis/app/views/payment-plan-summary.php`, `sis/tests/v63-fixes.php`.

## v64 — New AcadLink design, step 1: the SIS
- The staff SIS (Admission, Registration, Records, Cashier, Assessment, Fees, Administration, Settings) uses the new palette:
  white `#FFFFFF` sidebar, cards and panels; light blue-gray `#F3F6FA` page; deep blue `#123D70` headings and welcome
  banner; royal blue `#235FD6` icons, links and highlights; orange `#EF7D24` for the selected menu item and the primary action.
- White sidebar with small outline icons, quiet group labels and the selected item in orange; white header with an
  orange line; orange primary buttons, blue outline buttons; deep-blue dashboard banner with quiet geometric shapes.
- One new stylesheet, `sis/assets/blue-theme.css`, loaded last; it only overrides colours and a few sizes, so every
  screen and function works as before. Printed documents are unchanged (screen only).
- Not yet in the new design (next steps): Student Portal, LMS, Canteen POS, Library, Finance, Grading System, sign-in page.
- Source: `sis/assets/blue-theme.css` (new), `sis/app/views/layout.php`.

### v64 step 2 — the same colours on every system

Every AcadLink system now uses one palette (white, light blue-gray, deep blue `#123D70`, royal blue `#235FD6`, orange `#EF7D24`):

- **Library, LMS, Canteen** — white sidebar, orange selected item, orange main button (`sis/assets/blue-theme.css`).
- **Grading System** and the **sign-in page** — block at the end of `css/style.css`; the pages now link `style.css?v=64`.
- **Student Portal** — deep-blue bar with an orange line, orange main button (`portal/assets/portal.css`).
- **Workspace chooser** — blue tiles, orange Open button (`css/workspace-selector.css`).

Only colours changed: no screen, menu, permission or data was altered. Press Ctrl+F5 once after updating.

## v64.1 — Regrouped menu and fixed toolbar order

- **SIS menu.** "Enrollment" and "Records" are now **Student Services** (applications, admission, registration, clearance, eligibility, students, photos, privacy, TOR, histories) and **Academics** (curriculum, class schedules, sections, year levels, teachers). Assessment, Fees and Administration are unchanged. No page or permission changed, only where the link sits.
- **Toolbar (SIS and Grading).** One fixed order everywhere: **View · New | Edit · Delete · Post · Void | Select All | Filters · Apply Filter | Search · Refresh · More**. View and Delete now have their own buttons (View used to be under More Actions; Delete used to share the Void button). Each button still clicks the page's own control, so confirmations and permissions are the same.

Files: `sis/app/views/layout.php`, `js/standard-toolbar-core.js`, `js/record-toolbar.js`, `sis/assets/standard-toolbar.js`, `css/standard-toolbar.css`, `pages/*.html` (version tags). Press Ctrl+F5 once.

## v64.2 — Mockup layout on desktop and phone

**Desktop (SIS):** page title in the header; side menu with an "Overview" heading and the mockup groups: Student Services, Academics (now with links to Grading and LMS), Assessment, Operations (Cashiering, Document Fees, Library Fines, and links to Accounting, Library and Canteen POS), Administration (Reports, Users, Settings, Activity Log, Help). Groups open by default (Assessment stays closed); each group still remembers being closed. Links to another system only show for people who can open it.

**Phone (SIS and Finance, under 992 px):**
- Header: logo, page title, bell, avatar.
- Toolbar: one row of actions with icon and word (View · New | Edit · Delete · Post · Void | Select All), then filter, search, refresh and more on the row under it.
- Lists become cards: checkbox, name in bold, the other columns under it; the selected card turns light blue. This also fixes the table header that floated over the first row on phones.
- Flat bottom menu: Home · Admissions · Enrollment · Cashier · More (the first four pages each person may open; More opens the full menu). Finance: Home · Cashier · Review · Reports · More.

Files: `sis/app/views/layout.php`, `sis/assets/blue-theme.css`, `sis/assets/theme.js`. Press Ctrl+F5 once.

## v64.3 — Library and LMS in the mockup layout; new loading spinner

- **Library and LMS:** page title in the header (desktop and phone), flat bottom menu with an orange line over the selected item, no second big title on phones. LMS welcome is a deep-blue banner. Library lists show as cards on phones, and Library pages keep a side margin on phones (they touched the screen edge before).
- **Phone cards everywhere:** number and money columns show their column name ("Copies 2", "Balance 5,275.00") so a bare number is never unclear (`sis/assets/card-labels.js`).
- **Loading spinner:** only a spinning circle in the middle of the screen, coloured blue, green and orange, in every system (SIS family and Grading). The words are kept for screen readers. The thin progress line at the top uses the same colours.

Files: `sis/app/views/layout.php`, `sis/assets/blue-theme.css`, `sis/assets/card-labels.js` (new), `sis/assets/activity.css`, `sis/assets/navigation.css`, `css/style.css`, `pages/*.html`. Press Ctrl+F5 once.

## v64.4 — Canteen POS and Finance in the mockup layout

- **Canteen POS:** page title in the header (desktop and phone), flat bottom menu with an orange line over the selected item, no second big title on phones, side margins on phones (content touched the screen edge before). Categories are one row of round chips that scrolls sideways. Products and Sales lists show as cards on phones: small picture, bold name, details with column names ("Price ₱95.00", "Stock 18").
- **Finance:** "Overview" heading over **Dashboard** in the side menu, like the SIS (the first link was named "Overview"). Title in the header, toolbar, cards and bottom menu were already in place from v64.2.

Files: `sis/app/views/layout.php`, `sis/assets/blue-theme.css`. Press Ctrl+F5 once.

## v64.5 — No page title in the desktop header

The page title next to the search box ("Dashboard", "Students"…) is removed on desktop in every module (SIS, Finance, Library, LMS, Canteen). The breadcrumb under the header still names the page. On phones the title stays in the header, where it replaces the school name.

File: `sis/assets/blue-theme.css`. Press Ctrl+F5 once.

## v64.6 — Polish: readable activity, no browser error, phone fixes, dashboard chart, faster dashboards

- **Recent activity is readable.** Dashboard and Activity Log show the class ("WEB101 Web Development · BSIT 2A"), book, product, teacher or subject instead of internal codes like "lms_class #cur_8166e…". "Lms" is written "LMS".
- **No more "Transition was aborted" browser error.** The page-change animation is off (it failed on some pages and after sign-in). The bottom menu is printed first on every page, so it still shows at once.
- **Bug fixed — Canteen Cashier on phones:** since v64.4 the category chips made the page wider than the screen, so the phone zoomed out and the bottom menu was hard to tap.
- **No page is wider than a phone:** Settings could not shrink; wide tables now scroll inside their own box.
- **Phone cards:** details are spaced apart instead of joined with "·" (a dot could start a line). Recent activity and Finance "Collections by source" are cards too.
- **Slow saves:** after 8 seconds the spinner shows "This is taking longer than usual. Please wait." so nobody clicks Save twice.
- **Dashboard (desktop and phone):** "Enrollment activity, last 7 days" chart (hover or tab to a day for its count) and "Recent applications" with New / Returnee / Transferee badges, for people who work on admissions.
- **Grading System toolbar:** shows only the buttons a page can use, like the SIS.
- **Faster dashboards:** SIS and Finance dashboard numbers are kept for one minute per person and dropped as soon as that person saves something. Reports are never cached.

Files: `sis/app/lib/sis.php`, `sis/app/lib/core.php`, `sis/app/pages/dashboard.php`, `finance/pages/overview.php`, `sis/app/views/layout.php`, `sis/assets/{blue-theme.css,activity.css,activity.js,card-labels.js,bottom-nav.css}`, `css/{style.css,standard-toolbar.css}`, `js/{loading.js,record-toolbar.js}`, `pages/*.html`, `sis/tests/v63-fixes.php`. Press Ctrl+F5 once.

### v64.6 — Bug fixed: Curriculum page "unresponsive"

With about 1,000 subjects and 1,000 teachers, the Curriculum form wrote the full teacher list into every subject row: 1,013,061 dropdown entries and 54 MB of HTML, about 2½ minutes to load, and the browser said the page was unresponsive. Now each row starts with Professor X and the chosen teacher, and the full list (written once) is added when that row's list is opened: 522 KB, under 1 second. The Grading System's Curriculum page had the same problem and is fixed the same way (0.6 seconds).

Saving also sends only the ticked subjects' teachers. Before, a large form sent over 2,000 values, and PHP's default limit is 1,000, so teacher choices could be silently lost.

Files: `sis/app/pages/curriculum.php`, `sis/assets/curriculum-picker.js` (new), `js/curriculum.js`, `pages/curriculum.html`.

## v64.7 — Faster LMS lists for coordinators; "Inspect" restricted

**Speed (measured on the live site with every page of every system).** Most pages open in under a second. The LMS lists were slow for coordinators and admins, who see every class (1,280 on the test site): My Classes 4.8 s, Schedule 4.6 s, Reports 3.0 s, Gradebook 2.5 s, Settings 1.9 s. They ran 5–7 database queries per class (6,000+ per page). Now they load what they need for all classes in about ten queries (`Lms::preload`). The results are exactly the same; a test compares them class by class.

**"Inspect is restricted on AcadLink."** Right-click (outside text boxes), F12, Ctrl+Shift+I / J / C and Ctrl+U (view source) are blocked with that message on every page (SIS, Finance, Canteen, Library, LMS, Student Portal, Grading System, sign-in). Copy and paste in text boxes still work. Note: this only discourages casual looking. Chrome's own menu (More tools → Developer tools) cannot be blocked by any website. AcadLink's real protection is on the server, which checks every permission.

Files: `lms/lib/lms.php`, `lms/pages/{classes,schedule,gradebook,reports,settings}.php`, `js/no-inspect.js` (new), `sis/app/views/layout.php`, `portal/views/layout.php`, `index.html`, `pages/*.html`, tests in `sis/tests/v61-lms.php` and `v63-fixes.php`.

## v64.8 — Actions in More Actions; Create account for one student

**No more "Actions" button or side panel.** About 40 SIS and Finance pages had an "Actions" bar that opened a side panel (Quick Actions, Record Actions, Tools, Payment Setup, Related Modules). It is gone; pages use the full width. Everything that was in it is in the toolbar's **More Actions** dropdown, under the same group names, without duplicates. Each item still clicks the original control, so permissions and confirmations are unchanged (checked: Fee Labels "Activate / Deactivate", Teachers "Create account", Registration "Subjects" pop-up).

**Tools fixed.** The panel also ran on the LMS, Library and Canteen pages named Settings and Reports, where it did not belong: LMS Settings showed a "Tools" list with one bare "Edit" per class (1,280). It now runs only in the SIS and Finance.

**Create account from a student's row.** On Students, a Power User sees **Create account** in a student's row menu (…) when the student has no sign-in. After confirming, the account is created with the Student ID as username and a temporary password (shown once, valid 7 days, must be changed at first sign-in), and it is linked to the student at once. The same checks as Bulk create accounts apply (duplicate Student ID, an existing or legacy account, a usable username). Students who already have a sign-in show **Manage account** instead.

Files: `sis/assets/{sis-actions.js,sis-actions.css,standard-toolbar.js}`, `js/standard-toolbar-core.js`, `css/standard-toolbar.css`, `sis/app/pages/students.php`, `sis/app/views/layout.php`, `pages/*.html`, tests in `sis/tests/v63-fixes.php`.

## v64.9 — Long lists send one page at a time

Admission, Registration, Teachers and Subject Fees used to draw every row (1,000–2,000) and then show 15 of them, so the browser spent about a second drawing rows nobody saw. They now send only the page you are looking at.

| Page (test data like the live site) | Before | After |
|---|---|---|
| Admission (2,001 applicants) | 1.37 s · 2.3 MB | 0.16 s · 50 KB |
| Registration (1,002) | 0.73 s · 1.0 MB | 0.19 s · 44 KB |
| Teachers (1,003) | 0.61 s · 0.9 MB | 0.14 s · 35 KB |
| Subject Fees (1,010) | 0.70 s · 0.7 MB | 0.17 s · 145 KB |

- The pager works as before ("Showing 1–15 of N", page buttons, 15 / 30 / 50 / 100 per page, remembered); moving to another page asks the server for it. A new **All** choice shows every row for that one view (for printing a full list); it is not remembered.
- Counters and totals still count every matching record. Admission no longer stops at the newest 2,000 applicants.
- Searching finds records on every page (Teachers: the toolbar search; Subject Fees: its search box now searches on the server). The Teachers box that searched only the rows on screen is removed.
- Sorting by clicking a column heading sorts the rows of the page shown.

Files: `sis/app/lib/record-list.php` (`RecordList::window`, `slice`, `pagesAttr`, `pagerHtml`), `sis/app/pages/{admission,registration,teachers,subjectfees}.php`, `sis/assets/theme.js`, `sis/assets/blue-theme.css`, `sis/app/views/layout.php`, tests in `sis/tests/v63-fixes.php` and `v63-speed.php`.

## v64.10 — Date filter on Admission and Registration

- **Admission → Date Applied** and **Registration → Date Registered**: a From–To date filter (tick it in the filter panel, like the other filters). Counters, totals and paging follow it.
- **Cashier** already had **O.R. Date Range** (today by default); it is unchanged.
- All three have quick choices under the dates: **Today**, **Last 7 days**, **This month** (they fill the dates and tick the filter; press Apply).

Files: `sis/app/views/layout.php` (`date_range_controls`), `sis/app/pages/{admission,registration,cashier}.php`, `sis/assets/app.js`, `sis/assets/blue-theme.css`, test in `sis/tests/v63-fixes.php`.

## v64.11 — Lists reopen on page 1

Found while re-testing v64.9 on the live site: a list remembered the page number with its filters, so after visiting page 5 of Registration, opening Registration again showed page 5. Filters are still remembered; the page number and "All" are not, so a list always reopens on page 1.

File: `sis/app/lib/record-list.php`; test in `sis/tests/v63-fixes.php`.

## v65 — Full check: security, heavy use, email, printing, accessibility, speed

**Security review (no holes found).** Every page declares who may open it, and every form needs the session's CSRF token. A student account and a teacher account tried 41 other people's pages and records (other students' grades, assessments, receipts, photos, clearance files, LMS classes, files, messages and submissions, staff pages): all refused or showing only their own data. Database queries take request input only as bound values. Unescaped output: a marker put in names, titles and messages stayed plain text on 96 pages of every system and role. Uploads are kept in the database, type-checked by content and served sandboxed. Sign-in locks an account for 15 minutes after 5 wrong passwords (100 tries per computer per 15 minutes). The Grading System API checks every record against its rules, also in list queries. The sign-in cookie is Secure, HttpOnly and SameSite.

**Bug fixed — many people at once.** On the test site, 30 requests at the same moment gave 10 errors ("Cannot connect to the MySQL database. Check api/config.php."): the hosting plan allows only about 20 database connections at once. The SIS and the Grading System API now wait a moment and try again (up to about 6 seconds) when the database is busy, and show "The system is busy" instead of the settings message if it still cannot connect. Tested with a 3-connection limit and 20 requests at once: before 17 failed, now all 20 succeed.

**Email.** Notification emails are queued only when sending is set up and are sent after the page is delivered; each round now stops after about 6 seconds (8-second timeout per email) so a slow mail service never holds the server's connections. The rest goes out after the next page view.

**Printing.** The Statement of Account no longer prints the on-screen payment plan box above its header; "Skip to content" never prints; Subject Fees prints without its buttons and edit form; on a paged list, **Print List** prints every row.

**Accessibility (WCAG AA).** Zero problems on 16 checked pages (staff, student, LMS) with axe-core. Orange with white text was too faint to read (2.75:1); buttons and the selected menu item use a deeper orange **#B85309** (4.91:1, hover #A84E0A); bright orange stays only in the loading circle. Fixed: faint greys, the old green tab, a More actions button without a name for screen readers, unlabelled Portal history filters, wrong list markup on Students, a stronger focus ring on the sign-in boxes. Keyboard: every staff page shows where the focus is. Phones: no page is wider than the screen any more (Subject Fees and the student LMS were 8 px too wide).

**Speed.** The Students list sends one page at a time (was up to 500 students, 811 KB; now 15, 47 KB) and every student is reachable (no 500 limit). The Grading System's Curriculum page opens in about 3 s on the test site (about 1 s of it is the data; the rest is the Grading System's usual start-up).

Files: `sis/app/lib/core.php`, `sis/app/bootstrap.php`, `api/lib.php`, `sis/app/lib/notify.php`, `sis/app/lib/account-email.php`, `sis/app/pages/{students,subjectfees,history}.php`, `sis/app/views/payment-plan-summary.php`, `sis/assets/{app.js,sis-actions.css,blue-theme.css}`, `js/standard-toolbar-core.js`, `css/{style.css,workspace-selector.css}`, `portal/assets/portal.css`, version tags in `sis/app/views/layout.php`, `portal/views/layout.php`, `sis/app/pages/workspaces.php`, `pages/*.html`; tests in `sis/tests/v63-fixes.php`.

## v65.1 — New filter design on Cashier, Admission and Registration

The filter panel is now one plain row, as in the design: a tick box with a bold label above each filter, the control below it, and unticked filters shown grey. The date range is two boxes, **From | date** and **To | date** (it takes two columns). Dropdowns show a ▾ arrow; Cashier's dropdown says "All cashiers". On phones each filter takes the full width. The Today / Last 7 days / This month shortcuts and the panel's own Apply/Clear buttons are hidden; the toolbar's Filters and Apply still work, and unticking a filter and pressing Apply removes it.

Files: `sis/app/pages/{cashier,admission,registration}.php`, `sis/app/views/layout.php` (`date_range_controls`, version tags), `sis/assets/blue-theme.css`; test in `sis/tests/v63-fixes.php`.

## v65.2 — New Add / Edit curriculum page (SIS → Curriculum)

**Add curriculum** and **Edit** now open their own page, as in the designs (no more pop-up form):
- **Back to curricula**, then a **Curriculum details** card: Curriculum name (new, optional, e.g. "BSBA First Year"; shown in the list), Program, Year level and Term.
- **Subjects** tab: *Selected subjects* with "3 subjects • 9 total units", **Add subjects**, and a bin to remove a subject.
- **Select subjects** window: *Available subjects* with search and **Add** / **Added**; *Selected subjects* with "2 subjects • 6 units" and **Remove**; **Cancel** leaves everything as it was, **Confirm selection** applies it.
- **Teachers** tab: *Assign teachers* with search, "3 unassigned", **Choose teacher** / **Change**, 10 subjects per page. Tabs show counts: Subjects (8), Teachers (5 assigned).
- **Select teacher** window: subject, "BSBA • 1st Year • 1st Semester", search by name or ID, initials, Teacher ID, **Select teacher**. (Teacher records have no department, so that column is not shown.)
- A bar at the bottom: **Unsaved changes**, **Cancel**, **Save changes**; leaving the page with unsaved changes asks first.
- Phones: one field per line, the windows fit the screen, the bar sits above the bottom menu.

Unchanged: what saving does (classes are created for matching sections; teacher changes reach active classes without submitted grades). The teacher list is written once in the page, so large schools stay fast.

Files: `sis/app/pages/curriculum.php`, `sis/assets/curriculum-picker.js`, `sis/assets/blue-theme.css`, `sis/app/lib/grading.php` (`saveCurriculum` takes an optional name), version tags in `sis/app/views/layout.php`; tests in `sis/tests/v63-fixes.php`.

## v65.3 — Fee templates: list first, View shows one template's fees

**Assessment → Fee templates** now shows only the list of templates (no template opens by itself). Tick a template and press **View** in the toolbar (or click its name, or the eye button on its row) to open it.

A template's page shows only what it charges: **General fees** (category, label, how it is charged, amount) and **Subject fees**, with **← Back to templates**. To add items, press **+ Add fee** or **+ Add subject fee** (both open *Manage fees*, where you tick fees and set general fee amounts). **Edit**, **Manage fees** and **Copy template** (now a small window) are in the toolbar and its More menu. The fee preview was removed from this page; the list filters are hidden on a template's page.

Files: `sis/app/views/assessment-fee-templates.php`, `sis/assets/assessment-fee-templates.css`, `sis/assets/theme.js` (tables marked `data-plain-table` stay plain), version tags in `sis/app/views/layout.php`; test in `sis/tests/v63-fixes.php`.

## v65.4 — Subject pricing page (Assessment → Subject Fees)

**Set a subject price** and **Edit** on Assessment → Subject Fees now open the **Subject pricing** page, as in the design:
- **Select subjects**: choose the **Fee** and the **Price per unit** (with **Set as default** to make that fee the first choice next time), search subjects and press **Add** (added ones show ✓ Added).
- **Charge in fee templates**: the chosen templates as chips (× to remove) and **Choose templates**; only these templates charge the prices.
- A summary: **Selected subjects**, **Total units**, **Price per unit**, **Total amount**.
- **Selected subjects**: code, name, units, fee, an editable **Price / unit** per subject, amount and a bin; **Total**.
- **Unsaved changes**, **Cancel**, **Save prices**. All subjects are saved together: if one is refused, none is saved and the page comes back with what was entered.
- Edit opens the page with that subject, its fee, price and templates; more subjects can be added. Changing the price on top changes every selected subject's price.
- With lecture/laboratory units on, **Charged per** (all, lecture or laboratory units) is next to the fee.
- Phones: each selected subject is a two-line card.

The list of subject prices is unchanged; the old form and the separate "Default fee" box below it were replaced by this page.

Files: `sis/app/pages/subjectfees.php`, `sis/app/views/subject-pricing.php` (new), `sis/assets/subject-pricing.js` (new), `sis/assets/blue-theme.css`, version tag in `sis/app/views/layout.php`; test in `sis/tests/v63-fixes.php`.

## v65.5 — Class schedules follow the class's teacher

A class (one subject of one section in one term) has its teacher in **Grading assignments** (the curriculum's teacher at first, or another teacher chosen for that section). Class Schedules kept its own teacher, so changing the class's teacher left the schedule, the teacher's **My schedule**, timetables and the clash check on the old teacher. Now:
1. **Add a class** in Class Schedules fills in the **Teacher** with the teacher of that section's class for the chosen subject (it can still be changed). A schedule saved without a teacher takes the class's teacher.
2. When a class's teacher changes — in **Grading assignments**, from the **curriculum**, or when the class is created — the schedule entries of that section, subject and term that still have the previous teacher (or no teacher) take the new one. An entry is left as it is when the new teacher already has a class at an overlapping time. Professor X ("needs teacher") shows as no teacher in schedules.
3. Class Schedules shows **"Grading teacher: …"** beside a schedule whose teacher differs from the class's teacher.

Tested: changing a class's teacher in the Grading System moved its schedule; a clashing entry stayed; only entries with the previous teacher move; a new class fills only empty entries.

Files: `shared/schedule-teacher-sync.php` (new), `shared/curriculum-assignments.php`, `sis/app/lib/grading.php`, `sis/app/lib/schedules.php` (the unused `assignedTeacher` helper removed), `sis/app/pages/schedules.php`, `sis/assets/schedule-teacher.js` (new), `sis/assets/blue-theme.css`, version tag in `sis/app/views/layout.php`; tests in `sis/tests/v63-fixes.php`.

## v65.6 — Auto-schedule; lecture, lab and total units

**Subjects: lecture units + lab units = total units.** Curriculum → Subjects always asks for **Lecture units** and **Lab units** and shows the **Total units** as you type; the list shows Lec units, Lab units and Total units. (Before, this needed Settings → "Separate lecture and laboratory units"; that switch now only adds the "Charged per" choice to subject fees.) Subjects saved before keep counting as all lecture until they are edited.

**Class Schedules → Auto-schedule.** Open a section and press **Auto-schedule**:
- It lists the section's subjects with **Lec units, Lab units, Total units**, the hours a week they need (1 hour per lecture unit, 3 hours per lab unit — both can be changed) and what is already scheduled.
- It makes a **draft**: lectures as MWF 1 hr, TTh 1.5 hrs (alternating to use the whole week) or similar; a lab as one block (up to 3 hours) a day. Every meeting is at a time when the section **and the subject's teacher** are free, within the school hours (7:30 AM–6:00 PM, Monday–Friday by default, Saturday can be added) and keeping 12:00–1:00 free.
- **Rooms**: there are no rooms yet, so rooms show TBA. When rooms are added in the Rooms tab, it picks a free room big enough for the section (a laboratory room for labs).
- Every draft row can be changed (type, days, start, end, room) or unticked; **Save schedule** saves them together and checks clashes again (if one clashes, nothing is saved and the message names the row). Saved schedules stay editable with **Edit**, and their teacher follows the class (v65.5).
- Subjects that cannot fit are listed to be added by hand; running it again only adds what is still missing.

Files: `sis/app/lib/schedule-auto.php` (new), `sis/app/pages/schedules.php`, `sis/app/pages/curriculum.php`, `sis/assets/subject-units.js` (new), `sis/assets/blue-theme.css`, `sis/app/bootstrap.php`, version tag in `sis/app/views/layout.php`; tests in `sis/tests/v63-fixes.php`.

## v65.7 — Fix: the search box covered Void on a registration

On screens 1700 px wide and more, the toolbar sits in the top bar beside the School Year and was limited to 860 px. A registration's toolbar (Back, Print Assessment, Confirm Enrollment, Pay at Cashier, Void, search, Refresh, More Actions) needs about 890 px, so its buttons were squeezed and the search box covered **Pay at Cashier** and **Void**. The toolbar is now as wide as its buttons need; only the search box gets narrower. Checked on 10 pages at 1366, 1600, 1700 and 1920 px: no overlapping buttons.

Files: `sis/assets/easy-navigation.css`, version tag in `sis/app/views/layout.php`; test in `sis/tests/v63-fixes.php`.

## v65.8 — A registration's Tools beside the statement; Class schedule opens again

- On a registration, the **Tools** (Class schedule, Subjects, Assessment name, Scholarship / discount, Add a fee, Charge or less, Teachers, Payment plan — or Class schedule, Add / drop subjects and Payment plan once confirmed) and the **Summaries** are shown on the right of the statement again, as a list with icons. Since v64.8 they were only in **More Actions** (still there too). On phones they are above the statement.
- **Bug fixed:** **Class schedule** opened nothing (a script error in the browser): the line that loads the registration's schedule was removed together with the tool descriptions. All tools were checked to open, for an open and for a confirmed registration.

Files: `sis/app/pages/registration.php`, `sis/assets/sis-actions.js`, `sis/assets/blue-theme.css`, version tags in `sis/app/views/layout.php`; test in `sis/tests/v63-fixes.php`.

## v65.9 — SIS menu: new order and groups

The SIS menu is now, from the top:
- **Notifications**, then **Overview** (Dashboard)
- **Assessment**: Fee categories, Fee labels, Fee Templates, Subject Fees, Scholarships & Discounts, Payment Setup
- **Academics**: Curriculum, Class Schedules, Sections, Year Levels, Teachers (the **Grading** and **LMS** links are removed; open them from the system switcher)
- **Operations**: Enrollment Applications, Admission, Registration, Enrollment Eligibility, Cashiering, then Document Fees, Library Fines, Accounting, Library, Canteen POS
- **Student Services**: Students, Photo Approvals, Data Privacy, TOR & Certificates (and Online Clearance when it is on)
- **Administration**: unchanged

**Look (as in the design):** Notifications on top; each group (Overview, Assessment, Academics, Operations, Student Services, Administration) under a full-width light blue band with its name in capitals; groups are always open (no arrows); Assessment items read Fee templates, Subject fees, Scholarships & discounts, Payment setup with new icons; the account at the bottom shows ⋯.

**History of Grades** and **History of Assessments** are no longer in the menu; they still open from the Students page and from a student's record (the menu then shows Students as the current page).

Files: `sis/app/views/layout.php`; test in `sis/tests/v63-fixes.php`.

## v65.10 — The new menu design in every workspace

The SIS menu design (v65.9) is now used in **Finance, LMS, Library, Canteen POS** and the **Grading System**: Notifications on top where the workspace has it, then each group under a full-width light blue band with its name in capitals, groups always open.
- **Finance**: Notifications, Overview (Dashboard), Cashier, Balances, Accounting, Reports.
- **LMS**: Overview (Dashboard), Classes (My Classes, Schedule, Attendance, Assignments, Gradebook, Materials, Messages), Administration (Reports, Settings).
- **Library**: Overview (Dashboard — was "Overview"), Circulation (Catalog, Circulation, Members, Reservations, Fines), Administration (Reports, Settings).
- **Canteen POS**: Point of Sale (Cashier, Sales & Receipts, My Shift), Supervisor, Inventory, Reports.
- **Grading System**: Notifications, Overview (Dashboard), Academic setup, Grading, School Information System.
The Student Portal keeps its row of tabs at the top (it has no side menu).

Files: `sis/app/views/layout.php`, `sis/assets/blue-theme.css`, `sis/assets/theme.js`, `css/style.css`, `pages/*.html`; test in `sis/tests/v63-fixes.php`.

## v65.11 — × on each tuition line of a registration

On a registration's statement, **Tuition Fee · BSBA1101** and the other lines priced per subject (Assessment → Subject fees) now have an **×** like the Miscellaneous fees. It takes that subject's fee off this student's assessment only; the assessment, scholarships computed on tuition and the payment plan are recalculated at once. Removed lines are listed under **Not charged to this student** with **Charge again** (not printed). Like the other ×, it works while the registration is open.

Database: a small table `sis_registration_subject_skips` is created automatically (schema version 36) on the first page view after the update.

Files: `sis/app/lib/sis.php` (`Registration::skipSubjectFee`, `subjectSkips`, `build`), `sis/app/lib/subject-fees.php`, `sis/app/pages/registration.php`, `sis/app/views/assessment_sheet.php`, `sis/assets/sis-actions.js` (`data-keep-controls`), `sis/app/bootstrap.php`; test in `sis/tests/v63-fixes.php`.

## v65.12 — × on scholarship and discount lines

On a registration's statement, each **scholarship / discount** line (e.g. *Academic Scholarship (50% of Tuition)* under *Less: Scholarships & Discounts*) now has an **×** like the fee lines. It removes that program from this student's assessment and recalculates it (the same as removing it in the Scholarship / discount tool, where it can be given again). Only while the registration is open; not printed.

Files: `sis/app/views/assessment_sheet.php`; test in `sis/tests/v63-fixes.php`.

## v66 — Control over fee reductions and reopening; nine improvements

**Fee reductions and reopening (Settings → Fee reductions and reopening; Operations → Approvals).**
- Taking a fee or a subject's tuition off (×), giving a scholarship / discount, or a **Less** adjustment lowers what a student pays. Each now asks for a **reason** (setting on by default).
- **Reopening a confirmed enrollment waits for an approver**: the registrar asks with a reason (button "Ask to reopen"); the registration stays confirmed until it is approved.
- **After an enrollment was reopened**, lowering its fees also waits for an approver. A new (never confirmed) registration only needs the reason.
- Approvers are the accounts with the new permission **"Approve fee reductions and reopening"** (Users and Groups → Registration); the Power User has it. An approver's own change applies at once.
- Approvers are notified; **Operations → Approvals** (with a count) shows what is waiting (Approve, or Decline with a note) and the **History** — every reduction and reopening with its reason, who asked, who decided and the total before → after, with filters, a total and Print. The person who asked is notified of the decision and can cancel while it waits.
- A registration shows its **Changes and approvals**.

**Improvements**
1. **Curriculum**: changing an existing curriculum's program, year level or term moves it (it used to leave a copy). Moving onto a curriculum that already exists is refused.
2. (see above)
3. **Class Schedules → Use the class teachers**: when schedules of the term have another teacher than their class, one button fixes them all (ones that would clash are left and listed).
4. **Class Schedules → Auto-schedule all sections**: one draft for every section of the term; sections are planned one after another, so a teacher or room is never used twice at the same time.
5. **Curriculum → Subjects → Edit lecture / lab units** (in ⋯ More): every subject on one page with Lec, Lab and Total; only changed subjects are saved.
6. A refused save keeps what was typed also in grouped fields (e.g. Manage fees amounts), and its window opens again.
7. Phones: the page's New / Add button no longer shows twice (Fee templates, Curriculum).
8. The Grading System menu is written once (`js/grading-menu.js`) instead of in 14 pages.
9. GitHub also runs **browser flows** (take a fee off with a reason, set a subject price, auto-schedule all sections, registrar without approve buttons) and opens the new pages for each role.

Database: `sis_registration_changes` (schema 37) and the permission `sis.reductions.approve` are added automatically.

Files: `sis/app/lib/reductions.php` (new), `sis/app/pages/approvals.php` (new), `js/grading-menu.js` (new), `sis/tests/browser-flows.mjs` (new), `sis/app/pages/{registration,schedules,curriculum,settings}.php`, `sis/app/views/{layout,assessment_sheet}.php`, `sis/app/lib/{grading,schedule-auto,core,auth}.php`, `shared/{module-access,schedule-teacher-sync}.php`, `sis/app/bootstrap.php`, `sis/index.php`, `sis/assets/{app.js,standard-toolbar.js,subject-units.js,blue-theme.css}`, `pages/*.html`, `.github/workflows/tests.yml`, `sis/tests/{v63-fixes.php,browser-smoke.mjs}`.

## v66.1 — The last two ways to lower fees are controlled too

v66 controls the × on fees and tuition, scholarships, Less adjustments and reopening. Two other ways to lower what a student pays did not ask anything; now they work the same way (a reason; after a reopen, an approver; listed in Operations → Approvals → History with the total before → after):
- **Add a fee at a lower amount** — e.g. Registration Fee ₱100 when its usual amount (the student's fee template, else the fee's default) is ₱500. Fees at their usual amount or higher are added as before, with no reason.
- **A cheaper fee template** (Assessment name), including "none (no fees)", when the new total is lower than the current one.
Both windows have a **Reason** box.

Database: the change kinds LOWER_FEE and CHANGE_TEMPLATE are added to `sis_registration_changes` automatically (schema 38).

Files: `sis/app/lib/reductions.php`, `sis/app/pages/registration.php`, `sis/app/bootstrap.php`; test in `sis/tests/v63-fixes.php`.

## v66.2 — Approvals are the school's choice (one switch)

Settings → Fee reductions and reopening now has one switch for approvals: **Use an approver when a confirmed enrollment is reopened**. It is **off** unless the school turns it on.
- **Off:** no approvals — reopening and lowering fees happen at once (with their reason when "Ask for a reason" is on). Everything is still listed in Operations → Approvals → History.
- **On:** reopening a confirmed enrollment waits for an approver ("Ask to reopen"), and after that, lowering that student's fees waits for an approver too.
The two earlier approval switches are replaced by this one; "Ask for a reason when what a student pays is lowered" stays.

Files: `sis/app/lib/core.php` (setting `approval_on_reopen`, `Settings::reload`), `sis/app/lib/reductions.php`, `sis/app/pages/settings.php`; test in `sis/tests/v63-fixes.php`.

## v66.3 — Grading System menu without Academic setup

The Grading System menu no longer has the **Academic setup** group (Subjects, Curriculum, Teachers, Sections, Students): these are managed in the SIS (Academics → Curriculum with its Subjects tab, Sections, Teachers; Student Services → Students). The menu is now Notifications, Overview (Dashboard), Grading (Grading assignments, Enter grades, Final grades, …) and School Information System.

Files: `js/grading-menu.js`, version tag in `pages/*.html`; test in `sis/tests/v63-fixes.php`.

## v66.4 — Tamper check for the Activity Log

Every Activity Log entry now gets a **fingerprint** (a keyed SHA-256 of its contents; the key comes from the server's settings file, so someone who can only edit the database — e.g. in phpMyAdmin — cannot make a valid one) and is **sealed** to the entry before it a couple of minutes after it is written. Entries from before this update are sealed the first time the Activity Log page opens.

**Administration → Activity Log → Check the log** recomputes everything:
- **No changes found** — with the log's current **fingerprint** (write it down with the date; a rebuilt log would not have it).
- **The log was changed** — it names the first entry whose contents were changed, or where an entry was removed from the middle.

Note: entries sealed before the check cannot be changed or removed unnoticed; the newest few minutes are sealed soon after. The Grading System's own audit trail and account history are separate and not part of this check.

Files: `sis/app/lib/core.php` (`Audit`), `sis/app/pages/activity.php`, `sis/app/bootstrap.php` (schema 39), `sis/assets/blue-theme.css`; test in `sis/tests/v63-fixes.php`.

### Also in v66.4 — Edit units of many subjects: only changed rows are sent

The units editor (Curriculum → Subjects → Edit units) listed every subject with three fields each, so a school with more than about 330 subjects went over the server's limit of 1000 fields per save and the save failed. Now only the rows you changed are sent. You can change up to 300 subjects in one save. If you need to change more, save, then change the rest.

Files: `sis/assets/subject-units.js`, `sis/app/pages/curriculum.php`.

## v66.5 — Activity Log: the list shows under the tamper check again

In v66.4 the new "Tamper check" box took the full height of the page, so the list of entries started far below it and the page looked empty. The box is now only as tall as its contents.

Also: the "Check the log" button was moved by the page into the hidden Actions panel, so on the page it could not be seen. It is now a small form, so it stays in the box (desktop and phones).

Also: pages that open inside a pop-up window (Receive payment in Finance and SIS, New applicant, Register a student, O.R. series, Terms) still had the old green buttons. They now use the blue and orange design too (`body.al-embed`).

Also: on the SIS dashboard a large amount such as ₱12,210,450.00 broke onto two lines in its card. The amounts now stay on one line and their size follows the card's width.

Also: outline buttons in the SIS ("Check the log", "Show", …) still had green text from an older stylesheet, and when pointed at they showed green text on blue, which could not be read. They are now blue, with white text when pointed at. The "default" and "Scholarship" badges and the ticked parts on Backup are blue too.

Files: `sis/assets/blue-theme.css` (version tag 66.5 in `sis/app/views/layout.php`), `sis/app/views/layout.php`, `sis/app/pages/activity.php`.

## v66.6 — Add AcadLink to the home screen (iPhone and Android)

"Add to Home Screen" (Safari on iPhone) or "Add to Home screen / Install app" (Chrome on Android) now gives an AcadLink icon that opens like an app, full screen, on every workspace: SIS, Finance, LMS, Library, Canteen, the Student Portal and the Grading System.

- `manifest.webmanifest`: named "AcadLink" (was "AcadLink Grading System"), opens the workspace chooser, navy theme, covers the whole site.
- `sis/app/views/layout.php` and `portal/views/layout.php`: link the manifest and add the iPhone home-screen tags (icon, title, full screen). The Grading System (`index.html`) already had most of them; it now also names the icon "AcadLink".

No service worker and nothing stored on the phone: every page still loads live from the server.

Help (SIS → Help, `sis/app/pages/help.php`) has a new section, "Put AcadLink on your phone's home screen", with the steps for iPhone/iPad (Safari: Share → Add to Home Screen) and Android (Chrome: ⋮ → Add to Home screen / Install app).

## v66.7 — E-signature can be drawn from every workspace's My profile

Found by a full-system Playwright test of the test site: on My profile opened from Finance, LMS, Library or Canteen, the e-signature drawing script (`esign.js`) was not found (404), so a signature could not be drawn or uploaded there. It worked only from the SIS. The page now loads the script from the SIS folder wherever it is opened.

Files: `sis/app/pages/profile.php`.

## v66.8 — AcadLink stays light on phones in Dark Mode

Reported from an iPhone: the sign-in page showed dark-green text boxes and help box. The cause was leftover `prefers-color-scheme: dark` rules from the old green theme; the v64 blue/orange design is light only, so on a phone set to Dark Mode some parts turned dark while the rest stayed light.

- Removed all 11 leftover dark-mode blocks: sign-in (`css/style.css`), Student Portal (whole page went dark brown), LMS (calendar "today" cell), Library, Canteen, bottom navigation, people and schedule grid.
- `:root { color-scheme: light; }` in `css/style.css` and `sis/assets/blue-theme.css`, and `<meta name="color-scheme" content="light">` in the SIS and Portal layouts, so form boxes, dropdowns and scrollbars stay light too. (The Grading app already had it.)
- Bumped the stylesheet version tags to `?v=66.8` so phones load the new files.

Checked with Playwright on an iPhone 13 screen in Dark Mode: sign-in, Workspaces, SIS dashboard, Students, Schedules, Finance, LMS, Library, Canteen, Grading and the Student Portal all stay light. Before the fix the same test flagged sign-in, LMS and the Student Portal.

Files: `css/style.css`, `sis/assets/blue-theme.css`, `sis/assets/bottom-nav.css`, `sis/assets/people.css`, `sis/assets/schedule-grid.css`, `portal/assets/portal.css`, `lms/assets/lms.css`, `library/assets/library.css`, `canteen/assets/canteen.css`, `sis/app/views/layout.php`, `portal/views/layout.php`, plus pages whose stylesheet version tag changed.
