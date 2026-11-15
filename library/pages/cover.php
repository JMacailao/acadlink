<?php
/** A book cover (?r=cover&id=<title>&v=), read as bytes from library_covers, with browser caching. */
declare(strict_types=1);
BinaryResponse::begin(); // v63.21: covers of a list download side by side; no after-page jobs
$id = (int) ($_GET['id'] ?? 0);
$img = Library::cover($id);
if (!$img || !in_array($img[0], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) { while (ob_get_level()) ob_end_clean(); http_response_code(404); exit; }
BinaryResponse::send($img[0], $img[1], BinaryResponse::versionMatches($img[1]), 86400, ['Content-Security-Policy' => "default-src 'none'"]);
