# AcadLink v58 — Finance is its own workspace

## v58.1 — Cashier is in both the SIS and Finance
- **Cashier stays in the SIS** (menu: Cashier, and Document Fees under Fees) **and is also in Finance**, each with its own
  menu. Registration's **Pay** opens the SIS Cashier again. Links to Cashier, receipts, Document Fees and O.R. Series
  stay in the system you are in.
- Only **Existing Balances** and **Accounting** are Finance-only; old SIS links to those still forward to Finance.
- The Cashier group opens both the SIS (it sees Cashier there) and Finance.
- Fixed: people in the Cashier group were treated as Teachers in the SIS (wrong role name, SIS home sent them back
  to the chooser).
- Source: `cashier.php`, `docfees.php`, `receipt.php`, `docreceipt.php`, `orseries.php` are back in `sis/app/pages/`;
  `finance/index.php` opens the same files with the Finance menu.

The rest of this note describes v58; where it says those pages moved to Finance, v58.1 above applies.

No SQL to import. Copy the files over v57.1 (keep your `api/config.php` and uploads), open SIS once and press Ctrl+F5.
The database upgrades itself the first time SIS or Finance opens.

## What changed

**Choose a workspace** (after sign-in) now shows six cards in this order:

Grading System · Admission & Registration (SIS) · Learning Management System · Canteen POS · Library · Finance

- **Finance** opens its own system with its own menu: Overview, Notifications, Cashier (Cashier, Document Fees,
  O.R. Series), Balances (Existing Balances), Accounting (overview, Review and Post, Expenses and Refunds, Ledger,
  Chart of Accounts, Account Mappings, Bank Reconciliation) and Reports.
- **SIS** keeps Admission, Registration, enrollment, student records and Assessment/fee setup. Its menu no longer
  lists the Finance pages. On Registration, **Pay** opens Finance's Cashier.
- **LMS, Canteen POS and Library** show **Coming soon**. Only the Power User sees Canteen POS and Library for now.
- **Finance Overview** shows **Collections by source** for a date range: Tuition and fees, Documents,
  Canteen POS and Library fines. Canteen and Library say "Not set up yet" until those systems are built; their sales
  and fines will appear here automatically. It also shows what needs attention (entries to review, balance requests,
  today's receipts) for the person signed in.

## Accounts and access

- **New Cashier group**: receive payments, print receipts and see students' balances in Finance. No accounting books
  and no SIS. Voiding stays off by default; the Power User can turn it on in **Users and Groups → Groups → Cashier**.
- **Accounting** keeps the books (and opens Finance).
- **Nobody loses access.** Anyone who used Cashier, Existing Balances or Accounting in SIS uses them in Finance with
  the same permissions. Registrars who can view receipts or balances therefore also see the Finance card; remove those
  modules from the Registrar group if you don't want that.
- Users and Groups lists the Finance, Canteen POS and Library modules. Fixed: changes to Finance (and LMS) modules
  in a group or for one person are now saved.
- Old links keep working: `sis/index.php?r=cashier`, `?r=accounting`, `?r=balance`, `?r=receipt`… forward to
  `finance/index.php` with the same filters.

## Source code

- New folder `finance/`: `index.php` (entry point), `pages/` (cashier, docfees, balance, orseries, receipt,
  docreceipt, overview), `modules/accounting/` (moved from `sis/modules/accounting/`), `lib/overview.php`,
  `assets/accounting-workflow.js` (moved from `sis/assets/`).
- New folders `canteen/` and `library/` with placeholder pages.
- `sis/app/front.php`: request handling shared by `sis/index.php` and `finance/index.php` (split out of `sis/index.php`).
- Changed: `sis/index.php`, `sis/app/bootstrap.php`, `sis/app/lib/core.php` (`url()` knows Finance pages),
  `sis/app/views/layout.php` (Finance menu and title), `sis/app/pages/workspaces.php`, `sis/app/pages/login.php`,
  `shared/module-access.php`, `shared/central-accounts.php`, `sis/app/views/access_grid.php`, `portal/index.php`,
  `portal/views/layout.php`, `js/app.js`, `css/workspace-selector.css`.
- Removed from `sis/`: `app/pages/{cashier,docfees,balance,orseries,receipt,docreceipt,accounting}.php`,
  `modules/accounting/`, `assets/accounting-workflow.js`. Delete them on the server after uploading, or leave them:
  nothing links to them any more.

## Tests

`php sis/tests/v58-finance.php` (11 checks). The other suites give the same results as v57.1
(`central-accounts.php` now expects six default groups).
