<?php
/** Download an LMS file (?r=file&id=): class members for posts and classwork; the student and the teacher for submissions. */
declare(strict_types=1);
BinaryResponse::begin(); // v63.21: no session lock or after-page jobs while a file downloads
$f = Lms::fileFor((int) ($_GET['id'] ?? 0));
$inline = str_starts_with($f['mime'], 'image/') || $f['mime'] === 'application/pdf';
// an LMS file id always names the same bytes (files are added, never changed)
BinaryResponse::send($f['mime'], $f['bytes'], true, 3600, [
    'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']),
    'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
]);
