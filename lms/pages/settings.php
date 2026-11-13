<?php
/** v61.1 Settings (teachers): the grading categories and weights of each class, with a shortcut to copy one class's setup to the others. */
declare(strict_types=1);
/** @var bool $isPost */
if (!lms_nav_allows('settings')) throw new Forbidden('Settings are for teachers.');
$sy = lms_year();
$classes = array_values(array_filter(Lms::myClasses($sy), fn($c) => $c['role'] !== 'STUDENT'));
if ($isPost && ($_POST['action'] ?? '') === 'quota') { Lms::saveQuota((int) ($_POST['mb'] ?? 0)); flash('success', 'Storage limit saved.'); redirect('settings'); }
if ($isPost && ($_POST['action'] ?? '') === 'clean') {
    [$n, $b] = Lms::cleanYear((string) ($_POST['year'] ?? ''), (string) ($_POST['confirm'] ?? ''));
    flash('success', $n . ' file' . ($n === 1 ? '' : 's') . ' removed (' . Lms::mb($b) . ' freed). Grades, posts and hand-in records were kept.'); redirect('settings');
}
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
  <?php $used = Lms::classUsage($c['id']); $lim = Lms::quotaBytes(); ?><div class="lms-quota" title="File space used"><div><span style="width:<?= $lim ? min(100, (int) round($used * 100 / $lim)) : 0 ?>%"></span></div><small><?= h(Lms::mb($used)) ?><?= $lim ? ' of ' . h(Lms::mb($lim)) : '' ?> file space</small></div>
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
<?php if (Lms::me()['admin']): $limMb = (int) (Lms::quotaBytes() / 1048576); $byYear = Lms::usageByYear(); $current = GS::schoolYears()[0] ?? ''; ?>
<section class="lms-panel mt-3" style="max-width:860px"><h2><i class="bi bi-hdd" aria-hidden="true"></i>File storage <small>(coordinator)</small></h2>
  <form method="post" class="d-flex flex-wrap gap-2 align-items-end mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="quota">
    <div><label class="form-label small" for="qMb">Space for each class</label><div class="input-group" style="max-width:220px"><input class="form-control" id="qMb" name="mb" type="number" min="0" max="100000" value="<?= $limMb ?>"><span class="input-group-text">MB</span></div></div>
    <button class="btn btn-primary">Save</button><span class="small lms-muted">0 = no limit. Each file can be up to 10 MB. Files are kept in the database, so they are in every backup.</span></form>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>School year</th><th class="text-end">Classes with files</th><th class="text-end">Files</th><th class="text-end">Space</th><th></th></tr></thead><tbody>
  <?php foreach ($byYear as $y => $u): ?><tr><td><?= h($y) ?><?= $y === $current ? ' <span class="lms-pill">current</span>' : '' ?></td><td class="text-end"><?= $u['classes'] ?></td><td class="text-end"><?= $u['files'] ?></td><td class="text-end"><?= h(Lms::mb($u['bytes'])) ?></td>
    <td class="text-end"><?php if ($y !== $current && $y !== '(class removed)'): ?><form method="post" class="d-inline-flex gap-1" data-confirm="Delete every file of <?= h($y) ?> classes? Grades, posts and hand-in records stay; only the attached files are removed. This can't be undone."><?= csrf_field() ?><input type="hidden" name="action" value="clean"><input type="hidden" name="year" value="<?= h($y) ?>"><input class="form-control form-control-sm" name="confirm" placeholder="Type <?= h($y) ?>" aria-label="Type the school year to confirm" style="width:130px" required><button class="btn btn-sm btn-outline-primary">Free up space</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$byYear): ?><tr><td colspan="5" class="lms-muted">No files yet.</td></tr><?php endif; ?></tbody></table></div>
  <p class="small lms-muted mt-2 mb-0">Take a backup before freeing up space: removed files can only come back from a backup.</p></section>
<?php endif; ?>
<?php layout_end();
