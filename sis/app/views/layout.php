<?php
/** Page frame: head, permission-filtered sidebar, flash messages. */
declare(strict_types=1);
require_once __DIR__ . '/bottom-nav.php'; // v61.2
require_once dirname(__DIR__,3).'/shared/client-state.php';

/** [route, label, icon, permissions, group, hint] — items of one group are listed together under a collapsible heading */
const NAV = [
    ['dashboard', 'Overview', 'house-door', [], '', ''],
    ['notifications', 'Notifications', 'bell', ['admission', 'registration', 'payments.view', 'accounting'], '', 'Special-request approvals'],
    ['my', 'My Account', 'person-badge', ['self'], '', 'Grades, fees, receipts'],
    ['enrollment', 'Enrollment Applications', 'clipboard-check', ['admission', 'admission.view'], 'Enrollment', 'Student Portal applications'],
    ['admission', 'Admission', 'person-plus', ['admission', 'admission.view'], 'Enrollment', 'New applicants'],
    ['registration', 'Registration', 'journal-check', ['registration', 'payments.view', 'registration.view'], 'Enrollment', 'Enroll for a term'],
    ['clearance', 'Online Clearance', 'patch-check', ['clearance'], 'Enrollment', 'Sign and review student clearance'],
    ['eligibility', 'Enrollment Eligibility', 'shield-check', ['registration', 'eligibility.view'], 'Enrollment', 'Checks, holds and prerequisites'],
    ['students', 'Students', 'people', ['students'], 'Records', ''],
    ['photos', 'Photo Approvals', 'person-bounding-box', ['students.edit'], 'Records', 'New photos students sent'],
    ['privacy', 'Data Privacy', 'shield-lock', ['students.edit', 'settings.manage'], 'Records', 'Privacy notice and records to review'],
    ['teachers', 'Teachers', 'person-video3', ['teachers'], 'Records', ''],
    ['sections', 'Sections', 'collection', ['sections'], 'Records', ''],
    ['yearlevels', 'Year Levels', 'bar-chart-steps', ['yearlevels'], 'Records', ''],
    ['curriculum', 'Curriculum', 'list-check', ['curriculum'], 'Records', 'Subjects per term'],
    ['history&tab=grades', 'History of Grades', 'clock-history', ['students', 'registration'], 'Records', 'Permanent grade records'],
    ['history&tab=assessments', 'History of Assessments', 'receipt', ['registration', 'accounting', 'payments.view'], 'Records', 'Permanent assessment records'],
    ['documents', 'TOR & Certificates', 'file-earmark-text', ['documents'], 'Records', 'Issue, sign, reprint and void'],
    ['schedules', 'Class Schedules', 'calendar-week', ['schedules'], 'Records', 'Days, times, rooms and teachers'],
    ['cashier', 'Cashier', 'cash-coin', ['payments.view'], '', 'Payments and receipts'],
    ['assessment&tab=categories', 'Fee categories', 'tags', ['assessment'], 'Assessment', 'Fee groups'],
    ['assessment&tab=labels', 'Fee labels', 'tag', ['assessment'], 'Assessment', 'Fee lines'],
    ['assessment&tab=names', 'Fee Templates', 'card-list', ['assessment'], 'Assessment', 'Fee templates'],
    ['subjectfees', 'Subject Fees', 'journal-plus', ['assessment'], 'Assessment', 'Fees of particular subjects, per unit'],
    ['discounts', 'Scholarships & Discounts', 'award', ['assessment', 'discounts.manage'], 'Assessment', 'Less on the fees'],
    ['assessment&tab=labels&plan=list', 'Payment Setup', 'calendar3', ['assessment'], 'Assessment', 'Installments, due dates and full-payment discount'],
    ['docfees', 'Document Fees', 'file-earmark-text', ['payments.view'], 'Fees', 'TOR and certificate requests to pay'],
    ['libfines', 'Library Fines', 'book', ['payments.view'], 'Fees', 'Overdue, lost and damage fines to pay'],
    ['reports', 'Report Manager', 'printer', ['admission', 'registration', 'payments.view', 'accounting', 'reports'], 'Administration', 'Census, cashier reports'],
    ['users', 'Users and Groups', 'person-gear', ['users.manage'], 'Administration', 'Sign-in accounts'],
    ['emailsetup', 'Email Sending', 'envelope-at', ['settings.manage'], 'Administration', 'Forgot password emails (EmailJS)'],
    ['bulkaccounts', 'Bulk Accounts', 'people-fill', ['users.manage'], 'Administration', 'Create many teacher or student accounts'],
    ['migration', 'Data Migration', 'file-earmark-arrow-up', ['registration','accounting','users.manage'], 'Administration', 'Reviewed historical imports'],
    ['importall', 'Import All', 'cloud-upload', ['curriculum.edit'], 'Administration', 'Subjects, fees, teachers and curriculum'],
    ['settings', 'Settings', 'gear', ['settings.manage'], 'Administration', 'Assessment & receipt printing'],
    ['docrequirements', 'Document Requirements', 'list-check', ['settings.manage'], 'Administration', 'Registration document checklist'],
    ['activity', 'Activity Log', 'clock-history', ['activity'], 'Administration', 'Who did what'],
    ['help', 'Help', 'question-circle', [], '', 'How to use the SIS'],
];

