# Existing Balances and Notifications redesign

This release applies the approved green AcadLink mockup to both screens and includes the previously implemented Enrollment Eligibility Checker.

## Install

1. Back up your application and database.
2. Replace the deployed `sis/` folder with this ZIP's `sis/` folder. Preserve your existing `api/config.php` and database credentials.
3. Refresh the browser to load the updated styles. No additional schema migration is required for this redesign. If the eligibility release has not yet been installed, follow `ENROLLMENT_ELIGIBILITY.md` for its included automatic table upgrade.

## Existing Balances

- Four live summary cards show previous balance, students with balances, pending requests and approved requests for the selected target school year and semester.
- Summary totals are explicitly labeled as applying before search/status filters.
- The directory includes previous unpaid balances even where no approval request exists. Settled balances with a request remain available for review.
- Search by student name or ID; filter payment status and request status independently. Use Apply filters or Reset. Results are paginated at 25 students per page.
- Payment status is derived from outstanding original registrations. An approved request does not change an unpaid balance to settled.
- Open Review or View to inspect original assessments, paid amounts, remaining balances and the latest 50 receipts. Voided receipts are labeled and do not count as paid amounts.
- Request details show current balance, proposed initial payment, target term, conditions, reason, latest decision and audit history.
- Approve/Decline require a reason. Revoking an already approved request is explicitly labeled Revoke approval.
- A stale pending-review form cannot silently revoke an approval made by another reviewer. The server locks and rechecks the expected request state.
- Filters remain in the URL after review actions. Close details restores the previous list scroll position where session storage is available.
- Check eligibility opens the existing checker for the selected student and target term.

## Notifications

- All, Unread and Needs my action tabs; search student/request, filter event type and inclusive date range; 20 notifications per page with accurate totals.
- Needs my action includes pending requests the current role is authorized to approve. Resolved requests are removed automatically from that view.
- Rows show unread state, event title, student, target enrollment term, historical request amounts where relevant, current request status, timestamps and a contextual action.
- Details show the full message and latest linked request state. Open request leads to the balance review screen for the correct student and term.
- Reading a message and reviewing a request remain separate actions. Mark as read and Mark all as read do not approve, decline or resolve cases.
- New events distinguish approval revoked from request declined. Older BALANCE_CASE notifications remain readable and filterable; historically ambiguous declined events are not reclassified as revocations.
- Inbox searches, filters, detail lookups and read actions remain recipient-scoped. Existing role-addressed messages retain the application's existing shared role-level read-state behavior; this release does not introduce per-person read receipts for role messages.

## Permissions and mobile behavior

- Existing server approval permissions remain authoritative. Cashiers cannot approve pending requests; the existing permission to deliberately revoke an approved request is retained.
- Student balance access remains restricted to the signed-in student's account, including when another Student ID is supplied in the URL. Student sessions cannot open the staff notification inbox or use approval actions.
- Existing CSRF and double-submission controls remain in place.
- On narrow screens, balance rows become labeled stacked cards, filters wrap, request facts stack, and notification actions remain readable. Status text accompanies every color.
- The normal server-rendered links and forms work without JavaScript. The small enhancement script only remembers list scroll position.

## Validation

- **13 focused PHP/MariaDB workspace tests passed**, covering balance reconciliation, no-request students, independent statuses, filters, recipient scope, read/decision separation, reviewer permissions, stale decisions, historical notifications, dates and pagination.
- **All 18 eligibility tests passed again** after integration.
- Signed-in browser checks passed for filtering, detail selection, approval, preserved filters, current notification status, marking read, Needs my action, missing-CSRF rejection, mobile layout, cashier permissions and student self-service isolation. No browser JavaScript errors were observed.
- PHP and JavaScript syntax checks passed. Desktop and 390px mobile screenshots were inspected.
- The two pre-existing full-suite inconsistencies documented in `ENROLLMENT_ELIGIBILITY.md` remain outside this redesign.

Run the focused test from the project root with `php sis/tests/workspaces.php` on a test MySQL/MariaDB server. It creates a disposable `sis_workspaces_test` database and refuses to use the configured live database. Database creation privileges are required. Test fixtures are created only by the test script; they are not application seed data.
