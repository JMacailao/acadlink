<?php
/**
 * Library — its own system and entry point (v60), sharing the SIS's sign-in, database, services and look.
 * index.php?r=<route>. Circulation desk (check-out, returns, renewals, holds), catalog and copies, loans and overdue
 * notices, fines (paid at the SIS/Finance Cashier), acquisitions, stocktakes, barcode labels, reports and loan rules.
 */
declare(strict_types=1);

const APP_SYSTEM = 'LIBRARY';
const LOCAL_ROUTES = ['dashboard', 'circulation', 'members', 'cover', 'catalog', 'loans', 'holds', 'fines', 'acquisitions', 'stocktake', 'labels', 'reports', 'settings',
    'notifications', 'search', 'profile', 'password', 'navstate'];
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();

const ROUTES = [
    'dashboard'    => [__DIR__ . '/pages/overview.php', ['library.view', 'library.catalog', 'library.reports']],
    'circulation'  => [__DIR__ . '/pages/desk.php', ['library.view']],
    'members'      => [__DIR__ . '/pages/members.php', ['library.view']],
    'cover'        => [__DIR__ . '/pages/cover.php', ['library.view', 'library.catalog', 'library.reports']],
    'catalog'      => [__DIR__ . '/pages/catalog.php', ['library.catalog', 'library.view']],
    'loans'        => [__DIR__ . '/pages/loans.php', ['library.view']],
    'holds'        => [__DIR__ . '/pages/holds.php', ['library.view']],
    'fines'        => [__DIR__ . '/pages/fines.php', ['library.view']],
    'acquisitions' => [__DIR__ . '/pages/acquisitions.php', ['library.acquire']],
    'stocktake'    => [__DIR__ . '/pages/stocktake.php', ['library.stocktake']],
    'labels'       => [__DIR__ . '/pages/labels.php', ['library.catalog.edit']],
    'reports'      => [__DIR__ . '/pages/reports.php', ['library.reports']],
    'settings'     => [__DIR__ . '/pages/settings.php', ['library.settings'], ['library.reports']],
    'notifications'=> ['notifications', ['admission', 'registration', 'payments.view', 'accounting']],
    'search'       => ['search', ['students', 'admission', 'registration', 'payments.view']],
    'profile'      => ['profile', []],
    'password'     => ['password', []],
    'navstate'     => ['navstate', []],
];
const MODULE_ROUTE_FILES = [];

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
if (!isset(ROUTES[$route])) {
    $q = $_GET; $q['r'] = $route;
    header('Location: ../sis/index.php?' . http_build_query($q), true, 307); exit;
}
[$page, $perms] = ROUTES[$route];
if ($perms && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $perms = array_merge($perms, ROUTES[$route][2] ?? []);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

require SIS_ROOT . '/app/front.php';
