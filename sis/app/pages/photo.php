<?php
/** v62 A student photo (?r=photo&id=): staff who see students or admissions, the student, and their linked parents. */
declare(strict_types=1);
[$mime, $bytes] = StudentPhoto::open((int) ($_GET['id'] ?? 0));
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, max-age=86400');
echo $bytes;
exit;
