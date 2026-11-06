<?php
/** The sign-in page picture (set in the grading system's School settings), sent as an image the browser can cache. */
declare(strict_types=1);
$pic = School::signinPicture();
if (!$pic) { http_response_code(404); header('Content-Type: text/plain'); exit; }
[$type, $bytes] = $pic;
$etag = '"' . md5($bytes) . '"';
header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . strlen($bytes));
echo $bytes;
exit;
