<?php
/**
 * Student Portal — its own entry point (since v50.1), sharing the SIS's sign-in, database and data rules.
 * index.php?r=<route>. Staff pages stay in ../sis/; links to them go there (see url()).
 */
declare(strict_types=1);

define('PORTAL_ROOT', __DIR__);
const LOCAL_ROUTES = ['my', 'myenrollment', 'myadmission', 'history', 'receipt', 'password', 'profile', 'documents', 'docreceipt', 'schedule', 'clearance', 'clearanceslip', 'clearancefile', 'library']; // links to other pages go to ../sis/
require __DIR__ . '/../sis/app/bootstrap.php';
sis_boot();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
header('Cache-Control: no-store');

// route => [page file, permissions (any of); [] = any signed-in user]
const PORTAL_ROUTES = [
    'my'           => [__DIR__ . '/pages/my.php', ['self']],
    'myenrollment' => [__DIR__ . '/pages/myenrollment.php', ['self']],
    'myadmission'  => [__DIR__ . '/pages/myadmission.php', ['self']],
    'history'      => [SIS_ROOT . '/app/pages/history.php', ['self']],
    'receipt'      => [SIS_ROOT . '/app/pages/receipt.php', ['self']],
    'password'     => [SIS_ROOT . '/app/pages/password.php', []],
    'profile'      => [__DIR__ . '/pages/profile.php', ['self']],
    'documents'    => [__DIR__ . '/pages/documents.php', ['self']],
    'schedule'     => [__DIR__ . '/pages/schedule.php', ['self']],
    'docreceipt'   => [SIS_ROOT . '/app/pages/docreceipt.php', ['self']],
    'clearance'    => [__DIR__ . '/pages/clearance.php', ['self']],          // v55
    'clearanceslip'=> [SIS_ROOT . '/app/pages/clearanceslip.php', ['self']],
    'clearancefile'=> [SIS_ROOT . '/app/pages/clearancefile.php', ['self']],
    'library'      => [__DIR__ . '/../library/pages/portal.php', ['self']], // v60
];
$route = is_string($_GET['r'] ?? null) && isset(PORTAL_ROUTES[$_GET['r']]) ? $_GET['r'] : 'my';
[$file, $perms] = PORTAL_ROUTES[$route];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

require SIS_ROOT . '/app/views/layout.php';
require __DIR__ . '/views/layout.php';

$obLevel = ob_get_level();
ob_start();
try {
    if ($isPost) Auth::checkCsrf();
    if (!Auth::user()) throw new AuthRequired();
    if (Auth::user()['must_change_password'] && $route !== 'password') redirect('password');
    // Staff open their own systems; the portal is for students (and staff given the Student Portal on purpose)
    if ($perms) Auth::require(...$perms);
    require $file;
    while (ob_get_level() > $obLevel) ob_end_flush();
} catch (AuthRequired) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    if ($isPost) flash('warning', 'Your session ended, so this was NOT saved. Sign in and try again.');
    $_SESSION['return_to'] = ['r' => $route, 'params' => array_filter(array_diff_key($_GET, ['r' => 1]), 'is_scalar')];
    header('Location: ../sis/index.php?r=login'); exit;
} catch (Forbidden $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    http_response_code(403);
    if ($isPost) { keep_old_input(); flash('danger', $e->getMessage()); header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('my'))); exit; }
    layout_start('Not allowed');
    echo '<div class="alert alert-danger"><i class="bi bi-shield-lock"></i> ' . h($e->getMessage()) . '</div>';
    echo '<p><a class="btn btn-outline-secondary" href="../sis/index.php?r=workspaces">Back to my systems</a></p>';
    layout_end();
} catch (AppError $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    if ($isPost) { keep_old_input(); flash('danger', $e->getMessage()); header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('my'))); exit; }
    layout_start('Problem');
    echo '<div class="alert alert-warning" style="white-space:pre-line">' . h($e->getMessage()) . '</div>';
    layout_end();
} catch (PDOException $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    error_log('Portal DB error: ' . $e->getMessage());
    $msg = $e->getCode() === '23000' ? 'That record conflicts with an existing one.' : 'A database error occurred. It was logged.';
    if ($isPost) { keep_old_input(); flash('danger', $msg); header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('my'))); exit; }
    http_response_code(500);
    layout_start('Error');
    echo '<div class="alert alert-danger">' . h($msg) . '</div>';
    layout_end();
}
