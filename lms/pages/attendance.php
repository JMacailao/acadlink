<?php
/** v61.1 Attendance across classes. Teachers: today's meetings with Take attendance, today's totals, and each class's rate. Students: their own record per class. */
declare(strict_types=1);
$sy = lms_year();
$classes = Lms::myClasses($sy);
$teach = lms_teaches($classes);
$date = In::date($_GET, 'date') ?? date('Y-m-d');
if ($date > date('Y-m-d')) $date = date('Y-m-d');
$ts = strtotime($date);
layout_start('Attendance');
lms_head('Attendance', $teach ? 'Take attendance for each meeting and see how every class is doing.' : 'Your attendance in each class.', '', lms_year_picker('attendance', $sy));
if ($teach):
    $mine = array_values(array_filter($classes, fn($c) => $c['role'] !== 'STUDENT'));
    $meet = Lms::meetingsOn(Lms::allMeetings($mine), $ts);
    $att = Lms::attendanceOn(array_column($mine, 'id'), $date);
    $taken = [];
    foreach (Db::all('SELECT s.class_id, s.label, SUM(a.status = \'PRESENT\') p, SUM(a.status = \'LATE\') l, SUM(a.status = \'ABSENT\') a, COUNT(a.student_doc_id) n FROM lms_attendance_sessions s LEFT JOIN lms_attendance a ON a.session_id = s.id WHERE s.meeting_date = ? GROUP BY s.id', [$date]) as $r) $taken[$r['class_id'] . '|' . $r['label']] = $r;
?>
<div class="lms-two">
  <section class="lms-panel">
    <div class="lms-ph"><h2><i class="bi bi-calendar-day" aria-hidden="true"></i>Classes on <?= h(date('D, M j', $ts)) ?></h2>
      <form method="get" class="lms-year"><input type="hidden" name="r" value="attendance"><?php if ($sy): ?><input type="hidden" name="sy" value="<?= h($sy) ?>"><?php endif; ?><input class="form-control form-control-sm" type="date" name="date" value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>" aria-label="Date" data-autosubmit></form></div>
    <?php foreach ($meet as $m): $k = $m['class']['id'] . '|' . $m['label']; $t = $taken[$k] ?? null; ?><div class="lms-tc">
      <span class="lms-period">P<?= (int) $m['period'] ?></span><span class="lms-tc-name"><?= h(lms_class_label($m['class'])) ?><?php if ($t): ?><small class="d-block lms-muted"><?= (int) $t['p'] ?> present · <?= (int) $t['l'] ?> late · <?= (int) $t['a'] ?> absent</small><?php endif; ?></span>
      <span class="lms-tc-time"><?= h(Schedules::time($m['start_time'])) ?> – <?= h(Schedules::time($m['end_time'])) ?></span>
      <a class="btn btn-sm <?= $t ? 'btn-outline-primary' : 'btn-primary' ?> lms-tc-btn" href="<?= url('class', ['id' => $m['class']['id'], 'tab' => 'attendance', 'date' => $date, 'label' => $m['label']]) ?>"><?= $t ? 'Edit attendance' : 'Take Attendance' ?></a></div><?php endforeach; ?>
    <?php if (!$meet): ?><p class="lms-muted mb-2">No classes scheduled on this day.</p><?php endif; ?>
    <p class="lms-muted small mt-2 mb-0">For a make-up or extra meeting, open the class and use its Attendance tab.</p>
  </section>
  <section class="lms-panel"><?= lms_panel_head('people', 'Summary', '', '', date('M j', $ts)) ?>
    <div class="lms-attsum"><div class="lms-attsum-big"><b><?= $att['total'] ? (int) round($att['present'] * 100 / $att['total']) . '%' : '—' ?></b><small><?= $att['total'] ? $att['present'] . ' of ' . $att['total'] . ' present' : 'Not taken yet' ?></small></div>
      <div class="lms-attsum-tiles"><span class="is-present"><b><?= $att['present'] ?></b>Present</span><span class="is-absent"><b><?= $att['absent'] ?></b>Absent</span><span class="is-late"><b><?= $att['late'] ?></b>Late</span></div></div></section>
</div>
<section class="lms-panel"><?= lms_panel_head('bar-chart', 'By class', '', '', 'whole term') ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Class</th><th class="text-end">Students</th><th class="text-end">Meetings</th><th class="text-end">Absences</th><th class="text-end">Attendance rate</th><th></th></tr></thead><tbody>
  <?php foreach ($mine as $c): $x = Db::one("SELECT COUNT(DISTINCT s.id) m, SUM(a.status = 'ABSENT') ab, SUM(a.status IN ('PRESENT','LATE','EXCUSED')) ok, COUNT(a.student_doc_id) n FROM lms_attendance_sessions s LEFT JOIN lms_attendance a ON a.session_id = s.id WHERE s.class_id = ?", [$c['id']]); ?>
    <tr><td><b><?= h($c['subjectCode']) ?></b> <?= h(lms_class_label($c)) ?></td><td class="text-end"><?= count($c['studentIds']) ?></td><td class="text-end"><?= (int) $x['m'] ?></td><td class="text-end"><?= (int) $x['ab'] ?></td>
      <td class="text-end fw-semibold"><?= (int) $x['n'] ? lms_pts(round((int) $x['ok'] * 100 / (int) $x['n'], 1)) . '%' : '—' ?></td><td class="text-end"><a href="<?= url('class', ['id' => $c['id'], 'tab' => 'attendance']) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div></section>
<?php else: ?>
<section class="lms-panel"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Class</th><th class="text-end">Present</th><th class="text-end">Late</th><th class="text-end">Absent</th><th class="text-end">Excused</th><th class="text-end">Rate</th><th></th></tr></thead><tbody>
<?php foreach ($classes as $c): $x = Lms::myAttendanceTotals([$c['id']]); ?><tr><td><b><?= h($c['subjectCode']) ?></b> <?= h(lms_class_label($c)) ?></td><td class="text-end"><?= $x['present'] ?></td><td class="text-end"><?= $x['late'] ?></td><td class="text-end <?= $x['absent'] ? 'text-danger fw-semibold' : '' ?>"><?= $x['absent'] ?></td><td class="text-end"><?= $x['excused'] ?></td>
  <td class="text-end fw-semibold"><?= $x['total'] ? lms_pts(round(($x['present'] + $x['late'] + $x['excused']) * 100 / $x['total'], 1)) . '%' : '—' ?></td><td class="text-end"><a href="<?= url('class', ['id' => $c['id'], 'tab' => 'attendance']) ?>">Details</a></td></tr><?php endforeach; ?>
<?php if (!$classes): ?><tr><td colspan="7" class="lms-muted">No classes yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif;
layout_end();
