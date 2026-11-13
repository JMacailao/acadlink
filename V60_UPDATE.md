# AcadLink v60 — Library

No SQL to import. Copy the files over v59 (keep `api/config.php`), open SIS once and press Ctrl+F5.
The library tables, the Librarian group and account 4700 Library Fines are created automatically.

## What it does
**Choose a workspace → Library** opens its own system (`library/`), on the same sign-in. Borrowers are the AcadLink
students and teachers already on record.

- **Circulation Desk**: scan the borrower's ID card (or type a Student ID, Teacher ID or name), then scan each copy
  to **check out**. **Return** by scanning the copy. The desk shows items out, due dates, the fine so far, holds and
  unpaid fines; **Renew** and **Lost** buttons per loan.
  - Refused with a clear message: library-use-only copies, copies already out, too many items, an overdue item,
    unpaid fines (can be turned off), a copy reserved for someone else.
- **Catalog**: titles (title, authors, ISBN with check-digit validation, publisher, year, edition, subject, call
  number, type) and **individual copies**, each with its own barcode (automatic `LB0000001…` or your own),
  status, shelf/location, replacement price and "library use only". Search by words, ISBN, call number or barcode.
- **Barcode Labels**: Code 39 labels (title, barcode, call number) for new copies, a title, or typed barcodes;
  print on label sheets. Verified readable by a barcode scanner.
- **Loans & Overdue**: everything out, overdue only, and **printable overdue notices** (one per borrower).
- **Holds**: borrowers reserve a title; the next returned copy goes to the **hold shelf** for the first in line,
  for a set number of days, then passes to the next or back to the shelf.
- **Fines** (overdue per day after grace days, capped per item; lost item = replacement price; damage fee on return)
  are **added to the borrower's account** and **paid at the Cashier**: SIS → Fees → Library Fines or
  Finance → Cashier → Library Fines, with an O.R. from the usual series. Each payment books a journal entry
  (Dr cash/bank, Cr 4700 Library Fines) for Finance → Review and Post; voiding the receipt reverses it.
  Librarians can **waive** a fine with a reason. Finance → Collections by source shows **Library fines**.
- **Acquisitions**: order titles from a supplier; **receiving** creates the copies with barcodes and the unit cost.
- **Stocktake**: start a count (whole library or one shelf), scan every copy, see what was not seen, close it to
  mark those copies **Missing**; missing copies scanned later become available again.
- **Reports**: check-outs, returns, overdue, fines charged/collected/waived/unpaid, most borrowed, collection status.
- **Loan Rules**: loan days, items at a time, renewals and fine per day — separately for students and teachers —
  plus grace days, maximum fine, lost-item fee, hold pickup days, holds per borrower, and the unpaid-fines block.
- **Student Portal → Library**: search the catalog, **reserve**, see loans and due dates, **renew** (within the
  rules), cancel reservations, and see unpaid fines. Overdue items and holds ready for pickup are shown at the top.

## Accounts and access
- New group **Librarian** (everything in the Library). It opens only the Library. The Power User has full access.
- Users and Groups → Library lists five modules (circulation with "waive fines", catalog, acquisitions, stocktake,
  reports/loan rules). Fine payments use the Cashier permissions.
- Every check-out, return, renewal, hold, fine, waiver, payment, copy change, order and stocktake is in the Activity Log.

## Source code
- New: `library/index.php`, `library/lib/library.php`, `library/pages/*` (desk, catalog, loans, holds, fines,
  acquisitions, stocktake, labels, reports, settings, cashier, finereceipt, portal), `library/assets/{library.js,library.css}`,
  `sis/tests/v60-library.php`.
- Changed: `shared/module-access.php`, `shared/central-accounts.php`, `sis/app/lib/auth.php`, `sis/app/bootstrap.php`,
  `sis/app/views/layout.php`, `sis/index.php`, `finance/index.php`, `portal/index.php`, `portal/views/layout.php`,
  `sis/app/pages/{workspaces,login}.php`, `sis/assets/{navigation,standard-toolbar}.js`, `finance/lib/overview.php`,
  `finance/modules/accounting/{lib,pages}/accounting.php`.

## Tests
`php sis/tests/v60-library.php` (20 checks). Other suites unchanged and passing (`central-accounts.php` expects nine
default groups; `v58-finance.php` updated for Library being open).

## v60.1 — Library design
- New look for the whole Library: deep plum sidebar with the **AcadLink Library** brand, lavender highlights,
  rounded cards and big page headings with an icon tile.
- Menu: **Overview · Catalog · Circulation · Members · Reservations · Fines · Reports · Settings**. Acquisitions,
  Stocktake and Barcode labels are tabs inside Catalog; All loans & overdue is inside Circulation.
- **Overview** (new home): Books in catalog, Borrowed today, Overdue, Active members; Today's circulation;
  Popular titles; Due soon.
- **Circulation desk**: Find member and Current member cards, **Check-out / Check-in / Renew** tabs, and Active loans
  with covers and status (Overdue (n days), Due today, On loan) and a Renew button. Check-out accepts a copy barcode
  **or an ISBN** (a free copy of that title is picked, or the one on the hold shelf for that member).
  Renew works by scanning the book.
- **Members**: everyone with loans, reservations or unpaid fines, and search for any student or teacher.
- **Book covers**: upload a cover when adding or editing a title (stored as bytes in `library_covers`, shrunk to
  600 px). Titles without a cover show a coloured tile with their initials.
