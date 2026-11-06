# SIS Core Module

The shared School Information System remains under `sis/app` because Admission, Registration, Cashier, Assessment, Balance Enrollment, Notifications, Settings, and the grading-system bridge share the same services and templates.

Separated feature modules:

- `../accounting/` — Accounting service and Accounting page.
- `../student-portal/` — Student Portal page.

The files under `sis/app/lib/accounting.php`, `sis/app/pages/accounting.php`, and `sis/app/pages/my.php` are compatibility loaders for existing includes.
