<?php
/** Home: quick actions, totals, recent activity and enrollment progress for the chosen school year. */
declare(strict_types=1);
if (Auth::can('self') && !Auth::can('students')) redirect('my');
if (Auth::role()==='TEACHER' || !Auth::central()->can((int)Auth::centralId(),'sis.workspace.view')) redirect('workspaces');
$sy = selected_sy();
$u = Auth::user();
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// ---- Quick actions: the jobs this person does
$act = fn(string $label, string $hint, string $icon, string $href, string $color) => compact('label', 'hint', 'icon', 'href', 'color');
$actions = [];
if (Auth::can('admission', 'registration', 'payments.view', 'accounting')) {
    $notificationCount = NotificationCenter::unreadCount();
    $actions[] = $act('Notifications' . ($notificationCount ? ' (' . $notificationCount . ')' : ''), 'Review active approvals and alerts', 'bell', url('notifications'), $notificationCount ? 'orange' : 'teal');
}
if (Auth::can('admission')) $actions[] = $act('New applicant', 'Record and admit an applicant', 'person-plus', url('admission', ['new' => 1]), 'blue');
if (Auth::can('registration')) $actions[] = $act('Register a student', 'Choose a section, subjects and fees', 'journal-text', url('registration', ['new' => 1]), 'green');
if (Auth::can('payments.record')) $actions[] = $act('Receive a payment', 'Record a payment and issue a receipt', 'cash-coin', url('cashier', ['new' => 1]), 'orange');
if (can_search()) $actions[] = $act('Find a student', 'Name, Student ID or number', 'search', url('search'), 'purple');
if (Auth::can('assessment.edit')) $actions[] = $act('Set up fees', 'Categories, labels, names', 'card-list', url('assessment', ['tab' => 'names']), 'pink');
if (Auth::can('users.manage')) $actions[] = $act('Accounts', 'Add staff and student sign-ins', 'people', url('users'), 'teal');

// ---- At a glance
$keep = fn(string $k, callable $f) => ShortCache::get('dash|' . $sy . '|' . $k, 60, $f); // v64.6: kept for a minute
$students = Auth::can('students') ? $keep('students', fn() => (int) Db::val("SELECT COUNT(*) FROM docs WHERE col = 'students' AND f_schoolYear = ?", [$sy])) : 0;
$registered = $keep('registered', fn() => (int) Db::val("SELECT COUNT(DISTINCT student_number) FROM sis_registrations WHERE record_origin='ENROLLMENT' AND school_year = ? AND status <> 'CANCELLED'", [$sy]));
$cards = [];
$c = fn(string $label, string $value, string $icon, string $href, string $color) => compact('label', 'value', 'icon', 'href', 'color');
if (Auth::can('students')) $cards[] = $c('Students', number_format($students), 'people', url('students'), 'blue');
if (Auth::can('registration', 'payments.view')) $cards[] = $c('Registered', number_format($registered), 'clipboard-check', url('registration', ['sy' => $sy]), 'green');
if (Auth::can('payments.view')) {
    $cards[] = $c('Collected today', '₱' . Money::fmt(Money::cents((string) $keep('today', fn() => Db::val("SELECT COALESCE(SUM(amount),0) FROM sis_payments WHERE status = 'POSTED' AND DATE(paid_at) = CURDATE()")))), 'cash-stack', url('cashier'), 'purple');
    $cards[] = $c('Outstanding balances', '₱' . Money::fmt(Money::cents((string) $keep('outstanding', fn() => Db::val("SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM sis_registrations WHERE school_year = ? AND status = 'REGISTERED'", [$sy])))), 'exclamation-circle', url('cashier'), 'pink');
}

// ---- Recent activity: everyone's for the registrar and administrator, your own for other staff
$mineOnly = !Auth::can('activity');
$activity = Activity::recent(5, false, 0, $mineOnly ? Auth::id() : null);

