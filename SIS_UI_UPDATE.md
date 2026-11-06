# AcadLink SIS interface update

The SIS interface follows the supplied DESIGN reference images: deep green navigation, a pale workspace, navy headings, green actions, and spacious directory tables.

## Using the update

1. Back up the existing application files.
2. Replace the deployed `sis/` folder with this package's `sis/` folder. Keep your current database and deployment configuration.
3. Reload the browser. If older styles remain, refresh the page while bypassing its cache.

No database migration is required for this interface update. The complete project is included in this ZIP; the changes are inside `sis/`.

## What changed

- Add and edit forms for students, teachers, sections, curriculum, subjects, fee categories, fee labels, assessment names, scholarship/discount programs, users and accounts open in focused dialogs.
- Directories use the full available width after their add form moves into a dialog.
- Forms have persistent associated labels, required-field markers, examples, Cancel and close controls, keyboard focus, Escape support, and native browser validation guidance.
- Student filters include school year, section, year level, search and Reset.
- Fee templates appear in a table with Manage fees and Edit actions. Fee lines and calculation previews remain available beneath the selected template.
- User account forms show the username or student ID according to the chosen role.
- Breadcrumbs, consistent green actions and tabs, directory search, record counts, empty search results and responsive dialog layouts improve navigation.

Without JavaScript, the original inline forms remain available. Existing server authorization, CSRF handling, save actions and business rules remain in place. Browser-side checks supplement server validation.

## Validation

- All 55 SIS PHP source files parsed successfully with php-parser configured for PHP 8.3.
- JavaScript syntax checked with Node.
- Browser checks used static fixtures built from the actual form markup, together with the project's styles and scripts. Eleven add/edit forms passed dialog opening, associated labels, green button styling, and Escape checks. Search, empty search results, required-field guidance, Cancel and a 390px mobile dialog also passed.
- Desktop and mobile fixture screenshots were inspected.

A PHP/MySQL runtime and the school's database were not available for an authenticated end-to-end test. Verify a test save in each module on your own test server before applying the update to the live system.
