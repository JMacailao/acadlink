<?php
/**
 * Canteen POS — its own system and entry point (v59), sharing the SIS's sign-in, database, services and look.
 * index.php?r=<route>. Selling, receipts, cashier shifts and cash counts, supervisor-approved voids and refunds,
 * products, stock, stocktakes, sales reports and settings. Accepted shifts are booked in Finance → Review and Post.
 */
declare(strict_types=1);

const APP_SYSTEM = 'CANTEEN';
const LOCAL_ROUTES = ['alerts', 'dashboard', 'sales', 'sale', 'receipt', 'shift', 'shifts', 'approvals', 'products', 'inventory', 'reports', 'settings',
    'img', 'qr', 'notifications', 'search', 'profile', 'password', 'navstate'];
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();

// route => [page file, required permissions (any of); [] = any signed-in user]
const ROUTES = [
    'alerts'       => ['alerts', []], // v62: my notifications
    'dashboard'    => [__DIR__ . '/pages/pos.php', ['canteen.view', 'canteen.products', 'canteen.inventory', 'canteen.reports']],
    'sales'        => [__DIR__ . '/pages/sales.php', ['canteen.view']],
    'sale'         => [__DIR__ . '/pages/sale.php', ['canteen.view']],
    'receipt'      => [__DIR__ . '/pages/receipt.php', ['canteen.view']],
    'shift'        => [__DIR__ . '/pages/shift.php', ['canteen.sell']],
    'shifts'       => [__DIR__ . '/pages/shifts.php', ['canteen.supervise', 'canteen.reports']],
    'approvals'    => [__DIR__ . '/pages/approvals.php', ['canteen.supervise']],
    'products'     => [__DIR__ . '/pages/products.php', ['canteen.products']],
    'inventory'    => [__DIR__ . '/pages/inventory.php', ['canteen.inventory']],
    'reports'      => [__DIR__ . '/pages/reports.php', ['canteen.reports']],
    'settings'     => [__DIR__ . '/pages/settings.php', ['canteen.products.edit']],
    'img'          => [__DIR__ . '/pages/image.php', ['canteen.view', 'canteen.products', 'canteen.inventory']],
    'qr'           => [__DIR__ . '/pages/image.php', ['canteen.view', 'canteen.products']],
    // Shared with the SIS: the same pages, shown with the Canteen menu
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
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

require SIS_ROOT . '/app/front.php';
