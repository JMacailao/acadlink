# Shared SIS Actions panel and payment plans

## Updating your installation

Back up the current database and application files. Upload the application files from this package while keeping your own **api/config.php** and uploaded files. Open SIS using an administrator account, then press **Ctrl+F5**. The database account must be able to create tables.

Existing installations automatically add the three payment-plan tables through schema upgrade **15**. There is **no second SQL query and no need to reimport INSTALL.sql**. For a fresh database, import the root INSTALL.sql once; it includes Grading, SIS, Accounting, centralized accounts, the initial Power User and payment-plan tables.

Existing users, passwords, grades, receipts, registrations, balances and payment-free assessments remain intact. Existing registrations are not automatically assigned a new plan. Previously published assessments without a plan keep their original publication fingerprint.

## Shared SIS layout

The main sidebar stays dark green. The right Actions panel uses a light gray background, compact menu rows and theme-colored highlights. Primary actions, filtering, Print List, Search and Refresh stay in the header. Existing secondary buttons move to the right without copying their handlers. Additional actions for the selected record appear under Record Actions. Form Save/Cancel controls remain with their forms. Permissions and server-side checks remain authoritative.

The panel is available in Admission, Enrollment Applications, Registration, Cashier, Students, Teachers, Sections, Year Levels, Curriculum, Assessment, Scholarships & Discounts, Accounting, Users, Bulk Accounts and Migration. Its contents follow the current module. Payment Setup appears only under Assessment → Labels.

Use **Actions** in the header to collapse or reopen the panel. On a phone or tablet it opens as a right-side drawer. Changes to filters disable record actions until Apply Filters is used again. Existing list loading and navigation rules remain in effect. The Student Portal retains its separate purple theme.

## Administrator: create installment plans

1. Open **Assessment → Labels → Actions → Installment Plans**.
2. Enter a clear name, such as “2026 First Semester — four payments”.
3. Optionally limit the school year, semester, program and year level. Blank means all.
4. Choose **2–24 installments**. The starting example is 40%, 20%, 20%, 20%. For three installments, for example, enter 40%, 30%, 30%.
5. Enter each installment's name, percentage and due date. Dates must be in order. Percentages support two decimal places and must total exactly 100%.
6. Leave Active checked and click Save payment plan.

The amounts are computed from the existing assessment, using cumulative rounding so every cent is allocated and no installment becomes negative. A schedule does not create another charge, invoice, receipt or revenue entry.

## Administrator: full-payment offers

Open **Assessment → Labels → Actions → Full Payment Discount**. Enter a plan name, percentage or fixed peso discount, deadline and any academic restrictions. Select the eligible fee labels. Leave the labels unselected to cover the total assessment; select tuition labels for tuition-only offers.

Choose explicitly whether the offer may combine with scholarships or other reductions. When combination is allowed, the existing reductions are applied first, then the full-payment offer is calculated on the remaining eligible charges. Books and other excluded fees do not receive a tuition-only reduction.

A full-payment discount must leave a positive amount payable. Percentage offers must be below 100%; use the existing scholarship/waiver features for a completely waived assessment.

Example: ₱20,000 tuition with a 5% full-payment offer becomes ₱19,000 only when the required payment is completed by the deadline. The original regular balance stays in force until qualification. The Cashier screen shows the qualifying amount and prevents collecting more than that amount while the offer is available.

## Manage existing plans

Use **Existing Plans** to view templates and edit them. The Active checkbox controls availability for future selections. Editing or deactivating a template does not change the saved terms on a student's registration. Only a Power User or an account explicitly granted Users-management permission can configure plans.

Labels also has Activate/Deactivate for the selected label, Export Labels and View Activity where permitted. These are separate from activating a payment-plan template.

## Registrar: choose and publish a student's plan

1. Create/open the student's **OPEN registration draft**.
2. Review subjects, fees and scholarships first.
3. In Payment plan, select an applicable active plan and click Save plan. A plan must be selected before the first receipt and before enrollment confirmation. Existing confirmed or previously paid assessments are not silently changed.
4. Review the saved schedule, amounts, due dates and conditional discount in the assessment. To replace or remove the plan, do so before the first receipt.
5. For a Student Portal application, publish or republish the reviewed assessment. Changing its plan invalidates the previous publication until review. Existing eligibility and minimum-payment checks still apply; installment dates alone do not automatically block enrollment.
6. Confirm enrollment through the existing registration process when all requirements are met.

## Cashier

The payment screen shows the saved plan, amount due now, overdue amount, next unpaid due date and each installment's paid/remaining amounts. Receive payments through the existing receipt form. Partial and advance payments are allowed; net payments cover the oldest unpaid installment first. Every payment retains its own official receipt.

A qualifying full-payment receipt applies the earned discount once, inside the payment transaction. Repeating the same receipt request cannot duplicate the discount. Payments after the deadline do not earn an expired offer. A payment void or a posted refund recalculates qualification and the schedule. If the qualifying funds are restored, the earned discount is recalculated using the original receipt dates.

## Student Portal and history

Students see their own saved schedule with amounts, due dates, payment status, overdue amount and any conditional or earned full-payment discount. Schedules are shown with the published assessment, student account and assessment-history statement. Existing ownership and publication checks still apply.

## Accounting

Assessment and payment entries remain distinct. Installment schedules create no additional journal entries. If a full-payment discount is earned before confirmation, it is included in the eventual assessment entry. If an already confirmed assessment changes because a discount is earned or withdrawn, a balanced adjustment is prepared for Accounting review and approval; it is not posted automatically.

Managed plan adjustments inherit the assessment's fund and department. They cannot be discarded, returned to an editable manual draft or independently reversed: correct the source payment/refund to recalculate them. Reversing the whole assessment in Accounting also reverses its linked plan corrections, preserving their audit history.

A receipt posted in Accounting remains locked against Cashier edits/voids. Accounting must reverse its posting before Cashier voids it. The existing approval, balanced-journal, period-closing and refund controls still run on the server.

## Verification

Tests use disposable databases and fictional students, never the live installation. Coverage includes fresh installation and repeatable upgrade, existing publication compatibility, permissions, 3/4/24-installment schedules, fractional percentages, tiny-amount rounding, partial and advance payments, template snapshots, conditional and expired offers, fixed/percentage and tuition-only discounts, scholarship stacking, receipt retries, voids, refunds/reversals and balanced accounting. Browser checks use actual PHP-rendered pages and production scripts to exercise shared panels, selected-record actions, editor validation, relevant SIS modules and the mobile drawer.
