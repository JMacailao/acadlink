<?php
/** Students = the grading system's student records. */
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$canEdit = Auth::can('students.edit');
if ($isPost) {
    $id = GS::saveStudent(In::str($_POST, 'id', 191), $_POST);
    flash('success', 'Student saved in the grading system.');
    redirect('student', ['id' => $id]);
}
$sy = is_string($_GET['sy'] ?? null) ? $_GET['sy'] : selected_sy();
$sec = is_string($_GET['section'] ?? null) ? $_GET['section'] : '';
$year = is_string($_GET['year'] ?? null) && in_array($_GET['year'], YEAR_LEVELS, true) ? $_GET['year'] : '';
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
/** Student statuses as the grading system words them; an empty status is Regular. */
$STATUS = ['' => 'Regular', 'irregular' => 'Irregular', 'dropped' => 'Dropped', 'transferred' => 'Transferred out', 'graduated' => 'Graduated'];
$program = is_string($_GET['program'] ?? null) ? strtoupper(trim($_GET['program'])) : '';
$status = is_string($_GET['status'] ?? null) && ($_GET['status'] === 'regular' || isset($STATUS[$_GET['status']])) ? $_GET['status'] : '';
$statusKey = fn(array $s) => isset($STATUS[(string) ($s['status'] ?? '')]) ? (string) ($s['status'] ?? '') : '';
$sections = GS::sections();
// v56: enrollment of the chosen school year: Confirmed (official list) or Pending (still being processed)
// v56.5 tabs: Enrolled (default, the official list) · Pending confirmation · All records
$enrollF = in_array($_GET['enrollment'] ?? '', ['confirmed', 'pending', 'all'], true) ? $_GET['enrollment'] : 'confirmed';
$termF = in_array($_GET['term'] ?? '', TERMS, true) ? (string) $_GET['term'] : '';
$enrollOf = [];
$eArgs = []; $eWhere = '';
if ($sy) { $eWhere .= ' AND school_year = ?'; $eArgs[] = $sy; }
if ($termF !== '') { $eWhere .= ' AND term = ?'; $eArgs[] = $termF; }
foreach (Db::all("SELECT student_doc_id, MAX(locked_at IS NOT NULL) AS done FROM sis_registrations WHERE status <> 'CANCELLED' AND record_origin = 'ENROLLMENT'$eWhere GROUP BY student_doc_id", $eArgs) as $er)
    $enrollOf[$er['student_doc_id']] = (int) $er['done'] ? 'confirmed' : 'pending';
if (!RecordList::applied()) $_GET['apply'] = '1'; // the Enrolled list shows at once
$rows = RecordList::applied()?array_filter(GS::students(), fn($s) => (!$sy || ($s['schoolYear'] ?? '') === $sy) && (!$sec || ($s['sectionId'] ?? '') === $sec)
    && (!$year || ($s['yearLevel'] ?? '') === $year) && ($program === '' || strtoupper((string) GS::programOf((string) ($s['sectionName'] ?? ''))) === $program)
    && ($status === '' || $statusKey($s) === ($status === 'regular' ? '' : $status)) && ($enrollF === 'all' || ($enrollOf[$s['id']] ?? '') === $enrollF) && ($q === '' || str_contains(mb_strtolower(($s['studentId'] ?? '') . ' ' . ($s['studentName'] ?? '') . ' ' . ($s['email'] ?? '')), $q))):[];
