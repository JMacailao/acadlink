<?php
/**
 * Finance — its own system and entry point (v58), sharing the SIS's sign-in, database, services and look.
 * index.php?r=<route>. Cashier, receipts, document fees, existing balances, O.R. series and Accounting live here;
 * admission, registration and assessment stay in ../sis/. Links to other pages go there (see url()).
 * Every route keeps the permissions it had in the SIS, so the same people can do the same things.
 */
declare(strict_types=1);

const APP_SYSTEM = 'FINANCE';
// Finance's own pages, plus the shared pages that should keep the Finance menu (notifications, reports, profile…)
const LOCAL_ROUTES = ['dashboard', 'cashier', 'docfees', 'balance', 'orseries', 'receipt', 'docreceipt', 'accounting',
    'notifications', 'reports', 'search', 'profile', 'password', 'navstate'];
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();

// route => [page file, required permissions (any of); [] = any signed-in user, view-only permissions]
const ROUTES = [
    'dashboard'    => [__DIR__ . '/pages/overview.php', []],   // checks Finance access itself
    'cashier'      => [__DIR__ . '/pages/cashier.php', ['payments.view']],
    'docfees'      => [__DIR__ . '/pages/docfees.php', ['payments.record', 'payments.void'], ['payments.view']],
    'balance'      => [__DIR__ . '/pages/balance.php', ['registration', 'accounting', 'self'], ['balances.view']],
    'receipt'      => [__DIR__ . '/pages/receipt.php', ['payments.view', 'self']],
    'docreceipt'   => [__DIR__ . '/pages/docreceipt.php', ['payments.view', 'self']],
    'orseries'     => [__DIR__ . '/pages/orseries.php', ['settings.manage']],
    'accounting'   => [__DIR__ . '/modules/accounting/pages/accounting.php', ['accounting']],
    // Shared with the SIS: the same pages, shown with the Finance menu
    'notifications'=> ['notifications', ['admission', 'registration', 'payments.view', 'accounting']],
    'reports'      => ['reports', ['admission', 'registration', 'payments.view', 'accounting', 'reports']],
    'search'       => ['search', ['students', 'admission', 'registration', 'payments.view']],
    'profile'      => ['profile', []],
    'password'     => ['password', []],
    'navstate'     => ['navstate', []],
];
const MODULE_ROUTE_FILES = [];

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
// Students use the Student Portal for their receipts and balances
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && Auth::role() === 'STUDENT' && in_array($route, ['dashboard', 'receipt', 'docreceipt'], true)) {
    $q = $_GET; $q['r'] = $route === 'dashboard' ? 'my' : $route;
    header('Location: ../portal/index.php?' . http_build_query($q)); exit;
}
// Any other page (sign-in, registration, students…) belongs to the SIS
if (!isset(ROUTES[$route])) {
    $q = $_GET; $q['r'] = $route;
    header('Location: ../sis/index.php?' . http_build_query($q), true, 307); exit;
}
[$page, $perms] = ROUTES[$route];
if ($perms && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $perms = array_merge($perms, ROUTES[$route][2] ?? []);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

require SIS_ROOT . '/app/front.php';
