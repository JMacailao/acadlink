# Standard toolbar (SIS and Grading) — design

Approved in chat on 2026-10-28.

## Goal
Every staff module shows the same toolbar, in the same order, so people always find an action in the same place:

`New · Edit · Void · Post · Select All | Hide/Show Filters · Apply Filter | Search · Refresh · More Actions ▾`

A control that a page cannot use stays visible but greyed out, with a tooltip that says why.

## Rules
- Buttons only *click the module's own controls* (row links, row buttons, the page's Add button, accounting
  action buttons). Permissions, confirmations, reasons and server checks stay where they are.
- New = the page's own Add/New control. Edit / Void / Post act on the selected row (or, on a single-record
  page, on the open record).
- Slot matching (first match wins, on the action's visible label):
  - Edit: `Edit…`, `View / Edit`
  - Void: `Void…`, `Cancel admission`, `Delete…`, `Remove…`
  - Post: `Post…` (Accounting), `Import` (Migration batch)
- Select All = the page's own "select all" checkbox (Bulk accounts, Grading Students). Otherwise greyed.
- Search = the list search box. Enter applies, like Apply Filter.
- Refresh = reload the list with the same filters.
- More Actions = every other control that used to sit on the toolbar or page header (View, Print List, Import,
  Paste list, Find duplicates, Promote, the Actions panel toggle on phones…). Shows "No other actions" if empty.

## Where
- SIS: every staff page with the shell (lists, single-record pages, and pages without a list such as Dashboard,
  Subject Fees, Reports, Settings). Not on sign-in/password pages, printed documents, pop-ups (`bare`) or the
  Student Portal.
- Grading: Students, Teachers, Subjects, Sections, Curriculum, Assignments.

## Default filters (applied on open)
- SIS lists open already applied (no empty "click Apply" state on first open):
  - School year = the top-bar school year where the list has a school-year filter.
  - Date filters = today (Cashier O.R. date, Accounting ledger From/To).
  - Filters you applied earlier in the session come back instead of the defaults.
  - Clear Filters returns to the defaults.
- Grading lists open with School year = current school year (newest school year on record), applied.
- After changing any filter or the search, the list waits for Apply Filter (SIS and Grading).

## Testing
- Browser checks: every SIS route and every Grading list shows exactly the 10 controls in order; greyed
  states and tooltips; selecting a row enables Edit/Void/Post where the module allows; More lists the
  module's other actions; defaults applied on open; filters wait for Apply in Grading.
- Existing PHP suites stay green (record-list suite updated for auto-apply on open).