layout_start('Students');
page_title('Students', $canEdit ? '<a class="btn btn-primary" href="#addStudent"><i class="bi bi-plus-lg me-1"></i>Add student</a>' : '', 'Manage student records and enrollment details.');
?>
<?php $canGrades = StudentHistory::canViewAllGrades(); $canAssessments = Auth::can('registration', 'accounting', 'payments.view'); ?>
<nav class="sis-student-shortcuts d-print-none" aria-label="Student tasks">
  <?php if (Auth::can('registration')): ?><a href="<?= url('registration', ['new' => 1]) ?>"><i class="bi bi-journal-plus" aria-hidden="true"></i>New registration</a><?php endif; ?>
  <?php if ($canAssessments): ?><a href="<?= url('history', ['tab' => 'assessments']) ?>"><i class="bi bi-receipt" aria-hidden="true"></i>View assessments</a><?php endif; ?>
  <?php if ($canGrades): ?><a href="<?= url('history', ['tab' => 'grades']) ?>"><i class="bi bi-journal-text" aria-hidden="true"></i>History of grades</a><?php endif; ?>
  <?php if (Auth::can('users.manage')): ?><a href="<?= url('bulkaccounts', ['group' => 'STUDENT']) ?>"><i class="bi bi-people" aria-hidden="true"></i>Bulk create accounts</a><?php endif; ?>
  <?php if (Auth::can('registration', 'accounting', 'users.manage')): ?><a href="<?= url('migration') ?>"><i class="bi bi-upload" aria-hidden="true"></i>Import records</a><?php endif; ?>