/** v58: the Finance system's menu (finance/index.php). Same item format as NAV. */
const FINANCE_NAV = [
    ['dashboard', 'Overview', 'speedometer2', [], '', 'Revenue by source and what needs attention'],
    ['notifications', 'Notifications', 'bell', ['admission', 'registration', 'payments.view', 'accounting'], '', 'Special-request approvals'],
    ['cashier', 'Cashier', 'cash-coin', ['payments.view'], 'Cashier', 'Payments and receipts'],
    ['docfees', 'Document Fees', 'file-earmark-text', ['payments.view'], 'Cashier', 'TOR and certificate requests to pay'],
    ['libfines', 'Library Fines', 'book', ['payments.view'], 'Cashier', 'Overdue, lost and damage fines to pay'],
    ['orseries', 'O.R. Series', '123', ['settings.manage'], 'Cashier', 'Official receipt numbering'],
    ['balance', 'Existing Balances', 'wallet2', ['registration', 'accounting', 'balances.view'], 'Balances', 'Previous-semester balance and approvals'],
    ['accounting&tab=overview', 'Accounting overview', 'speedometer2', ['accounting'], 'Accounting', 'What needs attention'],
    ['accounting&tab=review', 'Review and Post', 'clipboard-check', ['accounting'], 'Accounting', 'Approve transactions'],
    ['accounting&tab=transactions', 'Expenses and Refunds', 'receipt', ['accounting'], 'Accounting', 'Reviewed financial transactions'],
    ['accounting&tab=ledger', 'Ledger', 'journal-text', ['accounting'], 'Accounting', 'Journal entries'],
    ['accounting&tab=accounts', 'Chart of Accounts', 'list-columns', ['accounting'], 'Accounting', ''],
    ['accounting&tab=mappings', 'Account Mappings', 'diagram-2', ['accounting'], 'Accounting', ''],
    ['accounting&tab=reconcile', 'Bank Reconciliation', 'bank', ['accounting'], 'Accounting', ''],
    ['reports', 'Report Manager', 'printer', ['payments.view', 'accounting'], 'Reports', 'Cashier and accounting reports'],
];

/** v59: the Canteen POS menu (canteen/index.php). */
const CANTEEN_NAV = [
    ['dashboard', 'Cashier', 'shop', ['canteen.view'], '', 'Ring up orders'],
    ['sales', 'Sales & Receipts', 'receipt', ['canteen.view'], '', 'Find, re-print, void or refund'],
    ['shift', 'My Shift', 'clock-history', ['canteen.sell'], '', 'Open, count and close your drawer'],
    ['approvals', 'Approvals', 'check2-square', ['canteen.supervise'], 'Supervisor', 'Voids and refunds to decide'],
    ['shifts', 'Shifts & Cash Counts', 'cash-stack', ['canteen.supervise', 'canteen.reports'], 'Supervisor', 'Accept counted drawers'],
    ['products', 'Products', 'box-seam', ['canteen.products'], 'Inventory', 'Items, prices, barcodes'],
    ['inventory', 'Stock', 'boxes', ['canteen.inventory'], 'Inventory', 'Receive, prepare, adjust, stocktake'],
    ['reports', 'Sales Reports', 'graph-up', ['canteen.reports'], 'Reports', 'By day, item and cashier'],
    ['settings', 'Canteen Settings', 'gear', ['canteen.products.edit'], 'Reports', 'QR code and receipt'],
];

/** v60: the Library menu (library/index.php). */
const LIBRARY_NAV = [
    ['dashboard', 'Overview', 'house-door', ['library.view', 'library.catalog', 'library.reports'], '', 'Today at the library'],
    ['catalog', 'Catalog', 'book', ['library.catalog', 'library.view'], '', 'Titles, copies, acquisitions, stocktake, labels'],
    ['circulation', 'Circulation', 'arrow-left-right', ['library.view'], '', 'Check out, check in, renew'],
    ['members', 'Members', 'people', ['library.view'], '', 'Students and teachers who borrow'],
    ['holds', 'Reservations', 'bookmark', ['library.view'], '', 'Holds and the hold shelf'],
    ['fines', 'Fines', 'coin', ['library.view'], '', 'Charged, paid and waived'],
    ['reports', 'Reports', 'bar-chart', ['library.reports'], '', 'Circulation and fines'],
    ['settings', 'Settings', 'gear', ['library.settings', 'library.reports'], '', 'Loan rules'],
];

/** v61: the LMS menu (lms/index.php). */
const LMS_NAV = [
    ['dashboard', 'Dashboard', 'house-door', ['lms.view'], '', 'Today at a glance'],
    ['classes', 'My Classes', 'people', ['lms.view'], '', 'Your classes'],
    ['schedule', 'Schedule', 'calendar3', ['lms.view'], '', 'Your week of classes'],
    ['attendance', 'Attendance', 'calendar2-check', ['lms.view'], '', 'Take and review attendance'],
    ['assignments', 'Assignments', 'journal-text', ['lms.view'], '', 'Assignments, quizzes and exams'],
    ['gradebook', 'Gradebook', 'bar-chart-line', ['lms.view'], '', 'Class grades'],
    ['materials', 'Materials', 'folder2', ['lms.view'], '', 'Files shared in your classes'],
    ['messages', 'Messages', 'chat-square-text', ['lms.view'], '', 'Private messages between teachers and students'],
    ['reports', 'Reports', 'pie-chart', ['lms.view'], '', 'Class summaries (teachers)'],
    ['settings', 'Settings', 'gear', ['lms.view'], '', 'Grading categories of your classes (teachers)'],
];

