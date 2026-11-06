<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
$id = (string) ($_GET['id'] ?? '');
$s = GS::get('students', $id);
if (!$s) throw new AppError('Student not found in the grading system.');
$canEdit = Auth::can('students.edit');
if ($isPost && ($_POST['action'] ?? '') === 'details') {
    StudentDetails::save($id, $_POST);
    flash('success', 'Records for documents saved.');
    redirect('student', ['id' => $id]);
}
if ($isPost) {
    GS::saveStudent($id, $_POST);
    flash('success', 'Student updated (also in the grading system).');
    redirect('student', ['id' => $id]);
}
$regs = Db::all('SELECT r.*, n.name AS assessment_name FROM sis_registrations r LEFT JOIN sis_assessment_names n ON n.id = r.assessment_name_id WHERE r.student_doc_id = ? OR r.student_number = ? ORDER BY r.school_year DESC, r.id DESC', [$id, $s['studentId'] ?? '']);
$pays = Db::all('SELECT p.*, r.reg_no FROM sis_payments p JOIN sis_registrations r ON r.id = p.registration_id WHERE r.student_doc_id = ? OR r.student_number = ? ORDER BY p.paid_at DESC', [$id, $s['studentId'] ?? '']);
$adm = Db::one('SELECT * FROM sis_admissions WHERE student_number = ?', [$s['studentId'] ?? '']);
layout_start((string) ($s['studentName'] ?? 'Student'));
page_title((string) ($s['studentName'] ?? ''), Auth::can('registration') ? '<a class="btn btn-primary" href="' . url('registration', ['new' => 1, 'student' => $s['studentId'] ?? '']) . '"><i class="bi bi-journal-plus"></i> Register for a term</a>' : '',
    h(($s['studentId'] ?? '') . ' · ' . ($s['sectionName'] ?? '') . ' · ' . ($s['yearLevel'] ?? '') . ' · ' . ($s['schoolYear'] ?? '')));
?>
<div class="row g-3">
<div class="col-lg-4">
  <?php if ($canEdit): ?>
  <form method="post" class="form-section" id="studentEdit"><?= csrf_field() ?><input type="hidden" name="_version" value="<?= h(GS::version('students', $id)) ?>">
    <h2 class="h6">Student record (grading system)</h2>
    <label class="form-label small">Student ID</label><input class="form-control form-control-sm mb-2" name="studentId" value="<?= h($s['studentId'] ?? '') ?>" maxlength="40" required>
    <label class="form-label small">Name (Last, First M.)</label><input class="form-control form-control-sm mb-2" name="studentName" value="<?= h($s['studentName'] ?? '') ?>" maxlength="150" required>
    <label class="form-label small">Email</label><input class="form-control form-control-sm mb-2" type="email" name="email" value="<?= h($s['email'] ?? '') ?>" maxlength="191">
    <label class="form-label small">Section</label><select class="form-select form-select-sm mb-2" name="sectionId"><?php foreach (GS::sections() as $x): ?><option value="<?= h($x['id']) ?>"<?= $x['id'] === ($s['sectionId'] ?? '') ? ' selected' : '' ?>><?= h($x['sectionName'] . ' · ' . $x['yearLevel'] . ' · ' . $x['schoolYear']) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm btn-primary">Save</button>
    <div class="form-text">To move a student to another class during the school year, use the grading system's Students page: it also moves their classes and grades.</div>
  </form>
  <?php else: ?>
  <div class="form-section small"><dl class="row mb-0"><dt class="col-5">Student ID</dt><dd class="col-7"><?= h($s['studentId'] ?? '') ?></dd><dt class="col-5">Email</dt><dd class="col-7"><?= h($s['email'] ?? '') ?></dd><dt class="col-5">Section</dt><dd class="col-7"><?= h($s['sectionName'] ?? '') ?></dd></dl></div>
  <?php endif; ?>
  <?php $det = StudentDetails::get($id); $detMissing = array_unique(array_merge(StudentDetails::missing('tor', $det, $s), StudentDetails::missing('good-moral', $det, $s))); ?>
  <?php if ($canEdit): ?>
  <form method="post" class="form-section" id="studentDetails"><?= csrf_field() ?><input type="hidden" name="action" value="details">
    <h2 class="h6">Records for documents <span class="small text-muted fw-normal">(optional; needed for TOR and Good Moral)</span></h2>
    <?php if ($detMissing): ?><p class="small text-warning-emphasis mb-2"><i class="bi bi-info-circle"></i> Needed before issuing a TOR or Good Moral: <?= h(implode(', ', $detMissing)) ?></p><?php endif; ?>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small mb-0" for="dSex">Sex</label><select class="form-select form-select-sm" id="dSex" name="sex"><option value="">—</option><?php foreach (StudentDetails::SEXES as $x): ?><option<?= $det['sex'] === $x ? ' selected' : '' ?>><?= $x ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-0" for="dBirth">Birth date</label><input class="form-control form-control-sm" type="date" id="dBirth" name="birth_date" value="<?= h($det['birth_date']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dPlace">Birth place</label><input class="form-control form-control-sm" id="dPlace" name="birth_place" maxlength="191" value="<?= h($det['birth_place']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dMobile">Mobile number</label><input class="form-control form-control-sm" id="dMobile" name="mobile" maxlength="30" inputmode="tel" value="<?= h($det['mobile']) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0" for="dAddr">Home address</label><input class="form-control form-control-sm" id="dAddr" name="home_address" maxlength="255" value="<?= h($det['home_address']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dAdm">Date admitted</label><input class="form-control form-control-sm" type="date" id="dAdm" name="date_admitted" value="<?= h($det['date_admitted']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dCred">Entrance credentials</label><input class="form-control form-control-sm" id="dCred" name="entrance_credentials" maxlength="255" placeholder="e.g. Form 138" value="<?= h($det['entrance_credentials']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dGrad">Graduation date</label><input class="form-control form-control-sm" type="date" id="dGrad" name="graduation_date" value="<?= h($det['graduation_date']) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="dSo">SO number</label><input class="form-control form-control-sm" id="dSo" name="so_number" maxlength="100" value="<?= h($det['so_number']) ?>"></div>
    </div>
    <button class="btn btn-sm btn-primary mt-2">Save</button>
  </form>
  <?php else: ?>
  <div class="form-section small" id="studentDetails"><h2 class="h6">Records for documents</h2><dl class="row mb-0"><?php foreach (StudentDetails::FIELDS as $k => $label): ?><dt class="col-5"><?= h($label) ?></dt><dd class="col-7"><?= h($det[$k] ?: '—') ?></dd><?php endforeach; ?></dl></div>
  <?php endif; ?>
  <?php if ($adm): ?><div class="form-section small"><h2 class="h6">Admission</h2><?= h($adm['admission_no']) ?> · <?= h(Admission::TYPES[$adm['applicant_type']]) ?> · <?= badge($adm['status']) ?><br><?= h($adm['phone']) ?> <?= h($adm['address']) ?><?= $adm['guardian_name'] ? '<br>Guardian: ' . h($adm['guardian_name'] . ' ' . $adm['guardian_phone']) : '' ?>
    <?php $dp = EnrollmentDocs::progress($adm); if ($dp['required']): ?><br><strong>Documents:</strong> <?= $dp['missing'] ? h($dp['done'] . ' of ' . $dp['required'] . ' required submitted · missing: ' . implode(', ', $dp['missing'])) : 'all required documents submitted' ?><?php if (Auth::can('admission')): ?> · <a href="<?= url('admission', ['id' => $adm['id']]) ?>">Checklist</a><?php endif; ?><?php endif; ?></div><?php endif; ?>
