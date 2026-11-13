<?php
/** A book cover (?r=cover&id=<title>), read as bytes from library_covers, with browser caching. */
declare(strict_types=1);
$img = Library::cover((int) ($_GET['id'] ?? 0));
while (ob_get_level()) ob_end_clean();
if (!$img || !in_array($img[0], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $img[0]);
header('Content-Length: ' . strlen($img[1]));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
echo $img[1];
exit;
