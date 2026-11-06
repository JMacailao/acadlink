# Accounting toolbar actions

The header toolbar now includes **View**, **Send for review**, **Approve**, **Post**, and **Void** in Review and Post, Expenses and Refunds, and the General Ledger. Print List, search, filters and Refresh remain in the shared toolbar. Other Accounting pages retain actions appropriate to their own records.

## How to use

1. Open the Accounting page, choose filters and click **Apply Filters**.
2. Select an entry in the table. The header identifies the selected reference and status.
3. Use an enabled action. View opens the entry details. Send for review, Approve, Post and Void open a confirmation form showing the selected reference.
4. Review the entry before confirming. Approve requires a review note. Void requires a reversal date and a reason of at least eight characters. Cancel closes the form without saving.
5. If filters or search change, apply them again and select an entry. Actions remain disabled until then.

| Entry state | Available financial actions, with the required permission |
| --- | --- |
| Draft | Send for review |
| Needs review | Approve |
| Approved / Ready to post | Approve or Post |
| Original posted entry | Void posting |
| Reversed, reversal or cancelled entry | View only |

Drafts cannot be posted directly. The existing server services still verify permissions, approval, balanced lines, accounting periods and source records when saving. An outdated page cannot bypass these checks.

**Void creates an Accounting reversal; it does not delete the original or automatically void a receipt.** After Accounting reverses a receipt posting, Cashier must separately void that receipt through the existing workflow. Student balances change at the Cashier step. Cashier and Registrar cannot void a receipt while its Accounting posting remains active.

The same header actions are available when viewing entry details. Without JavaScript, the original detail-page forms remain available. On small screens, swipe the toolbar to reach more actions.

## Install

Keep your current api/config.php and uploaded files. Replace the application files, open SIS and press Ctrl+F5. **No SQL changes are required for this toolbar update.**

Files changed for this update:

- sis/modules/accounting/pages/toolbar.php (new; required)
- sis/modules/accounting/pages/workspace.php
- sis/modules/accounting/pages/accounting.php
- sis/assets/accounting-workflow.js
- sis/assets/accounting-ui.css
- sis/app/views/layout.php
- sis/tests/accounting-workflow.php (validation)

## Verification

The accounting suite passed 58 checks on a disposable database, covering approval before posting, permissions, receipt locks, reversals, balances, refunds, periods, accounting arithmetic and real page rendering. Browser checks used those actual PHP-rendered pages and production scripts to verify row selection across review, transaction and grouped-ledger tables, status-based actions, target entry IDs, CSRF fields, required reason/date, stale-filter protection, detail defaults, desktop and mobile dialogs, and JavaScript errors.

Tests did not change the live database. Uploading this package is still required to update the live website.
