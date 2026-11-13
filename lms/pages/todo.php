<?php
/** To do: a student's work due and missing across classes; a teacher's work waiting to be graded. */
declare(strict_types=1);
$classes = Lms::myClasses();
$rows = Lms::todo($classes);
$me = Lms::me();
layout_start('To Do');
?>
<h1 class="lms-h1"><?= $me['student'] && !$me['teacher'] ? 'To do' : 'To grade' ?></h1>
<section class="lms-panel">
<?php foreach ($rows as $t): ?><a class="lms-todo" href="<?= url('work', ['id' => $t['class_id'], 'w' => $t['id']]) ?>"><b><?= h($t['title']) ?></b>
  <span><?= h($t['class_code']) ?> · <?= h($t['kind'] === 'QUIZ' ? 'Quiz' : $t['category']) ?><?= isset($t['to_grade']) ? ' · ' . (int) $t['to_grade'] . ' turned in' : ($t['due_at'] ? ' · due ' . h(lms_when($t['due_at'])) : ' · no due date') ?></span>
  <?php if (!isset($t['to_grade']) && $t['due_at'] && strtotime($t['due_at']) < time()): ?><em class="lms-pill is-missing">Missing</em><?php endif; ?></a>
<?php endforeach; if (!$rows): ?><p class="lms-muted mb-0">Nothing to do. Nice work!</p><?php endif; ?>
</section>
<?php layout_end();
