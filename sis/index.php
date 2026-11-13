<?php
/**
 * School Information System — its web entry point (Finance has its own: ../finance/index.php, v58).
 * index.php?r=<route>. Every route declares the permissions it needs; pages re-check
 * finer rules (record ownership, workflow state) in the services they call.
 * All POST requests must carry the session's CSRF token.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
sis_boot();

// Security headers are sent by app/front.php (shared with Finance)

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
    // Cashier (v58.1: also opened in Finance, ../finance/)
    'cashier'      => ['cashier', ['payments.view']],
    'docfees'      => ['docfees', ['payments.record', 'payments.void'], ['payments.view']],
    'receipt'      => ['receipt', ['payments.view', 'self']],
    'docreceipt'   => ['docreceipt', ['payments.view', 'self']],
    'orseries'     => ['orseries', ['settings.manage']],
    'notifications'=> ['notifications', ['admission', 'registration', 'payments.view', 'accounting']],
    'schedules'    => ['schedules', ['schedules.edit'], ['schedules']],
    'myschedule'   => ['myschedule', []],
    'clearance'    => ['clearance', []],      // v55: checks who signs or reviews inside the page
    'clearanceslip'=> ['clearanceslip', []],
    'clearancefile'=> ['clearancefile', []],
    'reports'      => ['reports', ['admission', 'registration', 'payments.view', 'accounting', 'reports']],
    // Administrator and students
    'bulkaccounts' => ['bulkaccounts', ['users.manage']],
    'users'        => ['users', ['users.manage']],
    'emailsetup'   => ['emailsetup', ['settings.manage']],
    'settings'     => ['settings', ['settings.manage']],
    'terms'        => ['terms', ['settings.manage']],
    'docrequirements' => ['docrequirements', ['settings.manage']], // v56: registration document checklist
    'documents'    => ['documents', ['documents.issue', 'settings.manage'], ['documents']],
    'docview'      => ['docview', ['documents', 'settings.manage']],
    'profile'      => ['profile', []],
];

// Feature modules may keep their implementation outside the legacy app/pages directory.
const MODULE_ROUTE_FILES = [];

$route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
// v50.1: the Student Portal has its own folder. Old links (bookmarks, notifications, emails) go there,
// and students opening the SIS land in the portal (staff pages still refuse them).
if (in_array($route, ['my', 'myenrollment', 'myadmission'], true)
    || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && Auth::role() === 'STUDENT' && in_array($route, ['dashboard', 'history', 'receipt', 'profile'], true))) {
    $q = $_GET; $q['r'] = $route === 'dashboard' ? 'my' : $route;
    header('Location: ../portal/index.php?' . http_build_query($q)); exit;
}
// v58: Finance has its own folder. Old SIS links (bookmarks, notifications, emails, forms) go there.
if (in_array($route, FINANCE_PAGES, true)) {
    header('Location: ' . url($route, array_filter(array_diff_key($_GET, ['r' => 1]), 'is_scalar')), true, 307); exit;
}
if (!isset(ROUTES[$route])) { http_response_code(404); $route = 'dashboard'; }
[$page, $perms] = ROUTES[$route];
// A third list: permissions that may open the page to look only (forms sent from it still need the first list)
if ($perms && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $perms = array_merge($perms, ROUTES[$route][2] ?? []);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

require __DIR__ . '/app/front.php';
