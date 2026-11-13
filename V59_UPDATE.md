# AcadLink v59 — Canteen POS

No SQL to import. Copy the files over v58.1 (keep `api/config.php`), open SIS once and press Ctrl+F5.
The canteen tables, groups and accounts are created the first time SIS, Finance or the Canteen opens.

## What it does
**Choose a workspace → Canteen POS** opens its own system (`canteen/`), on the same sign-in.

- **Cashier** (the screen from the design): scan a barcode or search, category buttons, grid or list of products with
  photos, prices and stock left; the order with − / + quantities, Clear Cart, total; **Cash** or **QR Payment**;
  **Charge Payment**.
  - Cash: type the cash received (or tap Exact / ₱500 / ₱1,000), the change is shown.
  - QR Payment: shows the canteen's saved QR code (Canteen Settings); the cashier confirms when the money arrives.
    A reference number is optional.
  - Prices always come from the product list on the server, never from the browser. A double click records one sale.
  - Items that count stock can't be sold beyond what is left.
- **Receipt**: on screen and as an 80 mm slip (Print receipt). It is a sales slip, not a BIR official receipt.
- **My Shift**: open the drawer with the opening cash; close it by counting bills and coins. The screen shows
  expected cash (opening + cash sales − refunds) and whether the count is short or over.
- **Sales & Receipts**: find sales by date, payment, receipt or reference; re-print; ask for a **void** (whole sale,
  while its shift is open) or a **refund** (some items, paid back in cash from the cashier's open shift).
- **Approvals** (supervisor): approve or reject voids and refunds. Approved ones return the items to stock.
  A supervisor can't approve their own request. A shift can't close while one of its requests waits.
- **Shifts & Cash Counts** (supervisor): accept a closed shift (a short/over count needs a note) or return it to the
  cashier to count again. **Accepting books one journal entry in Finance → Review and Post**:
  Dr Cash (counted − opening float), Dr QR collections, Cr Canteen Sales (sales − refunds), short/over to
  Cash Short and Over. Finance approves and posts it as usual.
- **Products**: name, category, price, unit cost, barcode, SKU, photo, count stock or not, low-stock warning, shown or hidden.
  **Categories** with their icons.
- **Stock**: levels and low-stock list, **receive** from a supplier (updates the unit cost), **servings prepared**
  today, **adjust** with a reason, **stocktake** (type the counts; differences are saved), and the full history.
- **Sales Reports**: gross sales, refunds, net sales and gross profit; by day (cash / QR), by product, by cashier;
  print or download the products CSV.
- **Canteen Settings**: QR code image and its caption, canteen name and receipt footer.
- **Finance → Overview → Collections by source** now shows Canteen POS (net sales).
- **Finance → Accounting → Account Mappings** has a Canteen POS section (cash, QR, sales, short/over).
  New accounts 4600 Canteen Sales and 5600 Cash Short and Over are added and mapped automatically.

## Accounts and access
- New groups: **Canteen Cashier** (sell, receipts, own shift, ask for voids/refunds; sees products and stock) and
  **Canteen Supervisor** (everything in the canteen, approvals, cash counts, reports). Both open only Canteen POS.
  The Power User has full canteen access.
- Users and Groups → Canteen POS lists five modules (sell, products, inventory, supervise, reports) to fine-tune.
- Every product, price, stock, shift, void and refund change is in the Activity Log.

## Source code
- New: `canteen/index.php`, `canteen/lib/canteen.php`, `canteen/pages/*` (pos, receipt, sales, sale, shift, shifts,
  approvals, products, inventory, reports, settings, image), `canteen/assets/{pos.js,canteen-pages.js,canteen.css}`,
  `sis/tests/v59-canteen.php`.
- Changed: `shared/module-access.php`, `shared/central-accounts.php`, `sis/app/lib/auth.php`, `sis/app/bootstrap.php`,
  `sis/app/views/layout.php`, `sis/app/pages/workspaces.php`, `sis/app/pages/login.php`, `sis/assets/navigation.js`,
  `sis/assets/standard-toolbar.js`, `finance/lib/overview.php`, `finance/modules/accounting/{lib,pages}/accounting.php`.

## Tests
`php sis/tests/v59-canteen.php` (21 checks). Other suites unchanged and passing (`central-accounts.php` now expects
eight default groups; `v58-finance.php` updated for Canteen POS being open).
