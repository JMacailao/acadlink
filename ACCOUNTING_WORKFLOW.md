# Accounting in SIS

Open **SIS → Accounting Today**. Accounts and permissions are still managed only through SIS Users and Groups. Grading continues to handle academic work.

## Daily use

1. **Today** shows drafts, entries awaiting review, posted collections today, operational student receivables, unreconciled cash/bank lines and reversed receipts awaiting cashier void.
2. **Review & post** lets Accounting filter by status, dates, school year, student ID and receipt/reference text. Open an entry to check its source, student, amount, debit/credit lines and fund/department. Source receipts and student history are linked directly.
3. New assessments and cashier payments enter **Needs review**. Manual journals and business transactions enter **Draft**. Send a draft for review, approve with a verification reason, then **Post to General Ledger**. Preparation, review and posting are separately permission-checked on the server. By default the Accounting group has all three permissions; a school may give them to separate staff through Users and Groups.
4. Only **POSTED** journals enter the financial statements and reconciliation. Original entries and their posted reversals both stay in the ledger. Unposted cancelled entries do not affect it.
5. Reconcile cash/bank accounts, view reports, then close the completed accounting period.

Approval alone does not post. Repeating a successful posting or reversal returns the existing result. Concurrent cashier and accounting actions use a shared financial lock so they cannot both change the same receipt inconsistently.

## Receipts: reversal before void

Receiving a payment issues an official receipt and reduces the student's balance immediately. Its accounting entry waits for review. An unposted receipt may still be voided by someone with cashier-void permission; its unposted journal is cancelled.

After Accounting posts a receipt, Cashier and Registrar cannot void it or edit its number, including through a direct server request. Accounting must first open its posted entry and choose **Correct a posted entry → Reverse posting**, with an open reversal date and a reason of at least eight characters. The reversal date cannot precede the original entry.

The receipt remains active after that reversal and the student's balance is unchanged. Cashier then voids the original receipt. That action restores the student's balance and removes its active fee allocations once. Record a replacement payment through the normal cashier form when needed. A reversed receipt must be voided; its number cannot simply be edited back into service.

A posted assessment similarly needs Accounting reversal before Registration can reopen or void its source. Imported opening balances must be reversed from **Data Migration**, which already requires Accounting approval and retains the import source.

## Student ledger, adjustments and refunds

**Student ledger** opens the student's original school-year/term assessments and full financial history. Tuition, books and other fees remain separate. For confirmed assessments, Cashier can choose a specific fee or use the oldest unpaid fees. Discounts are distributed proportionally across positive fees using exact integer cents; existing unallocated payments are applied FIFO when displaying fee balances. Payments made before assessment confirmation remain at account level. Historical allocation snapshots remain stored if an authorized correction later rebuilds an assessment.

**Expenses & refunds → Student charge / discount** creates a reviewed correction for a confirmed normal assessment. Posting adds an adjustment line and updates its total together with the journal. Reversing creates a compensating adjustment; it does not delete the original. A discount or reversal that would leave an overpaid assessment is blocked. Correct imported opening balances through Data Migration instead.

**Student refund** requires an active receipt already posted by Accounting. Partial refunds are allowed up to the original amount minus previous active refunds. Posting reduces net paid and restores the refunded fee balance. A refund returns money; cancelling a fee additionally requires a separate approved discount adjustment. Reverse any active refunds before reversing the receipt itself. Refund journals inherit the original receipt's fund/department. Student history displays posted refunds and corrections, including reversed ones.

## GLFRS: accounts, funds and statements

Existing account mappings continue to supply receivables, cash/bank, fee revenue and discounts. New default accounts add equipment, accumulated depreciation, supplier payables, payroll payable, salaries and depreciation expense. Existing account codes are not overwritten.

Entries carry **Operating**, **Capital**, or **Restricted / scholarship** fund and a department. Unposted entries may be classified before approval; changing classification clears approval. Supplier payments and refunds inherit their source classification. Existing entries default to Operating / School during upgrade; this is a compatibility classification, not a reconstruction of undocumented historical funds.

Report Manager provides Trial Balance, General Ledger, Income Statement and Balance Sheet, with fund and department filters. Financial reports also link to student receivables and collections. Operational student balances can temporarily differ from General Ledger receivables while assessments/payments await posting. Receivables aging is measured from assessment confirmation, not a contractual due date.

