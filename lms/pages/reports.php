<?php
/** v61.1 Reports (teachers and coordinators): per class — students, hand-ins, late and missing work, class average and attendance. Printable. */
declare(strict_types=1);
if (!lms_nav_allows('reports')) throw new Forbidden('Reports are for teachers.');
$sy = lms_year();
$classes = array_values(array_filter(Lms::myClasses($sy), fn($c) => $c['role'] !== 'STUDENT'));
layout_start('Reports');
lms_head('Reports', 'A summary of each class for ' . ($sy ?: 'this school year') . '.', '', lms_year_picker('reports', $sy) . '<button type="button" class="btn btn-outline-primary btn-sm d-print-none" data-page-print><i class="bi bi-printer me-1"></i>Print</button>');
$tot = ['students' => 0, 'handed' => 0, 'expected' => 0, 'missing' => 0];
$rows = [];
foreach ($classes as $c) { $r = Lms::classReport($c); $rows[] = [$c, $r]; foreach ($tot as $k => $_) $tot[$k] += $r[$k]; }
$avg = array_filter(array_map(fn($x) => $x[1]['average'], $rows), fn($v) => $v !== null);
$att = array_filter(array_map(fn($x) => $x[1]['attendance'], $rows), fn($v) => $v !== null);
?>
<div class="lms-stats">
  <?= lms_stat('people', 'Students', (string) $tot['students'], count($classes) . ' class' . (count($classes) === 1 ? '' : 'es')) ?>
  <?= lms_stat('inbox', 'Hand-in rate', $tot['expected'] ? (int) round($tot['handed'] * 100 / $tot['expected']) . '%' : '—', $tot['handed'] . ' of ' . $tot['expected'] . ' turned in') ?>
  <?= lms_stat('exclamation-circle', 'Missing work', (string) $tot['missing'], 'past due, not turned in') ?>
  <?= lms_stat('graph-up', 'Average', $avg ? lms_pts(round(array_sum($avg) / count($avg), 1)) . '%' : '—', $att ? 'attendance ' . lms_pts(round(array_sum($att) / count($att), 1)) . '%' : 'no attendance yet') ?>
</div>
<section class="lms-panel"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Class</th><th class="text-end">Students</th><th class="text-end">Classwork</th><th class="text-end">Turned in</th><th class="text-end">Late</th><th class="text-end">Missing</th><th class="text-end">Average</th><th class="text-end">Passing</th><th class="text-end">Meetings</th><th class="text-end">Attendance</th></tr></thead><tbody>
<?php foreach ($rows as [$c, $r]): ?><tr><td><a href="<?= url('class', ['id' => $c['id'], 'tab' => 'grades']) ?>"><b><?= h($c['subjectCode']) ?></b> <?= h(lms_class_label($c)) ?></a><small class="d-block lms-muted"><?= h($c['teacherName'] . ' · ' . $c['term']) ?></small></td>
  <td class="text-end"><?= $r['students'] ?></td><td class="text-end"><?= $r['works'] ?></td><td class="text-end"><?= $r['expected'] ? (int) round($r['handed'] * 100 / $r['expected']) . '%' : '—' ?></td><td class="text-end"><?= $r['late'] ?></td><td class="text-end <?= $r['missing'] ? 'text-danger fw-semibold' : '' ?>"><?= $r['missing'] ?></td>
  <td class="text-end fw-bold"><?= $r['average'] === null ? '—' : lms_pts($r['average']) . '%' ?></td><td class="text-end"><?= $r['graded'] ? $r['passing'] . ' of ' . $r['graded'] : '—' ?></td><td class="text-end"><?= $r['meetings'] ?></td><td class="text-end"><?= $r['attendance'] === null ? '—' : lms_pts($r['attendance']) . '%' ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="10" class="lms-muted">No classes yet.</td></tr><?php endif; ?></tbody></table></div>
<p class="lms-muted small mt-2 mb-0">Averages are class-work grades from the LMS gradebook; passing uses the school's grade scale. Official grades are in the Grading System.</p></section>
<?php layout_end();
