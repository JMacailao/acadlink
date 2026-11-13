<?php
/** v61.1 Gradebook: each class with its class-work average (teachers), or the student's own grade in each class. Opens the class's Grades tab. */
declare(strict_types=1);
$sy = lms_year();
$classes = Lms::myClasses($sy);
$teach = lms_teaches($classes);
if (isset($_GET['class'])) { $c = Lms::classFor((string) $_GET['class']); redirect('class', ['id' => $c['id'], 'tab' => 'grades']); }
layout_start('Gradebook');
lms_head('Gradebook', $teach ? 'Class-work grades of your classes. Open a class to see every score and to send the grades to the Grading System as drafts.' : 'Your class-work grade in each class.', '', lms_year_picker('gradebook', $sy));
$me = Lms::me();
?>
<section class="lms-panel"><div class="table-responsive"><table class="table align-middle mb-0">
<?php if ($teach): ?><thead><tr><th>Class</th><th class="text-end">Students</th><th class="text-end">Graded work</th><th class="text-end">With a grade</th><th class="text-end">Passing</th><th class="text-end">Class average</th><th></th></tr></thead><tbody>
  <?php foreach ($classes as $c): if ($c['role'] === 'STUDENT') continue; $r = Lms::classReport($c); ?><tr><td><b><?= h($c['subjectCode']) ?></b> <?= h(lms_class_label($c)) ?><small class="d-block lms-muted"><?= h($c['term']) ?></small></td>
    <td class="text-end"><?= $r['students'] ?></td><td class="text-end"><?= $r['works'] ?></td><td class="text-end"><?= $r['graded'] ?></td><td class="text-end"><?= $r['passing'] ?></td>
    <td class="text-end fw-bold"><?= $r['average'] === null ? '—' : lms_pts($r['average']) . '%' ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('class', ['id' => $c['id'], 'tab' => 'grades']) ?>">Open gradebook</a></td></tr><?php endforeach; ?>
<?php else: ?><thead><tr><th>Class</th><th>Teacher</th><th class="text-end">Class work</th><th></th></tr></thead><tbody>
  <?php foreach ($classes as $c): $g = Lms::gradebook($c); $row = $g['rows'][$me['student']] ?? null; ?><tr><td><b><?= h($c['subjectCode']) ?></b> <?= h(lms_class_label($c)) ?></td><td><?= h($c['teacherName']) ?></td>
    <td class="text-end fw-bold"><?= $row && $row['percent'] !== null ? lms_pts($row['percent']) . '%' : '—' ?></td><td class="text-end"><a href="<?= url('class', ['id' => $c['id'], 'tab' => 'grades']) ?>">Details</a></td></tr><?php endforeach; ?>
<?php endif; if (!$classes): ?><tr><td class="lms-muted">No classes yet.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="lms-muted small mt-2 mb-0">Official final grades are given in the Grading System<?= $teach ? '. Sending from a class puts these as drafts on your grade sheet; you still review and submit them there.' : ' and appear in the Student Portal.' ?></p></section>
<?php layout_end();
