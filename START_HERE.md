**Updating to v61.1 (LMS design)?** Read `V61_UPDATE.md`. Upload the files, open SIS once and press Ctrl+F5. No SQL import is needed.

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

First sign-in:

- **Username:** `power.admin`
- **Temporary password:** `Start-32f32c74-A1`

Choose your own password when prompted. Then open **Users and Groups** in SIS to create Accounting, Registrar, Teacher and Student accounts. For teachers, you can also use **Teachers → ⋯ → Create account** to generate and link an account automatically. Use **My workspaces** to open Grading with the same identity.

**No CLI command, separate central-account installation, additional seed import or first-user setup command is needed.** The SQL file already creates both compatibility links for the initial Power User.

## What the import does

- Creates all tables required by this centralized-accounts version.
- Adds the compatibility columns for Teacher accounts and admission application terms.
- Seeds fee categories, receipt numbering, accounting accounts and mappings, and 32 default settings.
- Seeds the five groups and their permissions.
- Creates exactly one active central Power User, with its SIS ID and Grading UID linked.
- Stores a PHP-compatible password hash and requires a password change before either system can be used.

Importing the same file again preserves existing users, changed passwords, group activation settings, existing settings and transaction records. It does not create another default administrator. It does not match or merge existing users by name.

## Existing database

Keep your database backup. Existing centralized users keep their sign-in credentials; do not expect the initial password above to work after it has been changed. Databases with old, unlinked accounts still require explicit identity review through the migration workflow in CENTRAL_ACCOUNTS.md. INSTALL.sql does not reconstruct historical accounting entries or silently activate legacy accounts.

## Scope

This installer covers the implemented **centralized-accounts version**, including its existing accounting module. The later proposed GLFRS/TPS/MRS expansion and posted-receipt void-approval workflow remain a separate implementation; importing SQL does not add those application features.

## Student Portal enrollment
Existing students can apply for enrollment after prior-term grades are tagged Complete. Registrar review, assessment publication and payment checks are described in PORTAL_ENROLLMENT.md. Existing databases add the two application tables automatically when SIS opens; fresh installations still import INSTALL.sql only.
