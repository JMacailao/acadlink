<?php
/** Sections = the grading system's sections. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$canEdit = Auth::can('sections.edit');
if ($isPost) {
    GS::saveSection(In::str($_POST, 'id', 191), (string) ($_POST['schoolYear'] ?? ''), (string) ($_POST['yearLevel'] ?? ''), (string) ($_POST['sectionName'] ?? ''));
    flash('success', 'Section saved in the grading system.');
    redirect('sections', ['sy' => $_POST['schoolYear'] ?? '']);
}
$sy = selected_sy();
$sections = RecordList::rows(fn()=>GS::sections($sy));
$counts = [];
foreach (Db::all("SELECT f_sectionId AS s, COUNT(*) AS n FROM docs WHERE col = 'students' GROUP BY f_sectionId") as $r) $counts[$r['s']] = (int) $r['n'];
$regs = [];
foreach (Db::all("SELECT section_doc_id AS s, COUNT(*) AS n FROM sis_registrations WHERE status = 'REGISTERED' AND school_year = ? GROUP BY section_doc_id", [$sy]) as $r) $regs[$r['s']] = (int) $r['n'];
$edit = isset($_GET['edit']) ? GS::get('sections', (string) $_GET['edit']) : null;
layout_start('Sections');
page_title('Sections', ($canEdit ? '<a class="btn btn-primary" href="#addSection"><i class="bi bi-plus-lg me-1"></i>Add section</a>' : '') . '<form method="get" id="sectionFilters" data-record-filters class="d-flex"><input type="hidden" name="r" value="sections"><input type="hidden" name="apply" value="1"><select class="form-select" name="sy" data-autosubmit>' . sy_options($sy) . '</select><select class="form-select" name="year" aria-label="Year level">'.list_options(YEAR_LEVELS,(string)($_GET['year']??''),'All year levels').'</select><input class="form-control" name="q" aria-label="Find section" placeholder="Section name" value="'.h($_GET['q']??'').'"></form>', "Organize sections, year levels and student assignments.");
?>
<div class="row g-3">
<div class="<?= $canEdit ? 'col-xl-8' : 'col-12' ?>"><div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover mb-0">
  <thead class="table-light"><tr><th>Section</th><th>Program</th><th>Year level</th><th>Students</th><th>Registered (<?= h($sy) ?>)</th><th></th></tr></thead><tbody>
  <?php foreach ($sections as $s): ?><tr><td class="fw-semibold"><?= h($s['sectionName']) ?></td><td><?= h(GS::programOf((string) $s['sectionName'])) ?></td><td><?= h($s['yearLevel']) ?></td>
    <td><a href="<?= url('students', ['sy' => $s['schoolYear'], 'section' => $s['id']]) ?>"><?= $counts[$s['id']] ?? 0 ?></a></td><td><?= $regs[$s['id']] ?? 0 ?></td>
    <td class="text-end"><?php if ($canEdit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('sections', ['sy' => $sy, 'edit' => $s['id']]) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$sections): ?><tr><td colspan="6" class="text-center text-muted py-4">No sections for <?= h($sy) ?>.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($canEdit): ?>
<div class="col-xl-4"><form method="post" class="form-section" id="addSection"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($edit['id'] ?? '') ?>">
  <h2 class="h6"><?= $edit ? 'Edit ' . h($edit['sectionName']) : 'Add a section' ?></h2>
  <label class="form-label small">School year</label><input class="form-control form-control-sm mb-2" name="schoolYear" required placeholder="2026-2027" value="<?= h($edit['schoolYear'] ?? $sy) ?>">
  <label class="form-label small">Year level</label><select class="form-select form-select-sm mb-2" name="yearLevel"><?= list_options(YEAR_LEVELS, $edit['yearLevel'] ?? '1st Year') ?></select>
  <label class="form-label small">Section name (program + year + letter, e.g. BSIT 1A)</label><input class="form-control form-control-sm mb-2" name="sectionName" required maxlength="60" value="<?= h($edit['sectionName'] ?? '') ?>">
  <button class="btn btn-sm btn-primary">Save section</button><?php if ($edit): ?> <a class="btn btn-sm btn-link" href="<?= url('sections', ['sy' => $sy]) ?>">Add instead</a><?php endif; ?>
  <div class="form-text">A section name is unique per school year. Renaming also updates the students, classes and grades that copy it.</div>
</form></div>
<?php endif; ?>
</div>
<?php layout_end();
