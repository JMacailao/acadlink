# Activity feedback

SIS forms show the current action immediately, change the submit button and prevent duplicate POST submissions. Examples include checking an account preview, recording a payment, posting an entry and confirming enrollment. Cancelled confirmations and invalid forms stay editable. The resulting server page supplies the success or error message. A slow request shows a notice after eight seconds; the client does not automatically repeat a write. Check the saved record or transaction before repeating an action after a lost connection.

SIS navigation shows the destination while the page loads. Modal windows retain their existing loading indicator. Enrollment eligibility explicitly shows that requirements are being checked. Downloads keep their controls available because downloading a file does not navigate the page. Use the browser download list to inspect file downloads.

Grading database reads, saves, removals and batch saves show the operation and collection in a small, nonblocking status card. Concurrent requests share one indicator; finishing one request does not hide another request still running. Routine background presence updates stay quiet. Existing page-level success messages and errors remain authoritative. Reduced-motion settings stop the activity ring animation, and indicators are hidden when printing.

Bulk account creation shows processed and total counts, created/skipped/review/failed results and a slow-request notice. Start and Retry are disabled during creation. Pause completes the current group. Use Create / resume with the original temporary password after interruption; already linked accounts are kept. Student year/section filters operate on the loaded directory and announce list updates, selection resets and empty results. Preview and result exports include year and section.

Backup and restore retain their dedicated file validation, upload progress, applying-changes step and interrupted-result checking. Activity indicators do not replace these controls or their transaction protections.

No database migration is needed for this update. Keep the existing `api/config.php` when uploading the updated files. The PHP 8.0-compatible bulk-selection validation remains in place.
