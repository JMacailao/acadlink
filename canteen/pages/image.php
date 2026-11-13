<?php
/** Product photos (?r=img&id=) and the payment QR code (?r=qr), served from the database with browser caching. */
declare(strict_types=1);
$data = $route === 'qr' ? Canteen::setting('qr_image') : (string) Db::val('SELECT image FROM canteen_products WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
while (ob_get_level()) ob_end_clean();
if (!preg_match('#^data:(image/(?:png|jpeg|gif|webp));base64,(.+)$#s', $data, $m)) { http_response_code(404); exit; }
header('Content-Type: ' . $m[1]);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo base64_decode($m[2]);
exit;
