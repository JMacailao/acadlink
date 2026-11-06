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
if (Auth::can('admission')) $actions[] = $act('New applicant', 'Step 1 · record and admit', 'person-plus', url('admission', ['new' => 1]), 'blue');
if (Auth::can('registration')) $actions[] = $act('Register a student', 'Step 2 · section, subjects, fees', 'journal-text', url('registration', ['new' => 1]), 'green');
if (Auth::can('payments.record')) $actions[] = $act('Receive a payment', 'Step 3 · cashier and receipt', 'cash-coin', url('cashier', ['new' => 1]), 'orange');
if (can_search()) $actions[] = $act('Find a student', 'Name, Student ID or number', 'search', url('search'), 'purple');
if (Auth::can('assessment.edit')) $actions[] = $act('Set up fees', 'Categories, labels, names', 'card-list', url('assessment', ['tab' => 'names']), 'pink');
if (Auth::can('users.manage')) $actions[] = $act('Accounts', 'Add staff and student sign-ins', 'people', url('users'), 'teal');

// ---- At a glance
$students = Auth::can('students') ? (int) Db::val("SELECT COUNT(*) FROM docs WHERE col = 'students' AND f_schoolYear = ?", [$sy]) : 0;
$registered = (int) Db::val("SELECT COUNT(DISTINCT student_number) FROM sis_registrations WHERE record_origin='ENROLLMENT' AND school_year = ? AND status <> 'CANCELLED'", [$sy]);
$cards = [];
$c = fn(string $label, string $value, string $icon, string $href, string $color) => compact('label', 'value', 'icon', 'href', 'color');
if (Auth::can('students')) $cards[] = $c("Students in $sy", number_format($students), 'people', url('students'), 'blue');
if (Auth::can('registration', 'payments.view')) $cards[] = $c("Registered in $sy", number_format($registered), 'clipboard-check', url('registration', ['sy' => $sy]), 'green');
if (Auth::can('payments.view')) {
    $cards[] = $c('Collected today', '₱' . Money::fmt(Money::cents((string) Db::val("SELECT COALESCE(SUM(amount),0) FROM sis_payments WHERE status = 'POSTED' AND DATE(paid_at) = CURDATE()"))), 'cash-stack', url('cashier'), 'purple');
    $cards[] = $c("Balances to collect ($sy)", '₱' . Money::fmt(Money::cents((string) Db::val("SELECT COALESCE(SUM(total_amount - amount_paid),0) FROM sis_registrations WHERE school_year = ? AND status = 'REGISTERED'", [$sy]))), 'exclamation-circle', url('cashier'), 'pink');
}

// ---- Recent activity: everyone's for the registrar and administrator, your own for other staff
$mineOnly = !Auth::can('activity');
$activity = Activity::recent(5, false, 0, $mineOnly ? Auth::id() : null);

// ---- Enrollment progress for the school year
$pending = (int) Db::val("SELECT COUNT(*) FROM sis_admissions WHERE status = 'PENDING' AND school_year = ?", [$sy]);
$onHold = (int) Db::val("SELECT COUNT(*) FROM sis_admissions a WHERE a.status = 'ADMITTED' AND a.school_year = ? AND NOT EXISTS (SELECT 1 FROM sis_registrations r WHERE r.student_number = a.student_number AND r.record_origin='ENROLLMENT' AND r.status <> 'CANCELLED')", [$sy]);
$base = max($students, $registered);
$notEnrolled = max(0, $base - $registered);
$pct = $base > 0 ? $registered / $base : 0;
$noFees = Auth::can('assessment.edit') && !(int) Db::val('SELECT COUNT(*) FROM sis_assessment_names WHERE is_active = 1');

layout_start('Home');
page_title("$greet, " . (trim((string) $u['full_name']) ?: $u['username']),
    '<form method="get" class="sy-card"><input type="hidden" name="r" value="dashboard"><i class="bi bi-calendar3" aria-hidden="true"></i><div><label for="sySel">School year</label><select class="form-select form-select-sm" id="sySel" name="sy" data-autosubmit>' . sy_options($sy) . '</select></div></form>',
    'You are signed in as <strong>' . h($u['role_name']) . '</strong>. Not sure where to start? <a class="link-blue" href="' . url('help') . '">Read the 2-minute guide.</a>');
?>
<?php if ($noFees): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i>
  <span class="me-auto">No fee template yet: registrations will have no fees. Set up <strong>Categories → Labels → Names</strong> first.</span>
  <a class="btn btn-sm btn-warning" href="<?= url('assessment', ['tab' => 'labels']) ?>">Set up fees</a></div>
