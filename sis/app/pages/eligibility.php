<?php
declare(strict_types=1);
if ($isPost) Auth::require('registration', 'eligibility.holds', 'curriculum.edit'); else Auth::require('registration', 'eligibility.view');
if ($isPost) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'hold') { EnrollmentEligibility::addHold($_POST); flash('success', 'Enrollment hold added. It applies immediately.'); }
    elseif ($action === 'release') { EnrollmentEligibility::releaseHold((int) In::int($_POST, 'hold_id', true, 'Hold'), (string) In::str($_POST, 'release_reason', 1000, true, 'Release reason')); flash('success', 'Hold released.'); }
    elseif ($action === 'drop_charges') { AddDrop::savePercents($_POST); flash('success', 'Charges for dropped subjects saved.'); }
    elseif ($action === 'prerequisite') { EnrollmentEligibility::savePrerequisite((string) In::str($_POST, 'subject_id', 191, true, 'Subject'), (string) In::str($_POST, 'prerequisite_id', 191, true, 'Prerequisite')); flash('success', 'Prerequisite saved.'); }
    elseif ($action === 'remove_prerequisite') { Auth::require('curriculum.edit'); $pid = (int) In::int($_POST, 'prerequisite_id', true, 'Prerequisite'); Db::q('DELETE FROM sis_subject_prerequisites WHERE id = ?', [$pid]); Audit::log('eligibility_prerequisite_removed', 'prerequisite', $pid); flash('success', 'Prerequisite removed.'); }
    else throw new AppError('Unknown eligibility action.');
    redirect('eligibility', ['student' => trim((string) ($_POST['student_number'] ?? ''))]);
}
$number = trim((string) ($_GET['student'] ?? ''));
$student = $number !== '' ? GS::studentByNumber($number) : null;
$sy = is_string($_GET['sy'] ?? null) ? $_GET['sy'] : ($student['schoolYear'] ?? selected_sy());
$term = in_array($_GET['term'] ?? '', TERMS, true) ? $_GET['term'] : '1st Semester';
$subjects = GS::subjects();
$byId = array_column($subjects, null, 'id');
$rules = Db::all('SELECT * FROM sis_subject_prerequisites ORDER BY id');
$holds = $number !== '' ? Db::all('SELECT * FROM sis_eligibility_holds WHERE student_number = ? ORDER BY id DESC', [$number]) : [];
layout_start('Enrollment Eligibility');
page_title('Enrollment Eligibility', '', 'Check enrollment restrictions, manage explicit holds, and configure subject prerequisites.');
?>
<form method="get" class="form-section"><input type="hidden" name="r" value="eligibility"><div class="row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label" for="eligStudent">Student ID</label><input class="form-control" id="eligStudent" name="student" maxlength="40" value="<?= h($number) ?>" required placeholder="Enter the exact student ID"></div>
  <div class="col-md-3"><label class="form-label" for="eligYear">School year</label><input class="form-control" id="eligYear" name="sy" maxlength="9" value="<?= h($sy) ?>" required placeholder="2026-2027"></div>
  <div class="col-md-3"><label class="form-label" for="eligTerm">Term</label><select class="form-select" id="eligTerm" name="term"><?= list_options(TERMS, $term) ?></select></div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Check eligibility</button></div>
</div></form>
<?php if ($number !== '' && !$student): ?><div class="alert alert-warning">No student with that ID. New applicants are checked through New registration after admission.</div><?php endif; ?>
<?php if ($student):
    $program = GS::programOf((string) ($student['sectionName'] ?? ''));
    $curriculum = $program ? GS::curriculumFor($program, (string) $student['yearLevel'], $term) : null;
    $eligibility = EnrollmentEligibility::check($student['id'], $number, $sy, $term, array_values((array) ($curriculum['subjectIds'] ?? [])));
    require __DIR__ . '/../views/eligibility_panel.php'; ?>