// ---- Enrollment progress for the school year
$pending = $keep('pending', fn() => (int) Db::val("SELECT COUNT(*) FROM sis_admissions WHERE status = 'PENDING' AND school_year = ?", [$sy]));
$onHold = $keep('onhold', fn() => (int) Db::val("SELECT COUNT(*) FROM sis_admissions a WHERE a.status = 'ADMITTED' AND a.school_year = ? AND NOT EXISTS (SELECT 1 FROM sis_registrations r WHERE r.student_number = a.student_number AND r.record_origin='ENROLLMENT' AND r.status <> 'CANCELLED')", [$sy]));
$base = max($students, $registered);
$notEnrolled = max(0, $base - $registered);
$pct = $base > 0 ? $registered / $base : 0;
// ---- v64.6: applications in the last 7 days and the newest applications (people who work on admissions)
$seeAdmissions = Auth::can('admission', 'admission.view');
$week = []; $recentApps = [];
if ($seeAdmissions) {
    $byDay = []; foreach ($keep('week', fn() => Db::all("SELECT DATE(created_at) d, COUNT(*) n FROM sis_admissions WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND status <> 'CANCELLED' GROUP BY DATE(created_at)")) as $row) $byDay[$row['d']] = (int) $row['n'];
    for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $week[] = ['day' => date('D', strtotime($d)), 'date' => date('M j', strtotime($d)), 'n' => $byDay[$d] ?? 0]; }
    $recentApps = Db::all("SELECT id, last_name, first_name, middle_name, program, year_level, applicant_type, status FROM sis_admissions WHERE school_year = ? AND status <> 'CANCELLED' ORDER BY id DESC LIMIT 5", [$sy]);
}
$noFees = Auth::can('assessment.edit') && !(int) Db::val('SELECT COUNT(*) FROM sis_assessment_names WHERE is_active = 1');

