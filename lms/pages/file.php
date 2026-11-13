<?php
/** Download an LMS file (?r=file&id=): class members for posts and classwork; the student and the teacher for submissions. */
declare(strict_types=1);
$f = Lms::fileFor((int) ($_GET['id'] ?? 0));
while (ob_get_level()) ob_end_clean();
$inline = str_starts_with($f['mime'], 'image/') || $f['mime'] === 'application/pdf';
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . strlen($f['bytes']));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=3600');
echo $f['bytes'];
exit;
