# v32 — Simple continuing enrollment

## What changed

The Student Portal uses its purple theme for navigation, headers, primary actions, selected tabs and notification accents. Staff SIS retains its green theme.

The fixed-header toolbar also keeps its connection to selected Registration, Admission and Cashier rows. Select the student's row, then click Open to open the full registration. View opens the printable assessment instead. If filters change, apply them again before selecting a row.

The Student Portal separates **Your current enrollment** from **You are applying for**. Current details come from confirmed school records. Assignment semester labels are not used to guess the student's current semester.

The application has four dropdowns: school year, semester, year level, and preferred section. The section is optional: **Registrar will assign my section** is available. Options follow the open enrollment periods and the student's program and promotion eligibility.

Changing semester does not promote the student. Moving to the next year requires a later school year, First Semester, and the existing all-Passed, completed second-semester requirements. Students cannot skip a year. The Registrar can approve another available section within the requested year level.

Submitting or accepting an application does not replace the student's current placement. The approved placement applies only when staff successfully **Confirm Enrollment** in Registration. Existing grade, registration, assessment, payment and receipt records remain in history.

## School setup

1. Open **SIS → Settings → Academic terms and deadlines**.
2. Select the school year and term. Set **Enrollment opens** and **Enrollment closes** so today's school date is inside the range. Click **Save term**. There is no separate Open toggle.
3. Create the target sections for the correct school year, year level and program. A year level with no eligible section will not appear in the student's choices.
4. Prepare the matching curriculum and assessment template. Finalize and tag the required prior grades Complete in Grading. Complete does not mean Passed: failed, missing, incomplete or reversed evidence does not qualify for promotion.

## Student steps

1. Sign in to the Student Portal and open **Apply for Enrollment**.
2. Read **Your current enrollment**. Contact the Registrar if the record is wrong.
3. Under **You are applying for**, select the school year, semester and year level.
4. Choose a preferred section, or leave **Registrar will assign my section**.
5. Click **Check requirements**, review the summary, confirm the details and click **Submit application**. If choices change, check requirements again before submitting.
6. Follow **My applications** for the Registrar's decision, published assessment, payments and enrollment status. Payment or acceptance alone does not mean Enrolled.

Example: a Second Year student applying for Second Semester stays Second Year. When eligible for the next school year, the student can request Third Year, First Semester. Their Second Year section remains current until the approved Third Year registration is confirmed.

## Registrar steps

1. Open **Enrollment Applications**, apply the filters and open the request.
2. Compare the saved current enrollment, requested year and preferred section with the student's history, requirements, balances and holds.
3. Choose **Approve section**. The student's preference is selected when available; another eligible section in that requested year may be chosen. Return the request for correction if its requested year is wrong.
4. Select the curriculum and assessment template, confirm the review and use **Accept and prepare assessment**. This creates a draft and records the approved section separately from the original preference.
5. Open Registration, review subjects and fees, then publish the assessment. Collect any required payment through the existing Cashier process.
6. Use **Confirm Enrollment**. The system checks eligibility and payment again, applies the approved placement, and preserves earlier records. If checks fail, placement stays unchanged.

## Updating an existing installation

Back up your database and files. Replace the application files from this ZIP while keeping your existing **api/config.php** and uploaded files. Open SIS to run the automatic, additive schema upgrade to version 15. The database user needs permission to add columns. Refresh the browser with Ctrl+F5.

**No extra SQL query and no INSTALL.sql reimport are needed for an existing installation.** The upgrade adds five nullable application fields and keeps existing records. Older applications without saved placement snapshots are labelled accordingly; historical details are not invented.

For a fresh database, follow START_HERE.md and import the root **INSTALL.sql** once. It contains the Grading, SIS, account and enrollment requirements together.

This update does not relabel existing grading assignments or change existing account passwords.

## Validation

Verified on a disposable MariaDB database: fresh installation, repeatable upgrade of populated v31-shaped applications, ownership, duplicate prevention, Registrar permission checks, preferred versus approved sections, semester continuation, next-year promotion, promotion rechecking at confirmation, assessment/payment checks, rollback and history preservation. The enrollment suite reports 48 passed, 0 failed. Actual PHP-rendered Student and Registrar screens were checked in Chromium, including cascading dropdowns, stale-review prevention and a 390-pixel mobile viewport.
