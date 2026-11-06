# AcadLink v57 — standard toolbar on every module

No database change and no SQL to run. Copy the files over v56.

## What changed
Every staff module in the SIS and the Grading system now has the same toolbar, in the same order:

`New · Edit · Void · Post · Select All | Hide/Show Filters · Apply Filter | Search · Refresh · More Actions ▾`

- All ten controls are always there. One a page can't use is **greyed out**; hover it to see why
  ("Select a row first", "Sections has no Void action", "This page has no filters"…).
- **Click a row** to select it, then Edit / Void / Post. Double-click a row in Grading = Edit.
- The buttons run the module's own action, so the same permissions, confirmations and reasons apply:

| Toolbar | What it runs |
|---|---|
| New | The module's Add button (Add Student, Add O.R., Add Journal Entry, Add requirement…) |
| Edit | Edit / View / Edit (Admission) / Edit O.R. No. (Cashier) / Open (Registration) / Manage (Users) |
| Void | Void (Cashier, Registration, Accounting posting) · Cancel Admission · Delete / Remove (setup records, Grading) |
| Post | Post (Accounting) · Import (Migration batch) |
| Select All | The page's select-all box (Bulk accounts, Grading Students) |
| More Actions | Everything else: View, Print List, Admit, Reject, Pay, Re-print, Import from Excel, Paste list, Find duplicates, Promote, the Actions panel… |

- On single-record pages (one registration, one applicant) Edit/Void/Post act on the open record
  (e.g. Void registration, Cancel Admission).
- Pages with their own filter form (Existing Balances, History of Grades, Schedules, Notifications…):
  Apply Filter submits that form and Hide/Show Filters hides it.
- Phones: the same buttons as icons (tooltips keep the names); search on its own row.

## v57.1 — one fixed place
The toolbar is always in the same place: the bar right under the top bar (SIS), on **every** page — lists,
single records and pages without a list (Dashboard, Settings, Reports…). In the Grading system it sits at the
top of the page, above the title, and stays in view when you scroll. On phones it is at the top of the page.

## Default filters
- **SIS lists open already applied** — no more empty "Choose your filters, then click Apply" screen.
  - School year = the top-bar school year (Students, Admission, Registration, Enrollment, Student accounts).
  - Date filters = **today** (Cashier O.R. date; Accounting ledger From/To — was "1st of the month").
  - Filters you applied earlier in the session come back instead. **Clear Filters** returns to the defaults.
- **Grading lists** (Students, Sections, Assignments) open at the **current school year** (the newest one on
  record). Grading Students has a new **School year** filter.
- After you change a filter or the search, the list waits for **Apply Filter** (or Enter in the search box),
  in both systems.

## Files
- New: `js/standard-toolbar-core.js` (shared), `css/standard-toolbar.css`, `sis/assets/standard-toolbar.js`,
  `js/record-toolbar.js` (Grading), `sis/tests/v57-toolbar.php`, `docs/specs/2026-10-28-standard-toolbar-design.md`.
- Changed: `sis/app/views/layout.php`, `sis/app/lib/record-list.php` (openDefaults, Clear → clear=1),
  `sis/index.php`, `sis/assets/unified-toolbar.css`, `sis/modules/accounting/pages/accounting.php` (ledger date),
  `pages/{students,teachers,subjects,sections,curriculum,assignments}.html`, `js/students.js` (school year filter).

## Tests
`php sis/tests/v57-toolbar.php` (9 checks). All earlier suites unchanged and passing.