<?php endif; ?>

<h2 class="section-title">Quick actions</h2>
<div class="row g-3">
<?php foreach ($actions as $a): ?>
  <div class="col-6 col-md-4 col-xxl-2"><a class="qa-card qa-<?= h($a['color']) ?>" href="<?= h($a['href']) ?>">
    <span class="qa-icon"><i class="bi bi-<?= h($a['icon']) ?>" aria-hidden="true"></i></span><i class="bi bi-chevron-right qa-go" aria-hidden="true"></i>
    <span class="t"><?= h($a['label']) ?></span><span class="d"><?= h($a['hint']) ?></span></a></div>
<?php endforeach; ?>
</div>

<?php if ($cards): ?>
<h2 class="section-title">At a glance</h2>
<div class="row g-3">
<?php foreach ($cards as $k): ?>
  <div class="col-sm-6 col-xl-3"><a class="glance g-<?= h($k['color']) ?>" href="<?= h($k['href']) ?>">
    <span class="g-icon"><i class="bi bi-<?= h($k['icon']) ?>" aria-hidden="true"></i></span>
    <span class="min-w-0"><span class="g-value d-block"><?= h($k['value']) ?></span><span class="g-label d-block"><?= h($k['label']) ?></span></span>
    <i class="bi bi-chevron-right g-go" aria-hidden="true"></i></a></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3 mt-2">
  <div class="col-xl-7">
    <div class="home-panel">
      <div class="d-flex align-items-center mb-3"><h2 class="me-auto"><i class="bi bi-clock-history"></i><?= $mineOnly ? 'Your recent activity' : 'Recent activity' ?></h2>
        <?php if (Auth::can('activity')): ?><a class="link-blue small" href="<?= url('activity') ?>">View all</a><?php endif; ?></div>
      <?php require __DIR__ . '/../views/activity_table.php'; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="home-panel">
      <div class="d-flex align-items-center mb-3"><h2 class="me-auto"><i class="bi bi-bar-chart-line"></i>Enrollment progress</h2><span class="small text-muted"><?= h($sy) ?></span></div>
      <div class="donut-wrap">
        <svg class="donut" viewBox="0 0 120 120" role="img" aria-label="<?= h("$registered of $base registered") ?>">
          <circle cx="60" cy="60" r="50" fill="none" stroke="#e8edf5" stroke-width="12"/>
          <circle cx="60" cy="60" r="50" fill="none" stroke="#10a37f" stroke-width="12" stroke-linecap="round"
            stroke-dasharray="<?= round(2 * M_PI * 50 * $pct, 2) ?> <?= round(2 * M_PI * 50, 2) ?>" transform="rotate(-90 60 60)"<?= $pct <= 0 ? ' opacity="0"' : '' ?>/>
          <text x="60" y="58" text-anchor="middle" class="donut-center" font-size="15" font-weight="700" fill="#12203f"><?= h(number_format($registered) . ' / ' . number_format($base)) ?></text>
          <text x="60" y="75" text-anchor="middle" font-size="9" fill="#56617a">Registered</text>
        </svg>
        <ul class="legend">
          <li><span class="dot" style="background:#3b82f6"></span><a class="text-decoration-none" href="<?= Auth::can('admission') ? url('admission', ['status' => 'PENDING']) : '#' ?>">New applicants</a><b><?= number_format($pending) ?></b></li>
          <li><span class="dot" style="background:#10a37f"></span>Registered<b><?= number_format($registered) ?></b></li>
          <li><span class="dot" style="background:#f59e0b"></span><a class="text-decoration-none" href="<?= Auth::can('admission') ? url('admission', ['status' => 'ADMITTED']) : '#' ?>">On hold (admitted, not registered)</a><b><?= number_format($onHold) ?></b></li>
          <li><span class="dot" style="background:#b6bfcd"></span>Not enrolled yet<b><?= number_format($notEnrolled) ?></b></li>
        </ul>
      </div>
      <div class="progress-note"><i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <div class="me-auto"><div class="t"><?= $base ? round($pct * 100) . '% of ' . h($sy) . ' students are registered.' : 'No students for ' . h($sy) . ' yet.' ?></div>
          <div class="d"><?= $pending ? $pending . ' applicant' . ($pending === 1 ? ' is' : 's are') . ' waiting for admission.' : ($onHold ? $onHold . ' admitted student' . ($onHold === 1 ? ' still needs' : 's still need') . ' to be registered.' : 'Nothing is waiting right now.') ?></div></div>
        <i class="bi bi-flower1 text-success fs-4" aria-hidden="true"></i></div>
    </div>
  </div>
</div>
<?php layout_end();
