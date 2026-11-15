<?php
/** Product photos (?r=img&id=&v=) and the payment QR code (?r=qr), read as bytes from canteen_images, with browser caching. */
declare(strict_types=1);
BinaryResponse::begin(); // v63.21: photos of a list download side by side; no after-page jobs
[$type, $owner] = $route === 'qr' ? ['QR', 0] : ['PRODUCT', (int) ($_GET['id'] ?? 0)];
$img = Canteen::image($type, $owner);
if (!$img || !in_array($img[0], ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) { while (ob_get_level()) ob_end_clean(); http_response_code(404); exit; }
// a ?v= tag that matches the stored picture: the address names these exact bytes (a new photo gets a new tag)
BinaryResponse::send($img[0], $img[1], BinaryResponse::versionMatches($img[1]), 86400, ['Content-Security-Policy' => "default-src 'none'"]);
