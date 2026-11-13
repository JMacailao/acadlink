<?php
/** v61.1 Materials: every file shared in the person's classes (stream posts, materials and classwork), searchable and by class. */
declare(strict_types=1);
$sy = lms_year();
$classes = Lms::myClasses($sy);
$teach = lms_teaches($classes);
$pick = (string) ($_GET['class'] ?? '');
$shown = $pick !== '' ? array_values(array_filter($classes, fn($c) => $c['id'] === $pick)) : $classes;
$q = trim((string) ($_GET['q'] ?? ''));
$files = Lms::materials($shown);
if ($q !== '') $files = array_values(array_filter($files, fn($f) => stripos($f['name'] . ' ' . $f['source'], $q) !== false));
layout_start('Materials');
lms_head('Course Materials', $teach ? 'Files you shared in your classes. To add one, open a class and post a material or attach it to classwork.' : 'Handouts and files your teachers shared.', '', lms_year_picker('materials', $sy, array_filter(['class' => $pick])));
echo lms_class_chips('materials', $classes, $pick, array_filter(['sy' => $_GET['sy'] ?? null]));
?>
<section class="lms-panel">
  <form method="get" class="lms-search mb-2"><input type="hidden" name="r" value="materials"><?php foreach (array_filter(['sy' => $_GET['sy'] ?? null, 'class' => $pick]) as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string) $v) ?>"><?php endforeach; ?>
    <i class="bi bi-search" aria-hidden="true"></i><input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search files" aria-label="Search files"></form>
  <?php foreach ($files as $f): $c = $f['class']; ?><div class="lms-mat"><?= lms_file_badge($f['name'], $f['mime']) ?>
    <a href="<?= url('file', ['id' => $f['id']]) ?>"><b><?= h($f['name']) ?></b><small><?= h(strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION))) ?> · <?= h(lms_size((int) $f['size_bytes'])) ?> · <?= h(lms_when($f['created_at'], false)) ?></small></a>
    <span class="lms-mat-class"><?= h(lms_class_label($c)) ?><small class="d-block"><?= $f['work_id'] ? '<a href="' . h(url('work', ['id' => $c['id'], 'w' => $f['work_id']])) . '">' . h($f['source']) . '</a>' : '<a href="' . h(url('class', ['id' => $c['id'], 'tab' => $f['source_kind'] === 'MATERIAL' ? 'classwork' : 'stream'])) . '">' . h($f['source']) . '</a>' ?></small></span></div>
  <?php endforeach; if (!$files): ?><p class="lms-muted mb-0"><?= $q !== '' ? 'No files match.' : 'No files shared yet.' ?></p><?php endif; ?>
</section>
<?php layout_end();
