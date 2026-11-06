# Fresh installation

Use **../START_HERE.md** and import **../INSTALL.sql** once. The first Power User is already created by that file. No separate seed or CLI bootstrap is needed for a fresh database.

# Central account update

Account creation and sign-in instructions below are superseded by [CENTRAL_ACCOUNTS.md](../CENTRAL_ACCOUNTS.md). All accounts, including teachers, are managed in SIS. Install the central schema and bootstrap/migrate accounts before using this release.

# School Information System (SIS)

PHP 8 + MySQL + Bootstrap 5, in the `sis/` folder of the grading system. One database for both systems.

## Modules

| Menu | What it is | Where the data lives |
|---|---|---|
| **Student** | List, add, edit students | The grading system's students (`docs`) |
| **Section** | List, add, edit sections per school year | The grading system's sections |
| **Year Level** | 1st–5th Year: sections, students, registrations, assessment names | The grading system's year levels |
| **Teacher** | List, add, edit teachers | The grading system's teachers |
| **Curriculum** | Curriculum per program, year level and term; subjects | The grading system's curriculum and subjects |
| **Assessment → Categories** | Fee groups: Tuition, Miscellaneous, Laboratory, Other Fees… | `sis_fee_categories` |
| **Assessment → Labels** | The fee lines printed on the assessment (Tuition Fee, Library Fee, ID…), each with its category, how it is charged (fixed / per unit / per subject) and a default amount | `sis_fee_labels` |
| **Assessment → Names** | Fee templates, e.g. "BSIT 1st Year 1st Semester 2026-2027": the labels with their amounts, for a program / year level / term / school year | `sis_assessment_names`, `sis_assessment_name_items` |
| **Assessment → Scholarships & Discounts** | Programs that take an amount off: a percentage or a fixed amount, off the whole assessment or off chosen categories (e.g. *Academic Scholarship · 50% of Tuition*), with an optional maximum. The *Grantees* tab lists who received what per school year and term, with totals | `sis_discounts`, `sis_discount_categories`, `sis_registration_discounts` |
| **Transaction → Admission** | Record an applicant (new, transferee, returnee); **Admit** gives the Student ID; or reject | `sis_admissions` |
| **Transaction → Registration** | Register a student for a term: section, subjects (from the curriculum), assessment (from the matching name), charges or less/discounts; print the assessment | `sis_registrations` (+ subjects, items) |
| **Transaction → Cashier** | Find a registration, receive full or partial payments, print the official receipt (per O.R. series), edit the O.R. number, void with a reason, daily collections by method | `sis_payments`, `sis_or_series` |
| Users (administrator) | SIS sign-in accounts | `sis_users` |
| My Account (students) | Own final grades (once tagged complete), registrations, assessment, balance, receipts | — |
| Final grades (on Student, New registration, My Account) | Grades the registrar tagged **Complete** on the grading system's Final grades page | The grading system's `gradeCompletions` |

### Final grades from the grading system

On the grading system's **Final grades** page, the registrar chooses the school year, term and
year level. Any student with subjects can be tagged **Complete** (one by one, or
**Tag all complete**). The SIS then shows those grades (subjects, final grades, GWA, remarks, who
tagged them and when):
- on the student's record;
- on New registration, as the student's latest completed term;
- in the Student Portal (My final grades).

Students with ungraded or INC subjects can still be tagged: the registrar gets a warning first, and the SIS shows those grades with an "Incomplete grades" badge (ungraded subjects listed as "Not graded"). **Untag** takes the
grades off the SIS. If a grade changes after tagging, the page shows "Changed since tagged"
until the registrar tags it again.

Because Student, Section, Year Level, Teacher and Curriculum are the grading system's own records,
there is no double entry: a section added in the SIS appears in the grading system, a student
admitted and registered in the SIS appears on the grading system's Students page, and so on.
Renaming a section, teacher or subject also updates the copies the grading system keeps on its
students, grading assignments and grades (exactly as the grading system itself does).

## Sign-in page

All three sign-in pages share one design: the grading system (`pages/login.html`), Admission and
Registration (SIS staff) and the Student Portal. On the left the school's logo and name, what the system
is for and a photo of the school; on the right the sign-in card (show / hide the password, **Remember me**,
the date and time). The design is in the grading system's `css/style.css` and `js/signin-ui.js`, used by
both systems. The grading page keeps its first screen where you choose a system (the three cards).

