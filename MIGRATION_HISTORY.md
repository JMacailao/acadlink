# Student history and reviewed migration

Keep your existing `api/config.php` when uploading this release. Existing SIS databases add the new tables and registration-origin column automatically on the first SIS visit. The database user needs CREATE and ALTER privileges for this additive upgrade. For a fresh database, run only the root `INSTALL.sql`; it includes the new tables and the original first Power User. No second SQL script is needed.

## Student history

Students and student profiles have right-side **History of Grades** and **History of Assessments** links. Choose a student and filter by school year, year level and term. The Student Portal has the same links for the signed-in student's own record. Teachers can open History of Grades from their workspace or the student's entry in the Grading class list.

Power Users and Registrar can view academic history. Accounting views financial history. Teachers see academic history only for students in their current or recorded previous classes; finance stays restricted. Students see only their own released grades, assessments, itemized opening fees, payments and receipts. The existing unpaid-grade visibility setting still applies. Applicant accounts need a verified student link first.

Finalized grades and original assessments remain attached to their original period. Current enrollment fields are snapshots of the student's current position, not replacements for past grades. SIS changes, Grading changes, promotion, graduation and grading restores capture the before/after enrollment identity. Existing registrations, grade entries and completed grades supply historical context where available. Missing historical facts that were already erased by an older release cannot be reconstructed without an original backup or source record.

Promotion is available on the student's history page to authorized Registrar staff. It requires finalized, passed second-semester subjects and a later school-year section in the next year level. Promotion sets the next section; create the actual new enrollment and assessment through Registration. Graduation uses the same second-semester check and requires an audit reason; Registrar must verify full program completion. Graduated students keep their accounts and history. Existing Grading promotion also preserves snapshots and recognizes previously finalized completion records.

## Download and complete Excel

Open **SIS → Administration → Data Migration**. Choose itemized previous balances or previous academic records. Download a blank workbook or one containing existing Student IDs and full names. The populated download can be limited by school year, year level and section. These filters choose the students; fill in the ORIGINAL historical period on each imported record.

The Records sheet is the only imported sheet. The workbook includes instructions, an example on a separate sheet, lookup lists, and Excel dropdowns. Student IDs and names alone are not obligations: untouched prefilled rows without fee or subject details are ignored. Up to 1,000 completed rows / 5 MB are accepted per batch. Excel uploads use PHP SimpleXML; CSV upload is available too.

Balance columns:

`Student ID | Full Name | School Year | Year Level | Term | Section | Migration Reference | Fee Category | Fee Name | Outstanding Balance`

Use a separate row for each fee, such as Tuition 3000.00 and Books 800.00. Enter the remaining unpaid amount directly, without currency symbols or commas. The fee category/name must match the active Assessment setup. A stable ledger/source reference is required on every row. Repeated fees with different source references need explicit Accounting verification. Original charges and past payments must not be entered again as new receipts.

Academic columns:

`Student ID | Full Name | School Year | Year Level | Term | Section | Migration Reference | Subject Code | Subject Name | Units | Final Grade | Grade Scale`

Use existing subject codes. `percent` accepts 0–100 with a passing grade of 75; `point5` accepts 1–5 with a passing grade of 3 or better. INC and DRP are supported. Use one scale, year level and section per student/term. Verify the source school's scale before approval. The server uses the verified SIS subject name. Import a complete historical term in one batch; an existing finalized or working grade period is blocked from being overwritten.

## Review, approve and import

1. Upload and check. Student IDs must match exactly one existing SIS student. Names are cross-checked and discrepancies become explicit warnings; names never merge identities. Unknown students, fees or subjects must be corrected or mapped in SIS first.
2. Review row errors, warnings and financial totals. Download the correction workbook when needed; its Records sheet retains the original import headers. Correct and upload a new file, then discard the unposted invalid batch with a reason. Identical file uploads reopen the original batch.
3. Accounting with journal-post permission approves balances and chooses an active opening-balance EQUITY account. Registrar approves academic records. An audit reason and explicit verification of any identity/repeated-fee warnings are required.
4. Import the approved batch. Approval and import are separate actions. Changed student, fee or subject records invalidate the reviewed data; accounting accounts are checked again before posting. The batch is atomic: all rows, claims and journal entries commit together, or all roll back. If interrupted, reopen the batch and inspect its status; repeating an imported batch cannot create it again.

Historical balances are marked **MIGRATION** and use locked assessment accounts in the original school year/term, with a MIG-prefixed reference. They are not new enrollments and are excluded from enrollment census/class-list counts. An existing active assessment for the same student and term blocks an additional opening account; review the existing charge instead of duplicating it.

Opening entries debit Accounts Receivable and credit the reviewed EQUITY account, not current fee revenue. No receipt or historical cash payment is created on import. Balances flow through Existing Balances, enrollment checks, financial balance/AR reports and the Student Portal.

At Cashier, open the opening account and choose a specific fee or allocate to the oldest unpaid fees. The ordinary official-receipt workflow records an actual new payment. Fee allocations, remaining balances and receipts stay linked; a voided payment stops reducing the original fee balance.

Approved academic imports become finalized historical completion records with their source batch. They are available to history and prerequisite checks. Grading account creation remains disabled; migration creates no accounts. Imported academic records cannot be altered through ordinary Grading write endpoints or overwritten by an Excel grading restore.

## Corrections and backups

Draft/approved but unposted batches can be discarded with an audit reason. Imported batches must be reversed by the authorized Accounting/Registrar reviewer. Posted receipts must be voided through the authorized financial workflow before reversing an opening balance. Original source rows, journal reversals, references and audit records are kept. Ordinary registration reopen/void actions cannot alter migration accounts.

Academic reversal withdraws the imported completion from active eligibility calculations while preserving the source batch. Corrected academic imports require a new verified source reference and review. Current records in a replacement batch remain protected by the same workflow.

Use a FULL MySQL backup to protect SIS history, migration staging, claims, financial allocations and audit tables. The Grading Excel backup covers grading collections only; it does not replace a full database backup. Keep a full database backup before migrating a school's real opening records.

### Student ID and name only download
In Data Migration → Download Excel template → Record type, choose **Blank — Student ID and Name only**. School year, year level and section filters still apply. The downloaded workbook contains only a Records sheet with Student ID and Full Name columns, populated from existing SIS students. It is a student list, not a completed balance or academic import; choose the corresponding record type to download the required import columns.
