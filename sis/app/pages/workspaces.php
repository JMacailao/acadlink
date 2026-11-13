<?php
declare(strict_types=1);
/**
 * After sign-in: a full page with only the workspaces this account may open.
 * v58: Finance is its own workspace. v59: Canteen POS opens; Library is planned (shown as "Coming soon").
 */
$a = Auth::central()->current(); $id = (int) $a['id'];
$systems = ModuleAccess::systems(Auth::central()->permissions($id));
$logo = School::logo();
$art = [
    'GRADING' => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-paper al-float"><strong>GRADES</strong><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-grades">95<br>92<br>96<br>94</div></div><div class="al-book"></div><div class="al-cap al-bob"><i class="bi bi-mortarboard"></i></div>',
    'SIS'     => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-paper al-float"><strong>ENROLLMENT</strong><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div></div><div class="al-check al-bob"><i class="bi bi-check-lg"></i></div><div class="al-id al-bob"><i class="bi bi-person"></i><span></span></div>',
    'LMS'     => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-laptop al-float"><div class="al-mini-nav"></div><div class="al-screen"><div class="al-tiles"><span><i class="bi bi-play-btn"></i></span><span><i class="bi bi-journal-text"></i></span><span><i class="bi bi-ui-checks"></i></span></div><div class="al-screen-lines"></div></div></div><div class="al-float-tile al-bob"><i class="bi bi-cone-striped"></i></div>',
    'CANTEEN' => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-register al-float"><div class="al-register-screen"><span>₱ 85.00</span><div class="al-tiles"><span><i class="bi bi-cup-hot"></i></span><span><i class="bi bi-egg-fried"></i></span><span><i class="bi bi-basket"></i></span></div></div><div class="al-register-base"></div></div><div class="al-float-tile al-bob"><i class="bi bi-shop"></i></div>',
    'LIBRARY' => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-shelf al-float"><span></span><span></span><span></span><span></span><span></span></div><div class="al-open-book al-bob"><i class="bi bi-book-half"></i></div>',
    'FINANCE' => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-paper al-chart al-float"><strong>REVENUE</strong><div class="al-bars"><span style="height:38%"></span><span style="height:62%"></span><span style="height:48%"></span><span style="height:80%"></span></div></div><div class="al-coins al-bob"><i class="bi bi-cash-coin"></i></div>',
    'PORTAL'  => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-laptop al-float"><div class="al-mini-nav"></div><div class="al-screen"><div class="al-tiles"><span><i class="bi bi-mortarboard"></i></span><span><i class="bi bi-wallet2"></i></span><span><i class="bi bi-clock-history"></i></span></div><div class="al-screen-lines"></div></div></div><div class="al-float-tile al-bob"><i class="bi bi-book"></i></div>',
];
// system => [tone, title, description, features, button, link, badge]
$cards = [
    'GRADING' => ['', 'Grading System', 'Manage classes, enter grades, and keep every student’s progress on track.', ['Classes', 'Grade Entry', 'Reports'], 'Open', '../pages/dashboard.html', ''],
    'SIS'     => ['green', 'Admission &amp; Registration (SIS)', 'Handle applications, student records, enrollment, and assessments in one place.', ['Admissions', 'Enrollment', 'Assessment'], 'Open', url('dashboard'), ''],
    'LMS'     => ['orange', 'Learning Management System', 'Online classes, lessons, and activities in one place.', ['Lessons', 'Activities', 'Classes'], 'Open LMS', '../lms/index.php', ''],
    'CANTEEN' => ['coral', 'Canteen POS', 'Streamline canteen sales, manage items, and track daily transactions.', ['Sales', 'Menu Items', 'Transactions'], 'Open Canteen POS', '../canteen/index.php', ''],
    'LIBRARY' => ['violet', 'Library', 'Manage library resources, track borrowings, and keep the collection organized.', ['Catalog', 'Borrowing', 'Inventory'], 'Open Library', '../library/index.php', ''],
    'FINANCE' => ['teal', 'Finance', 'Manage fees, payments, and financial records with accuracy and transparency.', ['Billing', 'Collections', 'Reports'], 'Open Finance', '../finance/index.php', ''],
    'PORTAL'  => ['purple', 'Student Portal', 'View your grades, check assessments, and revisit your school history.', ['My Grades', 'Assessments', 'History'], 'Open Portal', url('my'), ''],
];
$short = ['GRADING' => 'Grading', 'SIS' => 'Admission', 'LMS' => 'LMS', 'CANTEEN' => 'Canteen POS', 'LIBRARY' => 'Library', 'FINANCE' => 'Finance', 'PORTAL' => 'Student Portal']; // v63.1: phone tiles
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s,]+/', trim((string) $a['display_name'])) ?: [], 0, 2))));
layout_start('Choose a workspace', true);
?>
<link rel="stylesheet" href="../css/workspace-selector.css?v=63.1">
<style>
  body { background: #f8fafc; }
  #portalChooser { min-height: 100dvh; max-width: 1180px; margin: 0 auto; padding: 28px 34px 24px !important; }
  #portalChooser .al-grid.n1 { grid-template-columns: minmax(0, 360px); justify-content: center; }
  #portalChooser .al-grid.n2, #portalChooser .al-grid.n4 { grid-template-columns: repeat(2, minmax(0, 360px)); justify-content: center; }
  #portalChooser .al-open { text-decoration: none; }
  #portalChooser .al-account { display: flex; align-items: center; gap: 10px; font-size: 13px; color: #45627a; }
  #portalChooser .al-account form { margin: 0; }
  #portalChooser .al-account button { border: 1px solid #d8e5f3; background: #fff; border-radius: 8px; padding: 6px 12px; color: #233d58; font-size: 13px; }
  #portalChooser .al-none { max-width: 520px; margin: 0 auto; text-align: center; background: #fff; border: 1px solid #f1d38b; border-radius: 16px; padding: 28px 24px; }
  #portalChooser .al-none i { font-size: 34px; color: #b7791f; }
  @media (max-width: 760px) { #portalChooser { padding: 18px 16px !important; } #portalChooser .al-grid.n2, #portalChooser .al-grid.n4 { grid-template-columns: minmax(0, 360px); } #portalChooser .al-account { flex-wrap: wrap; } }
</style>
<div id="portalChooser">
  <header class="al-top">
    <div class="al-brand"><span class="brand-seal has-logo<?= $logo ? '' : ' is-default' ?>" aria-hidden="true"><img src="<?= h($logo ?: '../icons/acadlink-mark.png') ?>" alt=""></span><div><div class="al-school"><?= h(School::brand()) ?></div><div class="al-product">AcadLink</div></div></div>
    <div class="al-account"><span>Signed in as <strong><?= h($a['display_name']) ?></strong></span>
      <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</button></form></div>
    <div class="dropdown al-m-account"><button type="button" class="al-m-pill dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account: <?= h($a['display_name']) ?>"><span class="al-m-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
      <div class="dropdown-menu dropdown-menu-end"><div class="px-3 py-2 small">Signed in as<br><strong><?= h($a['display_name']) ?></strong></div><div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?= url('password') ?>"><i class="bi bi-key me-2" aria-hidden="true"></i>Change password</a>
        <form method="post" action="<?= url('logout') ?>" class="m-0"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button></form></div></div>
  </header>
  <?php if (!$systems): ?>
  <div class="al-intro"><h1>No workspace yet</h1></div>
  <div class="al-none" role="alert"><i class="bi bi-shield-lock" aria-hidden="true"></i>
    <p class="mt-2 mb-1"><strong>You don't have access to any workspace yet.</strong></p>
    <p class="small text-muted mb-0">Ask the Power User (registrar or school administrator) to give your account access, then sign in again.</p></div>
  <?php else: ?>
  <div class="al-intro"><h1><?= count($systems) > 1 ? 'Choose a workspace' : 'Your workspace' ?></h1>
    <p><span class="al-d-text"><?= count($systems) > 1 ? 'Your account can open these workspaces. Select one to get started.' : 'Your account opens this workspace.' ?></span><span class="al-m-text">Tap a module to open it.</span></p></div>
  <nav class="al-grid n<?= count($systems) ?>" aria-label="Workspaces you can open">
    <?php foreach ($systems as $s): [$tone, $title, $text, $features, $open, $href, $badge] = $cards[$s]; $soon = in_array($s, ModuleAccess::COMING_SOON, true); ?>
    <section class="al-card system-card<?= $tone ? ' ' . $tone : '' ?><?= $soon ? ' is-soon' : '' ?>" data-system="<?= h($s) ?>">
      <div class="al-art" aria-hidden="true"><div class="al-stage"><?= $art[$s] ?></div></div>
      <span class="al-m-name"><?= h($short[$s] ?? strip_tags(html_entity_decode($title))) ?></span>
      <h2><?= $title ?><?= $badge !== '' ? '<span class="al-badge">' . h($badge) . '</span>' : '' ?></h2><p class="al-description"><?= h($text) ?></p>
      <div class="al-features"><?php foreach ($features as $f): ?><span><?= h($f) ?></span><?php endforeach; ?></div>
      <?php if ($soon): ?>
      <span class="al-open al-soon" role="button" aria-disabled="true" title="<?= h(strip_tags(html_entity_decode($title))) ?> is not available yet">Coming soon</span>
      <?php else: ?>
      <a class="al-open" href="<?= h($href) ?>" target="_top" aria-label="<?= h($open . ': ' . strip_tags(html_entity_decode($title))) ?>"><?= h($open) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div class="al-footer"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Only workspaces your account is allowed to use are shown.<?php if (Auth::role() === 'TEACHER'): ?> · <a href="<?= url('history', ['tab' => 'grades']) ?>">History of Grades</a><?php endif; ?></span></div>
</div>
<script src="<?= sis_web() ?>assets/workspace-tiles.js?v=63.1" defer></script>
<?php layout_end(true);
