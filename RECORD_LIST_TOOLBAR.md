# Record-list toolbar

SIS directories now begin with **no result records loaded**. Select filters and click **Apply Filters** to retrieve the matching list. Opening a URL containing filter values alone does not load the list; an explicit application is required.

The common toolbar is left-aligned and uses the same action order: **Add New** (when permitted), **Show/Hide Filters**, **Apply Filters**, **Clear**, **Print List**, and the module's permitted selected-record actions, followed by one flexible search field and the icon-only **Refresh list** control at the far right. Export controls are retained only where the module already supports them; no new unrestricted data export is introduced.

- **Clear** resets the filters and empties the result list. It also forgets the previous applied list context, so saving a new record afterward does not restore old results unexpectedly.
- **Refresh** reloads the last applied URL, not unsaved changes in the controls. It is disabled until filters have been applied.
- Changing a filter does not retrieve records automatically. The status says **Filters changed — click Apply Filters**; selected-record actions and printing remain disabled until the new filters are applied. Existing loaded records remain visible for reference.
- Initial pages say **Choose your filters, then click Apply Filters to show records.** Applied lists show their displayed row count or **No records match your filters.** Existing result limits remain in force.
- School year, year level and section choices depend on one another where relevant. Student and bulk-account cohort selectors update their choices locally before application. Bulk account lists retain unassigned student choices.
- Add/Edit forms still open through their existing dialog or transaction flow. Row edits preserve the applied filters. A selected-row button begins disabled; native eligibility restrictions (for example posted receipt locks) still apply.
- Printing requires an applied, nonempty result list. Existing specific-batch downloads, migration templates and report previews retain their explicit workflows. A completed bulk-account result download still contains the full batch without passwords.

## Compact titles and aligned filters

Every signed-in SIS module uses a shared header above its content: breadcrumbs on the left and a consistent 18px, medium-weight module title on the right. This includes Accounting tabs, configuration, reports, history, and detail pages. Titles are outside toolbar cards and are rendered on the server without duplicate headings. Supporting descriptions remain in the content below; list actions appear in the header toolbar. On phones, breadcrumbs and title stack; the title stays right-aligned. Embedded forms retain their own headings. Native flex filters (including Sections) keep each label and its field together in a grid cell. Labels sit above their controls, search fields align with dropdowns, and removed Reset buttons leave no empty column. Labels remain associated with their original controls and existing filter behavior is retained.

## Stable navigation and scrolling

The signed-in workspace fills the available width beside the sidebar. It has no centered maximum-width cap, including on ultrawide displays and when browser zoom is reduced. Browser zoom still scales text and controls normally. Login and embedded forms keep their existing layouts.

The shared white page header contains breadcrumbs and the compact module title in its first row, with the action toolbar, integrated search and refresh icon in its second row. The filter card is a separate surface underneath and contains only its supporting description, filter controls and status. On desktop, the header and filter card stay in place while records scroll independently. On mobile, the header remains below the menu bar while the filter card scrolls with the content. Column headings remain visible for ordinary directory tables. The sidebar brand and account controls stay in place, with a separate scrollable menu. Its scroll position is remembered within the current browser tab and account, including when another module is opened.

Refresh, filter submissions and same-context reloads restore the records position, clamped to the available results. A different module or detail record begins at the top. This stores UI positions only, never record data or credentials. If browser storage is blocked, navigation and filtering continue normally.

On mobile, the menu remains a drawer and the toolbar stays below the menu header. The module title remains visible. Apply Filters and Show/Hide Filters come first; remaining tools scroll sideways with a swipe hint. Touch controls are at least 44px high. Filters stack in one column on phones and two on tablets; date ranges wrap when space is limited. Text inputs use 16px text to avoid automatic zoom on iPhones. Wide tables retain readable columns and scroll sideways inside the records area. Filters initially collapse to leave space for records; Show Filters opens them. Filter visibility is remembered separately for desktop and mobile, including when resizing or changing orientation without a reload. Short screens keep the toolbar scrollable so all controls remain accessible. Printing removes viewport clipping and includes the full loaded list. Embedded forms and detail screens retain their existing layouts.

## Coverage

Students, Teachers, Sections, Year Levels, Curriculum/Subjects, Assessment Categories/Labels/Names, Scholarship/Discount Programs and Grantees, Users, Bulk Accounts, Admissions, Registrations, Cashier Receipts, Migration batch lists, and Accounting review/ledger/accounts/transactions/budgets/assets/reconciliation/financial lists/periods.

Dashboard totals and configuration pages (such as account mappings and group permissions) remain available. Exact-record views—student profiles, official receipts, cashier accounts, journal review, migration batch review, and selected-student history—remain directly accessible after their normal permission checks. A pending bulk preview is an existing batch detail and remains available for completion or resumption, independently of the candidate list filters.

## Installation and verification

No SQL changes are required. Replace the updated application files while keeping the live `api/config.php`, then reload the browser. Fresh setup continues to use the single root `INSTALL.sql`.

`php sis/tests/record-list.php` checks the server-side gate and filter context without a database. The browser workflow also supports applying native filters with JavaScript disabled; JavaScript adds local dropdown dependencies, dirty-filter feedback, row selection and responsive layout.

## Filter preferences

Cashier and Registration optional filters keep one enabling checkbox label above each control. The shared toolbar no longer adds a second label inside preference rows. The compact grid and switch alignment preserve required filters, optional disabled-field submission rules, and explicit Apply Filters behavior. The same label fix applies to other preference forms using the shared toolbar. No SQL changes.

## Integrated toolbar search

The existing search input is moved into the shared action row, preserving its original query parameter and filter-form ownership. There is no duplicate search in the filter grid. Students use names or Student IDs; Cashier retains its existing payer-name/Student-ID search; other lists use their relevant name, ID or reference. In Financial Reports, search applies to receivables aging; the full trial balance remains intact. Enter or Apply Filters submits the search with the current enabled filters. Typing alone does not reload records, and changed search text invalidates selected actions and printing. Refresh reloads the applied URL; Clear resets the list. On phones the action buttons scroll sideways, while search and its adjacent refresh icon remain on their own visible row.

## Header toolbar rollout

All signed-in SIS modules share the white header surface. Modules with the standard list toolbar place it inside that header; existing module-specific actions and permissions are retained. Embedded forms and sign-in screens retain their original layouts. The toolbar is excluded from printing while the module title is retained. Enter-to-search still submits the original filter form even though search and Apply Filters are outside its card.
