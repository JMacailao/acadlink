<?php
/** v61.1 Assignments across classes: teachers see every assignment, quiz and exam with hand-ins and what to check; students see their work and its status. */
declare(strict_types=1);
$sy = lms_year();
$classes = Lms::myClasses($sy);
$teach = lms_teaches($classes);
$pick = (string) ($_GET['class'] ?? '');
$shown = $pick !== '' ? array_values(array_filter($classes, fn($c) => $c['id'] === $pick)) : $classes;
$show = in_array($_GET['show'] ?? '', ['review', 'todo', 'done'], true) ? $_GET['show'] : 'all';
$rows = Lms::allWork($shown);
if ($teach) {
    if ($show === 'review') $rows = array_filter($rows, fn($w) => (int) $w['to_grade'] > 0);
} else {
    foreach ($rows as &$w) $w['state'] = Lms::state($w, $w['mine']); unset($w);
    if ($show === 'todo') $rows = array_filter($rows, fn($w) => in_array($w['state'][1], ['assigned', 'missing'], true));
    if ($show === 'done') $rows = array_filter($rows, fn($w) => !in_array($w['state'][1], ['assigned', 'missing'], true));
}
$keep = array_filter(['sy' => $_GET['sy'] ?? null, 'class' => $pick ?: null]);
layout_start('Assignments');
lms_head('Assignments', $teach ? 'Assignments, activities, quizzes and exams of all your classes.' : 'Your assignments, quizzes and exams in every class.', '', lms_year_picker('assignments', $sy, array_filter(['class' => $pick])));
echo lms_class_chips('assignments', $classes, $pick, array_filter(['sy' => $_GET['sy'] ?? null, 'show' => $show !== 'all' ? $show : null]));
$tabs = $teach ? ['all' => 'All', 'review' => 'Awaiting review'] : ['all' => 'All', 'todo' => 'To do', 'done' => 'Turned in'];
?>
<nav class="lms-tabs" aria-label="Show"><?php foreach ($tabs as $k => $v): ?><a href="<?= url('assignments', $keep + ($k !== 'all' ? ['show' => $k] : [])) ?>"<?= $show === $k ? ' class="active" aria-current="page"' : '' ?>><?= h($v) ?></a><?php endforeach; ?></nav>
<section class="lms-panel">
<?php foreach ($rows as $w): $c = $w['class']; $quiz = $w['kind'] === 'QUIZ'; ?>
  <a class="lms-item" href="<?= url('work', ['id' => $c['id'], 'w' => $w['id']]) ?>">
    <span class="lms-item-icon<?= $quiz ? ' quiz' : '' ?>"><i class="bi bi-<?= $quiz ? 'ui-checks' : 'journal-text' ?>" aria-hidden="true"></i></span>
    <span class="lms-item-main"><b><?= h($w['title']) ?></b><small><?= h(lms_class_label($c)) ?> · <?= h($quiz ? 'Quiz / exam' : $w['category']) ?> · <?= lms_pts($w['points']) ?> pts · <?= $w['due_at'] ? 'Due ' . h(lms_when($w['due_at'])) : 'No due date' ?><?= $w['status'] === 'DRAFT' ? ' · Draft' : '' ?></small></span>
    <span class="lms-item-side"><?php if ($teach && $c['role'] !== 'STUDENT'): ?><span class="lms-count"><b><?= (int) $w['turned_in'] ?></b>/<?= count($c['studentIds']) ?> turned in</span><?php if ((int) $w['to_grade']): ?><em class="lms-count-pill"><?= (int) $w['to_grade'] ?> to check</em><?php endif; ?>
      <?php else: ?><?= lms_state_pill($w['state'] ?? Lms::state($w, $w['mine'] ?? null)) ?><?php if (($w['mine']['status'] ?? '') === 'RETURNED' && $w['mine']['score'] !== null): ?><span class="lms-count"><b><?= lms_pts($w['mine']['score']) ?></b>/<?= lms_pts($w['points']) ?></span><?php endif; ?><?php endif; ?></span>
  </a>
<?php endforeach; if (!$rows): ?><p class="lms-muted mb-0"><?= $show === 'review' ? 'Nothing waiting to be checked.' : ($show === 'todo' ? 'Nothing to do. Nice work!' : 'No classwork yet.') ?><?= $teach && $show === 'all' ? ' Open a class and use Classwork → Create.' : '' ?></p><?php endif; ?>
</section>
<?php layout_end();
