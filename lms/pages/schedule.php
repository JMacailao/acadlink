<?php
/** My schedule: the week of the signed-in person's classes, from the SIS class schedule, with clashes flagged. */
declare(strict_types=1);
$classes = Lms::myClasses();
$rows = [];
foreach ($classes as $c) foreach (Lms::meetings($c) as $m) $rows[] = $m;
$clashes = [];
foreach ($rows as $m) foreach (Schedules::conflicts($m, (int) $m['id']) as $x) {
    $mine = array_filter($rows, fn($r) => (int) $r['id'] === (int) $x['id']);
    if ($mine || in_array('teacher ' . $x['teacher_name'], $x['why'], true)) $clashes[(int) $m['id']][] = $x['subject_code'] . ' ' . $x['section_name'] . ' (' . implode(', ', $x['why']) . ')';
}
layout_start('Schedule');
?>
<h1 class="lms-h1">My schedule</h1>
<p class="lms-muted">From the class schedule the registrar keeps in the SIS. Rooms, teachers and sections can't be booked twice at the same time there; anything that still clashes is shown below.</p>
<?php if ($clashes): ?><div class="alert alert-danger"><b>Schedule clashes:</b><ul class="mb-0"><?php foreach ($rows as $m) if (!empty($clashes[(int) $m['id']])): ?><li><?= h($m['subject_code'] . ' ' . $m['section_name'] . ' ' . Schedules::daysText($m['days']) . ' ' . Schedules::timeRange($m)) ?> clashes with <?= h(implode('; ', $clashes[(int) $m['id']])) ?></li><?php endif; ?></ul></div><?php endif; ?>
<section class="lms-panel"><?= $rows ? Schedules::grid($rows, Lms::me()['teacher'] ? 'teacher' : 'section') : '<p class="lms-muted mb-0">No class times are set for your classes yet.</p>' ?></section>
<?php if ($rows): ?><section class="lms-panel"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Class</th><th>Days</th><th>Time</th><th>Room</th><th>Teacher</th></tr></thead><tbody>
<?php foreach ($rows as $m): ?><tr><td><b><?= h($m['subject_code']) ?></b> <?= h($m['subject_name']) ?> · <?= h($m['section_name']) ?></td><td><?= h(Schedules::daysText($m['days'])) ?></td><td><?= h(Schedules::timeRange($m)) ?></td><td><?= h((string) $m['room_code']) ?></td><td><?= h($m['teacher_name']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php endif; ?>
<?php layout_end();
