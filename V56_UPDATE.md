# AcadLink v56 — Master Development Checklist update

This release covers checklist sections 1–10: subject and fee management, user interface, the Statement of Account (SOA), curriculum, grading assignments, teacher accounts, enrollment, accounting and cashier, Migration → Import All, and system-wide validation.

## Install on an existing site

1. Back up the database and keep your **api/config.php** and uploaded files.
2. Upload the files from this package over the existing ones.
3. Open the SIS once. **No SQL import is needed.** The SIS upgrades the database by itself (schema 25):
   - new tables: `sis_assessment_name_subject_fees`, `sis_document_requirements`, `sis_admission_documents`, `sis_import_all_batches`
   - new student classifications on `sis_admissions.applicant_type` (Continuing Student, Unit Earner)
   - the default document requirements, and the start date of the payment-plan rule
4. Reload open pages with **Ctrl+F5**, in both SIS and Grading.

Fresh installations still import only the root **INSTALL.sql**. The same automatic upgrade runs the first time the SIS opens.

## What changed

### 1. Subject and fee management
- **Assessment → Subject Fees** now lists **every subject**. A subject without a price shows **₱0.00**, so new subjects appear automatically. **Total = Subject Units × Price per Unit** is shown per row and updates live while you type a price.
- **Edit** changes the existing price in place. A second copy of the same fee on one subject is refused.
- Prices of ₱0.00 are allowed, and a blank price is ₱0.00.
- **Creating a subject never asks for a fee.** Set the price later in Subject Fees. Curriculum → Subjects has a Subject Type and an Edit button.
- **Fee Templates → Manage fees is one list of every fee** (v56.1).
  - **General fees** (registration, miscellaneous, tuition per unit…) and **subject fees** (from Subject Fees) are listed together, with a search box, *Show ticked only*, and *Tick all / Untick all*.
  - **Tick a fee to charge it.** Its price appears: for a general fee, the amount and how it is charged (both editable); for a subject fee, its price per unit from Subject Fees and the total (units × price).
  - **A subject fee costs the same in every template.** Change subject prices only in Assessment → Subject Fees. (A price set for one template in an older version stays while that fee is ticked, and is removed when it is unticked.)
  - One **Save fees** button. Untick a fee to stop charging it.
  - A **new template opens Manage fees right after it is created**, so you tick its fees straight away.
  - On upgrade, existing templates keep exactly the fees they charged. New templates start with nothing ticked. Copying a template copies its ticks.
- Changing a price, or the ticks, recalculates open assessments that have no payments yet. Assessments with payments, or with a published portal assessment, keep their amount until the registrar saves them. **Confirmed enrollments never change.**
- The SOA subject table shows every subject with **Price / Unit** and **Subject Fee**, including ₱0.00 subjects.

### 1b. Lecture and laboratory units (v56.2)
Turn on **Settings → Assessment → "Separate lecture and laboratory units"**. It is off by default, and nothing changes until you turn it on.
- **Subjects** (Curriculum → Subjects) get *Lecture units* and *Laboratory units*. Units = lecture + laboratory.
- **Subject Fees** gets *Charged per*: all units, lecture units or laboratory units. Example: Computer laboratory fee ₱600 per laboratory unit × 1 lab unit = ₱600. The total updates while you type.
- **Assessment / SOA** (both templates) shows **Lec / Lab / Units** for each subject with totals. Fee lines use the right units (e.g. "Computer laboratory fee · IT102 — 1 × ₱600.00").
- **Import All** has optional *Lecture Units* and *Laboratory Units* columns at the end of the template. Their sum must equal Subject Units, or leave Subject Units blank and it is computed.
- Open assessments without payments follow a subject's new lecture / laboratory split. **Confirmed enrollments never change.** A subject without a split counts as all lecture, so existing records compute exactly as before.
- Subjects edited only in the Grading system keep their split while their total units stay the same.
- The database adds two columns by itself (`sis_registration_subjects.lec_units / lab_units`, `sis_subject_fees.unit_basis`). No SQL import is needed.

### 2. User interface
- **Success sounds:** a short chime plays only after a Save or Send succeeds (a green message in SIS / Portal, a success toast in Grading). The green message always stays. Each person can switch the sound off in **My profile → Sound**.
- **Toolbar:** text in the fixed toolbar is now 11px everywhere (v55 still had an 8px rule). The toolbar no longer spills past the scrollbar. The Accounting hint no longer overlaps the buttons. Filter buttons show one icon each, and the search box is readable.
- **Scrolling:** every main view, form and table scrolls. On phones the SOA keeps its paper layout and scrolls sideways instead of being cut off.