Each system has its own color, the same as its card on that first screen: the **Grading System** is
blue, **Admission and Registration** (SIS staff) is green, and the **Student Portal** is purple: the
sign-in page, the menu, buttons and highlights. The SIS sets `data-system="sis"` or `"portal"` on the
page; the colors are CSS variables at the end of `css/style.css`.
The icon in the corner of each sign-in card shows the system: a teacher (Grading System), a book
(Admission and Registration) and a graduation hat (Student Portal). Under **OR**, small buttons open
the sign-in page of the other two systems (`../pages/login.html?system=grading` opens the grading
sign-in form directly, without the choose-a-system screen).

- **The photo** is set in the grading system: *School settings* → **Sign-in page picture** (choose a wide
  photo, then *Save settings*). It's resized in the browser and stored in pieces (`settings/signin`,
  fields `image0`…`imageN`) so each piece fits one Excel cell in backups. Without a photo the left side is
  plain navy.
- **Remember me** keeps only the username in this browser (never the password) and greets the person by
  it the next time ("Welcome Back, Admin"). Untick it to forget the username.
- **Forgot password?** explains that the administrator (students: the registrar) resets it.

## Finding your way

- **Home** shows big buttons for the jobs you do (New applicant, Register a student, Receive a
  payment, Find a student, Set up fees) and a **Waiting for you** list: applicants to admit,
  admitted students not yet registered, and students with a balance.
- **Search** (the box under the school name in the menu, or press <kbd>/</kbd>): one box for students, applicants, registrations
  and receipts, with buttons to open, register or receive payment.
- The menu lists the enrollment steps in order (**1 · Admission**, **2 · Registration**,
  **3 · Cashier**), and each of those pages shows the same 1 → 2 → 3 step bar.
- **Help** (in the menu) shows your own tasks first, then everyone else's. Your name, **Password** and
  **Sign out** are at the bottom of the menu, as in the grading system.

## Admission and Registration screens

The SIS and the student portal use the grading system's own stylesheet (`css/style.css`, loaded before
`sis/assets/app.css`), so both systems look the same: the navy menu on the left with the school name,
the same page titles, panels, tables and buttons. On phones the menu opens with the ☰ button in the navy
top bar.

Admission, Registration and Cashier share one screen style: a toolbar with an **Alternative Search Key**
box (name or number), **Filter Preferences** (tick a filter to use it; unticked filters are off), a grid
(click a row to select it, double-click or Enter opens it), and on the right the side panels
(Sub-Components and Summaries; click a panel title to fold it). Pop-ups that save something refresh the list when you close them.

- **Admission:** View / Edit (applicant form in a pop-up), **New**, **Admit** (gives the Student ID),
  **Reject** (with a reason), **Register** (opens New registration for an admitted applicant). Filters:
  school year, status (including *Admitted, not registered*), applicant type, program, year level, name /
  number. Summaries: pending · admitted · rejected, new · transferee · returnee, admitted not registered.
- **Registration list:** **View** (printable statement of account in a pop-up), **New** (pop-up), **Open**, **Void**
  (no payments yet), **Pay** (cashiers: receive payment in a pop-up). Filters: school year, term, year
  level, section, status (open / done / voided), payment (unpaid / partial / paid), name / number.
  Summaries: registered students, open · done · voided, total assessment, total paid, balance to collect.
- **One registration:** the same screen style. Toolbar: Back, Print Assessment, Confirm Enrollment, Pay at
  Cashier (pop-up), Reopen (administrator, done registrations), Void. The printable statement is in the
  middle (it updates after every change) with its payments below. On the right, the side panels:
  **Sub-Components** (*Subjects (curriculum)*, *Assessment name*, *Scholarship / discount*, *Add a fee*,
  *Charge or less*; each opens a pop-up), the scholarships given, the fees not charged (with *Charge
  again*), and **Summaries** (student, term, section, curriculum, units, total, paid, balance). On phones
  the side panels come first, then the assessment.

## Cashier

*Transaction → Cashier* lists the **official receipts** (today by default):
- Toolbar: **View** (the receipt in a pop-up), **New** (receive a payment, in a pop-up), **Void** (with a
  reason; the cashier is asked whether to make the O.R. number negative, e.g. `-OR-0000012`, so the number
  can be used again), **Edit O.R. No.** (correct the number of a posted receipt), **Re-print**, **Show / Hide Filters**, **Apply Filters**, **Print List** (collections
  report), **Refresh**. Click a row to select it; double-click (or Enter) opens it.
