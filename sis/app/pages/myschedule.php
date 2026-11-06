<?php
declare(strict_types=1);
/** My class schedule (v53): a teacher's weekly timetable (opened from the Grading System's sidebar). */
$a = Auth::central()->current() ?: throw new AuthRequired();
$teacher = !empty($a['person_verified']) && !empty($a['teacher_doc_id']) ? (string) $a['teacher_doc_id'] : '';
$terms = $teacher !== '' ? Db::all('SELECT DISTINCT school_year, term FROM sis_class_schedules WHERE teacher_doc_id = ? ORDER BY school_year DESC, FIELD(term,"Summer","3rd Semester","2nd Semester","1st Semester")', [$teacher]) : [];
$pick = (string) ($_GET['t'] ?? (($terms[0]['school_year'] ?? '') . '|' . ($terms[0]['term'] ?? '')));
[$sy, $term] = array_pad(explode('|', $pick), 2, '');
$rows = $teacher !== '' && $sy !== '' ? Schedules::list(['teacher' => $teacher, 'school_year' => $sy, 'term' => $term]) : [];
layout_start('My class schedule');
page_title('My class schedule', '<a class="btn btn-outline-secondary" href="../pages/dashboard.html"><i class="bi bi-arrow-left"></i> Grading System</a>', h((string) $a['display_name']));
?>
<div data-record-detail>
<?php if ($teacher === ''): ?><div class="alert alert-info">This account is not linked to a teacher record, so it has no class schedule.</div>
<?php else: ?>
<form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end d-print-none"><input type="hidden" name="r" value="myschedule">
  <div><label class="form-label small mb-0" for="msT">Term</label><select class="form-select" id="msT" name="t"><?php foreach ($terms as $t): $k = $t['school_year'] . '|' . $t['term']; ?><option value="<?= h($k) ?>"<?= $k === $pick ? ' selected' : '' ?>><?= h($t['school_year'] . ' · ' . $t['term']) ?></option><?php endforeach; if (!$terms): ?><option value="">No schedule yet</option><?php endif; ?></select></div>
  <button class="btn btn-outline-primary">Show</button>
  <div class="doc-toolbar ms-auto"><button type="button" class="btn btn-outline-secondary" id="docPrint"><i class="bi bi-printer"></i> Print</button></div>
</form>
<div class="form-section"><h2 class="h6"><?= h($term . ' ' . $sy) ?></h2><?= Schedules::grid($rows, 'teacher') ?></div>
<div class="form-section"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Subject</th><th>Section</th><th>Days</th><th>Time</th><th>Room</th></tr></thead><tbody>
<?php foreach ($rows as $s): ?><tr><td><span class="font-monospace"><?= h($s['subject_code']) ?></span> <?= h($s['subject_name']) ?> <span class="small text-muted"><?= h(Schedules::KINDS[$s['kind']]) ?></span></td><td><?= h($s['section_name']) ?></td><td><?= h(Schedules::daysText($s['days'])) ?></td><td class="text-nowrap"><?= h(Schedules::timeRange($s)) ?></td><td><?= h($s['room_code'] ?: '—') ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-muted text-center py-3">No classes scheduled.</td></tr><?php endif; ?></tbody></table></div></div>
<script src="assets/doc-print.js?v=50"></script>
<?php endif; ?>
</div>
<?php layout_end();
