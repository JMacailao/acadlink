# AcadLink v31 — smooth navigation and loading

## Update an existing installation

1. Keep a copy of your current files before uploading this release.
2. Upload the project files over the existing application. Keep your existing **api/config.php**, uploaded school images and other user uploads. Your deployed api/config.php is intentionally omitted from this release archive so uploading it will not overwrite your connection settings. The archive includes api/config.sample.php for fresh installations.
3. **Do not import INSTALL.sql again. No SQL or schema change is needed for this update.** Fresh installations still use the single root INSTALL.sql.
4. Reload your open workspaces with Ctrl+F5 once after uploading so the new JavaScript is loaded together.
5. Check module navigation, Apply Filters, Refresh, a normal save and an embedded registration form before opening the updated installation to everyone.

The previous teacher-name fix is included: ALLAN L. BALBACAL, RRT produces abalbacal. Existing account usernames are not automatically renamed.

## What changed

- SIS, Accounting and Student Portal keep the outer shell and sidebar in place during supported module navigation and GET filters. Existing results stay visible until the requested page is ready, then module content is updated in one pass. The entire browser document is not rebuilt on each filter.
- Unchanged current cached views avoid another module render. Browser Back/Forward, sidebar active state, query filters, search focus and list scroll positions are maintained.
- The shared loading card and top progress line appear after 160 ms, so fast responses do not flash an indicator. Concurrent reads share feedback. Slow requests have a status message; failed navigation has Retry and Open full page.
- Page scripts register once. A navigation cleanup removes their global listeners, dialogs, timers and resize observers before initializing the next module. This includes individual/bulk account pages and migration pages.
- Financial views, balances, receipts, admission, security administration, migration and one-time teacher credentials are not cached. Save/post/payment/void operations remain normal server-authorized writes and are never automatically retried.
- Same-origin AJAX writes clear affected browser read caches conservatively; other open tabs are notified. Cache data is kept in memory, not localStorage, IndexedDB or an offline service worker. Refresh bypasses the SIS page cache. Returning focus clears cached reads.
- Grading retains its existing independent page modules. Supported browsers use native cross-document view transitions to keep the sidebar visually steady; other browsers retain ordinary navigation. Grading is not converted into a single-page application.
- Grading's shared request transport coalesces identical reads and caches permitted collections for a short period. Unchanged subscription results do not redraw tables. Hidden tabs stop background data polling. Reference data reads may be reused for 5 minutes, student directories for 60 seconds, and assignments/grades for 30 seconds. Unknown, financial and account-management collections bypass caching.
- Grading tables keep confirmed rows visible while refreshing; first visits still show skeleton rows. Presence heartbeats stay quiet and do not invalidate academic data caches.

## Cache safety and limits

SIS caches only ordinary list views for Students/Teachers (60 seconds), Sections/Year Levels/Curriculum (5 minutes), and grade-history views (30 seconds). Detail/edit/new views bypass caching. The cache is bounded to 12 HTML responses and 4 million characters; individual responses above 1 million characters are not stored. Grading has a separate bounded cache of at most 60 read responses and 4 million serialized characters, within the currently loaded page.

Before a cached response is shown, a small server request verifies the current centralized account and permission scope. This avoids refetching the record list, while retaining server sign-in checks. Permission/account changes, password resets and sign-out cannot authorize access through a cache. Server checks still control every write, grade ownership rule and transaction state. The opaque cache namespace is not an authentication token.

Other users' changes become visible through the freshness limit or Refresh; transactions always validate current server state. Navigation is not an offline mode. Cross-tab invalidation uses BroadcastChannel when available, with focus clearing and expiry as fallbacks.

Native POST submissions, password/sign-in/workspace switching, pop-up frames, printing, exports and downloads keep their existing navigation behavior. This preserves the original transaction and download workflows. In-page GET filtering continues to require Apply Filters; typing does not send extra server requests.

## Verification performed

- PHP syntax checks and JavaScript syntax checks.
- Headless Chromium fixture tests: persistent shell identity; filtered results; cache reuse with authorization validation; repeated lifecycle and Add dialogs; latest-request wins; errors and Retry; uncached financial views; history; cross-tab invalidation; mobile viewport; reduced motion; dynamic account/migration scripts; authorization-scope change rejection.
- Browser checks using the actual PHP layout, Teacher page and RecordList markup with fixture services: preview, Add form, filtering and repeat navigation.
- Actual Grading JavaScript transport against a fixture API: coalescing, cached authorization, mutations, financial bypass, quiet presence, unchanged subscriptions and expired identity rejection.
- Cache unit tests: expiry, query keys, copy isolation, bounded memory, identity separation and read/write races. Run with `node tests/read-cache.mjs`.

A live MySQL end-to-end test and an InfinityFree deployment test have not been run in this environment. The database-backed accounting, enrollment and grading workflows should be checked in your test installation before rollout. No database migration or transaction-rule changes are included.