/** v61.2: the phone/tablet bottom menu of the LMS and the Canteen POS ("More" opens the side menu). */
function app_bottom_nav(): void
{
    global $route;
    $sys = app_system();
    if (!in_array($sys, ['LMS', 'CANTEEN'], true)) return;
    $nav = []; foreach (app_nav() as [$r, $label, $icon, $perms]) if (!$perms || Auth::can(...$perms)) $nav[$r] = [$label, $icon];
    $item = fn(string $r, ?string $label = null, ?string $icon = null, array $also = [], int $badge = 0) => ['href' => url($r), 'label' => $label ?? $nav[$r][0], 'icon' => $icon ?? $nav[$r][1], 'active' => $route === $r || in_array($route, $also, true), 'badge' => $badge];
    $more = ['label' => 'More', 'icon' => 'grid', 'sheet' => 'sidebar', 'active' => false];
    if ($sys === 'LMS') {
        if (!$nav) return;
        bottom_nav([$item('dashboard', 'Home', 'house-door'), $item('classes', 'Classes', 'people', ['class', 'work'])],
            $item('messages', 'Messages', 'chat-square-text', [], function_exists('lms_unread') ? lms_unread() : 0),
            [$item('assignments', 'Tasks', 'journal-text', ['todo']), $more], 'LMS menu');
        return;
    }
    $pick = array_values(array_filter(['sales', 'shift', 'products', 'reports', 'approvals', 'inventory', 'shifts', 'settings'], fn($r) => isset($nav[$r])));
    $short = ['sales' => 'Sales', 'shift' => 'Shift', 'products' => 'Products', 'reports' => 'Reports', 'approvals' => 'Approvals', 'inventory' => 'Stock', 'shifts' => 'Shifts', 'settings' => 'Settings'];
    $center = isset($nav['dashboard']) ? $item('dashboard', 'Cashier', 'shop') : ($pick ? $item(array_shift($pick), null, null) : null);
    if (!$center) return;
    $side = array_map(fn($r) => $item($r, $short[$r]), array_slice($pick, 0, 3));
    bottom_nav(array_slice($side, 0, 2), $center, array_merge(array_slice($side, 2, 1), [$more]), 'Canteen menu');
}

/** The menu of the system this page belongs to. */
function app_nav(): array
{
    return ['FINANCE' => FINANCE_NAV, 'CANTEEN' => CANTEEN_NAV, 'LIBRARY' => LIBRARY_NAV, 'LMS' => LMS_NAV][app_system()] ?? NAV;
}

/** The name shown under the school's name in the top bar and side rail. */
function app_title(): string
{
    return ['FINANCE' => 'Finance', 'CANTEEN' => 'Canteen POS', 'LIBRARY' => 'Library', 'LMS' => 'Learning Management System', 'PORTAL' => 'Student Portal'][app_system()] ?? 'School Information System';
}

/** Icon shown beside each sidebar group heading. */
const NAV_GROUP_ICONS = ['Enrollment' => 'clipboard-check', 'Records' => 'folder2-open', 'Assessment' => 'calculator', 'Fees' => 'cash-stack', 'Accounting' => 'journal-bookmark', 'Administration' => 'gear-wide-connected', 'Finance' => 'wallet2', 'Cashier' => 'cash-coin', 'Balances' => 'wallet2', 'Reports' => 'printer', 'Supervisor' => 'person-check', 'Inventory' => 'box-seam'];

/** The sidebar group of the current route, used by the breadcrumb ("Home › Enrollment › Admission"). */
function nav_group_of(string $route): string
{
    if ($route === 'student') $route = 'students';
    foreach (app_nav() as [$r, , , , $g]) if (explode('&', $r, 2)[0] === $route) return $g;
    return '';
}

/** The school year shown in the top bar: the latest one in the grading system. */
function current_school_year(): string
{
    try { $sy = GS::schoolYears()[0] ?? ''; } catch (Throwable) { $sy = ''; }
    return $sy;
}

/** The color theme: "portal" (purple) for students and the Student Portal sign-in, "sis" (green) for staff. */
function sis_system(): string
{
    $u = Auth::user();
    if ($u) return $u['role'] === 'STUDENT' ? 'portal' : 'sis';
    return ($_GET['portal'] ?? '') === 'student' ? 'portal' : 'sis';
}

/** Can this user use the search box at the top? */
function can_search(): bool
{
    return Auth::can('students', 'admission', 'registration', 'payments.view');
}