- Filters: O.R. date range, status, payment method, O.R. number, cashier, payer.
- Columns: O.R. number, date, payer, cash / check / credit card / other amounts, total, status,
  cashier, remarks. Voided receipts are struck through and not counted.
- Right side: Sub-components (receive payment, re-print, registrations and balances, find a student)
  and Summaries (cashier, reference date, total cash, check, card, others, grand total).

## Accounts (administrator)

*Administration → Users*:
- **Student accounts:** choose *Student* and type the Student ID (the username field is for staff only).
  The name comes from the grading system when left empty. Students sign in on the Student Portal with
  their Student ID (upper or lower case both work).
- **Passwords:** a new account and *Reset password* both show a temporary password suggested by the
  system (e.g. `Mango-4827-Ray`). Keep it or type your own (at least 10 characters, letters and
  numbers). The person must change it at next sign-in; resetting also unlocks a locked account.

## Report Manager

*Reports → Report Manager* lists the printable reports by group (click a group bar to fold it). Each row
has two round buttons: **Preview** (runs the report in a pop-up, ready to print) and **Options** (choose
the school year, term, section, dates or cashier first). Type in *Alternative Search Key* to find a report.
Each person sees only the reports of their work.

| Group | Reports |
|---|---|
| Admission and Enrollment | Enrollment Census, Enrollment List by Section, Admission List by Date Range, Admission Summary, Admitted Not Registered |
| Cashier and Collections | Cashier Report Details (per cashier: cash / check / card / others, voided receipts apart, signature lines), Collection Summary, Voided Official Receipts |
| Assessment and Balances | Students with Balances, Assessment Summary by Fee, Scholarship and Discount Grantees |

Reports print with the school header from Settings. Shortcuts: Cashier → *Cashier Report Details*,
Registration → *Enrollment Census*.

## Settings (administrator)

