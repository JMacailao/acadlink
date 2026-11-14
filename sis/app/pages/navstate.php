<?php
/** Cheap authorization check before displaying a cached page; no record-list queries. */
declare(strict_types=1);
// v63.12: opened as a page (no JSON asked for), go to the workspaces instead of showing the check's answer
if (!str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) redirect('workspaces');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['scope'=>acadlink_client_scope(Auth::central())],JSON_THROW_ON_ERROR);
