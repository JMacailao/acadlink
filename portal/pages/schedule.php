<?php
declare(strict_types=1);
/** Student Portal → My schedule (v53): the weekly class schedule of the student's subjects in a term. */
$num = Auth::studentNumber();
$regs = $num ? Db::all("SELECT * FROM sis_registrations WHERE student_number = ? AND status = 'REGISTERED' ORDER BY school_year DESC, FIELD(term,'Summer','3rd Semester','2nd Semester','1st Semester')", [$num]) : [];
$pick = (int) ($_GET['reg'] ?? ($regs[0]['id'] ?? 0));
$r = null; foreach ($regs as $x) if ((int) $x['id'] === $pick) $r = $x;
$subs = $r ? Db::all('SELECT subject_doc_id, subject_code, subject_name, units FROM sis_registration_subjects WHERE registration_id = ? ORDER BY subject_code', [$r['id']]) : [];
$sched = $r ? Schedules::forRegistration($r, array_column($subs, 'subject_doc_id')) : ['rows' => [], 'clashes' => []];
layout_start('My schedule');
page_title('My schedule', '', 'Your classes for the term: days, times, rooms and teachers.');
?>
<?php if (!$regs): ?><div class="form-section"><p class="mb-0 text-muted">Your schedule appears once you are registered for a term.</p></div>
<?php else: ?>
<form method="get" class="form-section d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="r" value="schedule">
  <div><label class="form-label small mb-0" for="msReg">Term</label><select class="form-select" id="msReg" name="reg"><?php foreach ($regs as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) $x['id'] === $pick ? ' selected' : '' ?>><?= h($x['school_year'] . ' · ' . $x['term'] . ' · ' . $x['section_name']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-outline-primary">Show</button>
</form>
<section class="form-section"><h2 class="h6"><?= h($r['school_year'] . ' · ' . $r['term'] . ' · ' . $r['section_name']) ?></h2><?= Schedules::grid($sched['rows'], 'section', (string) $r['section_name']) ?></section>
<section class="form-section"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Subject</th><th>Days</th><th>Time</th><th>Room</th><th>Teacher</th></tr></thead><tbody>
<?php foreach ($subs as $s): $meet = array_filter($sched['rows'], fn($m) => $m['subject_doc_id'] === $s['subject_doc_id']); ?>
  <?php if (!$meet): ?><tr><td><span class="font-monospace"><?= h($s['subject_code']) ?></span> <?= h($s['subject_name']) ?></td><td colspan="4" class="text-muted">Schedule not set yet</td></tr><?php endif; ?>
  <?php foreach ($meet as $m): ?><tr><td><span class="font-monospace"><?= h($s['subject_code']) ?></span> <?= h($s['subject_name']) ?> <span class="small text-muted"><?= h(Schedules::KINDS[$m['kind']]) ?></span></td><td><?= h(Schedules::daysText($m['days'])) ?></td><td class="text-nowrap"><?= h(Schedules::timeRange($m)) ?></td><td><?= h($m['room_code'] ?: '—') ?></td><td><?= h($m['teacher_name'] ?: '—') ?></td></tr><?php endforeach; ?>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php layout_end();
