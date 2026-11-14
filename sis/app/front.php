<?php
/**
 * v58: the request handling shared by the SIS (sis/index.php) and Finance (finance/index.php).
 * The including index.php has already set ROUTES, MODULE_ROUTE_FILES, $route, $page, $perms and $isPost.
 * Included at file scope, so pages see the same variables as before.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
header('Cache-Control: no-store');

require SIS_ROOT . '/app/views/layout.php';

if ($isPost) {
    try {
        Auth::checkCsrf();
    } catch (Forbidden $e) {
        http_response_code(403);
        layout_start('Expired form');
        echo '<div class="alert alert-danger">' . h($e->getMessage()) . '</div>';
        layout_end();
        exit;
    }
}
// A page that fails halfway is discarded, so its error page is not drawn inside the half-finished page.
$obLevel = ob_get_level();
ob_start();
try {
    if ($perms !== null) {
        if (!Auth::user()) throw new AuthRequired();
        $u = Auth::user();
        if ($u['must_change_password'] && !in_array($route, ['password', 'logout'], true)) redirect('password');
        // v51: a Power User needs an email for Forgot password; ask for it once after sign-in
        if (!$isPost && !in_array($route, ['profile', 'password', 'logout'], true) && AccountEmail::isPowerUser((int) Auth::centralId()) && AccountEmail::get((int) Auth::centralId()) === '') {
            flash('warning', 'Add your email before continuing. As Power User you need it to reset a forgotten password.');
            redirect('profile', ['need' => 'email']);
        }
        if ($perms) Auth::require(...$perms);
    }
    if (!$isPost && class_exists('RecordList')) RecordList::openDefaults(); // v57: lists open already applied
    require MODULE_ROUTE_FILES[$route] ?? (str_ends_with($page, '.php') ? $page : SIS_ROOT . '/app/pages/' . $page . '.php');
    while (ob_get_level() > $obLevel) ob_end_flush();
} catch (AuthRequired) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    // v63.12: background checks (page cache, idle timer) just say "signed out"; they are never a page to return to
    if (in_array($route, ['navstate', 'keepalive'], true)) {
        http_response_code(401); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo '{"signedIn":false}'; exit;
    }
    // A form sent after the sign-in timed out was not saved; say so, and return to the same page after signing in.
    if ($isPost) flash('warning', 'Your session ended, so this was NOT saved. Sign in and try again.');
    if (!in_array($route, ['login', 'logout'], true)) $_SESSION['return_to'] = ['r' => $route, 'params' => array_filter(array_diff_key($_GET, ['r' => 1]), 'is_scalar'), 'app' => app_system()];
    redirect('login');
} catch (Forbidden $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    http_response_code(403);
    if ($isPost) { keep_old_input(); flash('danger', $e->getMessage()); header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('dashboard'))); exit; }
    layout_start('Not allowed');
    echo '<div class="alert alert-danger"><i class="bi bi-shield-lock"></i> ' . h($e->getMessage()) . '</div>';
    layout_end();
} catch (AppError $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    if ($isPost) {
        keep_old_input();
        flash('danger', $e->getMessage());
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('dashboard')));
        exit;
    }
    layout_start('Problem');
    echo '<div class="alert alert-warning" style="white-space:pre-line">' . h($e->getMessage()) . '</div>';
    layout_end();
} catch (PDOException $e) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    error_log('SIS DB error: ' . $e->getMessage());
    $msg = $e->getCode() === '23000' ? 'That record conflicts with an existing one (duplicate or still in use).' : 'A database error occurred. It was logged.';
    if (!empty(sis_config()['debug'])) $msg .= ' [' . $e->getMessage() . ']';
    if ($isPost) { keep_old_input(); flash('danger', $msg); header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('dashboard'))); exit; }
    http_response_code(500);
    layout_start('Error');
    echo '<div class="alert alert-danger">' . h($msg) . '</div>';
    layout_end();
}