### 3. Statement of Account
- **Prepared by:** the full name of the staff member signed in when the SOA is opened or printed appears above the *Prepared by* line, on both SOA templates.
- **Payment plan and balance box** on both templates, always shown: plan name and kind, **total payable**, **total payments made** and **remaining balance**. A full-payment plan shows the "pay ₱X by date" offer, or the discount once earned. An installment plan shows the amount due now, the next due date and each installment. Figures come straight from the live registration, so Cashier payments, voids, refunds and earned discounts appear at once, matching Accounting.
- **Live payment plan on the assessment (v56.3):** on the registration, choosing a plan in *Payment plan* updates the SOA's **Payment plan and balance** box at once: total payable, the full-payment offer (e.g. "Pay ₱5,035.00 by Nov 17, 2026, saves ₱265.00") or the installment schedule. It is marked *Preview · not saved yet* until **Save plan** is clicked. Choosing back the saved plan restores the box. Payments, voids and refunds show in the box as soon as they are posted.
- **Full-payment discount deducted at once (v56.4):** a full-payment plan has the option **"Deduct the discount from the balance as soon as the plan is chosen"** (Assessment → Payment Setup → Full Payment Discount; on by default for new plans).
  - Example: Full payment 80% off on a ₱5,300 assessment. Choosing the plan deducts ₱4,240 straight away, so the balance is ₱1,060. This is shown in the preview before saving, and on the SOA after saving.
  - Switching to another plan, or removing the plan, before the first payment restores the full balance.
  - Accounting books the discount as usual.
  - Plans without the option keep the earlier rule: the discount is earned only when the full balance is paid by the deadline.
- **Payment plan required before the first payment** (Settings → Cashier, on by default). Cashier shows *Payment plan required* with a link to choose the plan, and the server refuses the payment.
  - Opening balances from Data Migration, enrollments that already had a payment, and registrations made before this update are not blocked.
  - The plan can now also be chosen on a confirmed enrollment that has no payment yet.

### 4–5. Curriculum and Grading Assignments
- **No Whole Year option:** new curricula and grading assignments must have a semester (1st, 2nd, 3rd Semester or Summer). Older records without a semester can still be opened and edited.
- **The teacher comes from the curriculum.** In Grading Assignments, choosing section, semester and subject fills in the curriculum's teacher; it can still be changed. *Add from curriculum* pre-selects each subject's curriculum teacher.
- **Teacher changes follow the curriculum:** changing a teacher in Curriculum also updates the active class (current school year, that semester) while it still has the previous curriculum teacher.
  - Teachers chosen by hand in Grading stay.
  - Classes with submitted grades keep their teacher, and the save message says how many.
  - Draft grades move to the new teacher. Grade values and history never change.

### 6. Teacher accounts
- **Enter grades** always offers 1st, 2nd, 3rd Semester and Summer.
- The teacher's dashboard (**My classes**) has a semester filter that changes the classes and the counts. Teachers still see only their own classes. The choice is shared with Enter grades.

### 7. Enrollment
- **Required:** First Name, Last Name and **Complete Address**, in SIS Admission and in the Student Portal application.
- **Student classification (required):** New Student, Continuing Student, Transferee, Unit Earner, and Returnee (readmission). Continuing students and returnees keep their Student ID. Unit earners stay *Unit Earner* in later terms.
- **Document checklist** on the admission form: a checkbox per document, *Required* or *Optional*, and *Submitted* with the date and who received it.
  - Missing required documents are listed before Admit, counted in the admission list (e.g. "3 of 5") and shown on the student page.
  - They do not block admission; the checklist keeps track of what is still due.
- **Administration → Document Requirements** (settings permission): add, edit, order and remove requirements, and choose which classifications each applies to. A requirement already ticked for applicants is turned off instead of deleted.
  - Defaults: Form 137, Form 138, Good Moral, PSA Birth Certificate, TOR, Transfer Credentials / Honorable Dismissal, Recent ID Pictures and Other Documents.
