# SIS Core Module

The shared School Information System remains under `sis/app` because Admission, Registration, Assessment,
Balance Enrollment, Notifications, Settings and the grading-system bridge share the same services and templates.

Systems with their own folder (each has its own `index.php`, and shares the SIS sign-in, database and services):

- `../../../finance/` — Finance (v58): Cashier, receipts, Document Fees, Existing Balances, O.R. series and
  Accounting (`finance/modules/accounting/`). Pages run through the shared request handling in `sis/app/front.php`.
- `../../../portal/` — Student Portal.
- `../../../lms/`, `../../../canteen/`, `../../../library/` — planned systems (placeholder pages for now).

`sis/app/lib/accounting.php` is a compatibility loader for existing includes. The payment and registration
services (`Cashier`, `Registration` in `sis/app/lib/sis.php`) stay shared, because Registration and Finance both use them.