function layout_start(string $title, bool $bare = false): void
{
    if (defined('PORTAL_ROOT')) { portal_layout_start($title, $bare); return; } // Student Portal: its own orange layout
    global $route, $LAYOUT_SIDEBAR;
    $school = School::name();
    $u = Auth::user();
    ?><!doctype html>
<?php $system = sis_system(); ?>
<html lang="en" data-role="sis" data-system="<?= $system ?>" data-app="<?= strtolower(app_system()) ?>"><!-- data-role: the grading stylesheet shows the page once set; data-system: the color theme -->
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= $system === 'portal' ? '#2b1866' : '#1d6b47' ?>">
<title><?= h($title) ?> · <?= h($school) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="../icons/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&amp;family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&amp;display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="../css/style.css"><!-- the grading system's own theme: both systems look the same -->
<link rel="stylesheet" href="<?= sis_web() ?>assets/app.css?v=56b">
<link rel="stylesheet" href="<?= sis_web() ?>assets/activity.css?v=31">
<link rel="stylesheet" href="<?= sis_web() ?>assets/navigation.css?v=31">
<link rel="stylesheet" href="<?= sis_web() ?>assets/reference-ui.css?v=20261021portal">
<link rel="stylesheet" href="<?= sis_web() ?>assets/record-list.css?v=20261020b">
<link rel="stylesheet" href="<?= sis_web() ?>assets/fixed-shell.css?v=20261020j">
<link rel="stylesheet" href="<?= sis_web() ?>assets/accounting-ui.css?v=20261021toolbar">
<link rel="stylesheet" href="<?= sis_web() ?>assets/sis-actions.css?v=20261021plans">
<link rel="stylesheet" href="<?= sis_web() ?>assets/compact-workspace.css?v=20261025toolbar11">
<link rel="stylesheet" href="<?= sis_web() ?>assets/theme.css?v=53">
<link rel="stylesheet" href="<?= sis_web() ?>assets/people.css?v=62b">
<link rel="stylesheet" href="<?= sis_web() ?>assets/unified-toolbar.css?v=60">
<link rel="stylesheet" href="../css/standard-toolbar.css?v=57">
<link rel="stylesheet" href="<?= sis_web() ?>assets/registration-fees.css?v=4">
<link rel="stylesheet" href="<?= sis_web() ?>assets/assessment-fee-templates.css?v=1">
<?php if (app_system() === 'CANTEEN'): ?><link rel="stylesheet" href="assets/canteen.css?v=59b"><?php endif; ?>
<?php if (app_system() === 'LIBRARY'): ?><link rel="stylesheet" href="assets/library.css?v=61c"><?php endif; ?>
<?php if (app_system() === 'LMS'): ?><link rel="stylesheet" href="assets/lms.css?v=61.2"><?php endif; ?>
<?php if (in_array(app_system(), ['LMS', 'CANTEEN', 'LIBRARY'], true)): ?><link rel="stylesheet" href="<?= sis_web() ?>assets/bottom-nav.css?v=62c"><?php endif; ?>
<?php if ($route === 'assessment' && ($_GET['tab'] ?? '') === 'names'): ?><script src="<?= sis_web() ?>assets/fee-templates.js?v=1" defer></script><?php endif; ?>
<?php if ($route === 'documents' && ($_GET['tab'] ?? '') === 'settings'): ?><script src="<?= sis_web() ?>assets/documents-template-preview.js?v=1" defer></script><?php endif; ?>
<?php if ($route === 'admission'): ?><link rel="stylesheet" href="<?= sis_web() ?>assets/admission-form.css?v=1"><?php endif; ?>
<?php if ($system === 'sis' && !$bare && $u): ?><link rel="stylesheet" href="<?= sis_web() ?>assets/easy-navigation.css?v=6"><link rel="stylesheet" href="<?= sis_web() ?>assets/dashboard.css?v=2"><?php endif; ?>
</head>
<?php $embedFlashes = $bare && isset($_GET['embed']) ? take_flashes() : []; // pop-up pages show their own messages ?>
<body data-client-scope="<?= h($u ? acadlink_client_scope(Auth::central()) : '') ?>" class="<?= $bare ? 'bare' : ('sis-theme' . ($system === 'sis' && $u ? ' sis-easy' : '')) ?>" data-user-id="<?= h((string) ($u['id'] ?? 'guest')) ?>" data-route="<?= h((string) $route) ?>"<?= array_filter($embedFlashes, fn($f) => $f[0] === 'success') ? ' data-changed="1"' : '' ?>>
<?php if ($embedFlashes): ?><div class="embed-wrap pb-0"><?php foreach ($embedFlashes as [$type, $msg]): ?><div class="alert alert-<?= h($type) ?> py-2 mb-0" style="white-space:pre-line" role="alert"><?= h($msg) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php $LAYOUT_SIDEBAR = !$bare && $u; if ($LAYOUT_SIDEBAR):
    $logo = School::logo();
    $initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim((string) $u['full_name'])) ?: [], 0, 2))));
    // Unread notifications for the bell and the menu (only for people who can open Notifications)
    $unread = 0; if (Auth::can(...ROUTES['notifications'][1])) { try { $unread = NotificationCenter::unreadCount(); } catch (Throwable) { $unread = 0; } } ?>