</div>
<div class="col-lg-8"><div class="form-section d-flex justify-content-end flex-wrap gap-2"><?php if (Auth::can('documents')): ?><a class="btn btn-outline-primary" href="<?= url('documents', ['student' => $id]) ?>"><i class="bi bi-file-earmark-text"></i> TOR &amp; Certificates</a><?php endif; ?><?php if(StudentHistory::canViewAllGrades()): ?><a class="btn btn-outline-primary" href="<?= url('history',['id'=>$id,'tab'=>'grades']) ?>">History of Grades</a><?php endif; ?><a class="btn btn-outline-primary" href="<?= url('history',['id'=>$id,'tab'=>'assessments']) ?>">History of Assessments</a></div>
  <?php if(StudentHistory::canViewAllGrades()): ?><div class="form-section"><h2 class="h6">Final grades <span class="text-muted fw-normal small">(tagged complete by the registrar in the grading system)</span></h2>
    <?= grades_html(GS::completions($id)) ?></div><?php endif; ?>
  <?php $clrOv = Clearance::overrides(null, (string) ($s['studentId'] ?? '')); if ($clrOv): ?><div class="form-section"><h2 class="h6"><i class="bi bi-patch-exclamation me-1"></i>Enrolled without a completed clearance</h2><ul class="small mb-0"><?php foreach ($clrOv as $co): ?><li><?= h($co['school_year'] . ' ' . $co['term']) ?> confirmed by <?= h((string) $co['by_name']) ?> on <?= h(substr($co['created_at'], 0, 10)) ?> although the clearance of <?= h($co['cleared_sy'] . ' ' . $co['cleared_term']) ?> was not completed. Reason: <?= h($co['reason']) ?>. Pending then: <?= h($co['pending']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="form-section"><h2 class="h6">Registrations and assessments</h2>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>No.</th><th>Term</th><th>Section</th><th>Assessment</th><th class="money">Total</th><th class="money">Balance</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($regs as $r): ?><tr><td class="font-monospace"><?= h($r['reg_no']) ?></td><td><?= h($r['school_year'] . ' ' . $r['term']) ?></td><td><?= h($r['section_name']) ?></td><td class="small"><?= h($r['assessment_name']) ?></td>
      <td class="money"><?= Money::fmt(Money::cents($r['total_amount'])) ?></td><td class="money"><?= Money::fmt(Registration::balance($r)) ?></td><td><?= badge(Registration::state($r)) ?> <?= Registration::state($r) !== 'VOIDED' ? badge(Registration::payStatus($r)) : '' ?></td>
      <td><a class="btn btn-sm btn-outline-<?= Registration::state($r) === 'OPEN' && Auth::can('registration') ? 'primary" href="' . url('registration', ['id' => $r['id']]) . '">Open' : 'secondary" href="' . url('registration', ['id' => $r['id']]) . '">View' ?></a></td></tr><?php endforeach; ?>
    <?php if (!$regs): ?><tr><td colspan="8" class="text-muted">Not registered yet.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="form-section"><h2 class="h6">Payments</h2>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Receipt</th><th>Registration</th><th>Method</th><th class="money">Amount</th></tr></thead><tbody>
    <?php foreach ($pays as $p): ?><tr class="<?= $p['status'] === 'VOIDED' ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(substr($p['paid_at'], 0, 10)) ?></td><td><a href="<?= url('receipt', ['id' => $p['id']]) ?>"><?= h($p['receipt_no']) ?></a></td><td><?= h($p['reg_no']) ?></td><td><?= h($p['method']) ?></td><td class="money"><?= Money::fmt(Money::cents($p['amount'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$pays): ?><tr><td colspan="5" class="text-muted">No payments.</td></tr><?php endif; ?></tbody></table></div></div>
</div></div>
<?php layout_end();
