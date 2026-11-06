<?php
declare(strict_types=1);
/** A photo or PDF a student submitted as proof for a clearance line (v55): the student, or staff who sign or review it. */
if (!Clearance::on()) throw new AppError(Clearance::offMessage());
$f = Clearance::file((int) ($_GET['id'] ?? 0));
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $f['file_mime']);
header('Content-Length: ' . strlen((string) $f['file']));
header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $f['file_name']) . '"');
// Images open sandboxed; a PDF opens in the browser's own viewer (which a sandbox would block)
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'' . ($f['file_mime'] === 'application/pdf' ? '' : '; sandbox'));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $f['file'];
exit;