<?php if ($system === 'sis'): ?><a class="sis-skip-link" href="#main">Skip to content</a><?php endif; ?>
<header class="sis-appbar d-print-none" aria-label="School workspace">
  <button class="sis-appbar-menu d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu"><i class="bi bi-list" aria-hidden="true"></i></button>
  <a href="<?= url('dashboard') ?>" class="sis-appbar-brand"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""><span class="sis-brand-text"><span class="sis-brand-name"><?= h(School::brand()) ?></span><span class="sis-brand-sub"><?= $system === 'portal' ? 'Student Portal' : h(app_title()) ?></span></span></a>
  <?php if (app_system() === 'CANTEEN'): ?><div class="cpos-appbar-title d-none d-md-flex"><i class="bi bi-shop" aria-hidden="true"></i>Canteen POS</div><?php endif; ?>
  <?php if (app_system() === 'LMS'): ?><div class="lms-appbar-title d-none d-md-flex"><?= h(School::brand()) ?> LMS</div><?php endif; ?>
  <?php if (app_system() === 'LIBRARY'): ?><div class="lib-appbar-title d-none d-md-flex"><i class="bi bi-book-half" aria-hidden="true"></i>Library</div><?php endif; ?>
  <?php if ($system === 'sis'): ?><div class="sis-appbar-tools" id="sisAppbarTools" hidden></div><?php endif; ?>
  <?php if (can_search()): ?>
  <form class="sis-appbar-search d-none d-md-flex" method="get" role="search"><input type="hidden" name="r" value="search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="globalSearch" name="q" value="<?= h($route === 'search' ? (string) ($_GET['q'] ?? '') : '') ?>" placeholder="Find a student, applicant or receipt…" aria-label="Find a student, applicant, registration or receipt" aria-keyshortcuts="Control+K"><kbd class="sis-kbd" aria-hidden="true">Ctrl K</kbd></form>
  <?php endif; ?>
  <?php $barYears = []; try { if (!in_array(app_system(), ['CANTEEN', 'LIBRARY', 'LMS'], true)) $barYears = GS::schoolYears(); } catch (Throwable) {} $barYear = $barYears ? selected_sy() : ''; if ($barYear !== ''): ?>
  <div class="dropdown sis-appbar-year d-none d-sm-block">
    <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="School year <?= h($barYear) ?>: change"><i class="bi bi-calendar3" aria-hidden="true"></i><span class="sis-year-text"><small>School Year</small><b><?= h(str_replace('-', ' - ', $barYear)) ?></b></span></button>
    <div class="dropdown-menu dropdown-menu-end"><h6 class="dropdown-header">Show records for</h6>
      <?php foreach ($barYears as $y): $q = $_GET; $q['sy'] = $y; unset($q['page']); ?><a class="dropdown-item<?= $y === $barYear ? ' active' : '' ?>" href="?<?= h(http_build_query($q)) ?>"<?= $y === $barYear ? ' aria-current="true"' : '' ?>><?= h($y) ?></a><?php endforeach; ?>
    </div>
  </div><?php endif; ?>
  <div class="dropdown sis-appbar-user">
    <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><span class="sis-user-avatar" aria-hidden="true"><?= h($initials) ?></span><span class="sis-user-text d-none d-sm-flex"><b class="text-truncate"><?= h($u['full_name']) ?></b><small class="text-truncate"><?= h($u['role_name']) ?></small></span><span class="visually-hidden">Account menu for <?= h($u['full_name']) ?></span></button>
    <div class="dropdown-menu dropdown-menu-end">
      <div class="sis-appbar-who"><span class="user-avatar" aria-hidden="true"><?= h($initials) ?></span><span class="min-w-0"><strong class="d-block text-truncate"><?= h($u['full_name']) ?></strong><small class="d-block text-truncate"><?= h($school) ?></small></span></div>
      <a class="dropdown-item" href="<?= url('workspaces') ?>"><i class="bi bi-grid" aria-hidden="true"></i>Switch system</a>
      <?php if (can_search()): ?><a class="dropdown-item d-md-none" href="<?= url('search') ?>"><i class="bi bi-search" aria-hidden="true"></i>Search</a><?php endif; ?>
      <a class="dropdown-item" href="<?= url('profile') ?>"><i class="bi bi-person-gear" aria-hidden="true"></i>My profile &amp; e-signature</a>
      <a class="dropdown-item" href="<?= url('password') ?>"><i class="bi bi-key" aria-hidden="true"></i>Change password</a>
      <form method="post" action="<?= url('logout') ?>" class="m-0"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Sign out</button></form>
    </div>
  </div>
  <?php if (in_array(app_system(), ['LMS', 'CANTEEN', 'LIBRARY'], true)): $mine = Notify::unread(); // v62: personal notifications ?><a class="sis-appbar-bell" href="<?= url('alerts') ?>" aria-label="Notifications<?= $mine ? ': ' . $mine . ' unread' : '' ?>"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($mine): ?><span class="sis-dot" aria-hidden="true"></span><?php endif; ?></a>
  <?php elseif (Auth::can(...ROUTES['notifications'][1])): ?><a class="sis-appbar-bell" href="<?= url('notifications') ?>" aria-label="Notifications<?= $unread ? ': ' . $unread . ' unread' : '' ?>"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($unread): ?><span class="sis-dot" aria-hidden="true"></span><?php endif; ?></a><?php endif; ?>

