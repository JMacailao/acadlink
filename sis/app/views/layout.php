<?php
/** Page frame: head, permission-filtered sidebar, flash messages. */
declare(strict_types=1);
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
    ['teachers', 'Teachers', 'person-video3', ['teachers'], 'Records', ''],
    ['sections', 'Sections', 'collection', ['sections'], 'Records', ''],
    ['yearlevels', 'Year Levels', 'bar-chart-steps', ['yearlevels'], 'Records', ''],
    ['curriculum', 'Curriculum', 'list-check', ['curriculum'], 'Records', 'Subjects per term'],
    ['history&tab=grades', 'History of Grades', 'clock-history', ['students', 'registration'], 'Records', 'Permanent grade records'],
    ['history&tab=assessments', 'History of Assessments', 'receipt', ['registration', 'accounting', 'payments.view'], 'Records', 'Permanent assessment records'],
    ['documents', 'TOR & Certificates', 'file-earmark-text', ['documents'], 'Records', 'Issue, sign, reprint and void'],
    ['schedules', 'Class Schedules', 'calendar-week', ['schedules'], 'Records', 'Days, times, rooms and teachers'],
    ['cashier', 'Cashier', 'cash-coin', ['payments.view'], '', 'Payments and receipts'],
    ['docfees', 'Document Fees', 'file-earmark-text', ['payments.view'], 'Finance', 'TOR and certificate requests to pay'],
    ['balance', 'Existing Balances', 'wallet2', ['registration', 'accounting', 'self', 'balances.view'], 'Finance', 'Previous-semester balance and approvals'],
    ['assessment&tab=categories', 'Fee categories', 'tags', ['assessment'], 'Finance', 'Fee groups'],
    ['assessment&tab=labels', 'Fee labels', 'tag', ['assessment'], 'Finance', 'Fee lines'],
    ['assessment&tab=names', 'Fee Templates', 'card-list', ['assessment'], 'Finance', 'Fee templates'],
    ['subjectfees', 'Subject Fees', 'journal-plus', ['assessment'], 'Finance', 'Fees of particular subjects, per unit'],
    ['discounts', 'Scholarships & Discounts', 'award', ['assessment', 'discounts.manage'], 'Finance', 'Less on the fees'],
    ['assessment&tab=labels&plan=list', 'Payment Setup', 'calendar3', ['assessment'], 'Finance', 'Installments, due dates and full-payment discount'],
    ['accounting&tab=overview', 'Accounting overview', 'speedometer2', ['accounting'], 'Finance', 'What needs attention'],
    ['accounting&tab=review', 'Review and Post', 'clipboard-check', ['accounting'], 'Finance', 'Approve transactions'],
    ['accounting&tab=transactions', 'Expenses and Refunds', 'receipt', ['accounting'], 'Finance', 'Reviewed financial transactions'],
    ['accounting&tab=ledger', 'Ledger', 'journal-text', ['accounting'], 'Finance', 'Journal entries'],
    ['accounting&tab=accounts', 'Chart of Accounts', 'list-columns', ['accounting'], 'Finance', ''],
    ['accounting&tab=mappings', 'Account Mappings', 'diagram-2', ['accounting'], 'Finance', ''],
    ['accounting&tab=reconcile', 'Bank Reconciliation', 'bank', ['accounting'], 'Finance', ''],
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

/** Icon shown beside each sidebar group heading. */
const NAV_GROUP_ICONS = ['Enrollment' => 'clipboard-check', 'Records' => 'folder2-open', 'Assessment' => 'calculator', 'Accounting' => 'journal-bookmark', 'Administration' => 'gear-wide-connected', 'Finance' => 'wallet2'];

