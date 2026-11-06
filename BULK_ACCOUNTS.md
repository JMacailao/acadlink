# Bulk teacher and student accounts

Sign in to SIS as a Power User. Open **Users and Groups → Bulk create accounts**.

1. Choose Teachers or Students and load the records.
2. For Students, choose School Year → Year Level → Section, then optionally search by name or Student ID. Choices follow the preceding dropdowns. The default school year follows the SIS school-year selector; Reset filters shows all records. Filters and search survive the preview redirect. Unassigned records remain selectable. Select individual records or all matching records (up to 1,000 per batch). Changing year, section or search clears the selection and explains why; the matched and selected counts stay visible.
3. Generate one shared temporary password, or enter and confirm your own. Choose an expiry of 1, 3, 7, 14 or 30 days.
4. Preview the accounts. Nothing is created yet. Check the names, IDs, year levels, sections and usernames. The server reads the year and section from the selected records.
5. Save the displayed temporary password securely; it is shown once. Click **Create / resume accounts**.
6. Download the results list. It includes year levels, sections, usernames and outcomes, with no passwords.

Teacher usernames use the first initial plus full surname, adding a number for collisions. Use first-name-first directory names: `Juan Miguel Dela Cruz, LPT` produces `jdelacruz`; `ALLAN L. BALBACAL, RRT` produces `abalbacal`. Recognized trailing suffixes/degrees are ignored. Review unusual surnames before creating accounts. Student usernames use the Student ID (sign-in is case insensitive). Invalid IDs and ambiguous or legacy identities need manual review in Users and Groups.

Existing accounts, including disabled accounts, are skipped. Bulk creation does not reset, reactivate or merge them. Each created account links to the selected directory record, receives the Teacher or Student group, and must change its password at first sign-in. Class assignments stay in Grading.

The password is shared only for the selected batch. Every account stores an independently salted hash. Individual password changes do not change another person's password. Expired temporary passwords cannot sign in to either system; a Power User can reset them in SIS.

Creation runs in groups of four. Each account and its identity links are saved in one transaction. Pause finishes the current group. Refreshing or a lost connection can resume the current batch for two hours in the same signed-in browser session; re-enter the original shared password. Completed accounts are not created again. Use **Retry failed accounts** for failed rows. If directory information or usernames changed after preview, create a fresh preview. A new preview replaces the previous batch and its results, so download the results first.

For a fresh database, run only the root **INSTALL.sql** as before. It includes the temporary-password expiry table and initial Power User. On an existing centralized installation, uploading this release and running the first bulk batch creates the additional table automatically; do not reinstall the database. The database user needs CREATE TABLE permission for that automatic update.

Bulk batches are available only in SIS and only to Power Users. Teacher permissions remain limited to assigned classes; student permissions remain limited to their own records.

After the final group is processed, successful and skipped rows disappear from the preview, the shared-password display and password fields are cleared, and the selection checkboxes reset. A completion summary stays visible. Failed, review-needed and missing-information rows remain for follow-up; failed accounts can be retried with the original temporary password. Pausing an unfinished batch keeps its pending rows and resumable state.

Full results remain downloadable for the batch's two-hour session lifetime, including successful records removed from the visible table. Refreshing a completed batch keeps its table clear. School year, year level, section and search stay selected. Starting a new preview replaces the old batch and its download; download the old results first.