</header>
<div class="app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar d-print-none" tabindex="-1" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-inner">
      <?php if (app_system() === 'LMS'): ?><a class="sis-rail-brand lms-rail-brand" href="<?= url('dashboard') ?>"><span class="lms-rail-mark" aria-hidden="true"><i class="bi bi-book-half"></i></span><span><strong><?= h(School::brand()) ?></strong><small>LMS</small></span></a>
      <?php elseif (app_system() === 'LIBRARY'): ?><a class="sis-rail-brand lib-rail-brand" href="<?= url('dashboard') ?>"><i class="bi bi-book-half" aria-hidden="true"></i><span><strong>AcadLink</strong><strong>Library</strong></span></a>
      <?php elseif ($system === 'sis'): ?><a class="sis-rail-brand" href="<?= url('dashboard') ?>"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""><span><strong><?= h(School::brand()) ?></strong><small><?= h(app_title()) ?></small></span></a><?php endif; ?>
      <div class="sis-sidebar-head d-lg-none"><span><?= h(School::brand()) ?> <?= $system === 'portal' ? 'Portal' : (['FINANCE' => 'Finance', 'CANTEEN' => 'Canteen', 'LIBRARY' => 'Library', 'LMS' => 'LMS'][app_system()] ?? 'SIS') ?></span><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button></div>
      <nav class="pb-2"><?php if(app_system()==='SIS' && in_array(Auth::role(),['TEACHER','STUDENT'],true)): ?><a class="side-link" href="<?= url('history',['tab'=>'grades']) ?>"><i class="bi bi-clock-history"></i><span>History of Grades</span></a><?php if(Auth::role()==='STUDENT'): ?><a class="side-link" href="<?= url('history',['tab'=>'assessments']) ?>"><i class="bi bi-receipt"></i><span>History of Assessments</span></a><?php endif; endif; ?>
      <?php if(app_system()==='SIS' && Auth::can('self')): ?><a class="side-link" href="<?= url(Auth::studentNumber()?'myenrollment':'myadmission') ?>"><i class="bi bi-person-plus"></i><span><?= Auth::studentNumber()?'Apply for Enrollment':'My admission application' ?></span></a><?php endif; ?>
      <?php $group = ''; $groupN = 0; $here = $route . (isset($_GET['tab']) && is_string($_GET['tab']) ? '&tab=' . $_GET['tab'] : '') . ($route === 'assessment' && isset($_GET['plan']) ? '&plan=list' : '');
      foreach (app_nav() as [$r, $label, $icon, $perms, $g, $hint]):
          if ($perms && !Auth::can(...$perms)) continue;
          if ($r === 'clearance' && !Clearance::on()) continue; // v55: hidden while online clearance is off
          if ($r === 'importall' && !ImportAll::can()) continue; // v56: needs both Curriculum and Assessment editing
          if (app_system() === 'LMS' && function_exists('lms_nav_allows') && !lms_nav_allows($r)) continue; // v61.1: Reports and Settings are for teachers
          [$rr, $qs] = array_pad(explode('&', $r, 2), 2, '');
          parse_str($qs, $params);
          $active = ($route === 'student' && $rr === 'students') || $here === $r || ($route === $rr && !$qs && !isset($_GET['tab'])) || ($route === 'assessment' && $rr === 'assessment' && !isset($_GET['tab']) && $r === 'assessment&tab=categories') || ($route === 'accounting' && !isset($_GET['tab']) && $r === 'accounting&tab=overview') || (app_system() === 'LIBRARY' && (['acquisitions' => 'catalog', 'stocktake' => 'catalog', 'labels' => 'catalog', 'loans' => 'circulation'][$route] ?? '') === $rr) || (app_system() === 'LMS' && (['class' => 'classes', 'work' => 'classes', 'todo' => 'assignments'][$route] ?? '') === $rr);
          if ($g !== $group) {
              if ($group !== '') echo '</div></div>';
              $group = $g;
              if ($g !== '') { $groupN++; echo '<div class="nav-group" data-nav-group="' . h($g) . '"><button type="button" class="nav-group-toggle" aria-expanded="true" aria-controls="navGroup' . $groupN . '"><i class="bi bi-' . (NAV_GROUP_ICONS[$g] ?? 'folder') . '" aria-hidden="true"></i><span>' . h($g) . '</span><i class="bi bi-chevron-up nav-group-chevron" aria-hidden="true"></i></button><div class="nav-group-items" id="navGroup' . $groupN . '">'; }
          } ?>
        <a class="side-link<?= $active ? ' active' : '' ?>" href="<?= url($rr, $params) ?>"<?= $active ? ' aria-current="page"' : '' ?><?= $hint !== '' ? ' title="' . h($hint) . '"' : '' ?>><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span><?= h($label) ?></span><?php if (app_system() === 'LMS' && $rr === 'messages' && function_exists('lms_unread') && ($lu = lms_unread())): ?><span class="sis-nav-badge" aria-label="<?= $lu ?> unread"><?= $lu > 99 ? '99+' : $lu ?></span><?php endif; ?><?php if ($rr === 'notifications' && $unread): ?><span class="sis-nav-badge" aria-label="<?= $unread ?> unread"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
      <?php endforeach; if ($group !== '') echo '</div></div>'; ?>
      </nav>
      <?php if ($system === 'sis'): ?><a class="sis-rail-profile" href="<?= url('profile') ?>"><span class="sis-user-avatar" aria-hidden="true"><?= h($initials) ?></span><span><strong><?= h($u['full_name']) ?></strong><small><?= h($u['role_name']) ?></small></span><i class="bi bi-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
    </div>
  </aside>
  <div class="app-main">
    <main class="app-content" id="main">
      <header class="sis-page-heading">
      <h1 class="sis-module-title"><?= h($title) ?></h1>
      <?php $crumbGroup = nav_group_of((string) $route); ?>
      <nav class="breadcrumbs d-print-none" aria-label="Breadcrumb"><a href="<?= url('dashboard') ?>"><i class="bi bi-house-door-fill" aria-hidden="true"></i>Home</a><?php if ($crumbGroup !== '' && $crumbGroup !== $title): ?><i class="bi bi-chevron-right" aria-hidden="true"></i><span><?= h($crumbGroup) ?></span><?php endif; ?><i class="bi bi-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= h($title) ?></span></nav>
      </header>
<?php elseif (!$bare): ?>
<main class="container py-4">
<?php endif; ?>
<?php if (!$bare && Auth::viewOnly((string) $route)): ?><span id="sisViewOnly" hidden></span><?php endif; ?>
<?php if (($oldInput = take_old_input()) !== null): ?><script type="application/json" id="sisOldInput"><?= $oldInput ?></script><?php endif; ?>
<?php if (!$bare) foreach (take_flashes() as [$type, $msg]): ?>
  <div class="alert alert-<?= h($type) ?> alert-dismissible fade show d-print-none" style="white-space:pre-line" role="alert"<?= $type === 'success' ? ' data-flash="success"' : '' ?>><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
<?php endforeach;
}