*Administration → Settings* sets how printouts look:
- **Header of both printouts:** school name (default: the grading system's), address, contact line,
  logo on or off.
- **Assessment statement:** title, paper size, whether to list the subjects, fee quantity/rate breakdown,
  payment history and balance due, a note at the bottom (payment schedule, refund policy…), and signature lines.
- **Official receipt:** title, paper size (A4, Letter, Legal, half letter, 80 mm receipt printer), 1 or 2
  copies (Original + Duplicate with a cut line), amount in words, remaining balance, note and signature
  label.
- **O.R. Series Setup** (*Official Receipt Series Types* pop-up: search, Add / Edit / Delete / Close): each
  series has a code, description, number prefix and digits, the next number, the receipt template and four
  ticks: **Active** (the cashier can choose it), **Auto-number** (the system gives the next number; untick it
  for pre-printed receipts whose number the cashier types), **Editable O.R. No.** and **Default**.
  Templates: *Standard receipt* (the settings above) or *Template Official Receipt* (the fields of the
  pre-printed official receipt form: date, account ID per semester, payment ID, student name, address,
  course, year level, total amount, amount in words, VAT-exempt sales, less discount, less withholding tax,
  total amount due, cashier).
- O.R. numbers: an O.R. number can be used **only once** (also across series). When receiving a payment
  the cashier picks the series; the next number is filled in and can be changed. A posted receipt's number
  can be corrected later (Cashier → *Edit O.R. No.* or on the receipt), with a reason in the activity log.
  When voiding, the cashier chooses: make the number negative (it becomes free and is suggested again), or
  keep it on the voided receipt. An existing install gets a first series *OR1* that continues the old
  receipt numbering automatically (no SQL to run).
- *Preview assessment form* and *Preview official receipt* show real records with the saved settings.

## Import the fee setup from Excel

On *Assessment → Categories / Labels / Names* and *Scholarships & Discounts*: **Download template**
(an Excel file with your current records, or examples, and a *Help* sheet), fill it, then **Import from
Excel**. Rows are matched by name: existing ones are updated, new ones added, nothing deleted.

- *Only check the file first* (on by default): shows what would be added / updated, saves nothing.
- If any row has an error, nothing is saved and the rows to fix are listed (e.g. "Labels, row 7: Charged
  "Per week": use Fixed amount, Per unit or Per subject").
- Names use two sheets: *Names* (who the template is for) and *Fee lines* (label + amount per name).
- Labels may name a new category: it is added. Scholarships list the categories they apply to, separated by commas.
- Files: .xlsx (Excel, Google Sheets, LibreOffice) or .csv. No PHP extension needed.

## Assessment fee workflow

```
Fee setup: Categories → Labels (fixed · per unit · per subject) → Assessment Names (templates)
           + Scholarships & Discounts          (Names tab warns: empty templates, templates that compete)
Student type: New / Transferee (Admission) · Continuing / Returning (find the student)
   └─ previous term's final grades must be tagged Complete in the grading system, else registration is blocked
Enrollment: section + term → subjects (curriculum) → assessment name (automatic best match, or chosen)
Build assessment: fee lines computed → per-student fees added / taken off → charges / less → scholarships & discounts
"Assessment correct?" (Confirm Enrollment — the balance does not have to be paid first):
   ✗ errors stop it (no subjects; new total below what was already paid)
   ⚠ warnings must be ticked (no fees; template for another program/level/term/year; inactive template;
     previous term no longer tagged complete)
   ℹ shown: student type, curriculum used, fee setup changed since the assessment was made (old → new total)
   Yes → the assessment is recomputed from the CURRENT fee setup and locked (Done). No → go back and fix.
Cashier: payments → official receipt → Unpaid / Partial / Paid
```

- Student type is worked out from what is recorded (admission type and earlier terms); nothing new to fill in.
- A down payment before the enrollment is confirmed is allowed; the cashier sees a note that fees may still change.
- Assigning teachers stays in the grading system (*Grading assignments*), so classes are managed in one place.

## Accounting (reviewed posting)

See [ACCOUNTING_WORKFLOW.md](../ACCOUNTING_WORKFLOW.md) for the complete daily workflow and installation notes.

- New confirmed assessments and cashier receipts enter **Review & post**. Manual entries and expenses start as **Draft**.
- Accounting approves, then posts. Only posted entries appear in financial statements. Receipts update the student's balance immediately, even while awaiting Accounting review.
- **Posted receipts cannot be voided or edited in Cashier or Registration.** Accounting first reverses the posting with an open date and a reason. Cashier then voids the original receipt; only that void restores the student balance.
- Student ledgers keep original periods, itemized fees, payments, reviewed adjustments and refunds. Specific-fee allocations are available on confirmed assessments; down payments before confirmation remain unallocated until the assessment is stable.
- Expenses, supplier bills/payments, payroll summaries, assets/depreciation, budgets, fund/department reports, reconciliation and closing are available inside Accounting.
- Existing posted journals remain posted during upgrade. Fresh setup still uses only root INSTALL.sql; existing installations upgrade automatically on the next SIS visit.

## Enrollment rules

- **New students** are recorded and admitted in Admission (they get a Student ID).
- **Returning students** (two school years or more away) need a **readmission request**: Admission → New, type
  *Returnee*, with the Student ID they already have; *Approve readmission* keeps that ID. Without it the
  registration is refused.
- **Continuing students** register only when the final grades of their previous term are tagged **complete** in
  the grading system (Final grades → Tag complete).
- **Academic terms and deadlines** (Settings): enrollment opens and closes per school year and term; classes start;
  final grades due. Outside the enrollment period registration is refused.
- An **administrator** can still register a student a rule blocks, with a reason (kept on the registration and in
  the activity log).
- **Teachers:** on a registration, *Teachers* shows each subject's teacher; *Assign* a teacher now (creates the
  class in the grading system's Grading assignments with the registered students) or leave it pending. When an
  enrollment is confirmed, the student joins the classes of their subjects that already have a teacher.
- **Grade visibility** (Settings → Student Portal): grades show once tagged complete; the school can hold a term's
  grades while its balance is unpaid.

## The flow

1. **Admission** — record the applicant → **Admit**: they get the next Student ID (same numbering
   as the grading system).
2. **Registration** — choose the admitted applicant (or a continuing student's Student ID), the
   section and the term.
   - The student is added to the grading system in that section (new student), or moved to the
     new school year's section (continuing student, like the grading system's Promote).
     In the same school year the grading class is not moved (change it in the grading system,
     which also moves the student's classes).
   - Subjects come from the curriculum of the section's program (text before the year number in
     the section name, e.g. BSIT 1A → BSIT), year level and term; they can be changed.
   - The fees come from the best matching **assessment name** (most specific program / year level /
     term / school year), or the one chosen:
     fixed = amount, per unit = amount × units, per subject = amount × number of subjects.
   - **Choose the fees for this student:** *Add a fee (from Labels)* charges any label at its default
     amount or another amount (per-unit / per-subject labels use the student's units or subjects). A
     label already in the template gets the new amount for this student only. **×** takes a fee off
     this student (*Charge again* puts it back). New registration also has an *Extra fees* checklist.
   - Add charges or less/discounts with a description. The total can never be negative, and never
     less than what was already paid.
   - **Grades first:** a continuing student is registered only when the final grades of their last
     term (their latest class in the grading system or SIS registration before the new term) are
     tagged **Complete** on the grading system's Final grades page (that term, or the whole school
     year). Otherwise New registration shows "Can't register yet" and the server refuses it. New
     students (first registration) are not affected.
   - **Scholarship / discount:** give a program to the registration (or choose it on New registration).
     It shows as a *Less: Scholarships & Discounts* line, is recomputed whenever subjects or fees change,
     and can never bring the total below zero. Changes are not possible once the registration is done.
   - **Confirm Enrollment** (status *Done*): while a registration is *Open* its subjects and fees can be changed. Click
     **Confirm Enrollment** when it is right, even if the balance is not paid yet: from then on it can only be **viewed** by everyone (lists
     show *View* instead of *Open*); the cashier can still receive payments. Only the administrator
     can **Reopen** it, with a reason (recorded in the audit log).
   - **Void registration** (open or done) needs a reason; the record is kept as *VOIDED* and can only
     be viewed, and the student can be registered again for that term. Payments must be voided by the
     cashier first.
   - One registration per student per school year and term (a cancelled one is kept).
3. **Cashier** — receive payment (cash, GCash, Maya, bank, card, check; non-cash needs a reference
   number), up to the balance. Submitting the form twice records it once. Receipts `OR-0000001`…;
   voids keep the record and need a reason. A registration with payments can't be cancelled until
   the payments are voided.

## Roles

| Role | Can use |
|---|---|
| Administrator | Everything, plus Users |
| Registrar | Student, Section, Year Level, Teacher, Curriculum (edit), Assessment (edit), Admission, Registration; views payments |
| Admission staff | Student, Section, Year Level, Curriculum, Assessment (view), Admission, Registration |
| Cashier | Student (view), Registration (view), Cashier |
| Accounting staff | Accounting (ledger, chart of accounts, mappings, reconciliation), accounting and cashier reports; views payments |
| Student | My Account and own receipts |

Permissions are checked on the server for every page and action; every form has a CSRF token.

## Install (same database as the grading system)

The SIS reads the database settings from `api/config.php`: there is no separate configuration.

- **From the grading system** (easiest): sign in as administrator → *Enrollment and fees (SIS)* →
  *Install SIS tables in this database*.
- **phpMyAdmin**: choose the grading system's database → Import `sis/database/schema.sql`, then
  `sis/database/seed.sql` (both are safe to import again).
- **Command line**: `php sis/bin/install.php`

First sign-in: **admin / ChangeMe!2026** — you must choose a new password. Then create the staff
accounts under *Users*, add your fee **Categories**, **Labels** and **Names**, and start with
*Admission*. Students sign in with their Student ID (create their account in *Users*, role Student).

Optional keys in `api/config.php`: `"sis_debug" => true` (development only), `"sis_force_secure_cookie" => true`
(behind an HTTPS proxy), `"timezone" => "Asia/Manila"`.

## Tests (use a test copy, never the live database)

```bash
php sis/tests/run.php            # 13 tests on a throwaway database "sis_test"
# web test: empty test database with the grading tables, then
php sis/bin/install.php --sample # adds demo sections, subjects, students, fees and payments
php -S 127.0.0.1:8090 -t .       # from the grading system's folder
php sis/tests/http_test.php      # ~150 checks: every role and page, CSRF, admission → registration → cashier
```

Sample accounts (password `Password123`): `registrar`, `admission`, `cashier`, and student `20250002`.

## Backups

The SIS tables are in the grading system's database: back up the whole database
(phpMyAdmin → Export, or `mysqldump --single-transaction DBNAME`). The grading system's own
*Backup and restore* page covers only its records, not the `sis_*` tables.

## Removed

The earlier SIS modules (academic evaluation, grade sync, transferee crediting, discount and
scholarship programs, refunds, reports, audit-log page, settings, document uploads, online
admission) were removed. If you installed that earlier version, its tables are no longer used:
after a backup, import `database/drop_old_tables.sql` to remove exactly those tables (it never
touches the grading system's tables or the `sis_` tables).
