<?php
/** v61.1 Settings (teachers): the grading categories and weights of each class, with a shortcut to copy one class's setup to the others. */
declare(strict_types=1);
/** @var bool $isPost */
if (!lms_nav_allows('settings')) throw new Forbidden('Settings are for teachers.');
$sy = lms_year();
$classes = array_values(array_filter(Lms::myClasses($sy), fn($c) => $c['role'] !== 'STUDENT'));
if ($isPost && ($_POST['action'] ?? '') === 'copy') {
    $from = Lms::classFor((string) ($_POST['from'] ?? ''));
    $set = Lms::settings($from['id']); $n = 0;
    $in = ['cat' => array_keys($set['weights']), 'weight' => array_values(array_map('strval', $set['weights'])), 'count_missing' => $set['count_missing'] ? '1' : ''];
    foreach ((array) ($_POST['to'] ?? []) as $id) {
        if ($id === $from['id']) continue;
        $c = Lms::classFor((string) $id);
        Lms::saveSettings($c, $in + ['description' => Lms::settings($c['id'])['description']]); $n++;
    }
    flash($n ? 'success' : 'warning', $n ? 'Grading categories copied to ' . $n . ' class' . ($n === 1 ? '' : 'es') . '.' : 'Choose at least one class to copy to.');
    redirect('settings', array_filter(['sy' => $_GET['sy'] ?? null]));
}
layout_start('Settings');
lms_head('Settings', 'Grading categories and weights of your classes. Classwork is graded in these categories; the weights make the class-work grade.', '', lms_year_picker('settings', $sy));
?>
<div class="lms-cards lms-set-cards">
<?php foreach ($classes as $c): $s = Lms::settings($c['id']); ?><section class="lms-panel mb-0"><h2 class="mb-1"><?= h(lms_class_label($c)) ?></h2><p class="lms-muted small"><?= h($c['subjectCode'] . ' · ' . $c['term']) ?></p>
  <ul class="lms-weights"><?php foreach ($s['weights'] as $k => $v): ?><li><span><?= h($k) ?></span><b><?= lms_pts($v) ?>%</b></li><?php endforeach; ?></ul>
  <p class="small lms-muted"><?= $s['count_missing'] ? 'Missing past-due work counts as 0.' : 'Missing work is left out.' ?></p>
  <a class="btn btn-sm btn-outline-primary" href="<?= url('class', ['id' => $c['id'], 'tab' => 'settings']) ?>">Edit</a></section><?php endforeach; ?>
</div>
<?php if (!$classes): ?><div class="lms-panel lms-muted">No classes to set up.</div><?php endif; ?>
<?php if (count($classes) > 1): ?>
<form method="post" class="lms-panel mt-3" style="max-width:720px" data-confirm="Replace the grading categories of the chosen classes?"><?= csrf_field() ?><input type="hidden" name="action" value="copy">
  <h2>Copy grading categories</h2>
  <div class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label small" for="cF">From</label><select class="form-select" id="cF" name="from"><?php foreach ($classes as $c): ?><option value="<?= h($c['id']) ?>"><?= h($c['subjectCode'] . ' · ' . $c['sectionName']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-7"><span class="form-label small d-block">To</span><?php foreach ($classes as $c): ?><label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" name="to[]" value="<?= h($c['id']) ?>"> <?= h($c['subjectCode'] . ' · ' . $c['sectionName']) ?></label><?php endforeach; ?></div></div>
  <button class="btn btn-primary mt-2">Copy</button>
</form><?php endif; ?>
<?php layout_end();
