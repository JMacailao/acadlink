<?php
/**
 * v61.1 LMS dashboard. Teachers: today's classes, today's attendance, work waiting to be checked and the files they shared,
 * the weekly timetable, and quick "Take attendance" buttons. Students: the same view of their own day and work.
 */
declare(strict_types=1);
$sy = lms_year();
$classes = Lms::myClasses($sy);
$me = Lms::me();
$teach = lms_teaches($classes);
$ids = array_column($classes, 'id');
$meetings = Lms::allMeetings($classes);
$tt = Lms::timetable($meetings);
$now = time();
$todays = Lms::meetingsOn($meetings, $now);
$periods = count($tt['slots']);

// Week shown in the timetable: this week, or ?week=-1 / +1 …
$week = max(-52, min(52, (int) ($_GET['week'] ?? 0)));
$monday = strtotime('monday this week', $now) + $week * 7 * 86400;
$dayTs = []; foreach ($tt['days'] as $d) $dayTs[$d] = $monday + array_search($d, array_keys(Schedules::DAYS), true) * 86400;
$range = date('M j', $monday) . ' – ' . date('M j, Y', end($dayTs) ?: $monday + 4 * 86400);

if ($teach) {
    $teachClasses = array_values(array_filter($classes, fn($c) => $c['role'] !== 'STUDENT'));
    $att = Lms::attendanceOn(array_column($teachClasses, 'id'), date('Y-m-d', $now));
    $review = Lms::todo($teachClasses);
    $toReview = array_sum(array_map(fn($w) => (int) $w['to_grade'], $review));
    $files = Lms::materials($teachClasses);
} else {
    $att = Lms::myAttendanceTotals($ids);
    $review = Lms::todo($classes);
    $toReview = count($review);
    $files = Lms::materials($classes);
}
$attPct = $att['total'] ? (int) round(($att['present'] + ($teach ? 0 : $att['late'] + $att['excused'])) * 100 / $att['total']) : null;
$done = []; // meetings today that already have attendance
if ($teach && $todays) foreach (Db::all('SELECT class_id, label FROM lms_attendance_sessions WHERE meeting_date = ?', [date('Y-m-d', $now)]) as $r) $done[$r['class_id'] . '|' . $r['label']] = true;
$hour = (int) date('G', $now);
$first = trim((string) ($teach ? $me['name'] : ($me['student_name'] ?: $me['name'])));
$greetName = $teach ? (Auth::user()['full_name'] ?? $first) : (explode(' ', preg_replace('/^[^,]+,\s*/', '', $first))[0] ?: $first);
layout_start('Dashboard');
?>
<header class="lms-head lms-welcome"><div>
  <span class="lms-kicker"><?= $teach ? 'Teacher Workspace' : ($me['student'] ? 'Student Workspace' : 'Coordinator Workspace') ?></span>
  <h1 class="lms-h1">Good <?= $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening') ?>, <?= h($greetName) ?>!</h1>
  <p class="lms-muted mb-0">Here's your <?= $teach ? 'teaching' : 'class' ?> overview for today, <?= h(date('D, M j, Y', $now)) ?>.</p></div>
  <div class="lms-head-actions"><?= lms_year_picker('dashboard', $sy) ?></div>
</header>

<div class="lms-stats">
  <?= lms_stat('calendar3', "Today's Classes", (string) count($todays), $teach ? 'of ' . $periods . ' period' . ($periods === 1 ? '' : 's') : (count($todays) === 1 ? 'class today' : 'classes today'), url('schedule')) ?>
  <?= $teach
      ? lms_stat('people', 'Attendance', $attPct === null ? '—' : $attPct . '%', $att['total'] ? $att['present'] . ' of ' . $att['total'] . ' present' : 'not taken yet today', url('attendance'), 'Today')
      : lms_stat('people', 'My Attendance', $attPct === null ? '—' : $attPct . '%', $att['total'] ? ($att['present'] + $att['late'] + $att['excused']) . ' of ' . $att['total'] . ' meetings attended' : 'no meetings recorded yet', url('attendance')) ?>
  <?= $teach
      ? lms_stat('journal-text', 'Assignments Awaiting Review', (string) $toReview, $toReview === 1 ? 'submission' : 'submissions', url('assignments', ['show' => 'review']))
      : lms_stat('journal-text', 'Assignments To Do', (string) $toReview, $toReview === 1 ? 'piece of work' : 'pieces of work', url('assignments')) ?>
  <?= lms_stat('folder2', 'Course Materials', (string) count($files), count($files) === 1 ? 'file' : 'total files', url('materials')) ?>