function layout_end(bool $bare = false): void
{
    if (defined('PORTAL_ROOT')) { portal_layout_end($bare); return; }
    global $route, $LAYOUT_SIDEBAR;
    if (!$bare) echo "    </main>\n";
    if ($LAYOUT_SIDEBAR) echo "  </div>\n</div>\n";
    if ($LAYOUT_SIDEBAR): // the pop-up used by buttons with data-frame="url" (New registration, View assessment)
    ?>
<div class="modal fade" id="frameModal" tabindex="-1" aria-labelledby="frameModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5 text-truncate" id="frameModalTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body p-0"><div class="frame-loading"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div><iframe title="" class="frame-modal-iframe"></iframe></div>
  </div></div>
</div>
<?php endif; ?>
<?php if ($LAYOUT_SIDEBAR) app_bottom_nav(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="<?= sis_web() ?>assets/navigation.js?v=61"></script>
<?php if (app_system() === 'LMS'): ?><script src="assets/lms.js?v=61.1" defer></script><?php endif; ?>
<script src="<?= sis_web() ?>assets/activity.js?v=31"></script>
<script src="<?= sis_web() ?>assets/record-list.js?v=48" defer></script>
<script src="<?= sis_web() ?>assets/fixed-shell.js?v=31" defer></script>
<script src="<?= sis_web() ?>assets/accounting-ui.js?v=31" defer></script>
<script src="<?= sis_web() ?>assets/app.js?v=62.1"></script>
<script src="<?= sis_web() ?>assets/friendly-ui.js?v=48"></script>
<script src="<?= sis_web() ?>assets/sis-actions.js?v=41"></script>
<script src="<?= sis_web() ?>assets/theme.js?v=52"></script>
<script src="../js/standard-toolbar-core.js?v=57"></script>
<script src="<?= sis_web() ?>assets/standard-toolbar.js?v=67"></script>
<?php if ($route === 'admission'): ?><script src="<?= sis_web() ?>assets/admission-form.js?v=1" defer></script><?php endif; ?>
<script src="<?= sis_web() ?>assets/eligibility.js?v=31"></script>
<script src="<?= sis_web() ?>assets/workspaces.js?v=31"></script>
<script src="<?= sis_web() ?>assets/success-sound.js?v=56"></script>
<script src="<?= sis_web() ?>assets/registration-fees.js?v=1"></script>
</body>
</html><?php
}

/**
 * One line of Filter Preferences: a tick box, its label and the controls. Unticked = the controls are
 * disabled (so they are not sent) and the filter is off. $locked = always on (e.g. the date range).
 */
function fp_row(string $label, string $controls, bool $on, bool $locked = false): string
{
    static $n = 0;
    $id = 'fpc' . (++$n);
    return '<div class="fp-row"><label class="fp-check" for="' . $id . '"><input class="form-check-input" type="checkbox" id="' . $id . '" data-fp'
        . ($on || $locked ? ' checked' : '') . ($locked ? ' disabled' : '') . '>' . h($label) . '</label><div class="fp-ctl">' . $controls . '</div></div>';
}

/** "Alternative Search Key" box in a grid toolbar: searches $param and keeps the other filters. */
function alt_search(string $route, array $keep, string $param, string $placeholder): string
{
    $hidden = '<input type="hidden" name="r" value="' . h($route) . '">';
    foreach ($keep as $k => $v) if ($k !== $param) $hidden .= '<input type="hidden" name="' . h((string) $k) . '" value="' . h((string) $v) . '">';
    return '<form class="tb-search" method="get" role="search">' . $hidden . '<input class="form-control form-control-sm" name="' . h($param) . '" value="' . h((string) ($keep[$param] ?? '')) . '" placeholder="' . h($placeholder) . '" aria-label="Alternative search key"><span class="tb-search-label">Alternative Search Key</span></form>';
}

/** Header strip above the right-side panels (not shown in the grading system's look). */
function sub_explorer(): string
{
    return '';
}

/** Page heading with optional right-side buttons (raw HTML). */
function page_title(string $title, string $actions = '', string $subtitle = ''): void
{
    if (defined('PORTAL_ROOT')) { portal_page_title($title, $actions, $subtitle); return; }
    $inShell = !empty($GLOBALS['LAYOUT_SIDEBAR']);
    if ($inShell && $actions === '' && $subtitle === '') { RecordList::panel(); return; }
    echo '<div class="page-head"><div class="min-w-0">';
    if (!$inShell) echo '<h1 class="page-title">' . h($title) . '</h1>';
    if ($subtitle !== '') echo '<p class="page-lede">' . $subtitle . '</p>';
    echo '</div>' . ($actions !== '' ? '<div class="d-flex flex-wrap gap-2 align-items-center">' . $actions . '</div>' : '') . '</div>';
    RecordList::panel();
}

/**
 * The enrollment steps 1 Admission → 2 Registration → 3 Cashier, with the current one highlighted.
 * Steps this user can't open are shown but not linked.
 */
function step_bar(int $current): string
{
    $steps = [
        1 => ['Admission', 'Record and admit the applicant', 'admission', ['admission']],
        2 => ['Registration', 'Section, subjects and fees', 'registration', ['registration', 'payments.view']],
        3 => ['Cashier', 'Receive payment, print receipt', 'cashier', ['payments.view']],
    ];
    $h = '<ol class="stepbar d-print-none" aria-label="Enrollment steps">';
    foreach ($steps as $n => [$t, $d, $r, $p]) {
        $cls = $n === $current ? 'current' : ($n < $current ? 'done' : '');
        $inner = '<span class="n">' . $n . '</span><span><b>' . h($t) . '</b><small>' . h($d) . '</small></span>';
        $h .= '<li class="' . $cls . '"' . ($n === $current ? ' aria-current="step"' : '') . '>' . (Auth::can(...$p) && $n !== $current ? '<a href="' . url($r) . '">' . $inner . '</a>' : '<div>' . $inner . '</div>') . '</li>';
    }
    return $h . '</ol>';
}

/** Fee labels as <option>s grouped by category: "Library Fee — fixed ₱300.00". */
function label_options(array $labels, ?int $selected = null): string
{
    $h = ''; $cat = null;
    foreach ($labels as $l) {
        if ($l['category_name'] !== $cat) { if ($cat !== null) $h .= '</optgroup>'; $cat = $l['category_name']; $h .= '<optgroup label="' . h($cat) . '">'; }
        $how = ['FIXED' => 'fixed', 'PER_UNIT' => 'per unit', 'PER_SUBJECT' => 'per subject'][$l['charge_type']] ?? '';
        $h .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === $selected ? ' selected' : '') . '>' . h($l['name'] . ' — ' . $how . ' ₱' . Money::fmt(Money::cents($l['default_amount']))) . '</option>';
    }
    return $cat !== null ? $h . '</optgroup>' : $h;
}

