# Compact SIS workspace

The SIS uses the approved spreadsheet-style layout: a slim green school bar,
small left-aligned module title, compact toolbar, flexible search, narrow left
navigation and right Actions panel, and closely spaced records with grid lines.
Select a record before using its available actions. Actions can be collapsed
using the toolbar button; on phones that button opens a right-side drawer.

Desktop results use the remaining workspace height and scroll independently.
Filter forms still require Apply Filters. On narrow screens tools can be swiped,
tables scroll horizontally, and interactive controls keep touch-friendly sizes.
Student Portal retains its purple theme. Embedded forms and printed documents
retain their existing layouts. This update adds no SQL and changes no permissions.

Upload the updated application files, retaining api/config.php and uploads.
Refresh the browser with Ctrl+F5 to load the new stylesheet.

Changed files: sis/app/views/layout.php and sis/assets/compact-workspace.css.