</div>

<?php if (!$classes): ?><div class="lms-panel lms-empty"><i class="bi bi-easel2" aria-hidden="true"></i><p class="mb-0"><?= $me['teacher'] ? 'No classes are assigned to you for ' . h($sy) . ' yet. The registrar assigns classes in the Grading System (Teachers and class assignments).' : ($me['student'] ? 'You are not in any class for ' . h($sy) . ' yet. Your classes appear once you are on a class list in the Grading System.' : 'Your account is not linked to a teacher or student record. Ask the Power User to link it, or give it the LMS coordinator access.') ?></p></div><?php endif; ?>

<div class="lms-dash">
  <section class="lms-panel lms-timetable">
    <div class="lms-ph"><h2><i class="bi bi-calendar3" aria-hidden="true"></i>My Weekly Timetable</h2>
      <div class="lms-week"><a class="lms-week-btn" href="<?= url('dashboard', ['week' => $week - 1] + ($sy ? ['sy' => $sy] : [])) ?>" aria-label="Previous week"><i class="bi bi-chevron-left"></i></a>
        <span><?= h($range) ?></span>
        <a class="lms-week-btn" href="<?= url('dashboard', ['week' => $week + 1] + ($sy ? ['sy' => $sy] : [])) ?>" aria-label="Next week"><i class="bi bi-chevron-right"></i></a>
        <?php if ($week !== 0): ?><a class="small ms-1" href="<?= url('dashboard', $sy ? ['sy' => $sy] : []) ?>">This week</a><?php endif; ?></div></div>
    <?php if ($tt['slots']): ?>
    <div class="table-responsive"><table class="lms-tt"><colgroup><col class="c-p"><col class="c-t"><?php foreach ($tt['days'] as $d): ?><col><?php endforeach; ?></colgroup>
      <thead><tr><th scope="col">Period</th><th scope="col">Time</th><?php foreach ($tt['days'] as $d): $isToday = date('Y-m-d', $dayTs[$d]) === date('Y-m-d', $now); ?><th scope="col"<?= $isToday ? ' class="is-today"' : '' ?>><?= h(Schedules::DAYS[$d]) ?><small><?= h(date('M j', $dayTs[$d])) ?></small></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($tt['slots'] as $p => [$st, $en]): ?><tr><th scope="row">P<?= $p + 1 ?></th><td class="lms-tt-time"><?= h(Schedules::time($st)) ?> – <?= h(Schedules::time($en)) ?></td>
        <?php foreach ($tt['days'] as $d): $isToday = date('Y-m-d', $dayTs[$d]) === date('Y-m-d', $now); $cell = $tt['cells'][$p][$d] ?? []; ?><td<?= $isToday ? ' class="is-today"' : '' ?>>
          <?php foreach ($cell as $m): ?><a href="<?= url('class', ['id' => $m['class']['id']]) ?>" title="<?= h($m['class']['subjectName'] . ' · ' . $m['class']['sectionName'] . ($m['room_code'] ? ' · Room ' . $m['room_code'] : '')) ?>"><?= h($m['class']['subjectName'] !== '' ? $m['class']['subjectName'] : $m['class']['subjectCode']) ?><small>(<?= h($m['class']['sectionName']) ?>)</small></a><?php endforeach; ?>
          <?php if (!$cell): ?><span class="lms-tt-none" aria-label="No class">–</span><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="lms-muted mb-0">No class times are set for your classes yet. The registrar sets them in the SIS class schedule.</p><?php endif; ?>
  </section>

  <div class="lms-dash-side">
    <section class="lms-panel"><?= lms_panel_head('calendar-day', "Today's Classes", url('schedule'), 'View Schedule') ?>
      <?php foreach ($todays as $m): $key = $m['class']['id'] . '|' . $m['label']; ?><div class="lms-tc">
        <span class="lms-period">P<?= (int) $m['period'] ?></span><span class="lms-tc-name"><?= h(lms_class_label($m['class'])) ?></span><span class="lms-tc-time"><?= h(Schedules::time($m['start_time'])) ?> – <?= h(Schedules::time($m['end_time'])) ?></span>
        <?php if ($m['class']['role'] !== 'STUDENT'): ?><a class="btn btn-sm <?= isset($done[$key]) ? 'btn-outline-primary' : 'btn-primary' ?> lms-tc-btn" href="<?= url('class', ['id' => $m['class']['id'], 'tab' => 'attendance', 'date' => date('Y-m-d', $now), 'label' => $m['label']]) ?>"><?= isset($done[$key]) ? 'Attendance taken' : 'Take Attendance' ?></a>
        <?php else: ?><a class="btn btn-sm btn-outline-primary lms-tc-btn" href="<?= url('class', ['id' => $m['class']['id']]) ?>">Open class</a><?php endif; ?></div><?php endforeach; ?>
      <?php if (!$todays): ?><p class="lms-muted mb-0">No classes scheduled today.</p><?php endif; ?></section>

    <section class="lms-panel"><?= lms_panel_head('people', $teach ? 'Attendance Summary' : 'My Attendance', url('attendance'), 'View Details', $teach ? 'Today' : 'This school year') ?>
      <div class="lms-attsum"><div class="lms-attsum-big"><b><?= $attPct === null ? '—' : $attPct . '%' ?></b><small><?= $att['total'] ? ($teach ? $att['present'] . ' of ' . $att['total'] . ' present' : ($att['present'] + $att['late'] + $att['excused']) . ' of ' . $att['total'] . ' attended') : ($teach ? 'Not taken yet today' : 'No meetings yet') ?></small></div>
        <div class="lms-attsum-tiles"><span class="is-present"><b><?= $att['present'] ?></b>Present</span><span class="is-absent"><b><?= $att['absent'] ?></b>Absent</span><span class="is-late"><b><?= $att['late'] ?></b>Late</span></div></div></section>

    <section class="lms-panel"><?= lms_panel_head('journal-text', $teach ? 'Assignments Awaiting Review' : 'Assignments Due', url('assignments', $teach ? ['show' => 'review'] : [])) ?>
      <?php foreach (array_slice($review, 0, 4) as $w): $c = array_column($classes, null, 'id')[$w['class_id']]; ?><a class="lms-rv" href="<?= url('work', ['id' => $w['class_id'], 'w' => $w['id']]) ?>">
        <i class="bi bi-file-earmark-text" aria-hidden="true"></i><span><b><?= h($w['title']) ?></b><small><?= h(lms_class_label($c)) ?></small></span>
        <?php if ($teach): ?><em class="lms-count-pill"><?= (int) $w['to_grade'] ?> submission<?= (int) $w['to_grade'] === 1 ? '' : 's' ?></em>
        <?php else: $late = $w['due_at'] && strtotime($w['due_at']) < $now; ?><em class="lms-count-pill<?= $late ? ' is-missing' : '' ?>"><?= $late ? 'Missing' : ($w['due_at'] ? 'Due ' . h(lms_when($w['due_at'], false)) : 'No due date') ?></em><?php endif; ?></a><?php endforeach; ?>
      <?php if (!$review): ?><p class="lms-muted mb-0"><?= $teach ? 'Nothing waiting to be checked.' : 'Nothing due. Nice work!' ?></p><?php endif; ?></section>

    <section class="lms-panel"><?= lms_panel_head('folder2', 'Course Materials', url('materials'), $teach ? 'Manage Materials' : 'View all') ?>
      <?php foreach (array_slice($files, 0, 4) as $f): ?><div class="lms-mat"><?= lms_file_badge($f['name'], $f['mime']) ?><a href="<?= url('file', ['id' => $f['id']]) ?>"><b><?= h($f['name']) ?></b><small><?= h(strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION))) ?> · <?= h(lms_size((int) $f['size_bytes'])) ?></small></a><span class="lms-mat-class"><?= h(lms_class_label($f['class'])) ?></span></div><?php endforeach; ?>
      <?php if (!$files): ?><p class="lms-muted mb-0">No files shared yet.</p><?php endif; ?></section>
  </div>
</div>
<?php layout_end();
