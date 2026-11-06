<?php
/** Cheap authorization check before displaying a cached page; no record-list queries. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['scope'=>acadlink_client_scope(Auth::central())],JSON_THROW_ON_ERROR);
