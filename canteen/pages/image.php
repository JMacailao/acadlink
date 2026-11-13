<?php
/** Product photos (?r=img&id=) and the payment QR code (?r=qr), read as bytes from canteen_images, with browser caching. */
declare(strict_types=1);
$img = $route === 'qr' ? Canteen::image('QR', 0) : Canteen::image('PRODUCT', (int) ($_GET['id'] ?? 0));
while (ob_get_level()) ob_end_clean();
if (!$img || !in_array($img[0], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $img[0]);
header('Content-Length: ' . strlen($img[1]));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
echo $img[1];
exit;