## TPS: expenses, payables, payroll summaries and assets

Create suppliers/payees, then save drafts for paid expenses, staff reimbursements, supplier bills, bill disbursements and payroll summaries. Every transaction needs a stable unique source reference and reason. Reusing a reference opens its existing entry; use a new reference for a separately approved correction. Posted entries cannot be edited or deleted.

- A **supplier bill** debits its expense/asset and credits the selected liability.
- A **bill payment** debits that bill's liability and credits cash/bank. Partial payments are allowed; cumulative overpayment is blocked. Reverse payments before reversing their bill.
- A **paid expense/reimbursement** debits expense/asset and credits cash/bank.
- A **payroll summary** debits salary expense and credits payroll payable. Use a reviewed manual journal to pay that liability. This records approved payroll totals; it does not calculate employee payslips, taxes or statutory deductions.
- Register an acquired **asset** after booking its actual purchase. Enter cost, residual value and useful life in months. Monthly straight-line depreciation is drafted once per asset/month, starting in the acquisition month; Accounting reviews/posts it. The register shows posted depreciation and net book value. Asset registration alone does not create a purchase or a journal. Depreciation is not an unattended scheduled task; choose the month to draft it. Correct a reversed monthly depreciation using an explicitly reviewed manual correction rather than duplicating the asset/month source.

## Reconciliation, budgets and management reports

Reconciliation shows posted cash/bank lines up to the chosen statement date. Tick the statement items and enter its closing balance. The cleared total must agree exactly; stale/foreign/already-cleared lines are rejected. Record bank charges, deposits or interest through a reviewed journal before reconciling them. Statements must be reconciled chronologically. Reconciliation permission is separate from journal posting permission.

Budgets record date range, school year, department, fund, revenue/expense account and amount. Saving the same dimensions updates that budget. Actuals include only posted journal lines in that range/fund/department, including reversals. Variance is budget minus actual. Financial statements and existing school-year collection/balance reports provide the management reporting views.

## Closing and audit

Close a specified period only after pending drafts/reviews are resolved, reversed receipts are voided and cash/bank lines are reconciled. Closed periods block journal creation, posting and reconciliation in their date range. Reopening requires the exact closed dates, closing permission and an audit reason. Later corrections can reverse an older entry on an open later date while keeping the original period's transaction intact.

Preparation, submission, approval, classification, posting, reversals, supplier/asset/budget changes and closing/reopening are recorded in the existing Activity Log. The entry detail shows its creator, reviewer and poster. No accounting screen deletes posted history.

## Installation and backup

- **Fresh database:** run the single root **INSTALL.sql**. It includes both systems, Accounting tables, seeds, centralized accounts and the first Power User. No second SQL query is required.
- **Existing database:** back up the shared MySQL database, replace project files while keeping your live **api/config.php**, then open SIS. Schema version 12 adds the new columns/tables automatically under an upgrade lock. Existing journals remain **POSTED**, existing payments and references are retained, and existing passwords/groups are preserved.
- Grading Excel backup remains an academic backup. Use a **full MySQL backup** to protect journals, receipts, drafts, allocations, budgets, assets, period locks and centralized accounts together.
- A portable financial workflow test is included: `php sis/tests/accounting-workflow.php`. It creates a uniquely named disposable database on the configured MySQL server, runs the checks and removes that test database on success. It requires permission to create a test database and never imports into the configured live database.

## Accounting interface

Every Accounting workspace uses grouped navigation: Daily work, Books & setup, Planning & reporting, and Period control. All twelve original destinations remain available, with the current task highlighted. List pages keep the shared SIS header toolbar and flexible search, followed by the grouped tasks, a short task description, one compact native filter surface, and scrolling record panels.

Filter choices, field names, GET routes, POST actions, role checks and financial processing are unchanged. Records still load only after Apply Filters, except the existing Today, Student ledger and Mappings screens. Search-only lists omit the empty filter card. No-results messages distinguish awaiting filters from an applied search with no matches. Changed filters disable printing and prompt the user to apply again.

On phones the groups and controls reflow; empty messages fit the screen and populated wide tables retain horizontal scrolling. Desktop list toolbars, task navigation and filters remain above the scrolling records. Print views omit task navigation and toolbar controls. No SQL change is required for this interface update.