- **Students list tabs (v56.5), in both the SIS and Grading:** the Students list opens on **Enrolled**, the students with a confirmed enrollment (SIS: in the chosen school year, optionally one semester; Grading: in the student's school year).
  - **Pending confirmation** shows students registered but not confirmed yet, with a count.
  - **All records** shows every student record, for corrections and history.
  - Each student shows an *Enrolled*, *Pending confirmation* or *Not enrolled in SIS* badge.
  - Without the SIS installed, Grading shows all records as before.
- **Official lists show confirmed enrollments only:** the *Enrollment List by Section* report lists confirmed enrollments only. Census shows *Confirmed (enrolled)*. Students has an *Enrollment* filter and column (Enrolled / Pending). Pending registrations stay in Registration.

### 8. Accounting and Cashier
- The Cashier ↔ SOA sync and the payment-plan rule are described under section 3.
- Toolbar fixes on Accounting pages are described under section 2.
- Posting, approval, separation of duties and reversals are unchanged.

### 9. Data Migration → Import All
Open **Data Migration → Import All** (or Administration → Import All). This needs both Curriculum and Assessment editing rights; the Power User has both.
1. **Download Template:** columns come from the system's field list: Subject Code, Subject Name, Subject Units, Subject Type, Price per Unit, Fee Label, Fee Templates, Professor / Teacher, Curriculum, Course / Program, Year Level, Semester. Drop-down lists come from the current setup. *Template with current subjects* exports existing subjects for updating.
2. **Upload Excel File** (up to 1,000 rows / 5 MB). Nothing is imported yet. Every row is checked:
   - required fields and the Subject Code format
   - units (0.5–30) and price (₱0.00 or more)
   - fee labels and fee templates
   - teachers, matched by ID or exact name
   - curriculum, or Program + Year Level + Semester
   - duplicate Subject Codes with different details, and duplicate rows
   - existing subjects, matched by code
3. **Preview:** a checkbox per row, Select all / Deselect all, and a filter (New / Existing / Errors). Each row has an action: **Add** (new), or **Update / Replace / Skip** (existing). A bulk action applies to all ticked rows. Rows with errors cannot be ticked.
4. **Confirm:** live totals show uploaded, selected, to add, to skip, to update and to replace. You must tick *I reviewed*, and Replace also needs the word **REPLACE** typed. Rows are checked again against the live records before writing, so a code added in the meantime fails instead of becoming a duplicate.
5. **Results and history:** successful, failed, added, updated, replaced and skipped counts. A downloadable check report (before) and results report (after). The history shows the file, who uploaded it, the date, who imported it and the counts.
   - **Update** changes only the values in the file.
   - **Replace** sets the subject, its price for that fee, its ticked templates and its curriculum teacher to the file's values.
   - Prices follow Units × Price per Unit, and a blank price is ₱0.00.
   - Curriculum entries create the Grading Assignments automatically, with the file's teacher.
   - Grades, registrations, payments and enrollment history are never changed, and confirmed assessments keep their amounts.

## Decisions made where the checklist left a choice open
- **Missing documents warn but do not block admission.** Schools often accept late documents. Tell us if they should block.
- **The payment-plan rule skips** older registrations, registrations that already had payments, and migrated opening balances, so collections on existing balances continue. Admins can switch the rule off in Settings.
- **The curriculum teacher change skips** classes with submitted grades and teachers chosen by hand in Grading, so no submitted work moves owner silently.
- **"Returnee (readmission)" stays** as a fifth classification, because the existing readmission rules depend on it.
- **A registration without a fee template** still gets the Fee Management prices, as before.

## Files
**New:**
- sis/app/lib/enrollment-docs.php, sis/app/lib/import-all.php
- sis/app/pages/docrequirements.php, sis/app/pages/importall.php
- sis/app/views/admission_docs.php, sis/app/views/soa_plan_box.php
- sis/assets/admission-docs.js, sis/assets/import-all.js, sis/assets/success-sound.js
- sis/tests/v56-features.php, sis/tests/_legacy-payments.php, V56_UPDATE.md

**Changed:**
- api/lib.php, shared/curriculum-assignments.php
- js/app.js, js/assignments.js, js/curriculum.js, js/dashboard.js, js/grading.js, pages/assignments.html
- portal/pages/myadmission.php, portal/views/layout.php
- sis/index.php, sis/app/bootstrap.php
- sis/app/lib/core.php, grading.php, payment-plans.php, reports.php, sis.php, subject-fees.php
- sis/app/pages/admission.php, assessment.php, cashier.php, curriculum.php, migration.php, profile.php, registration.php, settings.php, student.php, students.php, subjectfees.php
- sis/app/views/assessment_sheet.php, assessment_sheet_enrollment.php, layout.php
- sis/assets/app.css, fee-manage.js, unified-toolbar.css (v56.1: Manage fees list — sis/app/lib/subject-fees.php TemplateFees, sis/app/pages/assessment.php)
- sis/bin/sample_data.php
- existing test fixtures (they now include the required address and switch the payment-plan rule off for older flows)
- CURRICULUM_TEACHERS.md

## Verification
All checks ran on disposable databases (MariaDB 10.11, PHP 8.3).

**New v56 suite: 39/39 checks passed.** It covers:
- the fee upgrade keeping existing totals
- ₱0.00 subjects, Edit without duplicates, manual ticks and copies
- recalculation of open assessments, while confirmed ones stay unchanged
- the payment-plan block, SOA figures and Prepared by
- semesters, curriculum teacher sync and history protection
- required fields, classifications and document requirements
- the official enrollment list
- Import All: template, every validation, ticking, Add / Update / Replace / Skip, the REPLACE word, stale previews, discard and data safety

**Existing suites:**

| Suite | Result |
| --- | --- |
| accounting-workflow | 82 checks passed |
| central-accounts | 34 passed |
| curriculum-teachers | 12 passed |
| end-to-end lifecycle | passed |
| eligibility | 18 passed |
| grading-restore-identity | 24 passed |
| record-list | 20 passed |
| workspaces | 13 passed |
| portal-enrollment | 42 passed |
| grade-scale parity | 51,696 checks passed |
| read-cache | passed |

The old `tests/run.php` already had 18 failures in v55, from outdated test calls. It still has the same 18 and no new ones.

**Browser checks** used headless Chromium on a local copy with sample data:
- every SIS page opened as Power User, with no PHP errors
- Registrar and Accounting refused on the new admin pages
- Import All end to end: template download, upload, preview, live totals, import and results
- automatic teacher and semester lists in Grading
- the teacher dashboard semester filter
- the SOA at desktop and phone widths
