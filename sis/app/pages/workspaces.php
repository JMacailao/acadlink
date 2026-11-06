<?php
declare(strict_types=1);
/** After sign-in: a full page with only the systems this account may open (Grading System, SIS, Student Portal). */
$a = Auth::central()->current(); $id = (int) $a['id'];
$systems = ModuleAccess::systems(Auth::central()->permissions($id));
$logo = School::logo();
$art = [
    'GRADING' => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-paper al-float"><strong>GRADES</strong><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-grades">95<br>92<br>96<br>94</div></div><div class="al-book"></div><div class="al-cap al-bob"><i class="bi bi-mortarboard"></i></div>',
    'SIS'     => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-paper al-float"><strong>ENROLLMENT</strong><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div><div class="al-line"></div></div><div class="al-check al-bob"><i class="bi bi-check-lg"></i></div><div class="al-id al-bob"><i class="bi bi-person"></i><span></span></div>',
    'LMS'     => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-laptop al-float"><div class="al-mini-nav"></div><div class="al-screen"><div class="al-tiles"><span><i class="bi bi-play-btn"></i></span><span><i class="bi bi-journal-text"></i></span><span><i class="bi bi-ui-checks"></i></span></div><div class="al-screen-lines"></div></div></div><div class="al-float-tile al-bob"><i class="bi bi-cone-striped"></i></div>',
    'PORTAL'  => '<div class="al-orbit"></div><div class="al-dot"></div><div class="al-dot two"></div><div class="al-laptop al-float"><div class="al-mini-nav"></div><div class="al-screen"><div class="al-tiles"><span><i class="bi bi-mortarboard"></i></span><span><i class="bi bi-wallet2"></i></span><span><i class="bi bi-clock-history"></i></span></div><div class="al-screen-lines"></div></div></div><div class="al-float-tile al-bob"><i class="bi bi-book"></i></div>',
];
$cards = [
    'GRADING' => ['', 'Grading System', 'Manage classes, enter grades, and keep every student’s progress on track.', ['Classes', 'Grade Entry', 'Reports'], 'Open Grading', '../pages/dashboard.html'],
    'SIS'     => ['green', 'Admission &amp; Registration', 'Handle applications, student records, enrollment, and payments in one place.', ['Admissions', 'Enrollment', 'Payments'], 'Open SIS', url('dashboard')],
    'LMS'     => ['orange', 'Learning Management System', 'Online classes, lessons and activities. Being built: not available yet.', ['Lessons', 'Activities', 'Classes'], 'Open LMS', '../lms/index.php'],
    'PORTAL'  => ['purple', 'Student Portal', 'View your grades, check assessments, and revisit your school history.', ['My Grades', 'Assessments', 'History'], 'Open Portal', url('my')],
];
layout_start('Choose a system', true);
?>
<link rel="stylesheet" href="../css/workspace-selector.css?v=41">
<style>
  body { background: #f8fafc; }
  #portalChooser { min-height: 100dvh; max-width: 1180px; margin: 0 auto; padding: 28px 34px 24px !important; }
  #portalChooser .al-grid.n1 { grid-template-columns: minmax(0, 360px); justify-content: center; }
  #portalChooser .al-grid.n2, #portalChooser .al-grid.n4 { grid-template-columns: repeat(2, minmax(0, 360px)); justify-content: center; }
  #portalChooser .al-card.orange { --tone: #c2410c; --soft: #fff1e6; --dark: #9a3412; border-color: #fbd5b5; }
  #portalChooser .al-badge { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 999px; background: #fff1e6; color: #9a3412; font-size: 11px; font-weight: 700; vertical-align: middle; }
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
  </header>
  <?php if (!$systems): ?>
  <div class="al-intro"><h1>No system yet</h1></div>
  <div class="al-none" role="alert"><i class="bi bi-shield-lock" aria-hidden="true"></i>
    <p class="mt-2 mb-1"><strong>You don't have access to any system yet.</strong></p>
    <p class="small text-muted mb-0">Ask the Power User (registrar or school administrator) to give your account access, then sign in again.</p></div>
  <?php else: ?>
  <div class="al-intro"><h1><?= count($systems) > 1 ? 'Choose a system' : 'Your system' ?></h1>
    <p><?= count($systems) > 1 ? 'Your account can open these systems.<br>Select one to get started.' : 'Your account opens this system.' ?></p></div>
  <nav class="al-grid n<?= count($systems) ?>" aria-label="Systems you can open">
    <?php foreach ($systems as $s): [$tone, $title, $text, $features, $open, $href] = $cards[$s]; ?>
    <section class="al-card system-card<?= $tone ? ' ' . $tone : '' ?>" data-system="<?= h($s) ?>">
      <div class="al-art" aria-hidden="true"><?= $art[$s] ?></div>
      <h2><?= $title ?><?= $s === 'LMS' ? '<span class="al-badge">Ongoing build</span>' : '' ?></h2><p class="al-description"><?= h($text) ?></p>
      <div class="al-features"><?php foreach ($features as $f): ?><span><?= h($f) ?></span><?php endforeach; ?></div>
      <a class="al-open" href="<?= h($href) ?>" target="_top"><?= h($open) ?><i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </section>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <div class="al-footer"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Only the systems your account is allowed to use are shown.<?php if (Auth::role() === 'TEACHER'): ?> · <a href="<?= url('history', ['tab' => 'grades']) ?>">History of Grades</a><?php endif; ?></span></div>
</div>
<?php layout_end(true);
