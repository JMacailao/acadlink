<?php
/** Curriculum and subjects = the grading system's records. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$canEdit = Auth::can('curriculum.edit');
$tab = ($_GET['tab'] ?? '') === 'subjects' ? 'subjects' : 'curriculum';
if ($isPost) {
    if (($_POST['action'] ?? '') === 'subject') {
        // v56.2: with lecture / laboratory units, Units = lecture + laboratory
        $splitIn = LecLab::on() ? LecLab::parse((string) ($_POST['lecUnits'] ?? ''), (string) ($_POST['labUnits'] ?? '')) : null;
        $unitsIn = $splitIn ? LecLab::text($splitIn[0] + $splitIn[1]) : (string) ($_POST['units'] ?? '');
        Db::tx(function () use (&$sid, $unitsIn, $splitIn) {
            $sid = GS::saveSubject(In::str($_POST, 'id', 191), (string) ($_POST['subjectCode'] ?? ''), (string) ($_POST['subjectName'] ?? ''), $unitsIn);
            GS::setSubjectType($sid, (string) ($_POST['subjectType'] ?? ''));
            if (LecLab::on()) GS::setSubjectSplit($sid, $splitIn);
        });
        flash('success', 'Subject saved. Its price per unit is set in Assessment → Subject Fees (₱0.00 until then).');
        redirect('curriculum', ['tab' => 'subjects']);
    }
    $sel = array_values(array_filter((array) ($_POST['subjectIds'] ?? []), 'is_string'));
    $id = GS::saveCurriculum((string) ($_POST['program'] ?? ''), (string) ($_POST['yearLevel'] ?? ''), (string) ($_POST['term'] ?? ''), $sel, (array)($_POST['subjectTeachers']??[]));
    $sync = GS::$lastCurriculumSync;
    flash('success', 'Curriculum saved. ' . ($sync['created'] ? $sync['created'] . ' missing Grading Assignment(s) created. ' : '')
        . ($sync['updated'] ? $sync['updated'] . ' active class(es) changed to the new teacher; their saved grades stay. ' : '')
        . ($sync['kept'] ? $sync['kept'] . ' class(es) with submitted grades kept their teacher: change those in Grading → Grading Assignments.' : ''));
    redirect('curriculum', ['open' => $id]);
}
$subjects = GS::subjects();
$teachers = GS::teachers();
$byId = [];
foreach ($subjects as $s) $byId[$s['id']] = $s;
layout_start('Curriculum');
page_title('Curriculum', $canEdit ? '<a class="btn btn-primary" href="#' . ($tab === 'subjects' ? 'addSubject' : 'addCurriculum') . '"><i class="bi bi-plus-lg me-1"></i>Add ' . ($tab === 'subjects' ? 'subject' : 'curriculum') . '</a>' : '', "Manage subjects and curriculum by program, year level and term.");
?>
<ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link<?= $tab === 'curriculum' ? ' active' : '' ?>" href="<?= url('curriculum') ?>">Curriculum</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'subjects' ? ' active' : '' ?>" href="<?= url('curriculum', ['tab' => 'subjects']) ?>">Subjects</a></li></ul>
<?php if ($tab === 'curriculum'):
    $list = RecordList::rows(fn()=>GS::curricula());
    $open = isset($_GET['open']) ? GS::get('curriculum', (string) $_GET['open']) : null; ?>
<div class="row g-3">
<div class="col-xl-7"><div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>Program</th><th>Year level</th><th>Term</th><th>Subjects</th><th>Units</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $c): $ids = (array) ($c['subjectIds'] ?? []); $units = array_sum(array_map(fn($i) => (float) ($byId[$i]['units'] ?? 0), $ids)); ?>
  <tr class="<?= $open && $open['id'] === $c['id'] ? 'table-primary' : '' ?>"><td class="fw-semibold"><?= h($c['program'] ?? '') ?></td><td><?= h($c['yearLevel'] ?? '') ?></td><td><?= h(($c['term'] ?? '') ?: 'No term') ?></td>
    <td><?= count($ids) ?></td><td><?= h($units) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('curriculum', ['open' => $c['id']]) ?>"><?= $canEdit ? 'Edit' : 'View' ?></a></td></tr>
  <?php endforeach; if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">No curriculum yet.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="col-xl-5">
  <form method="post" class="form-section" id="addCurriculum"><?= csrf_field() ?>
    <h2 class="h6"><?= $open ? h(($open['program'] ?? '') . ' · ' . ($open['yearLevel'] ?? '') . ' · ' . (($open['term'] ?? '') ?: 'No term')) : 'Add or edit a curriculum' ?></h2>
    <div class="row g-2 mb-2">
      <div class="col-4"><input class="form-control form-control-sm" name="program" list="programs" required maxlength="40" placeholder="Program" value="<?= h($open['program'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
      <div class="col-4"><select class="form-select form-select-sm" name="yearLevel" <?= $canEdit ? '' : 'disabled' ?>><?= list_options(YEAR_LEVELS, $open['yearLevel'] ?? '1st Year') ?></select></div>
      <div class="col-4"><select class="form-select form-select-sm" name="term" aria-label="Semester" required <?= $canEdit ? '' : 'disabled' ?>><?php if ($open && ($open['term'] ?? '') === ''): ?><option value="" selected>No semester (older record)</option><?php endif; ?><?= list_options(TERMS, $open ? (($open['term'] ?? '') ?: null) : '1st Semester') ?></select></div>
    </div>
    <datalist id="programs"><?php foreach (GS::programs() as $p): ?><option><?= h($p) ?></option><?php endforeach; ?></datalist>
    <input class="form-control form-control-sm mb-2" data-filter="#subjpick" placeholder="Filter subjects">
    <div style="max-height:360px;overflow:auto" class="border rounded p-2 mb-2"><table class="table table-sm mb-0" id="subjpick"><thead><tr><th>Select</th><th>Code</th><th>Subject</th><th>Units</th><th>Teacher</th></tr></thead><tbody>
    <?php $chosen = (array) ($open['subjectIds'] ?? []); foreach ($subjects as $s): ?><tr><td><input class="form-check-input" type="checkbox" name="subjectIds[]" value="<?= h($s['id']) ?>"<?= in_array($s['id'], $chosen, true) ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>></td>
      <td class="small font-monospace"><?= h($s['subjectCode'] ?? '') ?></td><td class="small"><?= h($s['subjectName'] ?? '') ?></td><td class="small"><?= h($s['units'] ?? '') ?></td><td><select class="form-select form-select-sm" name="subjectTeachers[<?= h($s['id']) ?>]" aria-label="<?= h('Teacher for '.($s['subjectName']??'')) ?>" <?= $canEdit?'':'disabled' ?>><option value="_acadlink_professor_x">Professor X · Needs teacher</option><?php foreach($teachers as $t): if($t['id']==='_acadlink_professor_x') continue; ?><option value="<?= h($t['id']) ?>" <?= ($open['subjectTeachers'][$s['id']]??'')===$t['id']?'selected':'' ?>><?= h($t['teacherName']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($canEdit): ?><button class="btn btn-sm btn-primary">Save curriculum</button><?php if ($open): ?> <a class="btn btn-sm btn-link" href="<?= url('curriculum') ?>">New instead</a><?php endif; ?><?php endif; ?>
    <div class="form-text">The program is the text before the year number in section names (BSIT 1A → BSIT). Blank teachers default to Professor X. Saving creates missing classes for matching sections; if there are no sections yet, classes are created when a matching section is saved. Changing a teacher here also changes the active classes (current school year, this semester) that still have the previous teacher; classes whose grades were already submitted keep their teacher. Saved grades are never changed.</div>
  </form>
</div></div>
<?php else: $edit = isset($_GET['edit']) ? GS::get('subjects', (string) $_GET['edit']) : null; ?>
<div class="row g-3">
<div class="<?= $canEdit ? 'col-xl-8' : 'col-12' ?>">
  <input class="form-control form-control-sm mb-2" data-filter="#subjects" placeholder="Search">
  <div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0" id="subjects"><thead class="table-light"><tr><th>Code</th><th>Subject</th><?php if (LecLab::on()): ?><th>Lec</th><th>Lab</th><?php endif; ?><th>Units</th><th>Type</th><th></th></tr></thead><tbody>
  <?php foreach (RecordList::rows(fn()=>$subjects) as $s): ?><tr><td class="font-monospace"><?= h($s['subjectCode'] ?? '') ?></td><td><?= h($s['subjectName'] ?? '') ?></td><?php if (LecLab::on()): $sp = LecLab::ofSubject($s); ?><td><?= h(LecLab::text($sp[0])) ?></td><td><?= h(LecLab::text($sp[1])) ?></td><?php endif; ?><td><?= h($s['units'] ?? '') ?></td><td class="small"><?= h($s['subjectType'] ?? '') ?></td>
    <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('curriculum', ['tab' => 'subjects', 'edit' => $s['id']]) ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if ($canEdit): ?>
<div class="col-xl-4"><form method="post" class="form-section" id="addSubject"><?= csrf_field() ?><input type="hidden" name="action" value="subject"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
  <h2 class="h6"><?= $edit ? 'Edit ' . h($edit['subjectName']) : 'Add a subject' ?></h2>
  <label class="form-label small">Code</label><input class="form-control form-control-sm mb-2" name="subjectCode" maxlength="40" value="<?= h($edit['subjectCode'] ?? '') ?>">
  <label class="form-label small">Subject name</label><input class="form-control form-control-sm mb-2" name="subjectName" required maxlength="150" value="<?= h($edit['subjectName'] ?? '') ?>">
  <?php if (LecLab::on()): $esp = $edit ? LecLab::ofSubject($edit) : [3, 0, 3]; ?>
  <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small" for="subLec">Lecture units</label><input class="form-control form-control-sm" id="subLec" name="lecUnits" inputmode="decimal" value="<?= h(LecLab::text($esp[0])) ?>"></div>
    <div class="col-6"><label class="form-label small" for="subLab">Laboratory units</label><input class="form-control form-control-sm" id="subLab" name="labUnits" inputmode="decimal" value="<?= h(LecLab::text($esp[1])) ?>"></div></div>
  <p class="form-text mt-0">Units = lecture + laboratory units.</p>
  <?php else: ?>
  <label class="form-label small">Units</label><input class="form-control form-control-sm mb-2" name="units" required value="<?= h($edit['units'] ?? '3') ?>">
  <?php endif; ?>
  <label class="form-label small">Subject type</label><select class="form-select form-select-sm mb-2" name="subjectType"><?= list_options(ImportAll::SUBJECT_TYPES, $edit['subjectType'] ?? null, 'Not set') ?></select>
  <p class="form-text mt-0">No fee is needed here: the price per unit is set in Assessment → Subject Fees.</p>
  <button class="btn btn-sm btn-primary">Save subject</button><?php if ($edit): ?> <a class="btn btn-sm btn-link" href="<?= url('curriculum', ['tab' => 'subjects']) ?>">Add instead</a><?php endif; ?>
</form></div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php layout_end();
