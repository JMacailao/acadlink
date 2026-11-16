<?php
/** Curriculum and subjects = the grading system's records. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$canEdit = Auth::can('curriculum.edit');
$tab = ($_GET['tab'] ?? '') === 'subjects' ? 'subjects' : 'curriculum';
if ($isPost) {
    if (($_POST['action'] ?? '') === 'units') { // v66: lecture / lab units of many subjects at once
        $ids = array_values((array) ($_POST['u_id'] ?? [])); $lecs = array_values((array) ($_POST['u_lec'] ?? [])); $labs = array_values((array) ($_POST['u_lab'] ?? []));
        if (count($ids) !== count($lecs) || count($ids) !== count($labs) || count($ids) > 300) throw new AppError('The list was not sent completely, or too many subjects were changed at once (at most 300). Reload the page and try again.'); // v66.4: only changed rows are sent
        $changed = 0;
        Db::tx(function () use ($ids, $lecs, $labs, &$changed) {
            foreach ($ids as $i => $sid) {
                $sub = GS::get('subjects', (string) $sid); if (!$sub) continue;
                $old = LecLab::ofSubject($sub);
                try { $split = LecLab::parse((string) $lecs[$i], (string) $labs[$i]); }
                catch (AppError $e) { throw new AppError(($sub['subjectCode'] ?? 'A subject') . ': ' . $e->getMessage()); }
                if (!$split || ((float) $split[0] === (float) $old[0] && (float) $split[1] === (float) $old[1])) continue; // unchanged
                GS::saveSubject((string) $sid, (string) ($sub['subjectCode'] ?? ''), (string) ($sub['subjectName'] ?? ''), LecLab::text($split[0] + $split[1]));
                GS::setSubjectSplit((string) $sid, $split);
                $changed++;
            }
        });
        flash('success', $changed . ' subject(s) saved. Open assessments use the new units when they are next recalculated.');
        redirect('curriculum', ['tab' => 'subjects']);
    }
    if (($_POST['action'] ?? '') === 'subject') {
        // v56.2: with lecture / laboratory units, Units = lecture + laboratory
        $splitIn = true ? LecLab::parse((string) ($_POST['lecUnits'] ?? ''), (string) ($_POST['labUnits'] ?? '')) : null;
        $unitsIn = $splitIn ? LecLab::text($splitIn[0] + $splitIn[1]) : (string) ($_POST['units'] ?? '');
        Db::tx(function () use (&$sid, $unitsIn, $splitIn) {
            $sid = GS::saveSubject(In::str($_POST, 'id', 191), (string) ($_POST['subjectCode'] ?? ''), (string) ($_POST['subjectName'] ?? ''), $unitsIn);
            GS::setSubjectType($sid, (string) ($_POST['subjectType'] ?? ''));
            GS::setSubjectSplit($sid, $splitIn); // v65.6: always (the Settings switch now only affects fees)
        });
        flash('success', 'Subject saved. Its price per unit is set in Assessment → Subject Fees (₱0.00 until then).');
        redirect('curriculum', ['tab' => 'subjects']);
    }
    $sel = array_values(array_filter((array) ($_POST['subjectIds'] ?? []), 'is_string'));
    $id = GS::saveCurriculum((string) ($_POST['program'] ?? ''), (string) ($_POST['yearLevel'] ?? ''), (string) ($_POST['term'] ?? ''), $sel, (array)($_POST['subjectTeachers']??[]), (string) ($_POST['curriculumName'] ?? ''), (string) ($_POST['fromId'] ?? ''));
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
// v64.6: a teacher's name by id. Each subject row starts with a short list; the full teacher list is written once (see the
// <template> below) and copied into a row's dropdown when it is opened. 1,000 subjects × 1,000 teachers froze the browser.
$teacherNames = []; foreach ($teachers as $t) $teacherNames[(string) $t['id']] = (string) ($t['teacherName'] ?? '');
layout_start('Curriculum');
$curEditing = $tab === 'curriculum' && (isset($_GET['open']) || isset($_GET['new']));
if (!$curEditing) page_title('Curriculum', $canEdit ? '<a class="btn btn-primary" href="' . ($tab === 'subjects' ? '#addSubject' : h(url('curriculum', ['new' => 1]))) . '"><i class="bi bi-plus-lg me-1"></i>Add ' . ($tab === 'subjects' ? 'subject' : 'curriculum') . '</a>' : '', "Manage subjects and curriculum by program, year level and term.");
?>
<?php if (!$curEditing): ?><ul class="nav nav-tabs mb-3"><li class="nav-item"><a class="nav-link<?= $tab === 'curriculum' ? ' active' : '' ?>" href="<?= url('curriculum') ?>">Curriculum</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'subjects' ? ' active' : '' ?>" href="<?= url('curriculum', ['tab' => 'subjects']) ?>">Subjects</a></li></ul><?php endif; ?>
<?php if ($tab === 'curriculum' && (isset($_GET['open']) || isset($_GET['new']))):
    // v65.2: Add / Edit curriculum is its own page, as in the design: details card, Subjects and Teachers tabs, a bar to save.
    $open = isset($_GET['open']) ? GS::get('curriculum', (string) $_GET['open']) : null;
    $chosen = (array) ($open['subjectIds'] ?? []);
    $dis = $canEdit ? '' : ' disabled';
    $req = '<span class="cur-req" aria-hidden="true">*</span>'; ?>
<div class="cur-page">
<a class="cur-back" href="<?= url('curriculum') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to curricula</a>
<div class="cur-title" role="heading" aria-level="2"><?= $open ? ($canEdit ? 'Edit curriculum' : 'Curriculum') : 'Add curriculum' ?></div>
<form method="post" id="curEdit" class="cur-form" data-cur-edit><?= csrf_field() ?><?php if ($open): ?><input type="hidden" name="fromId" value="<?= h($open['id']) ?>"><?php endif; ?>
  <section class="cur-card" aria-labelledby="curDetailsTitle">
    <h3 id="curDetailsTitle">Curriculum details</h3>
    <div class="cur-fields">
      <div class="cur-field cur-field-name"><label for="curName">Curriculum name</label><input class="form-control" id="curName" name="curriculumName" maxlength="80" placeholder="e.g. BSBA First Year" value="<?= h($open['name'] ?? '') ?>"<?= $dis ?>></div>
      <div class="cur-field"><label for="curProgram">Program <?= $req ?></label><input class="form-control cur-select-look" id="curProgram" name="program" list="programs" required maxlength="40" placeholder="Choose or type" autocomplete="off" value="<?= h($open['program'] ?? '') ?>"<?= $dis ?>></div>
      <div class="cur-field"><label for="curYear">Year level <?= $req ?></label><select class="form-select" id="curYear" name="yearLevel" required<?= $dis ?>><?= list_options(YEAR_LEVELS, $open['yearLevel'] ?? '1st Year') ?></select></div>
      <div class="cur-field"><label for="curTerm">Term <?= $req ?></label><select class="form-select" id="curTerm" name="term" required<?= $dis ?>><?php if ($open && ($open['term'] ?? '') === ''): ?><option value="" selected>No semester (older record)</option><?php endif; ?><?= list_options(TERMS, $open ? (($open['term'] ?? '') ?: null) : '1st Semester') ?></select></div>
    </div>
    <datalist id="programs"><?php foreach (GS::programs() as $p): ?><option><?= h($p) ?></option><?php endforeach; ?></datalist>
  </section>

  <div class="cur-tabs" role="tablist">
    <button type="button" class="cur-tab active" role="tab" id="curTabSubjects" aria-controls="curPaneSubjects" aria-selected="true" data-cur-tab="curPaneSubjects">Subjects <span data-cur-count-subjects></span></button>
    <button type="button" class="cur-tab" role="tab" id="curTabTeachers" aria-controls="curPaneTeachers" aria-selected="false" tabindex="-1" data-cur-tab="curPaneTeachers">Teachers <span data-cur-count-teachers></span></button>
  </div>

  <section class="cur-pane" id="curPaneSubjects" role="tabpanel" aria-labelledby="curTabSubjects">
    <div class="cur-pane-head">
      <div><h3>Selected subjects</h3><p class="cur-meta" data-cur-meta>0 subjects • 0 total units</p></div>
      <?php if ($canEdit): ?><button type="button" class="btn btn-primary cur-add" data-cur-open-picker><i class="bi bi-plus-lg" aria-hidden="true"></i> Add subjects</button><?php endif; ?>
    </div>
    <div class="cur-table-wrap"><table class="table cur-table mb-0"><thead><tr><th>Code</th><th>Subject name</th><th class="text-center">Units</th><?php if ($canEdit): ?><th class="text-center">Remove</th><?php endif; ?></tr></thead>
      <tbody data-cur-selected></tbody></table></div>
    <p class="cur-note">Assign teachers in the Teachers tab.</p>
  </section>

  <section class="cur-pane" id="curPaneTeachers" role="tabpanel" aria-labelledby="curTabTeachers" hidden>
    <div class="cur-pane-head">
      <div><h3>Assign teachers</h3><p class="cur-meta">Choose a teacher for each subject.</p></div>
    </div>
    <div class="cur-assign-tools">
      <div class="cur-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-cur-teacher-search placeholder="Search subject or teacher..." aria-label="Search subject or teacher"></div>
      <span class="cur-unassigned" data-cur-unassigned hidden></span>
    </div>
    <div class="cur-table-wrap"><table class="table cur-table mb-0"><thead><tr><th>Subject code</th><th>Subject name</th><th>Assigned teacher</th></tr></thead><tbody>
    <?php foreach ($subjects as $s): $tid = (string) ($open['subjectTeachers'][$s['id']] ?? ''); if ($tid === '_acadlink_professor_x' || ($tid !== '' && !isset($teacherNames[$tid]))) $tid = ''; ?>
      <tr data-cur-teacher-row="<?= h($s['id']) ?>"<?= in_array($s['id'], $chosen, true) ? '' : ' hidden' ?>><td class="cur-code"><?= h($s['subjectCode'] ?? '') ?></td><td><?= h($s['subjectName'] ?? '') ?></td>
        <td><div class="cur-assigned"><input type="hidden" name="subjectTeachers[<?= h($s['id']) ?>]" value="<?= h($tid === '' ? '_acadlink_professor_x' : $tid) ?>" data-cur-teacher-input><span class="cur-tname" data-cur-tname><?= h($tid === '' ? '' : $teacherNames[$tid]) ?></span><?php if ($canEdit): ?><button type="button" class="btn btn-sm cur-choose" data-cur-choose aria-label="<?= h('Choose teacher for ' . ($s['subjectName'] ?? '')) ?>"></button><?php endif; ?></div></td></tr>
    <?php endforeach; ?>
      <tr data-cur-teacher-empty><td colspan="3" class="text-center text-muted py-4">Add subjects first.</td></tr>
    </tbody></table></div>
    <div class="cur-pager"><span data-cur-tpage-info></span><span class="cur-pages" data-cur-tpages></span></div>
  </section>

  <dialog class="cur-picker" data-cur-picker aria-labelledby="curPickerTitle">
    <div class="cur-picker-head"><div><h3 id="curPickerTitle">Select subjects</h3><p class="cur-picker-sub" data-cur-picker-sub></p></div><button type="button" class="btn-close" data-cur-cancel-picker aria-label="Close"></button></div>
    <div class="cur-picker-body">
      <h4>Available subjects</h4>
      <div class="cur-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-filter="#subjpick" placeholder="Search subject code or name..." aria-label="Search subject code or name"></div>
      <div class="cur-table-wrap cur-avail"><table class="table cur-table mb-0" id="subjpick"><thead><tr><th>Code</th><th>Subject name</th><th class="text-center cur-units">Units</th><th class="text-center">Action</th></tr></thead><tbody>
      <?php foreach ($subjects as $s): ?><tr data-cur-sid="<?= h($s['id']) ?>"><td class="cur-code"><input class="visually-hidden" type="checkbox" name="subjectIds[]" value="<?= h($s['id']) ?>" data-code="<?= h($s['subjectCode'] ?? '') ?>" data-name="<?= h($s['subjectName'] ?? '') ?>" data-units="<?= h($s['units'] ?? '0') ?>"<?= in_array($s['id'], $chosen, true) ? ' checked' : '' ?><?= $dis ?> tabindex="-1" aria-hidden="true"><?= h($s['subjectCode'] ?? '') ?></td><td><?= h($s['subjectName'] ?? '') ?></td><td class="text-center cur-units"><?= h($s['units'] ?? '') ?></td>
        <td class="text-center"><button type="button" class="btn btn-sm cur-act" data-cur-toggle></button></td></tr><?php endforeach; ?>
      <?php if (!$subjects): ?><tr><td colspan="4" class="text-center text-muted py-4">No subjects yet. Add them in the Subjects tab of Curriculum.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="cur-picked-head"><h4>Selected subjects</h4><span class="cur-pill" data-cur-pill>0 subjects • 0 units</span></div>
      <p class="cur-picker-hint">Subjects you add appear here.</p>
      <div class="cur-table-wrap"><table class="table cur-table mb-0"><thead><tr><th>Code</th><th>Subject name</th><th class="text-center cur-units">Units</th><th class="text-center">Action</th></tr></thead><tbody data-cur-picked-list></tbody></table></div>
    </div>
    <div class="cur-picker-foot"><span data-cur-picked>0 subjects selected</span><button type="button" class="btn btn-outline-primary" data-cur-cancel-picker>Cancel</button><button type="button" class="btn btn-primary" data-cur-confirm-picker>Confirm selection</button></div>
  </dialog>

  <?php if ($canEdit): ?>
  <div class="cur-savebar"><span class="cur-dirty" data-cur-dirty>No changes</span>
    <a class="btn btn-outline-primary cur-cancel" href="<?= url('curriculum') ?>">Cancel</a>
    <button class="btn btn-primary cur-save"><?= $open ? 'Save changes' : 'Save curriculum' ?></button></div>
  <?php endif; ?>
</form>
<?php if ($canEdit): ?>
<dialog class="cur-picker cur-tpicker" data-cur-tpicker aria-labelledby="curTPickerTitle">
  <div class="cur-picker-head"><div><h3 id="curTPickerTitle">Select teacher</h3><p class="cur-tpicker-subject" data-cur-tpicker-subject></p><span class="cur-pill cur-pill-soft" data-cur-tpicker-ctx></span></div><button type="button" class="btn-close" data-cur-tcancel aria-label="Close"></button></div>
  <div class="cur-picker-body">
    <div class="cur-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" data-cur-tsearch placeholder="Search teacher name or ID..." aria-label="Search teacher name or ID"></div>
    <div class="cur-table-wrap cur-avail"><table class="table cur-table cur-tlist mb-0"><thead><tr><th class="cur-radio-col"><span class="visually-hidden">Choose</span></th><th>Teacher</th><th>Teacher ID</th></tr></thead><tbody>
    <?php foreach ($teachers as $t): if ($t['id'] === '_acadlink_professor_x') continue; $nm = (string) ($t['teacherName'] ?? ''); $ini = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/[\s,]+/', trim($nm), -1, PREG_SPLIT_NO_EMPTY), 0, 2)))); ?>
      <tr data-cur-tid="<?= h($t['id']) ?>"><td class="cur-radio-col"><input class="form-check-input" type="radio" name="curTeacherPick" id="tp-<?= h($t['id']) ?>" value="<?= h($t['id']) ?>"></td>
        <td><label for="tp-<?= h($t['id']) ?>" class="cur-tcell"><span class="cur-avatar" aria-hidden="true"><?= h($ini) ?></span><span data-cur-tlabel><?= h($nm) ?></span></label></td><td><?= h($t['teacherId'] ?? '') ?></td></tr>
    <?php endforeach; ?>
      <tr data-cur-tnone hidden><td colspan="3" class="text-center text-muted py-4">No teacher matches your search.</td></tr>
    </tbody></table></div>
  </div>
  <div class="cur-picker-foot"><span data-cur-tpicked>No teacher selected</span><button type="button" class="btn btn-outline-primary" data-cur-tcancel>Cancel</button><button type="button" class="btn btn-primary" data-cur-tconfirm disabled>Select teacher</button></div>
</dialog>
<?php endif; ?>
<p class="cur-help">The program is the text before the year number in section names (BSIT 1A → BSIT). Saving creates missing classes for matching sections. Changing a teacher here also changes the active classes that still have the previous teacher; classes whose grades were already submitted keep their teacher. Saved grades are never changed.</p>
</div>
<?php elseif ($tab === 'curriculum'):
    $list = RecordList::rows(fn()=>GS::curricula()); ?>
<div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0">
  <thead class="table-light"><tr><th>Name</th><th>Program</th><th>Year level</th><th>Term</th><th>Subjects</th><th>Units</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $c): $ids = (array) ($c['subjectIds'] ?? []); $units = array_sum(array_map(fn($i) => (float) ($byId[$i]['units'] ?? 0), $ids)); ?>
  <tr><td><?= h($c['name'] ?? '') ?></td><td class="fw-semibold"><?= h($c['program'] ?? '') ?></td><td><?= h($c['yearLevel'] ?? '') ?></td><td><?= h(($c['term'] ?? '') ?: 'No term') ?></td>
    <td><?= count($ids) ?></td><td><?= h($units) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('curriculum', ['open' => $c['id']]) ?>"><?= $canEdit ? 'Edit' : 'View' ?></a></td></tr>
  <?php endforeach; if (!$list): ?><tr><td colspan="7" class="text-center text-muted py-4">No curriculum yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php elseif ($canEdit && isset($_GET['units'])): /* v66: lecture / lab units of every subject on one page */ ?>