/** School year chosen with ?sy= (defaults to the latest one in the grading system). */
function selected_sy(): string
{
    $sy = is_string($_GET['sy'] ?? null) ? $_GET['sy'] : '';
    if ($sy !== '' && GS::validSchoolYear($sy)) return $sy;
    return GS::schoolYears()[0] ?? (date('n') >= 6 ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y'));
}

function sy_options(?string $selected, bool $blank = false): string
{
    $ys = GS::schoolYears();
    if ($selected && !in_array($selected, $ys, true)) array_unshift($ys, $selected);
    $h = $blank ? '<option value="">Any school year</option>' : '';
    foreach ($ys as $y) $h .= '<option' . ($y === $selected ? ' selected' : '') . '>' . h($y) . '</option>';
    return $h;
}

function list_options(array $values, ?string $selected, string $blank = ''): string
{
    $h = $blank !== '' ? '<option value="">' . h($blank) . '</option>' : '';
    foreach ($values as $v) $h .= '<option' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . h($v) . '</option>';
    return $h;
}

/** Small POST button form. */
/** Passed / Failed / Incomplete… as the grading system words it. */
function remark_badge(string $remarks): string
{
    $cls = ['Passed' => 'success', 'Failed' => 'danger', 'Conditional' => 'warning', 'Incomplete' => 'warning', 'Dropped' => 'secondary'][$remarks] ?? 'secondary';
    return $remarks === '' ? '' : '<span class="badge text-bg-' . $cls . '">' . h($remarks) . '</span>';
}

/** Final grades tagged complete by the registrar (grading system), one card each. */
function grades_html(array $completions, string $empty = 'No final grades have been tagged complete by the registrar yet.'): string
{
    if (!$completions) return '<p class="text-muted small mb-0">' . h($empty) . '</p>';
    $fmt = fn($n) => $n === null || $n === '' ? '—' : number_format((float) $n, 2);
    $out = '';
    foreach ($completions as $i => $c) {
        $gwa = $c['gwa'] ?? null;
        $final = ($c['finalLabel'] ?? '') !== '' ? ' · ' . h($c['finalLabel']) . ' <strong>' . $fmt($c['final'] ?? null) . '</strong>' : '';
        $at = is_array($c['completedAt'] ?? null) ? substr((string) ($c['completedAt']['__ts'] ?? ''), 0, 10) : '';
        $rows = '';
        foreach ($c['subjects'] ?? [] as $s) {
            $grade = !empty($s['mark']) ? h($s['mark']) : $fmt($s['finalGrade'] ?? null);
            $rows .= '<tr><td class="font-monospace">' . h($s['subjectCode'] ?? '') . '</td><td>' . h($s['subjectName'] ?? '') . '</td><td class="text-end">' . h($s['units'] ?? '') .
                '</td><td class="text-end fw-semibold">' . $grade . '</td><td>' . remark_badge((string) ($s['remarks'] ?? '')) . '</td></tr>';
        }
        foreach ($c['pending'] ?? [] as $s) {
            $rows .= '<tr class="text-muted"><td class="font-monospace">' . h($s['subjectCode'] ?? '') . '</td><td>' . h($s['subjectName'] ?? '') . '</td><td class="text-end">' . h($s['units'] ?? '') .
                '</td><td class="text-end">—</td><td><span class="badge text-bg-warning">Not graded</span></td></tr>';
        }
        $warn = !empty($c['incomplete']) ? ' <span class="badge text-bg-warning" title="Tagged complete although some grades were not graded or INC"><i class="bi bi-exclamation-triangle"></i> Incomplete grades</span>' : '';
        $out .= '<details class="border rounded p-2 mb-2"' . ($i === 0 ? ' open' : '') . '><summary class="d-flex flex-wrap align-items-center gap-2">' .
            '<span class="fw-semibold me-auto">' . h(GS::completionPeriod($c)) . ' <span class="text-muted fw-normal small">' . h(trim(($c['sectionName'] ?? '') . ' · ' . ($c['yearLevel'] ?? ''), ' ·')) . '</span></span>' .
            '<span class="small">GWA <strong>' . $fmt($gwa) . '</strong>' . $final . '</span> ' . remark_badge((string) ($c['remarks'] ?? '')) .
            ' <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> Complete</span>' . $warn . '</summary>' .
            '<div class="table-responsive mt-2"><table class="table table-sm small mb-1"><thead><tr><th>Code</th><th>Subject</th><th class="text-end">Units</th><th class="text-end">Final grade</th><th>Remarks</th></tr></thead><tbody>' . $rows .
            '<tr class="fw-semibold"><td colspan="2">Total units</td><td class="text-end">' . h($c['totalUnits'] ?? '') . '</td><td colspan="2"></td></tr></tbody></table></div>' .
            '<div class="small text-muted">Tagged complete' . ($at ? ' on ' . h($at) : '') . (!empty($c['completedByName']) ? ' by ' . h($c['completedByName']) : '') . ' (Registrar, grading system).'.(!empty($c['migrationBatch'])?' · Imported historical record':'').'</div></details>';
    }
    return $out;
}

function post_button(string $route, array $params, array $fields, string $label, string $class = 'btn-sm btn-primary', string $confirm = ''): string
{
    $h = '<form method="post" action="' . h(url($route, $params)) . '" class="d-inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return $h . '<button class="btn ' . h($class) . '">' . $label . '</button></form>';
}
