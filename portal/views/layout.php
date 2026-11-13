<?php
declare(strict_types=1);
/**
 * Student Portal layout (orange): a top bar with the school, the student's menu, Switch system and Sign out.
 * The SIS's layout_start()/page_title()/layout_end() hand over to these when PORTAL_ROOT is defined,
 * so the same page code works in both places.
 */

function portal_layout_start(string $title, bool $bare = false): void
{
    global $route;
    $u = Auth::user();
    $logo = School::logo();
    $systems = [];
    try { $a = Auth::central()->current(); if ($a) $systems = ModuleAccess::systems(Auth::central()->permissions((int) $a['id'])); } catch (Throwable) {}
    $menu = [
        ['my', 'Home', 'house-door', []],
        [Auth::studentNumber() ? 'myenrollment' : 'myadmission', Auth::studentNumber() ? 'Enrollment' : 'Admission', 'person-plus', []],
        ['history', 'Grades', 'mortarboard', ['tab' => 'grades']],
        ['history', 'Assessments', 'receipt', ['tab' => 'assessments']],
    ];
    if (Auth::studentNumber()) array_splice($menu, 2, 0, [['schedule', 'Schedule', 'calendar-week', []]]); // v53
    if (Auth::studentNumber()) array_splice($menu, 4, 0, [['documents', 'Documents', 'file-earmark-text', []]]); // v52: request TOR and certificates
    if (Auth::studentNumber() && Clearance::on()) array_splice($menu, 5, 0, [['clearance', 'Clearance', 'patch-check', []]]); // v55
    if (Auth::studentNumber()) $menu[] = ['library', 'Library', 'book', []]; // v60
    $tab = $_GET['tab'] ?? 'grades';
    $GLOBALS['PORTAL_MENU'] = $bare || !$u ? null : $menu; // v61.2: for the bottom menu on phones and tablets
    ?><!doctype html>
<html lang="en" data-system="portal">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#c2410c">
<title><?= h($title) ?> · Student Portal · <?= h(School::brand()) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="../icons/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700&amp;display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="../sis/assets/app.css?v=56b">
<link rel="stylesheet" href="../sis/assets/reference-ui.css?v=20261021portal">
<link rel="stylesheet" href="assets/portal.css?v=53">
<link rel="stylesheet" href="../sis/assets/bottom-nav.css?v=61.2b">
</head>
<body class="portal<?= $bare ? ' bare' : '' ?>" data-route="<?= h((string) $route) ?>">
<?php if (!$bare && $u): ?>
<header class="portal-bar d-print-none">
  <div class="portal-bar-inner">
    <a class="portal-brand" href="<?= url('my') ?>"><span class="portal-logo"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""></span>
      <span class="portal-brand-text"><span class="portal-school"><?= h(School::brand()) ?></span><span class="portal-product">Student Portal</span></span></a>
    <div class="dropdown portal-user">
      <button class="portal-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="portal-avatar" aria-hidden="true"><?= h(strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s,]+/', trim((string) $u['full_name'])) ?: [], 0, 2))))) ?></span>
        <span class="portal-user-name d-none d-sm-inline"><?= h($u['full_name']) ?></span></button>
      <div class="dropdown-menu dropdown-menu-end">
        <div class="px-3 py-2 small"><strong class="d-block"><?= h($u['full_name']) ?></strong><?= Auth::studentNumber() ? 'Student ID ' . h((string) Auth::studentNumber()) : 'Applicant' ?></div>
        <div class="dropdown-divider"></div>
        <h6 class="dropdown-header">Switch system</h6>
        <?php foreach ($systems as $sys): if ($sys === 'PORTAL') continue; ?>
          <a class="dropdown-item" href="<?= h(['GRADING' => '../pages/dashboard.html', 'SIS' => '../sis/index.php?r=dashboard', 'FINANCE' => '../finance/index.php', 'LMS' => '../lms/index.php', 'CANTEEN' => '../canteen/index.php', 'LIBRARY' => '../library/index.php'][$sys] ?? '#') ?>"><i class="bi bi-<?= ['GRADING' => 'journal-check', 'SIS' => 'building', 'FINANCE' => 'cash-coin', 'LMS' => 'laptop', 'CANTEEN' => 'shop', 'LIBRARY' => 'book'][$sys] ?? 'grid' ?> me-2"></i><?= h(ModuleAccess::SYSTEMS[$sys] ?? $sys) ?></a>
        <?php endforeach; ?>
        <a class="dropdown-item" href="../sis/index.php?r=workspaces"><i class="bi bi-grid-3x3-gap me-2"></i>All systems</a>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?= url('profile') ?>"><i class="bi bi-person-gear me-2"></i>My profile</a>
        <a class="dropdown-item" href="<?= url('password') ?>"><i class="bi bi-key me-2"></i>Change password</a>
        <form method="post" action="<?= url('logout') ?>" class="m-0"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form>
      </div>
    </div>
  </div>
  <nav class="portal-nav" aria-label="Student Portal">
    <?php foreach ($menu as [$r, $label, $icon, $params]):
      $active = $route === $r && ($r !== 'history' || ($params['tab'] ?? '') === $tab); ?>
      <a class="portal-nav-link<?= $active ? ' active' : '' ?>" href="<?= url($r, $params) ?>"<?= $active ? ' aria-current="page"' : '' ?>><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i><span><?= h($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
</header>
<?php endif; ?>
<main class="portal-main<?= $bare ? ' portal-main-bare' : '' ?>" id="main">
<?php if (($oldInput = take_old_input()) !== null): ?><script type="application/json" id="sisOldInput"><?= $oldInput ?></script><?php endif; ?>
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="alert alert-<?= h($type) ?> alert-dismissible fade show d-print-none" style="white-space:pre-line" role="alert"<?= $type === 'success' ? ' data-flash="success"' : '' ?>><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
<?php endforeach;
}

function portal_page_title(string $title, string $actions = '', string $subtitle = ''): void
{
    echo '<div class="portal-head"><div class="min-w-0"><h1 class="portal-title">' . h($title) . '</h1>';
    if ($subtitle !== '') echo '<p class="portal-lede">' . $subtitle . '</p>';
    echo '</div>' . ($actions !== '' ? '<div class="d-flex flex-wrap gap-2 align-items-center">' . $actions . '</div>' : '') . '</div>';
}

/** v61.2: bottom menu for phones and tablets: Home, Schedule (or Enrollment), Grades in the middle, Assessments, More. */
function portal_bottom_nav(array $menu): void
{
    require_once SIS_ROOT . '/app/views/bottom-nav.php';
    global $route;
    $tab = $_GET['tab'] ?? 'grades';
    $items = [];
    foreach ($menu as [$r, $label, $icon, $params]) $items[$r . ($params['tab'] ?? '')] = ['href' => url($r, $params), 'label' => $label, 'icon' => $icon, 'active' => $route === $r && ($r !== 'history' || ($params['tab'] ?? '') === $tab)];
    $get = fn(string $k, array $o = []) => isset($items[$k]) ? $o + $items[$k] : null;
    $second = $get('schedule') ?? $get('myenrollment') ?? $get('myadmission');
    $left = array_values(array_filter([$get('my'), $second]));
    $center = $get('historygrades', ['label' => 'Grades']);
    $right = array_values(array_filter([$get('historyassessments', ['label' => 'Fees']), ['label' => 'More', 'icon' => 'grid', 'sheet' => 'portalMore', 'active' => false]]));
    if (!$center) return;
    bottom_nav($left, $center, $right, 'Student Portal menu');
    $all = array_values($items);
    $all[] = ['href' => url('profile'), 'label' => 'My profile', 'icon' => 'person-gear', 'active' => $route === 'profile'];
    $all[] = ['href' => '../sis/index.php?r=workspaces', 'label' => 'Switch system', 'icon' => 'grid-3x3-gap', 'active' => false];
    bottom_sheet('portalMore', 'Student Portal', $all);
}

function portal_layout_end(bool $bare = false): void
{
    ?>
</main>
<?php if (!$bare): ?><footer class="portal-foot d-print-none"><?= h(School::brand()) ?> · Student Portal</footer><?php endif; ?>
<?php if (!$bare && !empty($GLOBALS['PORTAL_MENU'])) portal_bottom_nav($GLOBALS['PORTAL_MENU']); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="../sis/assets/navigation.js?v=49"></script>
<script src="../sis/assets/app.js?v=41"></script>
<script src="../sis/assets/success-sound.js?v=56"></script>
</body>
</html><?php
}