<form method="post" class="form-section" data-units-bulk><?= csrf_field() ?><input type="hidden" name="action" value="units">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h2 class="h6 mb-0 me-auto">Lecture and lab units</h2>
    <input type="search" class="form-control form-control-sm" style="max-width:260px" data-filter="#unitsTable" placeholder="Search subjects" aria-label="Search subjects">
    <a class="btn btn-sm btn-link" href="<?= url('curriculum', ['tab' => 'subjects']) ?>">Cancel</a><button class="btn btn-sm btn-primary">Save units</button></div>
  <p class="small text-muted">Total = lecture + lab units. Class Schedules gives 1 hour a week per lecture unit and 3 hours per lab unit. Only the subjects you change are saved.</p>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="unitsTable" data-plain-table><thead><tr><th>Code</th><th>Subject</th><th style="width:110px">Lec units</th><th style="width:110px">Lab units</th><th class="text-center" style="width:100px">Total</th></tr></thead><tbody>
  <?php foreach ($subjects as $su): $sp = LecLab::ofSubject($su); ?><tr data-unit-split>
    <td class="font-monospace"><?= h($su['subjectCode'] ?? '') ?><input type="hidden" name="u_id[]" value="<?= h($su['id']) ?>"></td><td><?= h($su['subjectName'] ?? '') ?><?= isset($su['lecUnits']) ? '' : ' <span class="badge text-bg-light border">all lecture so far</span>' ?></td>
    <td><input class="form-control form-control-sm" name="u_lec[]" inputmode="decimal" value="<?= h(LecLab::text($sp[0])) ?>" aria-label="<?= h('Lecture units of ' . ($su['subjectCode'] ?? '')) ?>"></td>
    <td><input class="form-control form-control-sm" name="u_lab[]" inputmode="decimal" value="<?= h(LecLab::text($sp[1])) ?>" aria-label="<?= h('Lab units of ' . ($su['subjectCode'] ?? '')) ?>"></td>
    <td class="text-center fw-semibold" data-unit-total><?= h(LecLab::text($sp[2])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <div class="mt-2 text-end"><button class="btn btn-primary">Save units</button></div>
</form>
<?php else: $edit = isset($_GET['edit']) ? GS::get('subjects', (string) $_GET['edit']) : null; ?>
<div class="row g-3">
<div class="<?= $canEdit ? 'col-xl-8' : 'col-12' ?>">
  <div class="d-flex flex-wrap gap-2 mb-2"><input class="form-control form-control-sm" style="max-width:320px" data-filter="#subjects" placeholder="Search"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary ms-auto" href="<?= url('curriculum', ['tab' => 'subjects', 'units' => 1]) ?>"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Edit lecture / lab units</a><?php endif; ?></div>
  <div class="table-responsive bg-white border rounded"><table class="table table-sm mb-0" id="subjects"><thead class="table-light"><tr><th>Code</th><th>Subject</th><th>Lec units</th><th>Lab units</th><th>Total units</th><th>Type</th><th></th></tr></thead><tbody>
  <?php foreach (RecordList::rows(fn()=>$subjects) as $s): ?><tr><td class="font-monospace"><?= h($s['subjectCode'] ?? '') ?></td><td><?= h($s['subjectName'] ?? '') ?></td><?php $sp = LecLab::ofSubject($s); ?><td><?= h(LecLab::text($sp[0])) ?></td><td><?= h(LecLab::text($sp[1])) ?></td><td><?= h($s['units'] ?? '') ?></td><td class="small"><?= h($s['subjectType'] ?? '') ?></td>
    <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('curriculum', ['tab' => 'subjects', 'edit' => $s['id']]) ?>"><i class="bi bi-pencil me-1"></i>Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if ($canEdit): ?>
<div class="col-xl-4"><form method="post" class="form-section" id="addSubject"><?= csrf_field() ?><input type="hidden" name="action" value="subject"><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
  <h2 class="h6"><?= $edit ? 'Edit ' . h($edit['subjectName']) : 'Add a subject' ?></h2>
  <label class="form-label small">Code</label><input class="form-control form-control-sm mb-2" name="subjectCode" maxlength="40" value="<?= h($edit['subjectCode'] ?? '') ?>">
  <label class="form-label small">Subject name</label><input class="form-control form-control-sm mb-2" name="subjectName" required maxlength="150" value="<?= h($edit['subjectName'] ?? '') ?>">
  <?php $esp = $edit ? LecLab::ofSubject($edit) : [3, 0, 3]; /* v65.6: always lecture + laboratory = total */ ?>
  <div class="row g-2 mb-1" data-unit-split><div class="col-4"><label class="form-label small" for="subLec">Lecture units</label><input class="form-control form-control-sm" id="subLec" name="lecUnits" inputmode="decimal" value="<?= h(LecLab::text($esp[0])) ?>"></div>
    <div class="col-4"><label class="form-label small" for="subLab">Lab units</label><input class="form-control form-control-sm" id="subLab" name="labUnits" inputmode="decimal" value="<?= h(LecLab::text($esp[1])) ?>"></div>
    <div class="col-4"><label class="form-label small" for="subTotal">Total units</label><output class="form-control form-control-sm bg-light fw-semibold" id="subTotal" data-unit-total for="subLec subLab"><?= h(LecLab::text($esp[2])) ?></output></div></div>
  <p class="form-text mt-0">Total = lecture + laboratory units. Class Schedules gives 1 hour a week per lecture unit and 3 hours a week per laboratory unit.</p>
  <label class="form-label small">Subject type</label><select class="form-select form-select-sm mb-2" name="subjectType"><?= list_options(ImportAll::SUBJECT_TYPES, $edit['subjectType'] ?? null, 'Not set') ?></select>
  <p class="form-text mt-0">No fee is needed here: the price per unit is set in Assessment → Subject Fees.</p>
  <button class="btn btn-sm btn-primary">Save subject</button><?php if ($edit): ?> <a class="btn btn-sm btn-link" href="<?= url('curriculum', ['tab' => 'subjects']) ?>">Add instead</a><?php endif; ?>
</form></div>
<?php endif; ?>
</div>
<?php endif; ?>
<script src="assets/subject-units.js?v=66.4" defer></script>
<?php layout_end();
