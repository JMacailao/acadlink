<?php
/**
 * School Information System — the only web entry point.
 * index.php?r=<route>. Every route declares the permissions it needs; pages re-check
 * finer rules (record ownership, workflow state) in the services they call.
 * All POST requests must carry the session's CSRF token.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
sis_boot();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
header('Cache-Control: no-store');

// route => [page file, required permissions (any of); null = public; [] = any signed-in user]
const ROUTES = [
    'navstate'     => ['navstate', []],
    'login'        => ['login', null],
    'forgot'       => ['forgot', null],
    'signinpic'    => ['signinpic', null],
    'logout'       => ['logout', []],
    'password'     => ['password', []],
    'workspaces'   => ['workspaces', []],
    'enrollment' => ['enrollment', ['admission'], ['admission.view']],
    'dashboard'    => ['dashboard', []],
    'help'         => ['help', []],
    'activity'     => ['activity', ['activity']],
    'search'       => ['search', ['students', 'admission', 'registration', 'payments.view']],
    // The grading system's records
    'history'      => ['history', []],
    'migration'    => ['migration', ['registration','accounting','users.manage']],
    'importall'    => ['importall', ['curriculum', 'assessment']], // v56: the page also needs both editing rights
    'students'     => ['students', ['students']],
    'student'      => ['student', ['students']],
    'sections'     => ['sections', ['sections']],
    'yearlevels'   => ['yearlevels', ['yearlevels']],
    'teachers'     => ['teachers', ['teachers']],
    'curriculum'   => ['curriculum', ['curriculum']],
    // Assessment: Categories, Labels, Names
    'assessment'   => ['assessment', ['assessment']],
    'discounts'    => ['discounts', ['assessment', 'discounts.manage']],
    'subjectfees'  => ['subjectfees', ['assessment']],
    // Transaction: Admission, Registration, Cashier
    'admission'    => ['admission', ['admission'], ['admission.view']],
    'registration' => ['registration', ['registration', 'payments.view'], ['registration.view']],
    'eligibility' => ['eligibility', ['registration', 'eligibility.holds'], ['eligibility.view']],
    'cashier'      => ['cashier', ['payments.view']],
    'balance'      => ['balance', ['registration', 'accounting', 'self'], ['balances.view']],
    'notifications'=> ['notifications', ['admission', 'registration', 'payments.view', 'accounting']],
    'receipt'      => ['receipt', ['payments.view', 'self']],
    'docfees'      => ['docfees', ['payments.record', 'payments.void'], ['payments.view']],
    'docreceipt'   => ['docreceipt', ['payments.view', 'self']],
    'schedules'    => ['schedules', ['schedules.edit'], ['schedules']],
    'myschedule'   => ['myschedule', []],
    'clearance'    => ['clearance', []],      // v55: checks who signs or reviews inside the page
    'clearanceslip'=> ['clearanceslip', []],
    'clearancefile'=> ['clearancefile', []],
    'reports'      => ['reports', ['admission', 'registration', 'payments.view', 'accounting', 'reports']],
    'accounting'   => ['accounting', ['accounting']],
    // Administrator and students
    'bulkaccounts' => ['bulkaccounts', ['users.manage']],
    'users'        => ['users', ['users.manage']],
    'emailsetup'   => ['emailsetup', ['settings.manage']],
    'settings'     => ['settings', ['settings.manage']],
    'orseries'     => ['orseries', ['settings.manage']],
    'terms'        => ['terms', ['settings.manage']],
    'docrequirements' => ['docrequirements', ['settings.manage']], // v56: registration document checklist
    'documents'    => ['documents', ['documents.issue', 'settings.manage'], ['documents']],
    'docview'      => ['docview', ['documents']],
    'profile'      => ['profile', []],
];

// Feature modules may keep their implementation outside the legacy app/pages directory.
const MODULE_ROUTE_FILES = [
    'accounting' => SIS_ROOT . '/modules/accounting/pages/accounting.php',
];

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
// v50.1: the Student Portal has its own folder. Old links (bookmarks, notifications, emails) go there,
// and students opening the SIS land in the portal (staff pages still refuse them).
if (in_array($route, ['my', 'myenrollment', 'myadmission'], true)
    || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && Auth::role() === 'STUDENT' && in_array($route, ['dashboard', 'history', 'receipt', 'profile'], true))) {
    $q = $_GET; $q['r'] = $route === 'dashboard' ? 'my' : $route;
    header('Location: ../portal/index.php?' . http_build_query($q)); exit;
}
if (!isset(ROUTES[$route])) { http_response_code(404); $route = 'dashboard'; }
[$page, $perms] = ROUTES[$route];
// A third list: permissions that may open the page to look only (forms sent from it still need the first list)
if ($perms && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $perms = array_merge($perms, ROUTES[$route][2] ?? []);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

require __DIR__ . '/app/views/layout.php';

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
    require MODULE_ROUTE_FILES[$route] ?? (__DIR__ . '/app/pages/' . $page . '.php');
    while (ob_get_level() > $obLevel) ob_end_flush();
} catch (AuthRequired) {
    while (ob_get_level() > $obLevel) ob_end_clean();
    // A form sent after the sign-in timed out was not saved; say so, and return to the same page after signing in.
    if ($isPost) flash('warning', 'Your session ended, so this was NOT saved. Sign in and try again.');
    if (!in_array($route, ['login', 'logout'], true)) $_SESSION['return_to'] = ['r' => $route, 'params' => array_filter(array_diff_key($_GET, ['r' => 1]), 'is_scalar')];
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