layout_start('Dashboard');
$progressPercent = (int) round($pct * 100);
?>
<div class="sis-dashboard">
  <?php if (PHP_VERSION_ID < 80200 && Backup::allowed()): // v63.13: power.admin sees when the server's PHP is out of support ?>
  <div class="alert alert-warning d-flex gap-2 align-items-start" role="alert"><i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i><div><b>This server runs PHP <?= h(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>, which no longer gets security fixes.</b> Switch the website to <b>PHP 8.3</b> in your host's panel (Hostinger: hPanel → Advanced → PHP Configuration → PHP version), then reload this page. AcadLink is tested on PHP 8.2 and 8.3.</div></div>
  <?php endif; ?>
  <?php if (Backup::allowed() && ($scN = SystemCheck::problems()) > 0): // v63.16 ?>
  <div class="alert alert-danger d-flex gap-2 align-items-center" role="alert"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i><div class="flex-grow-1"><b>System Check found <?= $scN ?> problem<?= $scN === 1 ? '' : 's' ?></b> on this server.</div><a class="btn btn-sm btn-outline-danger text-nowrap" href="<?= url('syscheck') ?>">Open System Check</a></div>
  <?php endif; ?>
  <?php if (Backup::allowed() && ($bk = Backup::reminder())): // v63.14 ?>
  <div class="alert alert-<?= h($bk[0]) ?> d-flex gap-2 align-items-start" role="status"><i class="bi bi-<?= $bk[0] === 'info' ? 'usb-drive' : 'cloud-slash' ?> mt-1" aria-hidden="true"></i><div class="flex-grow-1"><?= h($bk[1]) ?></div><a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= url('backup') ?>#auto">Backup &amp; Restore</a></div>
  <?php endif; ?>
  <header class="dash-intro">
    <div>
      <p class="dash-greeting"><?= h($greet . ', ' . (trim((string) $u['full_name']) ?: $u['username'])) ?>.</p>
      <p class="dash-description">Enrollment, payments and school activity at a glance.</p>
    </div>
    <form method="get" class="dash-year">
      <input type="hidden" name="r" value="dashboard">
      <label for="dashboardYear">School year</label>
      <select class="form-select" id="dashboardYear" name="sy" data-autosubmit><?= sy_options($sy) ?></select>
      <button class="btn btn-outline-primary" type="submit">Apply</button>
    </form>
  </header>

  <?php if ($noFees): ?>
  <div class="alert alert-warning dash-notice" role="status">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
    <span>No fee template yet. Set up fees before registering students.</span>
    <a class="btn btn-sm btn-warning" href="<?= url('assessment', ['tab' => 'names']) ?>">Set up fees</a>
  </div>
  <?php endif; ?>

  <?php if ($cards): ?>
  <section class="dash-totals" aria-label="School totals">
    <?php foreach ($cards as $k): ?>
    <a class="dash-total" href="<?= h($k['href']) ?>">
      <span class="dash-total-label"><i class="bi bi-<?= h($k['icon']) ?>" aria-hidden="true"></i><?= h($k['label']) ?></span>
      <strong class="dash-total-value"><?= h($k['value']) ?></strong>
      <span class="dash-total-period"><?= $k['label'] === 'Collected today' ? h(date('M j, Y')) : h($sy) ?></span>
    </a>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($actions): ?>
  <section class="dash-actions" aria-labelledby="dashboardActions">
    <h2 id="dashboardActions">Quick actions</h2>
    <nav class="dash-action-grid" aria-label="Quick actions">
      <?php foreach ($actions as $a): ?>
      <a class="dash-action" href="<?= h($a['href']) ?>" title="<?= h($a['hint']) ?>">
        <i class="bi bi-<?= h($a['icon']) ?>" aria-hidden="true"></i>
        <span><?= h($a['label']) ?></span>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
      </a>
      <?php endforeach; ?>
    </nav>
  </section>
  <?php endif; ?>

  <?php if ($seeAdmissions): // v64.6: enrollment activity and recent applications, as in the design mockup
    $max = max(1, ...array_column($week, 'n')); $top = (int) (ceil($max / 4) * 4) ?: 4; $W = 700; $H = 180; $px = fn(int $i) => 24 + $i * (($W - 48) / 6); $py = fn(int $n) => 10 + ($H - 20) * (1 - $n / $top);
    $pts = []; foreach ($week as $i => $w) $pts[] = round($px($i), 1) . ',' . round($py($w['n']), 1);
    $typeName = ['NEW' => 'New', 'CONTINUING' => 'Continuing', 'TRANSFEREE' => 'Transferee', 'UNIT_EARNER' => 'Unit earner', 'RETURNEE' => 'Returnee']; ?>
  <div class="dash-panels dash-admit">
    <section class="dash-panel dash-week" aria-labelledby="dashboardWeek">
      <header class="dash-panel-heading"><div><h2 id="dashboardWeek">Enrollment activity, last 7 days</h2><p>Applications received: <?= array_sum(array_column($week, 'n')) ?></p></div></header>
      <div class="dash-chart">
        <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-labelledby="dashboardWeek" preserveAspectRatio="none" class="dash-chart-svg">
          <?php for ($g = 0; $g <= 4; $g++): $gy = 10 + ($H - 20) * $g / 4; ?><line x1="0" x2="<?= $W ?>" y1="<?= $gy ?>" y2="<?= $gy ?>" class="dash-grid"/><?php endfor; ?>
          <polygon class="dash-area" points="<?= round($px(0), 1) ?>,<?= $H - 10 ?> <?= implode(' ', $pts) ?> <?= round($px(6), 1) ?>,<?= $H - 10 ?>"/>
          <polyline class="dash-line" points="<?= implode(' ', $pts) ?>"/>
          <?php foreach ($week as $i => $w): ?>
          <g class="dash-point" tabindex="0"><title><?= h($w['date']) ?>: <?= $w['n'] ?> application<?= $w['n'] === 1 ? '' : 's' ?></title>
            <rect x="<?= round($px($i) - ($W - 48) / 12, 1) ?>" y="0" width="<?= round(($W - 48) / 6, 1) ?>" height="<?= $H ?>" class="dash-hit"/>
            <circle cx="<?= round($px($i), 1) ?>" cy="<?= round($py($w['n']), 1) ?>" r="4.5" class="dash-dot"/>
          </g>
          <?php endforeach; ?>
        </svg>
        <div class="dash-days" aria-hidden="true"><?php foreach ($week as $w): ?><span><?= h($w['day']) ?></span><?php endforeach; ?></div>
        <table class="visually-hidden"><caption>Applications per day</caption><tr><th>Day</th><th>Applications</th></tr><?php foreach ($week as $w): ?><tr><td><?= h($w['date']) ?></td><td><?= $w['n'] ?></td></tr><?php endforeach; ?></table>
      </div>
    </section>
    <section class="dash-panel dash-apps" aria-labelledby="dashboardApps">
      <header class="dash-panel-heading"><div><h2 id="dashboardApps">Recent applications</h2><p>Newest submissions, <?= h($sy) ?></p></div><a href="<?= url('admission', ['sy' => $sy]) ?>">View all</a></header>
      <ul class="dash-app-list">
        <?php foreach ($recentApps as $ap): $t = (string) $ap['applicant_type']; ?>
        <li><a href="<?= url('admission', ['id' => $ap['id']]) ?>"><span class="dash-app-name"><?= h($ap['last_name'] . ', ' . $ap['first_name'] . ($ap['middle_name'] ? ' ' . mb_substr($ap['middle_name'], 0, 1) . '.' : '')) ?></span><span class="dash-app-sub"><?= h(trim(($ap['program'] ? $ap['program'] . ' – ' : '') . $ap['year_level'])) ?></span></a><span class="dash-app-type dash-type-<?= h(strtolower($t)) ?>"><?= h($typeName[$t] ?? $t) ?></span></li>
        <?php endforeach; if (!$recentApps): ?><li class="dash-app-empty">No applications yet for <?= h($sy) ?>.</li><?php endif; ?>
      </ul>
    </section>
  </div>
  <?php endif; ?>

  <div class="dash-panels">
    <section class="dash-panel dash-activity" aria-labelledby="dashboardActivity">
      <header class="dash-panel-heading">
        <div><h2 id="dashboardActivity"><?= $mineOnly ? 'Your recent activity' : 'Recent activity' ?></h2><p>The latest updates in your school.</p></div>
        <?php if (Auth::can('activity')): ?><a href="<?= url('activity') ?>">View all</a><?php endif; ?>
      </header>
      <?php require __DIR__ . '/../views/activity_table.php'; ?>
    </section>

    <section class="dash-panel dash-enrollment" aria-labelledby="dashboardEnrollment">
      <header class="dash-panel-heading">
        <div><h2 id="dashboardEnrollment">Enrollment progress</h2><p><?= h($sy) ?></p></div>
        <span class="dash-progress-percent"><?= $progressPercent ?>%</span>
      </header>
      <div class="dash-progress-body">
        <p class="dash-progress-caption"><strong><?= number_format($registered) ?></strong> of <?= number_format($base) ?> students registered</p>
        <div class="dash-progress-track" role="progressbar" aria-label="Students registered" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?>" aria-valuetext="<?= h(number_format($registered) . ' of ' . number_format($base) . ' students registered') ?>"><span style="width:<?= $progressPercent ?>%"></span></div>
        <dl class="dash-enrollment-totals">
          <div><dt><?php if (Auth::can('admission')): ?><a href="<?= url('admission', ['status' => 'PENDING', 'sy' => $sy]) ?>">New applicants</a><?php else: ?>New applicants<?php endif; ?></dt><dd><?= number_format($pending) ?></dd></div>
          <div><dt>Registered</dt><dd><?= number_format($registered) ?></dd></div>
          <div><dt><?php if (Auth::can('admission')): ?><a href="<?= url('admission', ['status' => 'ADMITTED', 'sy' => $sy]) ?>">Awaiting registration</a><?php else: ?>Awaiting registration<?php endif; ?></dt><dd><?= number_format($onHold) ?></dd></div>
          <div><dt>Not enrolled yet</dt><dd><?= number_format($notEnrolled) ?></dd></div>
        </dl>
        <p class="dash-progress-note"><?= $base === 0 ? 'No students for this school year yet. Start with a new applicant or student record.' : ($pending ? number_format($pending) . ' applicant' . ($pending === 1 ? ' is' : 's are') . ' waiting for admission.' : ($onHold ? number_format($onHold) . ' admitted student' . ($onHold === 1 ? ' needs' : 's need') . ' to be registered.' : 'No admissions or registrations waiting.')) ?></p>
      </div>
    </section>
  </div>
</div>
<?php layout_end();
