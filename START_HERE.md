**Updating to v63.19?** Upload `sis/app/lib/core.php` and `api/lib.php` (or deploy from GitHub). Fixes the 500 / blank page on Hostinger ("Illegal mix of collations").

**Updating to v63.15?** Upload the files (including `pages`, `js` and `shared`) and press Ctrl+F5. Nobody is signed out for doing nothing any more. You may delete `sis/assets/idle-signout.js`, `sis/assets/idle-signout.css` and `sis/app/pages/keepalive.php` from the server.

**Updating to v63.14?** Upload the files, including the new `vendor` folder, and press Ctrl+F5. Then open Settings → Backup & Restore: automatic daily backups are on; add the scheduled job shown there (Hostinger: hPanel → Advanced → Cron Jobs).

**Updating to v63.13?** First turn SSL on for the domain (Hostinger: hPanel → Security → SSL) and set PHP to 8.3 (hPanel → Advanced → PHP Configuration). Then upload the files, including every `.htaccess`, and press Ctrl+F5. http:// now goes to https:// automatically.

**Updating to v63.12?** Upload the files (including `shared`, `api` and `js`) and press Ctrl+F5. Sign out after no activity and One device at a time now apply to every system (Settings → System Settings → Sign-in security).

**Updating to v63.11?** Upload the files and press Ctrl+F5. Grading Backup was removed: use Settings → Backup & Restore, which includes the Grading System. You may delete `js/backup.js` from the server.

**Updating to v63.10?** Upload the files (including `pages`, `shared` and `icons`) and press Ctrl+F5. One device at a time now applies to every system; turn it off in Settings → System Settings → Sign-in security if staff share accounts across devices.

**Updating to v63.9 (Grading System settings in SIS)?** Upload the files (including the `pages` folder) and press Ctrl+F5. Setup and options and School settings are now in SIS → Administration → Settings (Grading Options, System Settings).

**Updating to v63.8 (School Profile in the SIS)?** Upload the files and press Ctrl+F5. The school's name and logo can now be set in Administration → Settings → School Profile.

**Updating to v63.7 (Settings in one place)?** Upload the files and press Ctrl+F5. Backup & Restore, Email Sending, Bulk Accounts, Data Migration, Import All and Document Requirements are now under Administration → Settings, in the menu on the right.

**Updating to v63.5 or v63.6 (school logo in the LMS, Canteen POS and Library)?** Upload the files and press Ctrl+F5. Upload the school's logo in Grading System → School settings if you have not yet.

**Updating to v63.3 (security fixes)?** Read the v63.3 part of `V63_UPDATE.md`. Upload the files (keep `api/config.php`) and press Ctrl+F5. If power.admin still used the old starting password, set a new one on First-time setup.

**Updating to v63 (Backup & Restore)?** Read `V63_UPDATE.md`. Upload the files, sign in as power.admin and press Ctrl+F5. No SQL import is needed.

**Updating to v62 (notifications, Data Privacy, parents, student photos)?** Read `V62_UPDATE.md`. Upload the files, sign in to SIS once and press Ctrl+F5. No SQL import is needed.

**Updating to v61.2 (bottom menu on phones and tablets) or v61.1 (LMS design)?** Read `V61_UPDATE.md`. Upload the files and press Ctrl+F5. No SQL import is needed.

**Updating to v61 (LMS)?** Read `V61_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

**Updating to v60 (Library)?** Read `V60_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

**Updating to v59 (Canteen POS)?** Read `V59_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

**Updating to v58 (Finance workspace)?** Read `V58_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

**Updating to v57 (standard toolbar)?** Read `V57_UPDATE.md`. Upload the files and press Ctrl+F5. No SQL import is needed.

**Updating to v56 (Master Development Checklist)?** Read `V56_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

# Start here — one SQL import

**Payment plans and shared SIS design:** see `SIS_PAYMENT_PLANS_UPDATE.md`. Configure plans under **Assessment → Labels**. Existing installations upgrade automatically to schema 15; no extra SQL import is needed.

**Accounting toolbar update:** see `ACCOUNTING_TOOLBAR_UPDATE.md` for header actions, selection, approval, posting and Accounting reversals. This toolbar update needs no SQL changes.

**Updating an existing installation to v32?** Follow `ENROLLMENT_PLACEMENT_UPDATE.md`. The enrollment form now separates current placement from the requested year and section. Keep your current `api/config.php` and uploaded files. SIS upgrades the database automatically; no SQL import is needed. The v31 navigation changes are documented in `SMOOTH_NAVIGATION_UPDATE.md`.

## Fresh database

1. Create an empty MySQL database (for example `acadlink`) and select it in phpMyAdmin.
2. Import **INSTALL.sql** from the project root. This is the only SQL file you need to import. It installs Grading, SIS, existing Accounting, eligibility, notifications, settings, five default groups and the first Power User together.
3. Copy **api/config.sample.php** to **api/config.php**, put that database's name, host, username and password there, then open **sis/index.php** through your PHP web server.

First sign-in (v63.3 — no password is published):

1. Open **sis/index.php**. The sign-in page shows **New installation: set the power.admin password first → First-time setup**.
2. On **First-time setup**, type the **database password** from your `api/config.php` (this proves you installed the site), then choose power.admin's password twice.
   If the database has no password (a local XAMPP/Laragon install), open the page on that computer (`http://localhost/…`) instead.
3. Sign in with the username **power.admin** and the password you chose.

Then open **Users and Groups** in SIS to create Accounting, Registrar, Teacher and Student accounts. For teachers, you can also use **Teachers → ⋯ → Create account** to generate and link an account automatically. Use **My workspaces** to open Grading with the same identity.

**No CLI command, separate central-account installation, additional seed import or first-user setup command is needed.** The SQL file already creates both compatibility links for the initial Power User.

## What the import does

- Creates all tables required by this centralized-accounts version.
- Adds the compatibility columns for Teacher accounts and admission application terms.
- Seeds fee categories, receipt numbering, accounting accounts and mappings, and 32 default settings.
- Seeds the five groups and their permissions.
- Creates exactly one active central Power User, with its SIS ID and Grading UID linked.
- Leaves power.admin without a usable password until First-time setup is done (v63.3), so nobody can sign in with a published password.

Importing the same file again preserves existing users, changed passwords, group activation settings, existing settings and transaction records. It does not create another default administrator. It does not match or merge existing users by name.

## Existing database

Keep your database backup. Existing centralized users keep their sign-in credentials; do not expect the initial password above to work after it has been changed. Databases with old, unlinked accounts still require explicit identity review through the migration workflow in CENTRAL_ACCOUNTS.md. INSTALL.sql does not reconstruct historical accounting entries or silently activate legacy accounts.

## Scope

This installer covers the implemented **centralized-accounts version**, including its existing accounting module. The later proposed GLFRS/TPS/MRS expansion and posted-receipt void-approval workflow remain a separate implementation; importing SQL does not add those application features.

## Student Portal enrollment
Existing students can apply for enrollment after prior-term grades are tagged Complete. Registrar review, assessment publication and payment checks are described in PORTAL_ENROLLMENT.md. Existing databases add the two application tables automatically when SIS opens; fresh installations still import INSTALL.sql only.