/** The sidebar group of the current route, used by the breadcrumb ("Home › Enrollment › Admission"). */
function nav_group_of(string $route): string
{
    if ($route === 'student') $route = 'students';
    foreach (NAV as [$r, , , , $g]) if (explode('&', $r, 2)[0] === $route) return $g;
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
<html lang="en" data-role="sis" data-system="<?= $system ?>"><!-- data-role: the grading stylesheet shows the page once set; data-system: the color theme -->
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
<link rel="stylesheet" href="assets/app.css?v=56b">
<link rel="stylesheet" href="assets/activity.css?v=31">
<link rel="stylesheet" href="assets/navigation.css?v=31">
<link rel="stylesheet" href="assets/reference-ui.css?v=20261021portal">
<link rel="stylesheet" href="assets/record-list.css?v=20261020b">
<link rel="stylesheet" href="assets/fixed-shell.css?v=20261020j">
<link rel="stylesheet" href="assets/accounting-ui.css?v=20261021toolbar">
<link rel="stylesheet" href="assets/sis-actions.css?v=20261021plans">
<link rel="stylesheet" href="assets/compact-workspace.css?v=20261025toolbar11">
<link rel="stylesheet" href="assets/theme.css?v=53">
<link rel="stylesheet" href="assets/unified-toolbar.css?v=57">
<link rel="stylesheet" href="../css/standard-toolbar.css?v=57">
<?php if ($system === 'sis' && !$bare && $u): ?><link rel="stylesheet" href="assets/easy-navigation.css?v=5"><link rel="stylesheet" href="assets/dashboard.css?v=2"><?php endif; ?>
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
  <a href="<?= url('dashboard') ?>" class="sis-appbar-brand"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""><span class="sis-brand-text"><span class="sis-brand-name"><?= h(School::brand()) ?></span><span class="sis-brand-sub"><?= $system === 'portal' ? 'Student Portal' : 'School Information System' ?></span></span></a>
  <?php if ($system === 'sis'): ?><div class="sis-appbar-tools" id="sisAppbarTools" hidden></div><?php endif; ?>
  <?php if (can_search()): ?>
  <form class="sis-appbar-search d-none d-md-flex" method="get" role="search"><input type="hidden" name="r" value="search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="globalSearch" name="q" value="<?= h($route === 'search' ? (string) ($_GET['q'] ?? '') : '') ?>" placeholder="Find a student, applicant or receipt…" aria-label="Find a student, applicant, registration or receipt" aria-keyshortcuts="Control+K"><kbd class="sis-kbd" aria-hidden="true">Ctrl K</kbd></form>
  <?php endif; ?>
  <?php $barYears = []; try { $barYears = GS::schoolYears(); } catch (Throwable) {} $barYear = $barYears ? selected_sy() : ''; if ($barYear !== ''): ?>
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
  <?php if (Auth::can(...ROUTES['notifications'][1])): ?><a class="sis-appbar-bell" href="<?= url('notifications') ?>" aria-label="Notifications<?= $unread ? ': ' . $unread . ' unread' : '' ?>"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($unread): ?><span class="sis-dot" aria-hidden="true"></span><?php endif; ?></a><?php endif; ?>

</header>
<div class="app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar d-print-none" tabindex="-1" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-inner">
      <?php if ($system === 'sis'): ?><a class="sis-rail-brand" href="<?= url('dashboard') ?>"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""><span><strong><?= h(School::brand()) ?></strong><small>School Information System</small></span></a><?php endif; ?>
      <div class="sis-sidebar-head d-lg-none"><span><?= h(School::brand()) ?> <?= $system === 'portal' ? 'Portal' : 'SIS' ?></span><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button></div>
      <nav class="pb-2"><?php if(in_array(Auth::role(),['TEACHER','STUDENT'],true)): ?><a class="side-link" href="<?= url('history',['tab'=>'grades']) ?>"><i class="bi bi-clock-history"></i><span>History of Grades</span></a><?php if(Auth::role()==='STUDENT'): ?><a class="side-link" href="<?= url('history',['tab'=>'assessments']) ?>"><i class="bi bi-receipt"></i><span>History of Assessments</span></a><?php endif; endif; ?>
      <?php if(Auth::can('self')): ?><a class="side-link" href="<?= url(Auth::studentNumber()?'myenrollment':'myadmission') ?>"><i class="bi bi-person-plus"></i><span><?= Auth::studentNumber()?'Apply for Enrollment':'My admission application' ?></span></a><?php endif; ?>
      <?php $group = ''; $groupN = 0; $here = $route . (isset($_GET['tab']) && is_string($_GET['tab']) ? '&tab=' . $_GET['tab'] : '') . ($route === 'assessment' && isset($_GET['plan']) ? '&plan=list' : '');
      foreach (NAV as [$r, $label, $icon, $perms, $g, $hint]):
          if ($perms && !Auth::can(...$perms)) continue;
          if ($r === 'clearance' && !Clearance::on()) continue; // v55: hidden while online clearance is off
          if ($r === 'importall' && !ImportAll::can()) continue; // v56: needs both Curriculum and Assessment editing
          [$rr, $qs] = array_pad(explode('&', $r, 2), 2, '');
          parse_str($qs, $params);
          $active = ($route === 'student' && $rr === 'students') || $here === $r || ($route === $rr && !$qs && !isset($_GET['tab'])) || ($route === 'assessment' && $rr === 'assessment' && !isset($_GET['tab']) && $r === 'assessment&tab=categories') || ($route === 'accounting' && !isset($_GET['tab']) && $r === 'accounting&tab=overview');
          if ($g !== $group) {
              if ($group !== '') echo '</div></div>';
              $group = $g;
              if ($g !== '') { $groupN++; echo '<div class="nav-group" data-nav-group="' . h($g) . '"><button type="button" class="nav-group-toggle" aria-expanded="true" aria-controls="navGroup' . $groupN . '"><i class="bi bi-' . (NAV_GROUP_ICONS[$g] ?? 'folder') . '" aria-hidden="true"></i><span>' . h($g) . '</span><i class="bi bi-chevron-up nav-group-chevron" aria-hidden="true"></i></button><div class="nav-group-items" id="navGroup' . $groupN . '">'; }
          } ?>
        <a class="side-link<?= $active ? ' active' : '' ?>" href="<?= url($rr, $params) ?>"<?= $active ? ' aria-current="page"' : '' ?><?= $hint !== '' ? ' title="' . h($hint) . '"' : '' ?>><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span><?= h($label) ?></span><?php if ($rr === 'notifications' && $unread): ?><span class="sis-nav-badge" aria-label="<?= $unread ?> unread"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?></a>
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
    global $LAYOUT_SIDEBAR;
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="assets/navigation.js?v=55"></script>
<script src="assets/activity.js?v=31"></script>
<script src="assets/record-list.js?v=48" defer></script>
<script src="assets/fixed-shell.js?v=31" defer></script>
<script src="assets/accounting-ui.js?v=31" defer></script>
<script src="assets/app.js?v=41"></script>
<script src="assets/friendly-ui.js?v=48"></script>
<script src="assets/sis-actions.js?v=39"></script>
<script src="assets/theme.js?v=51"></script>
<script src="../js/standard-toolbar-core.js?v=57"></script>
<script src="assets/standard-toolbar.js?v=61"></script>
<script src="assets/eligibility.js?v=31"></script>
<script src="assets/workspaces.js?v=31"></script>
<script src="assets/success-sound.js?v=56"></script>
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