<p class="small text-muted">This preview uses the student's current program and year level. A registration checks its actual selected section and subjects.</p>
<div class="form-section"><h2 class="h6">Enrollment holds — <?= h($student['studentName']) ?></h2>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Type / scope</th><th>Reason / office</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($holds as $hold): $active = !$hold['released_at'] && (!$hold['expires_on'] || $hold['expires_on'] >= date('Y-m-d')); ?>
<tr><td><?= h($hold['kind']) ?><div class="small text-muted"><?= h(($hold['school_year'] ?: 'All years') . ' · ' . ($hold['term'] ?: 'All terms')) ?></div></td><td><?= h($hold['reason']) ?><div class="small text-muted"><?= h($hold['office']) ?><?= $hold['expires_on'] ? ' · Ends ' . h($hold['expires_on']) : '' ?></div><?= $hold['release_reason'] ? '<div class="small">Release: ' . h($hold['release_reason']) . '</div>' : '' ?></td><td><?= $hold['released_at'] ? 'Released' : ($active ? 'Active' : 'Expired') ?></td><td>
<?php if ($active && Auth::can('eligibility.holds')): ?><form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="student_number" value="<?= h($number) ?>"><input type="hidden" name="hold_id" value="<?= (int) $hold['id'] ?>"><input class="form-control form-control-sm" name="release_reason" required maxlength="1000" aria-label="Release reason" placeholder="Reason for releasing"><button class="btn btn-sm btn-outline-primary">Release hold</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$holds): ?><tr><td colspan="4" class="text-muted">No explicit holds recorded.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (Auth::can('eligibility.holds')): ?>
<details class="mt-3"><summary class="fw-semibold">Add an enrollment hold</summary><form method="post" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="hold"><input type="hidden" name="student_number" value="<?= h($number) ?>">
<div class="col-md-4"><label class="form-label" for="holdKind">Hold type</label><select class="form-select" id="holdKind" name="kind"><?= list_options(['ACADEMIC','FINANCIAL','DOCUMENT','OTHER'], 'DOCUMENT') ?></select></div>
<div class="col-md-4"><label class="form-label" for="holdYear">School year (blank = all)</label><input class="form-control" id="holdYear" name="school_year" maxlength="9" value="<?= h($sy) ?>"></div>
<div class="col-md-4"><label class="form-label" for="holdTerm">Term</label><select class="form-select" id="holdTerm" name="term"><?= list_options(TERMS, $term, 'All terms') ?></select></div>
<div class="col-md-6"><label class="form-label" for="holdOffice">Responsible office *</label><input class="form-control" id="holdOffice" name="office" required maxlength="100" placeholder="e.g. Admission Office"></div>
<div class="col-md-6"><label class="form-label" for="holdExpiry">Expiration (optional; inclusive)</label><input class="form-control" id="holdExpiry" name="expires_on" type="date" min="<?= date('Y-m-d') ?>"></div>
<div class="col-12"><label class="form-label" for="holdReason">Reason and required next action *</label><textarea class="form-control" id="holdReason" name="reason" required maxlength="1000" rows="2" placeholder="e.g. Submit the original transcript to the Admission Office for verification."></textarea></div>
<div class="col-12"><button class="btn btn-primary">Add hold</button></div></form></details>
<?php endif; ?></div>
<?php endif; ?>
<?php $dp = AddDrop::percents(); ?>
<form method="post" class="form-section"><?= csrf_field() ?><input type="hidden" name="action" value="drop_charges">
  <h2 class="h6">Dropping subjects after enrollment</h2>
  <p class="small text-muted">How much of a dropped subject's fees stays charged, counted from the term's classes start date (Settings → Terms). Before classes start nothing is charged. The registrar adds or drops subjects in a confirmed registration (Add / drop subjects).</p>
  <div class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small mb-0" for="dc1">1st week of classes (%)</label><input class="form-control" id="dc1" name="drop_charge_week1" inputmode="numeric" value="<?= (int) $dp['drop_charge_week1'] ?>"<?= Auth::can('settings.manage') ? '' : ' disabled' ?>></div>
    <div class="col-sm-4"><label class="form-label small mb-0" for="dc2">2nd week (%)</label><input class="form-control" id="dc2" name="drop_charge_week2" inputmode="numeric" value="<?= (int) $dp['drop_charge_week2'] ?>"<?= Auth::can('settings.manage') ? '' : ' disabled' ?>></div>
    <div class="col-sm-4"><label class="form-label small mb-0" for="dc3">After the 2nd week (%)</label><input class="form-control" id="dc3" name="drop_charge_after" inputmode="numeric" value="<?= (int) $dp['drop_charge_after'] ?>"<?= Auth::can('settings.manage') ? '' : ' disabled' ?>></div>
  </div>
  <?php if (Auth::can('settings.manage')): ?><button class="btn btn-sm btn-primary mt-2">Save</button><?php endif; ?>
</form>
<div class="form-section"><h2 class="h6">Subject prerequisites</h2><p class="small text-muted">Each selected subject requires all mapped prerequisites. Only an earlier finalized grade marked Passed counts. Current or future grades, INC, dropped and failed grades do not satisfy a prerequisite. Equivalencies require separate school review.</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Subject</th><th>Requires a pass in</th><th>Action</th></tr></thead><tbody>
<?php foreach ($rules as $rule): ?><tr><td><?= h($byId[$rule['subject_doc_id']]['subjectCode'] ?? $rule['subject_doc_id']) ?></td><td><?= h($byId[$rule['prerequisite_doc_id']]['subjectCode'] ?? 'Missing prerequisite subject') ?></td><td><?php if (Auth::can('curriculum.edit')): ?><form method="post" data-confirm="Remove this prerequisite rule? It changes future eligibility decisions."><?= csrf_field() ?><input type="hidden" name="action" value="remove_prerequisite"><input type="hidden" name="student_number" value="<?= h($number) ?>"><input type="hidden" name="prerequisite_id" value="<?= (int) $rule['id'] ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$rules): ?><tr><td colspan="3" class="text-muted">No prerequisites configured. Add the school's actual subject requirements before relying on prerequisite checks.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (Auth::can('curriculum.edit')): ?><form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="action" value="prerequisite"><input type="hidden" name="student_number" value="<?= h($number) ?>">
<div class="col-md-5"><label class="form-label" for="ruleSubject">Subject *</label><select class="form-select" id="ruleSubject" name="subject_id" required><option value="">Choose subject</option><?php foreach ($subjects as $s): ?><option value="<?= h($s['id']) ?>"><?= h(($s['subjectCode'] ?? '') . ' · ' . $s['subjectName']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-5"><label class="form-label" for="rulePrereq">Prerequisite *</label><select class="form-select" id="rulePrereq" name="prerequisite_id" required><option value="">Choose prerequisite</option><?php foreach ($subjects as $s): ?><option value="<?= h($s['id']) ?>"><?= h(($s['subjectCode'] ?? '') . ' · ' . $s['subjectName']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Add rule</button></div></form><?php endif; ?>
</div>
<?php layout_end();