</nav>
<div class="row g-3">
<div class="col-12">
<?php $tabQ = array_filter(['r' => 'students', 'apply' => '1', 'sy' => $sy, 'term' => $termF, 'section' => $sec, 'year' => $year, 'program' => $program, 'status' => $status, 'q' => (string) ($_GET['q'] ?? '')], fn($v) => $v !== ''); $pendingN = count(array_filter($enrollOf, fn($v) => $v === 'pending')); ?>
  <ul class="nav nav-pills mb-2" role="tablist" aria-label="Enrollment">
    <?php foreach (['confirmed' => 'Enrolled', 'pending' => 'Pending confirmation', 'all' => 'All records'] as $tk => $tl): ?><li class="nav-item"><a class="nav-link<?= $enrollF === $tk ? ' active' : '' ?>" href="?<?= h(http_build_query($tabQ + ['enrollment' => $tk])) ?>"<?= $enrollF === $tk ? ' aria-current="page"' : '' ?>><?= h($tl) ?><?= $tk === 'pending' && $pendingN ? ' <span class="badge text-bg-warning">' . $pendingN . '</span>' : '' ?></a></li><?php endforeach; ?>
  </ul>
  <p class="small text-muted mb-2"><?= $enrollF === 'confirmed' ? 'Students with a confirmed enrollment' . ($sy ? ' in ' . h($sy) : '') . ($termF ? ' · ' . h($termF) : '') . '. This is the official list.' : ($enrollF === 'pending' ? 'Registered but not confirmed yet. They appear in Enrolled once the registrar confirms the enrollment.' : 'Every student record, enrolled or not (for corrections and history).') ?></p>
  <form class="row g-2 mb-3" method="get" id="studentFilters" data-record-filters aria-label="Filter students"><input type="hidden" name="r" value="students"><input type="hidden" name="apply" value="1">
    <div class="col-lg-4"><label class="visually-hidden" for="studentQuery">Student ID or name</label><div class="input-group"><input id="studentQuery" class="form-control" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search by student ID or name…"><button class="btn btn-outline-primary" aria-label="Search students"><i class="bi bi-search" aria-hidden="true"></i></button></div></div>
    <div class="col-lg-2"><select class="form-select" name="sy" aria-label="School year" data-autosubmit><?= sy_options($sy, true) ?></select></div>
    <div class="col-lg-2"><select class="form-select" name="year" aria-label="Year level" data-autosubmit><?= list_options(YEAR_LEVELS, $year, 'All year levels') ?></select></div>
    <div class="col-lg-2"><label class="visually-hidden" for="studentProgram">Program</label><select class="form-select" id="studentProgram" name="program"><option value="">All programs</option><?php foreach (GS::programs() as $pr): ?><option<?= strtoupper($pr) === $program ? ' selected' : '' ?>><?= h($pr) ?></option><?php endforeach; ?></select></div>
    <div class="col-lg-2"><select class="form-select" name="section" aria-label="Section" data-autosubmit><option value="">All sections</option><?php foreach ($sections as $s): ?><option value="<?= h($s['id']) ?>"<?= $s['id'] === $sec ? ' selected' : '' ?>><?= h($s['sectionName']) ?></option><?php endforeach; ?></select></div>

    <div class="col-lg-2"><label class="visually-hidden" for="studentStatus">Status</label><select class="form-select" id="studentStatus" name="status"><option value="">All statuses</option><?php foreach ($STATUS as $k => $label): $v = $k === '' ? 'regular' : $k; ?><option value="<?= h($v) ?>"<?= $status === $v ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
    <input type="hidden" name="enrollment" value="<?= h($enrollF) ?>">
    <div class="col-lg-2"><label class="visually-hidden" for="studentTerm">Semester</label><select class="form-select" id="studentTerm" name="term"><?= list_options(TERMS, $termF, 'Any semester') ?></select></div>
    <div class="col-lg-2"><a class="btn btn-outline-secondary w-100" href="<?= url('students', ['sy' => '']) ?>">Reset</a></div>
  </form>
  <div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover mb-0">
    <thead class="table-light"><tr><th>Student ID</th><th>Student Name</th><th>Program</th><th>Year Level</th><th>Section</th><th>School Year</th><th>Status</th><th>Enrollment</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach (array_slice($rows, 0, 500) as $s): ?><tr><td class="font-monospace"><?= h($s['studentId'] ?? '') ?></td><td><?= h($s['studentName'] ?? '') ?></td><td><?= h((string) GS::programOf((string) ($s['sectionName'] ?? ''))) ?></td>
      <td><?= h($s['yearLevel'] ?? '') ?></td><td><?= h($s['sectionName'] ?? '') ?></td><td><?= h($s['schoolYear'] ?? '') ?></td><td data-status><?= h($STATUS[$statusKey($s)]) ?></td>
      <td><?= ['confirmed' => '<span class="badge text-bg-success">Enrolled</span>', 'pending' => '<span class="badge text-bg-warning">Pending</span>'][$enrollOf[$s['id']] ?? ''] ?? '<span class="text-muted small">—</span>' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('student', ['id' => $s['id']]) ?>" data-icon="eye">View</a><?php if ($canEdit): ?> <a class="btn btn-sm btn-outline-primary" href="<?= url('student', ['id' => $s['id']]) ?>#studentEdit" data-icon="pencil-square">Edit Student</a><?php endif; ?><?php if ($canGrades): ?> <a class="btn btn-sm btn-outline-primary" href="<?= url('history', ['id' => $s['id'], 'tab' => 'grades']) ?>" data-icon="journal-text">History of Grades</a><?php endif; ?><?php if ($canAssessments): ?> <a class="btn btn-sm btn-outline-primary" href="<?= url('history', ['id' => $s['id'], 'tab' => 'assessments']) ?>" data-icon="receipt">History of Assessments</a><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4"><?= $enrollF === 'confirmed' ? 'No confirmed enrollments match. Students still being processed are under Pending confirmation; every record is under All records.' : ($enrollF === 'pending' ? 'No students are waiting for enrollment confirmation.' : 'No students found. Adjust your filters or choose Add student.') ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (count($rows) > 500): ?><p class="small text-muted mt-3"><?= count($rows) ?> students match; the first 500 are shown. Narrow the filters to see the rest.</p><?php endif; ?>
</div>
<?php if ($canEdit): $all = GS::sections(); ?>
<div class="col-12 student-add-panel" id="addStudent"><form method="post" class="form-section"><?= csrf_field() ?>
  <h2 class="h6">Add a student</h2>
  <label class="form-label small">Student ID (blank = next number)</label><input class="form-control form-control-sm mb-2" name="studentId" maxlength="40" placeholder="<?= h(GS::nextStudentNumber($sy ?: selected_sy())) ?>">
  <label class="form-label small">Name (Last, First M.)</label><input class="form-control form-control-sm mb-2" name="studentName" required maxlength="150">
  <label class="form-label small">Email</label><input class="form-control form-control-sm mb-2" type="email" name="email" maxlength="191">
  <label class="form-label small">Section</label><select class="form-select form-select-sm mb-2" name="sectionId" required><option value="">— choose —</option><?php foreach ($all as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['sectionName'] . ' · ' . $s['yearLevel'] . ' · ' . $s['schoolYear']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm btn-primary">Add student</button>
  <div class="form-text">New students who apply go through Transaction → Admission → Registration instead.</div>
</form></div>
<?php endif; ?>
</div>
<?php layout_end();
