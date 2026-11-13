<?php
/** My Classes: the classes the signed-in person teaches or takes, as cards, with what to do next and today's classes. */
declare(strict_types=1);
$years = GS::schoolYears();
$sy = in_array($_GET['sy'] ?? '', $years, true) ? $_GET['sy'] : ($years[0] ?? '');
$classes = Lms::myClasses($sy);
$todo = Lms::todo($classes);
$me = Lms::me();
$counts = [];
foreach ($classes as $c) {
    $counts[$c['id']] = in_array($c['role'], ['TEACHER', 'ADMIN'], true)
        ? ['to_grade' => (int) Db::val("SELECT COUNT(*) FROM lms_submissions s JOIN lms_work w ON w.id = s.work_id WHERE w.class_id = ? AND w.deleted_at IS NULL AND s.status = 'SUBMITTED'", [$c['id']]), 'students' => count($c['studentIds'])]
        : ['due' => (int) Db::val("SELECT COUNT(*) FROM lms_work w LEFT JOIN lms_submissions s ON s.work_id = w.id AND s.student_doc_id = ? WHERE w.class_id = ? AND w.deleted_at IS NULL AND w.status = 'PUBLISHED' AND (s.status IS NULL OR s.status = 'DRAFT') AND (w.due_at IS NULL OR w.due_at >= NOW())", [$me['student'], $c['id']])];
}
$today = date('D') === 'Thu' ? 'Th' : (date('D') === 'Sun' ? 'Su' : substr(date('D'), 0, 1));
$todays = [];
foreach ($classes as $c) foreach (Lms::meetings($c) as $m) if (in_array($today, Schedules::parseDays(explode(',', (string) $m['days'])), true)) $todays[] = $m + ['class' => $c];
usort($todays, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
layout_start('My Classes');
?>
<div class="lms-home-head">
  <div><h1 class="lms-h1">My Classes</h1>
    <p class="lms-muted"><?= $me['teacher'] ? 'Your classes this school year.' : ($me['student'] ? 'Your classes, work to do and announcements.' : 'Every class of the school year (coordinator view).') ?></p></div>
  <?php if (count($years) > 1): ?><form method="get"><input type="hidden" name="r" value="classes"><select class="form-select" name="sy" aria-label="School year" data-autosubmit><?php foreach ($years as $y): ?><option<?= $y === $sy ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div>
<div class="lms-home">
  <div>
    <div class="lms-cards">
    <?php foreach ($classes as $c): $n = $counts[$c['id']]; ?>
      <a class="lms-card" href="<?= url('class', ['id' => $c['id']]) ?>" style="--h:<?= lms_hue($c['subjectCode'] . $c['sectionName']) ?>">
        <div class="lms-card-top"><b><?= h($c['subjectName']) ?></b><span><?= h($c['subjectCode']) ?> · <?= h($c['sectionName']) ?></span><small><?= h($c['teacherName']) ?></small><i class="bi bi-mortarboard" aria-hidden="true"></i></div>
        <div class="lms-card-body"><span><?= h($c['term']) ?></span>
          <?php if (isset($n['to_grade'])): ?><span class="<?= $n['to_grade'] ? 'lms-hot' : '' ?>"><i class="bi bi-inbox" aria-hidden="true"></i><?= $n['to_grade'] ?> to grade · <?= $n['students'] ?> students</span>
          <?php else: ?><span class="<?= $n['due'] ? 'lms-hot' : '' ?>"><i class="bi bi-journal-check" aria-hidden="true"></i><?= $n['due'] ? $n['due'] . ' to do' : 'All caught up' ?></span><?php endif; ?></div>
      </a>
    <?php endforeach; ?>
    </div>
    <?php if (!$classes): ?><div class="lms-panel lms-empty"><i class="bi bi-easel2" aria-hidden="true"></i><p><?= $me['teacher'] ? 'No classes are assigned to you for ' . h($sy) . ' yet. The registrar assigns classes in the Grading System (Teachers and class assignments).' : ($me['student'] ? 'You are not in any class for ' . h($sy) . ' yet. Your classes appear once you are on a class list in the Grading System.' : 'Your account is not linked to a teacher or student record. Ask the Power User to link it, or give it the LMS coordinator access.') ?></p></div><?php endif; ?>
  </div>
  <aside>
    <section class="lms-panel"><h2><i class="bi bi-check2-square" aria-hidden="true"></i><?= $me['student'] && !$me['teacher'] ? 'To do' : 'To grade' ?></h2>
      <?php foreach ($todo as $t): ?><a class="lms-todo" href="<?= url('work', ['id' => $t['class_id'], 'w' => $t['id']]) ?>"><b><?= h($t['title']) ?></b><span><?= h($t['class_code']) ?><?= isset($t['to_grade']) ? ' · ' . (int) $t['to_grade'] . ' turned in' : ($t['due_at'] ? ' · due ' . h(lms_when($t['due_at'])) : '') ?></span>
        <?php if (!isset($t['to_grade']) && $t['due_at'] && strtotime($t['due_at']) < time()): ?><em class="lms-pill is-missing">Missing</em><?php endif; ?></a><?php endforeach; ?>
      <?php if (!$todo): ?><p class="lms-muted mb-0">Nothing right now.</p><?php else: ?><a class="small" href="<?= url('todo') ?>">See all</a><?php endif; ?></section>
    <section class="lms-panel"><h2><i class="bi bi-calendar-day" aria-hidden="true"></i>Today</h2>
      <?php foreach ($todays as $m): ?><div class="lms-today"><b><?= h(Schedules::timeRange($m)) ?></b><span><?= h($m['class']['subjectCode']) ?> · <?= h($m['class']['sectionName']) ?><?= $m['room_code'] ? ' · Room ' . h($m['room_code']) : '' ?></span></div><?php endforeach; ?>
      <?php if (!$todays): ?><p class="lms-muted mb-0">No classes scheduled today.</p><?php endif; ?></section>
  </aside>
</div>
<?php layout_end();
